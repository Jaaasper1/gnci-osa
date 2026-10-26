<?php
// ============================================================
// AI FAQ assistant for the OSA message system (Google Gemini).
// Answers only from includes/osa-faq.php. If it is not sure, it returns null
// and the message stays for OSA staff.
// Needs GEMINI_API_KEY in includes/config.local.php (server side only).
// Optional: GEMINI_MODEL in config.local.php (default below).
// ============================================================
require_once __DIR__ . '/osa-faq.php';
require_once __DIR__ . '/audit.php';

const OSA_ESCALATE_TOKEN = '[[ESCALATE]]';
const OSA_ASSISTANT_NAME = 'AI Assistant (OSA)';
const OSA_DEFAULT_MODEL  = 'gemini-3.5-flash-lite';

// Writes to the PHP error log so staff can see why the AI did not answer.
function osaAiLog(string $reason): void {
    error_log('[OSA AI assistant] ' . $reason);
}

// Returns the answer text, or null when the assistant should not answer.
function osaAssistantAnswer(string $question): ?string {
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
        osaAiLog('GEMINI_API_KEY is empty in config.local.php');
        return null;
    }
    if (!function_exists('curl_init')) {
        osaAiLog('cURL extension is not enabled in PHP');
        return null;
    }
    $faq = osaFaqEntries();
    if (!$faq) {
        osaAiLog('No usable FAQ entries (all still TODO) in includes/osa-faq.php');
        return null;
    }

    $faqText = '';
    foreach ($faq as $i => $e) {
        $faqText .= ($i + 1) . ". Tanong: {$e['q']}\n   Sagot: {$e['a']}\n";
    }
    $system = "You are the FAQ assistant of the Office of Student Affairs (OSA) of Guagua National College. "
        . "Answer ONLY using the FAQ below. Do not guess and do not use outside knowledge about the school. "
        . "If the message is not clearly answered by the FAQ, or it is a complaint, a personal or emotional problem, "
        . "a request about a specific case or record, or anything that needs staff, reply with exactly: " . OSA_ESCALATE_TOKEN . "\n"
        . "Reply in the same language the student uses (Tagalog, English, or Taglish). Keep it short and friendly.\n\n"
        . "FAQ:\n" . $faqText;

    $model = defined('GEMINI_MODEL') && GEMINI_MODEL !== '' ? GEMINI_MODEL : OSA_DEFAULT_MODEL;
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';

    $payload = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => mb_substr($question, 0, 1500)]]],
        ],
        'generationConfig' => ['maxOutputTokens' => 1024],   // room for the model's internal reasoning too
    ];

    // Retry briefly when Gemini is busy (503) or rate-limited (429).
    $attempts = 0;
    do {
        $attempts++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . GEMINI_API_KEY,
            ],
        ]);
        $raw     = curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $retryable = ($raw === false) || in_array($code, [429, 500, 503], true);
        if ($retryable && $attempts < 3) {
            sleep(2);
            continue;
        }
        break;
    } while (true);

    if ($raw === false) { osaAiLog('cURL error after ' . $attempts . ' tries: ' . $curlErr); return null; }
    if ($code !== 200) { osaAiLog('API returned HTTP ' . $code . ' after ' . $attempts . ' tries: ' . substr((string)$raw, 0, 300)); return null; }

    $data = json_decode($raw, true);
    $text = '';
    foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
        $text .= $part['text'] ?? '';
    }
    $text = trim($text);
    if ($text === '') { osaAiLog('Empty answer from API'); return null; }
    if (strpos($text, OSA_ESCALATE_TOKEN) !== false) return null;   // normal: question is not in the FAQ
    return mb_substr($text, 0, 2000);
}

// Called after a student sends a text message. Posts the AI answer if there is one.
function osaTryAutoReply(PDO $pdo, int $threadId, string $question): void {
    if (trim($question) === '') return;
    $answer = osaAssistantAnswer($question);
    if ($answer === null) return;   // no answer: the thread waits for staff
    msgAddReply($pdo, $threadId, 'assistant', 0, OSA_ASSISTANT_NAME, $answer);
    auditLog($pdo, 'auto_reply', 'message', $threadId, [], ['by' => 'assistant']);
}

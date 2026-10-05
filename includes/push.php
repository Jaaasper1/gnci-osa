<?php
require_once __DIR__ . '/push-config.php';

/**
 * Send a real push notification to every admin device that has subscribed
 * (tagged role=admin in OneSignal). Fails silently — a push error should
 * never break the page that triggered it (e.g. submitting a complaint).
 *
 * @param string      $title   Notification title
 * @param string      $message Notification body
 * @param string|null $url     Optional URL to open when the notification is tapped
 * @return bool true if OneSignal accepted the request
 */
function sendAdminPush($title, $message, $url = null) {
    if (ONESIGNAL_APP_ID === '' || ONESIGNAL_REST_API_KEY === '') {
        return false; // not configured yet — skip quietly
    }

    $payload = [
        'app_id'   => ONESIGNAL_APP_ID,
        'filters'  => [
            ['field' => 'tag', 'key' => 'role', 'relation' => '=', 'value' => 'admin'],
        ],
        'headings' => ['en' => $title],
        'contents' => ['en' => $message],
    ];
    if ($url) {
        $payload['url'] = $url;
    }

    if (!function_exists('curl_init')) {
        return false; // cURL extension not available on this host
    }

    $ch = curl_init('https://api.onesignal.com/notifications');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Key ' . ONESIGNAL_REST_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($response !== false) && $httpCode >= 200 && $httpCode < 300;
}

<?php
// ============================================================
// OSA FAQ. Used by the OSA Guide page and the Ask OSA bubble.
// Staff: replace each TODO answer with the official OSA information.
// - Entries whose answer starts with "TODO" are hidden everywhere.
// - Entries marked 'guide_only' => true show in the OSA Guide only, not in the Ask OSA bubble.
// ============================================================
function osaFaqEntries(): array {
    $entries = [

        // ── Office hours, location, contact (guide only) ──
        ['q' => 'What time is the OSA office open?',
         'a' => 'The OSA office is open from 8:00 AM to 5:00 PM, Monday through Friday', 'guide_only' => true, 'k' => 'time hours open schedule oras'],
        ['q' => 'Is the OSA office open on weekends (Saturday, Sunday)?',
         'a' => 'No', 'guide_only' => true],
        ['q' => 'Is the OSA office open on weekdays (Monday, Tuesday, Wednesday, Thursday, Friday)?',
         'a' => 'Yes, the OSA office is open', 'guide_only' => true],
        ['q' => 'Where is the OSA office?',
         'a' => 'LH near odditorium.', 'guide_only' => true, 'k' => 'where location room building saan'],

        // ── Requirements ──
        ['q' => 'What are the requirements for OSA membership?',
         'a' => 'No requirements yet.'],
        ['q' => 'What are the requirements for clearance?',
         'a' => 'Go to the OSA office.'],
        ['q' => 'What are the requirements for replacing an ID?',
         'a' => 'For your ID, just go to the MIS office.'],
        ['q' => 'What are the requirements for accreditation of a student organization?',
         'a' => "I don't have an answer for this yet."],

        // ── Grievance / complaints ──
        ['q' => 'How do I file a grievance or complaint?',
         'a' => 'Go to New Complaint.'],
        ['q' => 'How long does the grievance process take?',
         'a' => "I don't have information on this yet."],
        ['q' => 'Can a complaint be anonymous?',
         'a' => 'Yes, but it cannot be 100% validated.'],

        // ── Complaints and messages ──
        ['q' => 'What is Ask OSA?',
         'a' => 'Ask OSA is the chat button at the bottom-right of the student pages. You can type a question or message. An AI assistant answers first, using only the official OSA FAQ. If it cannot answer, OSA staff reply in the same chat.'],
        ['q' => 'How many questions can I ask the AI in Ask OSA?',
         'a' => 'You have 5 AI questions. The chat shows how many are left. After that, OSA staff will reply to your messages.'],
        ['q' => 'What is the difference between Ask OSA and a complaint?',
         'a' => 'Use Ask OSA for questions and inquiries. Use New Complaint for problems that need to be tracked, reviewed and resolved by the OSA. You can submit a complaint anonymously.'],
        ['q' => 'Where do I see the reply from OSA staff?',
         'a' => 'Open Ask OSA again to see the reply in your chat. New replies also appear in Notifications.'],
        ['q' => 'How do I track the status of my complaint?',
         'a' => 'Go to My Complaints.'],
        ['q' => 'How long does the OSA take to reply to a message?',
         'a' => "I don't have an answer for this yet."],

        // ── Account ──
        ['q' => 'How do I change my password?',
         'a' => 'We no longer use passwords. We now use OTP only. If you want to change your Gmail, go to Settings to update it.'],
        ['q' => 'What should I do if I cannot log in?',
         'a' => 'Go to the OSA office so they can check the possible problem or whether you entered the wrong Gmail.'],

        // ── Clearance and ID ──
        ['q' => 'Where do I get my clearance?',
         'a' => 'Go to the cashier to get your clearance.'],
        ['q' => 'How do I replace a lost ID?',
         'a' => 'Go to the MIS office to replace your ID.'],

        // ── Election ──
        ['q' => 'How do I vote in the election?',
         'a' => 'Go to Vote Now. Get a voucher from the OSA office or the watcher to enter the election.'],
        ['q' => 'Who can run in the election?',
         'a' => 'Anyone, as long as they are from the colleges.'],
        ['q' => 'How do I get a voting voucher?',
         'a' => 'From the OSA office or the watcher.'],

        // ── Events ──
        ['q' => 'Where can I see the OSA event schedule?',
         'a' => 'It is in the Events module on the side. On desktop, it is in the sidebar. On a phone, tap the three lines at the top left.'],
    ];

    return array_values(array_filter($entries, function ($e) {
        $a = trim($e['a']);
        // Skip unfinished entries: TODO, N/A, or placeholder text such as "ilagay ..." or "[...]"
        if (strpos($a, 'TODO') === 0) return false;
        if (strcasecmp($a, 'N/A') === 0 || strcasecmp($a, 'N/A.') === 0) return false;
        if (stripos($a, 'ilagay') !== false || strpos($a, '[') !== false) return false;
        return $a !== '';
    }));
}
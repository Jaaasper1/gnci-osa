<?php
// ============================================================
// OSA FAQ. Used by the OSA Guide page and the Ask OSA bubble.
// Source: PORTAL_FAQs.docx (official OSA FAQ). To change an answer, edit it here.
// - Entries whose answer starts with "TODO" are hidden everywhere.
// - Entries marked 'guide_only' => true are meant for the OSA Guide only.
// Note: the Ask OSA AI answers ONLY from this list (see includes/osa-assistant.php).
// ============================================================
function osaFaqEntries(): array {
    $entries = [

        // ── General Information ──
        ['q' => 'What time is the OSA office open?',
         'a' => 'The OSA office is open from 8:00 AM to 5:00 PM, Monday through Friday.', 'guide_only' => true],
        ['q' => 'Is the OSA office open on weekends (Saturday and Sunday)?',
         'a' => 'No. The office is closed on Saturdays and Sundays.', 'guide_only' => true],
        ['q' => 'Where is the OSA office located?',
         'a' => 'The office is located on the 2nd floor of Limlingan Hall, near the auditorium.', 'guide_only' => true],
        ['q' => 'What concerns can I bring to the OSA?',
         'a' => 'The Office of Student Affairs handles student-related concerns, including student discipline, grievances, student organizations, student activities, elections, and other matters concerning student welfare.'],
        ['q' => 'Do I need an appointment before visiting the OSA office?',
         'a' => 'No appointment is necessary. You may visit the OSA office during regular office hours.'],
        ['q' => 'Can I contact the OSA outside office hours?',
         'a' => 'You may send your inquiry through the OSA portal or official Facebook page outside office hours. Please note that staff responses will be provided during office hours, subject to availability.'],
        ['q' => 'Are my complaints and personal information confidential?',
         'a' => 'The OSA handles complaints and personal information with appropriate confidentiality. Information may be shared with authorized personnel when necessary to assess, investigate, or address a concern.'],
        ['q' => 'What if my concern is not listed in the FAQs?',
         'a' => 'You may use Ask OSA to send your inquiry. If your concern requires formal action, review, or investigation, please submit it through the New Complaint feature.'],

        // ── Ask OSA and Complaints ──
        ['q' => 'What is Ask OSA?',
         'a' => 'Ask OSA is the chat button at the bottom-right of the student pages. You can type a question or message. An AI assistant answers first using only the official OSA FAQs. If it cannot answer, an OSA staff member will respond in the same chat.'],
        ['q' => 'What is the difference between Ask OSA and a complaint?',
         'a' => 'Use Ask OSA for questions and general inquiries. Use New Complaint for concerns that need to be formally tracked, reviewed, and addressed by the OSA. You may submit a complaint anonymously.'],
        ['q' => 'How do I file a grievance or complaint?',
         'a' => 'You may personally visit the OSA office or submit your concern through this portal by clicking the New Complaint button.'],
        ['q' => 'Can I submit a complaint anonymously?',
         'a' => 'Yes. You may submit a complaint anonymously through the New Complaint feature.'],
        ['q' => 'Can I attach photos or supporting documents to my complaint?',
         'a' => 'Yes. You may attach relevant photos, screenshots, or documents to help the OSA assess your concern.'],
        ['q' => 'What happens after I submit a complaint?',
         'a' => 'The OSA will review your submission, assess the concern, and determine the appropriate action or referral. You may monitor updates through My Complaints.'],
        ['q' => 'How do I track the status of my complaint?',
         'a' => 'Go to My Complaints to view the status and available updates regarding your submission.'],
        ['q' => 'Can I edit or withdraw my complaint after submitting it?',
         'a' => 'For corrections or withdrawal requests, please contact the OSA through Ask OSA or visit the office.'],
        ['q' => 'How long does it take for the OSA to respond to my concern?',
         'a' => 'Response times may vary depending on the nature, urgency, and complexity of the concern. The OSA will provide updates whenever necessary.'],
        ['q' => 'What should I do if my complaint is urgent?',
         'a' => 'For urgent concerns involving immediate safety or the need for immediate assistance, approach the OSA office or contact the appropriate school authority immediately. Do not rely solely on an online submission.'],
        ['q' => 'Will the person I complained about know my identity?',
         'a' => 'The OSA handles complaints with confidentiality. However, depending on the nature of the concern, certain information may need to be disclosed to authorized personnel to ensure proper assessment, investigation, and due process.'],
        ['q' => 'Where do I see the reply from OSA staff?',
         'a' => 'Open Ask OSA again to view the reply in your chat. New replies will also appear in Notifications.'],
        ['q' => 'Can I access my previous conversations with Ask OSA?',
         'a' => 'Yes. Open Ask OSA to view your available conversation history. If a previous conversation is not visible, contact the OSA for assistance.'],
        ['q' => 'Can I report bullying or harassment through the portal?',
         'a' => 'Yes. You may submit a concern involving bullying or harassment through New Complaint. If someone is in immediate danger, seek assistance from the OSA or the appropriate school authority immediately.'],
        ['q' => 'Can I report a concern involving a faculty member or other school personnel?',
         'a' => 'Yes. You may submit your concern through New Complaint or personally approach the OSA. The concern will be assessed and referred to the appropriate office or authority when necessary.'],

        // ── Events and Student Activities ──
        ['q' => 'Where can I see the OSA event schedule?',
         'a' => 'The schedule is available in the Events module on the side menu. On desktop, it appears in the sidebar. On a phone, tap the three lines at the top-left to open the menu.'],
        ['q' => 'Where can I find announcements about upcoming OSA activities?',
         'a' => 'Check the Events module in the portal and the official GNC and OSA Facebook pages for announcements and updates.'],
        ['q' => 'How do I register for an OSA event?',
         'a' => 'Registration instructions will be included in the event announcement. Some activities may require online registration through a designated form or portal.'],
        ['q' => 'Where can I check if an event has been postponed or cancelled?',
         'a' => 'Updates will be posted through the Events module and the school\'s official communication channels.'],
        ['q' => 'Can I participate in OSA activities even if I am not a student officer?',
         'a' => 'Yes. Many OSA activities are open to eligible students. Please check the specific event announcement for participation requirements.'],
        ['q' => 'Can I request assistance from the OSA for personal or academic concerns?',
         'a' => 'Yes. You may approach the OSA for student-related concerns. Depending on the nature of your concern, you may be assisted directly or referred to the appropriate school office or service.'],

        // ── Student Organizations ──
        ['q' => 'How can I join a student organization?',
         'a' => 'Watch for announcements regarding organization recruitment and registration. You may also inquire directly with the OSA office.'],
        ['q' => 'Where can I find information about student organizations?',
         'a' => 'You may check official school and OSA announcements or visit the OSA office for information about recognized student organizations.'],
        ['q' => 'Who can I contact regarding student organization activities or concerns?',
         'a' => 'You may contact the OSA through Ask OSA, visit the office, or use the appropriate portal feature if your concern requires formal action.'],

        // ── Student Elections ──
        ['q' => 'How do I vote in the election?',
         'a' => 'Go to Vote Now. Obtain a voting voucher from the OSA office or the assigned election watcher to access the election. Please note that the Vote Now feature will only appear once the election has started.'],
        ['q' => 'How do I get a voting voucher?',
         'a' => 'The OSA staff or assigned election personnel will provide your voting voucher at the election venue.'],
        ['q' => 'Who is eligible to vote in the student elections?',
         'a' => 'Eligible college students who meet the requirements under the applicable election guidelines may participate in the student elections.'],
        ['q' => 'Can I vote more than once?',
         'a' => 'No. Each eligible student is entitled to cast only one official vote, subject to the applicable election guidelines.'],
        ['q' => 'What should I do if my voting voucher does not work?',
         'a' => 'Please approach the OSA staff or assigned election personnel at the voting venue for assistance.'],
        ['q' => 'Can I change my vote after submitting it?',
         'a' => 'No. Once your vote has been successfully submitted, it cannot be changed.'],
        ['q' => 'Where can I see the candidates and their platforms?',
         'a' => 'Candidate profiles, campaign materials, and election-related announcements will be posted through the official election channels and/or the portal, when available.'],

        // ── Portal and Account Support ──
        ['q' => 'How do I change my password?',
         'a' => 'The portal no longer uses passwords. You can log in using a One-Time Password (OTP). If you need to change your registered Gmail account, go to Settings and update your email address.'],
        ['q' => 'What should I do if I cannot log in?',
         'a' => 'You may personally visit the OSA office or send a message to the official OSA Facebook page for assistance.'],
        ['q' => 'What Gmail account should I use to log in?',
         'a' => 'Please use the registered Gmail account associated with your student profile.'],
        ['q' => 'What should I do if I am not receiving my OTP?',
         'a' => 'Check your Gmail inbox, Spam, and Promotions folders. If you still cannot receive your OTP, contact the OSA through its official Facebook page or visit the office.'],
        ['q' => 'Can I use a different Gmail account?',
         'a' => 'You may update your registered Gmail account through the Settings module of your portal account.'],
        ['q' => 'Can I access the OSA portal using my phone?',
         'a' => 'Yes. The OSA portal is accessible through mobile phones, tablets, and desktop computers using a web browser.'],
        ['q' => 'Why can\'t I see certain modules in my portal?',
         'a' => 'Some modules may only be available during specific periods or to students with the appropriate access permissions. If you believe you should have access, please contact the OSA.'],
        ['q' => 'Why am I not receiving notifications?',
         'a' => 'Check the Notifications module regularly and make sure your registered Gmail address is correct. If the issue continues, contact the OSA for assistance.'],
        ['q' => 'What should I do if I submitted a complaint by mistake?',
         'a' => 'Please contact the OSA through Ask OSA or visit the office as soon as possible so staff can review your request.'],
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
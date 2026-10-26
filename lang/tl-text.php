<?php
/**
 * English => Tagalog, used by tx() in includes/lang.php.
 * Key = the exact English sentence in the code. Missing keys just show English.
 * %s / %d placeholders are filled by tx() AFTER translating.
 */
return [
    // ── status words ──
    'New' => 'Bago', 'In Review' => 'Sinusuri', 'Resolved' => 'Naresolba', 'Rejected' => 'Tinanggihan', 'Closed' => 'Sarado', 'Open' => 'Bukas',

    // ── common ──
    'OSA Office' => 'OSA Office', 'Ask OSA (AI)' => 'Ask OSA (AI)', 'You' => 'Ikaw',
    'Voice message' => 'Voice message', 'Anonymous' => 'Anonymous', '✓ Anonymous' => '✓ Anonymous',
    'Email' => 'Email', 'Student ID' => 'Student ID', 'Live' => 'Live', 'Abstain' => 'Abstain',
    'Abstained:' => 'Hindi pumili:', 'Independent' => 'Independent', 'Not found.' => 'Hindi nahanap.',
    'An anonymous student' => 'Isang anonymous na estudyante',
    'Install App' => 'I-install ang App',

    // ── complaint categories (display only; saved value stays English) ──
    'Department-related Issue' => 'Isyung Kaugnay ng Departamento',
    'Student organization officer concern' => 'Alalahanin tungkol sa opisyal ng student organization',
    'Mismanagement of organization duties/resources' => 'Maling pamamahala ng tungkulin/yaman ng organisasyon',
    'Unfair treatment / lack of transparency by officers' => 'Hindi patas na pagtrato / kakulangan ng transparency ng mga opisyal',
    'Others' => 'Iba pa',
    'Please wait before submitting another complaint. See the timer below.' => 'Maghintay muna bago magsumite ng panibagong reklamo. Tingnan ang timer sa ibaba.',

    // ── events timing ──
    'Cancelled' => 'Kinansela', 'Happening now' => 'Kasalukuyan', 'Happening now · ends today' => 'Kasalukuyan · matatapos ngayon',
    'Happening now · %d day left' => 'Kasalukuyan · %d araw na lang', 'Happening now · %d days left' => 'Kasalukuyan · %d araw na lang',
    'Tomorrow' => 'Bukas', 'In %d days' => 'Sa %d araw', 'In %d weeks' => 'Sa %d linggo', 'In %d months' => 'Sa %d buwan',
    'Ended yesterday' => 'Natapos kahapon', 'Ended %d days ago' => 'Natapos %d araw na ang nakalipas',
    'Ended %d weeks ago' => 'Natapos %d linggo na ang nakalipas', 'Ended %d months ago' => 'Natapos %d buwan na ang nakalipas',
    'Upcoming · %s' => 'Paparating · %s', 'Past · %s' => 'Nakaraan · %s',
    'Today' => 'Ngayon', 'Yesterday' => 'Kahapon', 'This week' => 'Ngayong linggo', 'This month' => 'Ngayong buwan', 'Older' => 'Mas luma',

    // ── notifications ──
    'Complaint' => 'Reklamo', 'Voting' => 'Pagboto', 'Event' => 'Event', 'Account' => 'Account', 'Notification' => 'Abiso',
    'No notifications yet.' => 'Wala pang abiso.', 'No notifications in this view.' => 'Walang abiso sa view na ito.',
    'notification' => 'abiso', 'notifications' => 'abiso', '%d notification' => '%d abiso', '%d notifications' => '%d abiso',
    'Delete Notification' => 'Burahin ang Abiso', 'Delete All Notifications' => 'Burahin ang Lahat ng Abiso',
    'Mark all as read' => 'Markahan lahat bilang nabasa',

    // ── voting ──
    'No Election Right Now' => 'Walang Eleksyon Ngayon',
    'There is no ongoing election at the moment. Please check back later.' => 'Walang kasalukuyang eleksyon sa ngayon. Bumalik na lang mamaya.',
    'Voting Not Yet Open' => 'Hindi Pa Bukas ang Pagboto', 'Voting Has Closed' => 'Sarado Na ang Pagboto', 'Not Available' => 'Hindi Available',
    'Voting for "%s" hasn\'t opened yet. It opens on %s.' => 'Hindi pa bukas ang pagboto para sa "%s". Magbubukas ito sa %s.',
    'Voting for "%s" has already closed' => 'Sarado na ang pagboto para sa "%s"',
    '. You can view the results from the Events page.' => '. Makikita mo ang resulta sa Events page.',
    'Invalid or already used voucher code. Please check and try again.' => 'Di-wastong voucher code o nagamit na. Pakisuri at subukan muli.',
    'Your vote has been recorded! Thank you for participating.' => 'Naitala na ang iyong boto! Salamat sa pakikilahok.',
    'Recording your vote...' => 'Itinatala ang iyong boto...',
    'Please select a candidate or choose Abstain for each position.' => 'Pumili ng kandidato o piliin ang Abstain sa bawat posisyon.',
    'Abstain for this position' => 'Abstain para sa posisyong ito', '\u2713 Abstaining' => '\u2713 Abstain',
    'Vote' => 'Iboto', '\u2713 Selected' => '\u2713 Napili',

    // ── Ask OSA (AI) auto replies ──
    'You have used your AI questions. Please wait for the OSA admin or staff to reply to your message.' => 'Naubos mo na ang iyong mga tanong sa AI. Hintayin ang sagot ng OSA admin o staff sa iyong mensahe.',
    'Please wait for the OSA admin or staff to reply to your message.' => 'Hintayin ang sagot ng OSA admin o staff sa iyong mensahe.',

    // ── Facebook feed ──
    'Could not refresh (%s) — showing the last cached posts.' => 'Hindi ma-refresh (%s) — ipinapakita ang huling naka-save na mga post.',

    // ── OSA Guide: how-to cards ──
    'Submit a complaint' => 'Magsumite ng reklamo',
    'Use this for concerns that need to be tracked by the OSA.' => 'Gamitin ito para sa mga alalahaning kailangang subaybayan ng OSA.',
    'Click <b>New Complaint</b> in the sidebar.' => 'I-click ang <b>New Complaint</b> sa sidebar.',
    'Choose a <b>Category</b> and write a short <b>Complaint Title</b>.' => 'Pumili ng <b>Category</b> at magsulat ng maikling <b>Complaint Title</b>.',
    'Describe the issue in <b>Details</b>. Attach a file if it helps (optional).' => 'Ilarawan ang isyu sa <b>Details</b>. Mag-attach ng file kung makakatulong (opsyonal).',
    'Tick <b>Submit anonymously</b> if you do not want your name shown (optional).' => 'I-tick ang <b>Submit anonymously</b> kung ayaw mong makita ang pangalan mo (opsyonal).',
    'Click <b>Submit Complaint</b>. Track the status anytime in <b>My Complaints</b>.' => 'I-click ang <b>Submit Complaint</b>. Masusubaybayan mo ang status anumang oras sa <b>My Complaints</b>.',
    'Send a message to the OSA' => 'Magpadala ng mensahe sa OSA',
    'Use Messages for questions and inquiries that do not need a complaint.' => 'Gamitin ang Messages para sa mga tanong at katanungang hindi nangangailangan ng reklamo.',
    'Click <b>Messages</b> in the sidebar.' => 'I-click ang <b>Messages</b> sa sidebar.',
    'Type your message, or press <b>Voice message</b> to record one.' => 'I-type ang iyong mensahe, o pindutin ang <b>Voice message</b> para mag-record.',
    'Click <b>Send to OSA Office</b>.' => 'I-click ang <b>Send to OSA Office</b>.',
    'Replies from the office appear in your thread. Check <b>Notifications</b> for new replies.' => 'Lalabas ang mga sagot ng opisina sa iyong thread. Tingnan ang <b>Notifications</b> para sa mga bagong sagot.',
    'Vote in the election' => 'Bumoto sa eleksyon',
    'The Vote Now link only appears while an election is active.' => 'Lalabas lang ang Vote Now link kapag may aktibong eleksyon.',
    'Look for <b>Vote Now</b> in the sidebar, under Election.' => 'Hanapin ang <b>Vote Now</b> sa sidebar, sa ilalim ng Election.',
    'Review the candidates and choose your votes.' => 'Suriin ang mga kandidato at piliin ang iyong mga iboboto.',
    'Confirm your choices to submit your ballot.' => 'Kumpirmahin ang iyong mga napili para maisumite ang iyong balota.',
    'See events' => 'Tingnan ang mga event',
    'Find upcoming and past OSA activities.' => 'Hanapin ang paparating at nakaraang mga aktibidad ng OSA.',
    'Click <b>Events</b> in the sidebar.' => 'I-click ang <b>Events</b> sa sidebar.',
    'Browse the list and open an event for details.' => 'Basahin ang listahan at buksan ang isang event para sa detalye.',
    'Check notifications' => 'Tingnan ang mga abiso',
    'Updates on your complaints, messages, and the election.' => 'Mga update sa iyong mga reklamo, mensahe, at eleksyon.',
    'Click <b>Notifications</b> in the sidebar.' => 'I-click ang <b>Notifications</b> sa sidebar.',
    'A red number shows how many unread notifications you have.' => 'Ang pulang numero ay nagpapakita kung ilan ang hindi mo pa nababasang abiso.',
    'Change your account settings' => 'Baguhin ang settings ng iyong account',
    'Update your account details and preferences.' => 'I-update ang detalye at preference ng iyong account.',
    'Click <b>Settings</b> in the sidebar.' => 'I-click ang <b>Settings</b> sa sidebar.',
    'Make your changes, then save.' => 'Gawin ang iyong mga pagbabago, tapos i-save.',

    // ── OSA Guide: FAQ (questions + answers) ──
    'What time is the OSA office open?' => 'Anong oras bukas ang OSA office?',
    'The OSA office is open from 8:00 AM to 5:00 PM, Monday through Friday' => 'Bukas ang OSA office mula 8:00 AM hanggang 5:00 PM, Lunes hanggang Biyernes',
    'Is the OSA office open on weekends (Saturday, Sunday)?' => 'Bukas ba ang OSA office tuwing weekend (Sabado, Linggo)?',
    'No' => 'Hindi',
    'Is the OSA office open on weekdays (Monday, Tuesday, Wednesday, Thursday, Friday)?' => 'Bukas ba ang OSA office tuwing weekday (Lunes, Martes, Miyerkules, Huwebes, Biyernes)?',
    'Yes, the OSA office is open' => 'Oo, bukas ang OSA office',
    'Where is the OSA office?' => 'Saan ang OSA office?', 'LH near odditorium.' => 'Sa LH, malapit sa odditorium.',
    'What are the requirements for OSA membership?' => 'Ano ang mga requirement para sa OSA membership?', 'No requirements yet.' => 'Wala pang requirement.',
    'What are the requirements for clearance?' => 'Ano ang mga requirement para sa clearance?', 'Go to the OSA office.' => 'Pumunta sa OSA office.',
    'What are the requirements for replacing an ID?' => 'Ano ang mga requirement para sa pagpapalit ng ID?', 'For your ID, just go to the MIS office.' => 'Para sa ID, pumunta lang sa MIS office.',
    'What are the requirements for accreditation of a student organization?' => 'Ano ang mga requirement para sa akreditasyon ng student organization?',
    "I don't have an answer for this yet." => 'Wala pa akong sagot dito.', "I don't have information on this yet." => 'Wala pa akong impormasyon dito.',
    'How do I file a grievance or complaint?' => 'Paano ako magsasampa ng reklamo?', 'Go to New Complaint.' => 'Pumunta sa New Complaint.',
    'How long does the grievance process take?' => 'Gaano katagal ang proseso ng reklamo?',
    'Can a complaint be anonymous?' => 'Puwede bang anonymous ang reklamo?', 'Yes, but it cannot be 100% validated.' => 'Oo, pero hindi ito 100% mabeberipika.',
    'What is Ask OSA?' => 'Ano ang Ask OSA?',
    'Ask OSA is the chat button at the bottom-right of the student pages. You can type a question or message. An AI assistant answers first, using only the official OSA FAQ. If it cannot answer, OSA staff reply in the same chat.' => 'Ang Ask OSA ay ang chat button sa kanang-ibaba ng mga student page. Puwede kang mag-type ng tanong o mensahe. Unang sasagot ang AI assistant gamit lang ang opisyal na OSA FAQ. Kung hindi nito masagot, sasagot ang OSA staff sa parehong chat.',
    'How many questions can I ask the AI in Ask OSA?' => 'Ilang tanong ang puwede kong itanong sa AI sa Ask OSA?',
    'You have 5 AI questions. The chat shows how many are left. After that, OSA staff will reply to your messages.' => 'May 5 kang tanong sa AI. Makikita sa chat kung ilan pa ang natitira. Pagkatapos nito, OSA staff na ang sasagot sa iyong mga mensahe.',
    'What is the difference between Ask OSA and a complaint?' => 'Ano ang pagkakaiba ng Ask OSA at ng reklamo?',
    'Use Ask OSA for questions and inquiries. Use New Complaint for problems that need to be tracked, reviewed and resolved by the OSA. You can submit a complaint anonymously.' => 'Gamitin ang Ask OSA para sa mga tanong at katanungan. Gamitin ang New Complaint para sa mga problemang kailangang subaybayan, suriin, at lutasin ng OSA. Puwede kang magsumite ng reklamo nang anonymous.',
    'Where do I see the reply from OSA staff?' => 'Saan ko makikita ang sagot ng OSA staff?',
    'Open Ask OSA again to see the reply in your chat. New replies also appear in Notifications.' => 'Buksan muli ang Ask OSA para makita ang sagot sa iyong chat. Lalabas din ang mga bagong sagot sa Notifications.',
    'How do I track the status of my complaint?' => 'Paano ko masusubaybayan ang status ng aking reklamo?', 'Go to My Complaints.' => 'Pumunta sa My Complaints.',
    'How long does the OSA take to reply to a message?' => 'Gaano katagal sumagot ang OSA sa mensahe?',
    'How do I change my password?' => 'Paano ko papalitan ang aking password?',
    'We no longer use passwords. We now use OTP only. If you want to change your Gmail, go to Settings to update it.' => 'Hindi na kami gumagamit ng password. OTP na lang ang gamit. Kung gusto mong palitan ang iyong Gmail, pumunta sa Settings para i-update ito.',
    'What should I do if I cannot log in?' => 'Ano ang gagawin ko kung hindi ako makapag-log in?',
    'Go to the OSA office so they can check the possible problem or whether you entered the wrong Gmail.' => 'Pumunta sa OSA office para masuri nila ang posibleng problema o kung mali ang nailagay mong Gmail.',
    'Where do I get my clearance?' => 'Saan ako kukuha ng clearance?', 'Go to the cashier to get your clearance.' => 'Pumunta sa cashier para kumuha ng clearance.',
    'How do I replace a lost ID?' => 'Paano magpalit ng nawalang ID?', 'Go to the MIS office to replace your ID.' => 'Pumunta sa MIS office para palitan ang iyong ID.',
    'How do I vote in the election?' => 'Paano ako boboto sa eleksyon?',
    'Go to Vote Now. Get a voucher from the OSA office or the watcher to enter the election.' => 'Pumunta sa Vote Now. Kumuha ng voucher sa OSA office o sa watcher para makapasok sa eleksyon.',
    'Who can run in the election?' => 'Sino ang puwedeng tumakbo sa eleksyon?', 'Anyone, as long as they are from the colleges.' => 'Sinuman, basta galing sa mga kolehiyo.',
    'How do I get a voting voucher?' => 'Paano ako makakakuha ng voting voucher?', 'From the OSA office or the watcher.' => 'Sa OSA office o sa watcher.',
    'Where can I see the OSA event schedule?' => 'Saan ko makikita ang iskedyul ng mga event ng OSA?',
    'It is in the Events module on the side. On desktop, it is in the sidebar. On a phone, tap the three lines at the top left.' => 'Nasa Events module ito sa gilid. Sa desktop, nasa sidebar. Sa phone, pindutin ang tatlong guhit sa kaliwang-itaas.',
    'Show all (%d)' => 'Ipakita lahat (%d)', 'Show less' => 'Ipakita nang kaunti',
];

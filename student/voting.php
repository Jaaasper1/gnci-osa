<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/csrf.php';
requireStudent();

// Any state-changing POST on this page (voucher submit or vote submit)
// must carry a valid CSRF token tied to this session.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
}

$student_id = $_SESSION['user_id'];

// Get student's course
$student_course_stmt = $pdo->prepare("SELECT course FROM students WHERE id = ?");
$student_course_stmt->execute([$student_id]);
$student_course = $student_course_stmt->fetchColumn();

$election    = getActiveElection($pdo, $student_course);
$notifCount  = getUnreadNotifCount($pdo, $student_id);

// No election currently open for voting — show a "Not Available" state
// instead of silently bouncing back to Events.
if (!$election) {
    $anyElection = getAnyActiveOrRecentElection($pdo, $student_course);
    $naTitle   = 'No Election Right Now';
    $naMessage = 'There is no ongoing election at the moment. Please check back later.';

    if ($anyElection) {
        $now = time();
        if ($now < strtotime($anyElection['start_date'])) {
            $naTitle   = 'Voting Not Yet Open';
            $naMessage = 'Voting for "' . htmlspecialchars($anyElection['title']) . '" hasn\'t opened yet. It opens on '
                       . date('M j, Y \a\t g:i A', strtotime($anyElection['start_date'])) . '.';
        } elseif ($now > strtotime($anyElection['end_date'])) {
            $naTitle   = 'Voting Has Closed';
            $naMessage = 'Voting for "' . htmlspecialchars($anyElection['title']) . '" has already closed'
                       . (!empty($anyElection['show_results']) ? '. You can view the results from the Events page.' : '.');
        }
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voting — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=23">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
</head>
<body class="theme-election">
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Portal</div><div class="sub">Voting Portal</div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><i class="ti ti-lock" style="color:rgba(255,255,255,0.6);font-size:18px;"></i><span style="font-size:12px;color:rgba(255,255,255,0.6);"><?= t('voting.secure', 'Secure voting') ?></span><div class="avatar"><?= strtoupper(substr($_SESSION['name'],0,2)) ?></div></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> Events</a>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?></a>
    <a class="sitem" href="new-complaint.php"><i class="ti ti-circle-plus"></i> <?= t('sidebar.new_complaint', 'New Complaint') ?></a>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="settings.php"><i class="ti ti-settings"></i> <?= t('nav.settings', 'Settings') ?></a>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('nav.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">
    <div class="page-header"><h1><?= t('voting.title', 'Student Council Election') ?></h1></div>
    <div class="card" style="text-align:center;padding:40px;">
        <i class="ti ti-calendar-off" style="font-size:52px;color:#9CA3AF;"></i>
        <h2 style="margin:12px 0 6px;color:#374151;"><?= htmlspecialchars($naTitle) ?></h2>
        <p style="color:#9CA3AF;max-width:420px;margin:0 auto;"><?= $naMessage ?></p>
        <a href="events.php" class="btn btn-outline" style="margin-top:16px;"><i class="ti ti-home"></i> Back to Events</a>
    </div>
</div></div>
<script src="../assets/js/sidebar.js?v=4"></script>
</body>
</html>
<?php
    exit;
}

// On hold — voting is temporarily paused by OSA (e.g. class suspension) without closing the election.
if (!empty($election['is_hold'])) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voting — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=23">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
</head>
<body class="theme-election">
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Portal</div><div class="sub">Voting Portal</div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><i class="ti ti-lock" style="color:rgba(255,255,255,0.6);font-size:18px;"></i><span style="font-size:12px;color:rgba(255,255,255,0.6);"><?= t('voting.secure', 'Secure voting') ?></span><div class="avatar"><?= strtoupper(substr($_SESSION['name'],0,2)) ?></div></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> Events</a>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?></a>
    <a class="sitem" href="new-complaint.php"><i class="ti ti-circle-plus"></i> <?= t('sidebar.new_complaint', 'New Complaint') ?></a>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="settings.php"><i class="ti ti-settings"></i> <?= t('nav.settings', 'Settings') ?></a>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('nav.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">
    <div class="page-header"><h1><?= t('voting.title', 'Student Council Election') ?></h1></div>
    <div class="card" style="text-align:center;padding:40px;">
        <i class="ti ti-player-pause" style="font-size:52px;color:#F59E0B;"></i>
        <h2 style="margin:12px 0 6px;color:#374151;">Voting Temporarily Paused</h2>
        <p style="color:#9CA3AF;max-width:420px;margin:0 auto;">
            Voting for "<?= htmlspecialchars($election['title']) ?>" has been paused by OSA
            (for example, due to a class suspension). Your progress and the election schedule are safe —
            please check back once voting resumes.
        </p>
        <a href="events.php" class="btn btn-outline" style="margin-top:16px;"><i class="ti ti-home"></i> Back to Events</a>
    </div>
</div></div>
<script src="../assets/js/sidebar.js?v=4"></script>
</body>
</html>
<?php
    exit;
}

$election_id = $election['id'];

// Always re-check from DB — this is the single source of truth
$alreadyVoted = hasVotedAll($pdo, $student_id, $election_id);

// ── Voucher check ─────────────────────────────────────────────────────────────
// Has the student already validated their voucher this session?
$voucherVerified = isset($_SESSION['voucher_verified'][$election_id]) && $_SESSION['voucher_verified'][$election_id] === true;
$voucherError = '';

// POST: voucher submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['voucher_code']) && !$alreadyVoted) {
    $inputCode = strtoupper(trim($_POST['voucher_code']));
    $vStmt = $pdo->prepare("SELECT * FROM voting_vouchers WHERE code=? AND election_id=? AND student_id=? AND is_used=0");
    $vStmt->execute([$inputCode, $election_id, $student_id]);
    $voucher = $vStmt->fetch();
    if ($voucher) {
        $_SESSION['voucher_verified'][$election_id] = true;
        $voucherVerified = true;
    } else {
        $voucherError = 'Invalid or already used voucher code. Please check and try again.';
    }
}

// ─── POST: submit votes ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['votes'])) {
    // Hard block — no matter what, if already voted, stop here
    if ($alreadyVoted) {
        header('Location: voting.php?voted=1'); exit;
    }

    $votes = $_POST['votes'] ?? [];
    $error = '';

    // Get positions that have at least one approved candidate
    $positionsWithCandidates = [];
    $posRows = $pdo->prepare("SELECT * FROM positions WHERE election_id = ? ORDER BY sort_order");
    $posRows->execute([$election_id]);
    foreach ($posRows->fetchAll() as $pos) {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM candidates WHERE position_id = ? AND is_approved = 1");
        $cnt->execute([$pos['id']]);
        if ($cnt->fetchColumn() > 0) $positionsWithCandidates[] = $pos['id'];
    }

    // BLOCK: no approved candidates yet — nothing to vote on, redirect to dashboard
    if (empty($positionsWithCandidates)) {
        header('Location: events.php'); exit;
    }

    // Require a selection (or abstain) for every position with candidates
    $missingPositions = [];
    foreach ($positionsWithCandidates as $posId) {
        if (!array_key_exists($posId, $votes)) $missingPositions[] = $posId;
    }

    if (!empty($missingPositions)) {
        $error = 'Please select a candidate or choose Abstain for each position.';
    } else {
        $valid = true;
        $validatedVotes = [];

        foreach ($positionsWithCandidates as $position_id) {
            $position_id  = (int)$position_id;
            $candidate_id = $votes[$position_id] ?? '';

            if ($candidate_id === '' || $candidate_id === 'abstain') {
                $validatedVotes[$position_id] = ['candidate_id' => null, 'is_abstain' => 1];
            } else {
                $candidate_id = (int)$candidate_id;
                // Validate candidate belongs to this position + election
                $check = $pdo->prepare("SELECT id FROM candidates WHERE id = ? AND position_id = ? AND election_id = ? AND is_approved = 1");
                $check->execute([$candidate_id, $position_id, $election_id]);
                if (!$check->fetch()) {
                    $error = 'Invalid candidate selection. Please try again.';
                    $valid = false;
                    break;
                }
                $validatedVotes[$position_id] = ['candidate_id' => $candidate_id, 'is_abstain' => 0];
            }
        }

        if ($valid) {
            // Final re-check inside transaction — race condition protection
            $pdo->beginTransaction();
            try {
                // Lock check: re-read voted count inside transaction
                $lockCheck = $pdo->prepare("SELECT COUNT(*) as cnt FROM votes WHERE student_id = ? AND election_id = ? FOR UPDATE");
                $lockCheck->execute([$student_id, $election_id]);
                if ((int)$lockCheck->fetch()['cnt'] > 0) {
                    $pdo->rollBack();
                    header('Location: voting.php?voted=1'); exit;
                }

                // Insert votes — UNIQUE KEY in DB prevents duplicates as final safety net
                $rowsInserted = 0;
                foreach ($validatedVotes as $position_id => $voteData) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO votes (election_id, position_id, student_id, candidate_id, is_abstain) VALUES (?,?,?,?,?)");
                    $stmt->execute([$election_id, $position_id, $student_id, $voteData['candidate_id'], $voteData['is_abstain']]);
                    $rowsInserted += $stmt->rowCount();
                }
                $pdo->commit();

                // Only notify and redirect if rows were actually written
                // (rowCount = 0 means INSERT IGNORE silently blocked a duplicate)
                if ($rowsInserted === 0) {
                    header('Location: voting.php?voted=1'); exit;
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'A server error occurred. Please try again.';
                $valid = false;
            }
            if ($valid) {
                // Add notification ONCE — only after rows were truly inserted
                addNotification($pdo, $student_id, 'Your vote has been recorded in ' . $election['title'] . '. Thank you for participating!', 'voting.php');
                // Mark voucher as used
                $pdo->prepare("UPDATE voting_vouchers SET is_used=1, used_at=NOW() WHERE election_id=? AND student_id=? AND is_used=0")
                    ->execute([$election_id, $student_id]);
                // Clear session voucher flag
                unset($_SESSION['voucher_verified'][$election_id]);
                // PRG — redirect prevents form resubmission on refresh
                header('Location: voting.php?voted=1'); exit;
            }
        }
    }
}

// ─── GET: show page ───────────────────────────────────────────────────────────
// Re-check after POST (in case they got here without redirect)
$alreadyVoted = hasVotedAll($pdo, $student_id, $election_id);

$positions = $pdo->prepare("SELECT * FROM positions WHERE election_id = ? ORDER BY sort_order");
$positions->execute([$election_id]);
$positions = $positions->fetchAll();

$success = isset($_GET['voted']) ? 'Your vote has been recorded! Thank you for participating.' : '';
$error   = $error ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voting — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=23">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
</head>
<body class="theme-election">
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Portal</div><div class="sub">Voting Portal</div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><i class="ti ti-lock" style="color:rgba(255,255,255,0.6);font-size:18px;"></i><span style="font-size:12px;color:rgba(255,255,255,0.6);"><?= t('voting.secure', 'Secure voting') ?></span><div class="avatar"><?= strtoupper(substr($_SESSION['name'],0,2)) ?></div></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> Events</a>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?></a>
    <a class="sitem" href="new-complaint.php"><i class="ti ti-circle-plus"></i> <?= t('sidebar.new_complaint', 'New Complaint') ?></a>
    <?php if ($election): ?>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem active" href="voting.php"><i class="ti ti-checkbox"></i> <?= t('sidebar.vote_now', 'Vote Now') ?></a>
    <?php endif; ?><div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="settings.php"><i class="ti ti-settings"></i> <?= t('nav.settings', 'Settings') ?></a>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('nav.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">
    <div class="page-header"><h1><?= t('voting.title', 'Student Council Election') ?></h1><p><?= htmlspecialchars($election['title']) ?></p></div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><i class="ti ti-alert-circle"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($alreadyVoted): ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="ti ti-check"></i><?= $success ?></div>
        <?php endif; ?>
        <div class="card vote-success-card" style="text-align:center;padding:40px;">
            <i class="ti ti-circle-check" style="font-size:52px;color:#059669;"></i>
            <h2 style="margin:12px 0 6px;color:#065F46;">You have already voted!</h2>
            <p style="color:#9CA3AF;">Your vote has been recorded for <?= htmlspecialchars($election['title']) ?>.</p>
            <?php if ($election['show_results']): ?>
                <a href="results.php?id=<?= $election_id ?>" class="btn btn-primary" style="margin-top:16px;">View Live Results</a>
            <?php endif; ?>
            <a href="events.php" class="btn btn-outline" style="margin-top:16px;margin-left:8px;"><i class="ti ti-home"></i> Back to Events</a>
        </div>

    <?php else: ?>

    <?php if (!$voucherVerified): ?>
    <!-- Voucher gate -->
    <div class="card" style="max-width:420px;margin:0 auto;text-align:center;padding:36px 32px;">
        <div style="width:56px;height:56px;border-radius:50%;background:var(--maroon-pale);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="ti ti-ticket" style="font-size:28px;color:var(--maroon);"></i>
        </div>
        <h2 style="font-size:17px;font-weight:600;color:var(--maroon-dark);margin-bottom:6px;">Enter Your Voting Voucher</h2>
        <p style="font-size:13px;color:#6B7280;margin-bottom:20px;line-height:1.5;">You need a one-time voucher code to access the ballot. Get your code from the OSA or your election officer.</p>
        <?php if ($voucherError): ?>
            <div class="alert alert-error" style="text-align:left;"><i class="ti ti-alert-circle"></i><?= e($voucherError) ?></div>
        <?php endif; ?>
        <form method="POST" autocomplete="off">
            <?= csrfField() ?>
            <input type="text" name="voucher_code" class="form-control"
                   placeholder="XXXX-XXXX-XXXX"
                   style="text-align:center;font-size:18px;font-family:'Courier New',monospace;font-weight:700;letter-spacing:2px;margin-bottom:14px;text-transform:uppercase;"
                   maxlength="14" required autofocus
                   oninput="formatVoucher(this)">
            <button type="submit" class="btn btn-primary btn-block"><i class="ti ti-lock-open"></i> Unlock Ballot</button>
        </form>
    </div>

    <?php else: ?>
    <?php
        // Build party-list ticket data: for each party, map position_id => candidate_id
        // (only for positions where that party actually has an approved candidate)
        $partyStmt = $pdo->prepare("SELECT pl.id, pl.name, pl.color, pl.logo FROM party_lists pl WHERE pl.election_id = ? ORDER BY pl.name");
        $partyStmt->execute([$election_id]);
        $allParties = $partyStmt->fetchAll();

        $ticketStmt = $pdo->prepare("SELECT c.party_list_id, c.position_id, c.id as candidate_id FROM candidates c WHERE c.election_id = ? AND c.is_approved = 1 AND c.party_list_id IS NOT NULL");
        $ticketStmt->execute([$election_id]);
        $partyTickets = [];
        foreach ($ticketStmt->fetchAll() as $row) {
            $partyTickets[$row['party_list_id']][$row['position_id']] = $row['candidate_id'];
        }
        // Only keep parties that have at least one candidate running in this election
        $partiesWithCandidates = array_filter($allParties, function($p) use ($partyTickets) {
            return !empty($partyTickets[$p['id']]);
        });
    ?>
    <div class="alert alert-info"><i class="ti ti-info-circle"></i> Select <strong>one candidate</strong> for each position, or click <strong>Abstain</strong> if you wish to skip a position. This cannot be changed after submitting.</div>

    <?php if (!empty($partiesWithCandidates)): ?>
    <div class="card" style="margin-bottom:18px;">
        <div class="card-title" style="margin-bottom:4px;"><i class="ti ti-flag"></i> Vote a Whole Party Ticket</div>
        <p style="font-size:12px;color:#6B7280;margin-bottom:14px;">Click a party to instantly select all of its candidates. You can still change individual picks afterwards, and positions the party isn't running in will need to be selected manually.</p>
        <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <?php foreach ($partiesWithCandidates as $pl): ?>
            <button type="button" class="party-ticket-btn" id="party-btn-<?= $pl['id'] ?>" onclick="voteTicket(<?= $pl['id'] ?>)"
                    style="display:flex;align-items:center;gap:8px;border:2px solid <?= htmlspecialchars($pl['color'] ?: '#6B1A2A') ?>;background:#fff;border-radius:10px;padding:8px 14px;cursor:pointer;font-size:13px;font-weight:600;color:<?= htmlspecialchars($pl['color'] ?: '#6B1A2A') ?>;">
                <?php if ($pl['logo']): ?>
                    <img src="../uploads/<?= htmlspecialchars($pl['logo']) ?>" style="width:22px;height:22px;border-radius:50%;object-fit:cover;">
                <?php else: ?>
                    <i class="ti ti-flag"></i>
                <?php endif; ?>
                <?= htmlspecialchars($pl['name']) ?>
                <span style="font-size:10px;font-weight:400;color:#9CA3AF;">(<?= count($partyTickets[$pl['id']]) ?> position<?= count($partyTickets[$pl['id']]) > 1 ? 's' : '' ?>)</span>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" class="confirm-submit-form"
          data-confirm-title="Submit your vote?"
          data-confirm-message="This action cannot be undone. Once submitted, you will not be able to change or resubmit your vote."
          data-confirm-btn="Yes, Submit Vote"
          data-confirm-danger="1"
          data-confirm-validate="voteFormIsComplete"
          data-loading-overlay="Recording your vote...">
        <?= csrfField() ?>
        <?php foreach ($positions as $pos): ?>
        <?php
            $cands = $pdo->prepare("SELECT c.*, s.first_name, s.last_name, s.course, pl.name as party_name, pl.color as party_color, pl.logo as party_logo FROM candidates c JOIN students s ON c.student_id = s.id LEFT JOIN party_lists pl ON c.party_list_id = pl.id WHERE c.position_id = ? AND c.is_approved = 1");
            $cands->execute([$pos['id']]);
            $candidates = $cands->fetchAll();
        ?>
        <div class="vote-card">
            <div class="vote-position-label"><i class="ti ti-crown"></i><?= htmlspecialchars($pos['title']) ?></div>
            <div class="cand-grid">
            <?php foreach ($candidates as $cand): ?>
            <div class="cand-tile" id="tile-<?= $pos['id'] ?>-<?= $cand['id'] ?>">
                <div class="cand-photo">
                    <?php if ($cand['photo']): ?>
                        <img src="../uploads/<?= htmlspecialchars($cand['photo']) ?>" alt="">
                    <?php else: ?>
                        <?= strtoupper(substr($cand['first_name'],0,1) . substr($cand['last_name'],0,1)) ?>
                    <?php endif; ?>
                </div>
                <div class="cname"><?= htmlspecialchars($cand['first_name'] . ' ' . $cand['last_name']) ?></div>
                <?php if (!empty($cand['party_name'])): ?>
                <div class="cparty" style="font-size:11px;font-weight:700;color:<?= htmlspecialchars($cand['party_color'] ?: '#6B1A2A') ?>;margin:1px 0 2px;">
                    <i class="ti ti-flag" style="margin-right:3px;"></i><?= htmlspecialchars($cand['party_name']) ?>
                </div>
                <?php else: ?>
                <div class="cparty" style="font-size:11px;color:#9CA3AF;margin:1px 0 2px;">Independent</div>
                <?php endif; ?>
                <div class="cpos"><?= htmlspecialchars($cand['course']) ?></div>
                <?php if (!empty($cand['credentials'])): ?>
                <div class="ccred" style="font-size:11px;color:#C9943A;font-weight:600;margin:2px 0 4px;line-height:1.3;"><?= nl2br(htmlspecialchars($cand['credentials'])) ?></div>
                <?php endif; ?>
                <button type="button" class="vote-select-btn"
                        data-position="<?= $pos['id'] ?>"
                        data-candidate="<?= $cand['id'] ?>"
                        onclick="unhighlightAllPartyButtons(); activeTicketParty=null; selectVote(this)">Vote</button>
            </div>
            <?php endforeach; ?>
            </div>
            <div class="abstain-row">
                <button type="button" class="abstain-btn" id="abstain-btn-<?= $pos['id'] ?>"
                        data-position="<?= $pos['id'] ?>"
                        onclick="unhighlightAllPartyButtons(); activeTicketParty=null; selectAbstain(this)">
                    <i class="ti ti-ban"></i> Abstain for this position
                </button>
            </div>
            <input type="hidden" name="votes[<?= $pos['id'] ?>]" id="vote-input-<?= $pos['id'] ?>" value="">
        </div>
        <?php endforeach; ?>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <a href="events.php" class="btn btn-outline">Back</a>
            <button type="submit" class="btn btn-primary"><i class="ti ti-checkbox"></i> Submit Vote</button>
        </div>
    </form>
    <?php endif; // voucherVerified ?>
    <?php endif; // alreadyVoted ?>
</div></div>

<script>
function formatVoucher(input) {
    let v = input.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
    if (v.length > 4) v = v.slice(0,4) + '-' + v.slice(4);
    if (v.length > 9) v = v.slice(0,9) + '-' + v.slice(9);
    input.value = v.slice(0, 14);
}

// Party ticket data: { party_id: { position_id: candidate_id, ... }, ... }
var partyTickets = <?= json_encode(array_map(function($t) { return $t; }, $partyTickets ?? [])) ?>;
var activeTicketParty = null;

function voteTicket(partyId) {
    var ticket = partyTickets[partyId];
    if (!ticket) return;

    // Clicking the already-active party again = deselect the whole ticket
    if (activeTicketParty === partyId) {
        Object.keys(ticket).forEach(function(posId) {
            var candId = ticket[posId];
            var btn = document.querySelector('.vote-select-btn[data-position="' + posId + '"][data-candidate="' + candId + '"]');
            if (btn && btn.classList.contains('selected')) selectVote(btn);
        });
        unhighlightAllPartyButtons();
        activeTicketParty = null;
        return;
    }

    // Switching from a different party's ticket — clear that party's picks first,
    // including positions the new party isn't even running a candidate for.
    if (activeTicketParty !== null && partyTickets[activeTicketParty]) {
        var oldTicket = partyTickets[activeTicketParty];
        Object.keys(oldTicket).forEach(function(posId) {
            var oldCandId = oldTicket[posId];
            var oldBtn = document.querySelector('.vote-select-btn[data-position="' + posId + '"][data-candidate="' + oldCandId + '"]');
            if (oldBtn && oldBtn.classList.contains('selected')) selectVote(oldBtn);
        });
    }

    // Highlight the chosen party button, un-highlight the rest
    unhighlightAllPartyButtons();
    var activeBtn = document.getElementById('party-btn-' + partyId);
    if (activeBtn) {
        if (!activeBtn.dataset.origColor) activeBtn.dataset.origColor = activeBtn.style.color;
        activeBtn.style.background = activeBtn.dataset.origColor || '#6B1A2A';
        activeBtn.style.color = '#fff';
        activeBtn.classList.add('active-ticket');
    }
    activeTicketParty = partyId;

    // For every position in the ticket, select that party's candidate
    Object.keys(ticket).forEach(function(posId) {
        var candId = ticket[posId];
        var btn = document.querySelector('.vote-select-btn[data-position="' + posId + '"][data-candidate="' + candId + '"]');
        if (btn) selectVote(btn, true);
    });
}

function unhighlightAllPartyButtons() {
    document.querySelectorAll('.party-ticket-btn').forEach(function(b) {
        b.style.background = '#fff';
        b.style.color = b.dataset.origColor || b.style.color;
        b.classList.remove('active-ticket');
    });
}

function selectVote(btn, forceSelect) {
    var posId = btn.dataset.position;
    var alreadySelected = btn.classList.contains('selected');
    document.querySelectorAll('[data-position="' + posId + '"].vote-select-btn').forEach(function(b) {
        b.classList.remove('selected');
        b.textContent = 'Vote';
        b.closest('.cand-tile').classList.remove('selected');
    });
    var abstainBtn = document.getElementById('abstain-btn-' + posId);
    if (abstainBtn) abstainBtn.classList.remove('selected');
    if (alreadySelected && !forceSelect) {
        document.getElementById('vote-input-' + posId).value = '';
    } else {
        btn.classList.add('selected');
        btn.textContent = '\u2713 Selected';
        btn.closest('.cand-tile').classList.add('selected');
        document.getElementById('vote-input-' + posId).value = btn.dataset.candidate;
    }
}

function selectAbstain(btn) {
    var posId = btn.dataset.position;
    var alreadyAbstained = btn.classList.contains('selected');
    document.querySelectorAll('[data-position="' + posId + '"].vote-select-btn').forEach(function(b) {
        b.classList.remove('selected');
        b.textContent = 'Vote';
        b.closest('.cand-tile').classList.remove('selected');
    });
    if (alreadyAbstained) {
        btn.classList.remove('selected');
        btn.innerHTML = '<i class="ti ti-ban"></i> Abstain for this position';
        document.getElementById('vote-input-' + posId).value = '';
    } else {
        btn.classList.add('selected');
        btn.innerHTML = '<i class="ti ti-ban"></i> \u2713 Abstaining';
        document.getElementById('vote-input-' + posId).value = 'abstain';
    }
}

// Client-side validation before submit
function voteFormIsComplete() {
    var inputs = document.querySelectorAll('input[name^="votes["]');
    var allFilled = true;
    inputs.forEach(function(input) {
        var card = input.closest('.vote-card');
        if (card && card.querySelectorAll('.vote-select-btn').length > 0) {
            if (!input.value) allFilled = false;
        }
    });
    if (!allFilled) {
        var existing = document.querySelector('.alert-error');
        if (existing) existing.remove();
        var div = document.createElement('div');
        div.className = 'alert alert-error';
        div.innerHTML = '<i class="ti ti-alert-circle"></i> Please select a candidate or choose Abstain for each position.';
        document.querySelector('form').insertAdjacentElement('beforebegin', div);
        window.scrollTo({top: 0, behavior: 'smooth'});
    }
    return { valid: allFilled };
}
</script>
<script src="../assets/js/sidebar.js?v=4"></script>
</body>
</html>
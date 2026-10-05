<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireAdmin();
$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);

$success = $error = '';
$startTab = '';   // which tab opens first (active / closed / completed)

// Toggle active
if (isset($_GET['toggle'])) {
    $eid = (int)$_GET['toggle'];
    $val = (int)$_GET['val'];
    // Get this election's course so we don't close elections from other courses
    $thisCourse = $pdo->prepare("SELECT course, is_completed FROM elections WHERE id=?");
    $thisCourse->execute([$eid]);
    $thisRow = $thisCourse->fetch();
    $course = $thisRow ? $thisRow['course'] : '';
    if ($thisRow && $thisRow['is_completed'] && $val === 1) {
        header('Location: elections.php?error='.urlencode('This election is already Completed. Use "Reopen" first if you really need to open it again.')); exit;
    }
    // Only one active election per course at a time (and only one
    // "All Courses" / Supreme Council election active at a time)
    if ($val === 1) {
        if ($course) {
            $pdo->prepare("UPDATE elections SET is_active=0 WHERE course=? AND id!=?")->execute([$course, $eid]);
        } else {
            $pdo->prepare("UPDATE elections SET is_active=0 WHERE (course='' OR course IS NULL) AND id!=?")->execute([$eid]);
        }
    }
    $pdo->prepare("UPDATE elections SET is_active=? WHERE id=?")->execute([$val, $eid]);
    if (!$val) {
        // Closing an election also clears any hold, so it doesn't reopen "paused" later.
        $pdo->prepare("UPDATE elections SET is_hold=0 WHERE id=?")->execute([$eid]);
    }
    $success = $val ? 'Election opened!' : 'Election closed.';
    $startTab = $val ? 'active' : 'closed';
}

// Mark election as COMPLETED (final: voting done, results finalized).
// Only a Closed election can be completed - close it first.
if (isset($_GET['complete'])) {
    $eid = (int)$_GET['complete'];
    $chk = $pdo->prepare("SELECT is_active FROM elections WHERE id=?");
    $chk->execute([$eid]);
    $row = $chk->fetch();
    if (!$row) {
        $error = 'Election not found.';
    } elseif ($row['is_active']) {
        $error = 'Close the election first before marking it as Completed.';
    } else {
        $pdo->prepare("UPDATE elections SET is_completed=1, is_active=0, is_hold=0 WHERE id=?")->execute([$eid]);
        $success = 'Election marked as Completed. You can find it in the Completed tab.';
        $startTab = 'completed';
    }
}

// Reopen a completed election (moves it back to Closed)
if (isset($_GET['uncomplete'])) {
    $eid = (int)$_GET['uncomplete'];
    $pdo->prepare("UPDATE elections SET is_completed=0 WHERE id=?")->execute([$eid]);
    $success = 'Election moved back to Closed.';
    $startTab = 'closed';
}

// Toggle hold (pause voting without closing the election)
if (isset($_GET['toggle_hold'])) {
    $eid = (int)$_GET['toggle_hold'];
    $val = (int)$_GET['val'];
    $pdo->prepare("UPDATE elections SET is_hold=? WHERE id=?")->execute([$val, $eid]);
    $success = $val ? 'Election put on hold. Students will see a "voting paused" notice until you resume it.' : 'Election resumed — voting is open again.';
}

// Toggle show results
if (isset($_GET['toggle_results'])) {
    $eid = (int)$_GET['toggle_results'];
    $val = (int)$_GET['val'];
    $pdo->prepare("UPDATE elections SET show_results=? WHERE id=?")->execute([$val, $eid]);
    $success = 'Results visibility updated.';
}

// Delete entire election (only if no votes cast)
if (isset($_GET['del_election'])) {
    $eid = (int)$_GET['del_election'];
    $hasVotes = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE election_id=?");
    $hasVotes->execute([$eid]);
    if ($hasVotes->fetchColumn() > 0) {
        $error = 'Cannot delete — votes have already been cast in this election.';
    } else {
        // CASCADE will handle positions and candidates
        $pdo->prepare("DELETE FROM elections WHERE id=?")->execute([$eid]);
        $success = 'Election deleted.';
    }
    header('Location: elections.php?success='.urlencode($success).'&error='.urlencode($error)); exit;
}

// Delete position (only if no votes yet)
if (isset($_GET['del_pos'])) {
    $pid = (int)$_GET['del_pos'];
    $eid_back = (int)$_GET['eid'];
    $hasVotes = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE position_id=?");
    $hasVotes->execute([$pid]);
    if ($hasVotes->fetchColumn() > 0) {
        $error = 'Cannot delete position — votes have already been cast.';
    } else {
        $pdo->prepare("DELETE FROM candidates WHERE position_id=?")->execute([$pid]);
        $pdo->prepare("DELETE FROM positions WHERE id=?")->execute([$pid]);
        $success = 'Position deleted.';
    }
    header('Location: elections.php?edit='.$eid_back.'&success='.urlencode($success).'&error='.urlencode($error)); exit;
}

// CREATE new election
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $title     = trim($_POST['title'] ?? '');
    $desc      = trim($_POST['description'] ?? '');
    $start     = $_POST['start_date'] ?? '';
    $end       = $_POST['end_date'] ?? '';
    $positions = array_filter(array_map('trim', explode("\n", $_POST['positions'] ?? '')));
    $course    = $_POST['course'] ?? '';
    $course    = ($course === '__ALL__') ? '' : trim($course);
    if (!$title || !$start || !$end || !isset($_POST['course']) || $_POST['course'] === '' || empty($positions)) {
        $error = 'Please fill in all fields including course.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO elections (title, description, course, start_date, end_date, created_by) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$title, $desc, $course, $start, $end, $_SESSION['user_id']]);
        $new_eid = $pdo->lastInsertId();
        foreach (array_values($positions) as $i => $pos) {
            $pdo->prepare("INSERT INTO positions (election_id, title, sort_order) VALUES (?,?,?)")->execute([$new_eid, $pos, $i]);
        }
        header('Location: elections.php?success=' . urlencode('New election created!')); exit;
    }
}

// EDIT election (title, dates, description)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $eid   = (int)$_POST['eid'];
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $start = $_POST['start_date'] ?? '';
    $end   = $_POST['end_date'] ?? '';
    if (!$title || !$start || !$end) {
        $error = 'Please fill in all fields.';
    } else {
        $course_edit = $_POST['course'] ?? '';
        $course_edit = ($course_edit === '__ALL__') ? '' : trim($course_edit);
        $pdo->prepare("UPDATE elections SET title=?, description=?, course=?, start_date=?, end_date=? WHERE id=?")
            ->execute([$title, $desc, $course_edit, $start, $end, $eid]);
        $success = 'Election updated!';
    }
    header('Location: elections.php?edit='.$eid.'&success='.urlencode($success).'&error='.urlencode($error)); exit;
}

// ADD positions to existing election
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_positions') {
    $eid       = (int)$_POST['eid'];
    $positions = array_filter(array_map('trim', explode("\n", $_POST['new_positions'] ?? '')));
    if (empty($positions)) {
        $error = 'Please add at least one position.';
    } else {
        // get current max sort_order
        $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM positions WHERE election_id=?");
        $maxOrder->execute([$eid]);
        $start_order = (int)$maxOrder->fetchColumn() + 1;
        foreach (array_values($positions) as $i => $pos) {
            $pdo->prepare("INSERT INTO positions (election_id, title, sort_order) VALUES (?,?,?)")->execute([$eid, $pos, $start_order + $i]);
        }
        $count = count($positions);
        $success = $count . ' position' . ($count > 1 ? 's' : '') . ' added successfully!';
    }
    header('Location: elections.php?edit='.$eid.'&success='.urlencode($success).'&error='.urlencode($error)); exit;
}

// Redirect messages from actions above
if (isset($_GET['success']) && $_GET['success']) $success = $_GET['success'];
if (isset($_GET['error'])   && $_GET['error'])   $error   = $_GET['error'];

// Load edit target if set
$editElection = null;
$editPositions = [];
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM elections WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editElection = $stmt->fetch();
    if ($editElection) {
        $stmt2 = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM votes WHERE position_id=p.id) as vote_count FROM positions p WHERE election_id=? ORDER BY sort_order");
        $stmt2->execute([$editElection['id']]);
        $editPositions = $stmt2->fetchAll();
    }
}

$elections = $pdo->query("SELECT e.*, (SELECT COUNT(*) FROM positions WHERE election_id=e.id) as pos_count, (SELECT COUNT(DISTINCT student_id) FROM votes WHERE election_id=e.id) as vote_count FROM elections e ORDER BY e.course, e.created_at DESC")->fetchAll();
$activeElections = $closedElections = $completedElections = [];
foreach ($elections as $_el) {
    if (!empty($_el['is_completed']))  $completedElections[] = $_el;
    elseif ($_el['is_active'])         $activeElections[]    = $_el;
    else                               $closedElections[]    = $_el;
}
if ($startTab === '') $startTab = !empty($activeElections) ? 'active' : (!empty($closedElections) ? 'closed' : (!empty($completedElections) ? 'completed' : 'active'));
// Turnout for completed elections: voters / currently active students of that course
foreach ($completedElections as &$_ce) {
    if ($_ce['course'] !== '') {
        $q = $pdo->prepare("SELECT COUNT(*) FROM students WHERE is_active=1 AND course=?");
        $q->execute([$_ce['course']]);
    } else {
        $q = $pdo->query("SELECT COUNT(*) FROM students WHERE is_active=1");
    }
    $_ce['eligible'] = (int)$q->fetchColumn();
}
unset($_ce);
$electionTabs = [
    'active'    => ['Active',    'ti-player-play',  $activeElections,    'No active elections right now.'],
    'closed'    => ['Closed',    'ti-lock',         $closedElections,    'No closed elections.'],
    'completed' => ['Completed', 'ti-circle-check', $completedElections, 'No completed elections yet. Close an election, then click "Complete" once results are final.'],
];
$allCourses = getCourseOptions();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Elections — GNC-OSA Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=23">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
</head>
<body class="theme-election">
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Admin</div><div class="sub">Administrator</div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><div class="avatar" style="background:#7C3AED;color:#fff;">AD</div><span class="uname"><?= e($_SESSION['name']) ?></span></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section">Overview</div>
    <a class="sitem" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <div class="sidebar-section">Complaints</div>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> All Complaints</a>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem active" href="elections.php"><i class="ti ti-trophy"></i> Elections</a>
    <a class="sitem" href="candidates.php"><i class="ti ti-users"></i> Candidates</a>
    <a class="sitem" href="party-lists.php"><i class="ti ti-flag"></i> Party Lists</a>
    <a class="sitem" href="vouchers.php"><i class="ti ti-ticket"></i> <?= t('sidebar.vouchers', 'Vouchers') ?></a>
    <a class="sitem" href="results.php"><i class="ti ti-chart-pie"></i> Live Results</a>
    <div class="sidebar-section">Events</div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> Events</a>
    <div class="sidebar-section">System</div>
    <a class="sitem" href="students.php"><i class="ti ti-user-plus"></i> Students</a>
    <a class="sitem" href="staff.php"><i class="ti ti-shield-check"></i> OSA Staff</a>
    <a class="sitem" href="reports.php"><i class="ti ti-report-analytics"></i> Reports</a>
    <div class="sidebar-section">Account</div>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> Notifications
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">
    <div class="page-header">
        <h1><?= $editElection ? t('admin.elections.edit', 'Edit Election') : t('admin.elections.manage', 'Manage Elections') ?></h1>
        <p><?= $editElection ? e($editElection['title']) : 'Create and manage elections' ?></p>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><i class="ti ti-check"></i><?= e($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><i class="ti ti-alert-circle"></i><?= e($error) ?></div><?php endif; ?>

    <?php if ($editElection): ?>
    <!-- ====== EDIT MODE ====== -->
    <div style="margin-bottom:14px;">
        <a href="elections.php" class="btn btn-outline btn-sm"><i class="ti ti-arrow-left"></i> Back to all elections</a>
    </div>

    <div class="form-grid-2">
        <!-- Edit title/dates -->
        <div>
            <div class="card">
                <div class="card-title"><i class="ti ti-edit"></i> Edit Details</div>
                <form method="POST" id="edit-election-form">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="eid" value="<?= $editElection['id'] ?>">
                    <div class="form-group course-search-wrap">
                        <label>Course *</label>
                        <select name="course" class="form-control" id="ee-course-select" required>
                            <option value="__ALL__" <?= $editElection['course']===''?'selected':'' ?>>🏫 All Courses (Supreme Council)</option>
                            <?php foreach($allCourses as $c): ?><option value="<?= e($c) ?>" <?= $editElection['course']==$c?'selected':'' ?>><?= e($c) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Title *</label>
                        <div class="field-icon-input">
                            <i class="ti ti-typography"></i>
                            <input type="text" name="title" class="form-control" value="<?= e($editElection['title']) ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" class="form-control"><?= e($editElection['description']) ?></textarea>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Start Date *</label>
                            <div class="field-icon-input">
                                <i class="ti ti-calendar"></i>
                                <input type="datetime-local" name="start_date" id="ee-start-date" class="form-control"
                                       value="<?= date('Y-m-d\TH:i', strtotime($editElection['start_date'])) ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>End Date *</label>
                            <div class="field-icon-input">
                                <i class="ti ti-calendar"></i>
                                <input type="datetime-local" name="end_date" id="ee-end-date" class="form-control"
                                       value="<?= date('Y-m-d\TH:i', strtotime($editElection['end_date'])) ?>" required>
                            </div>
                            <div class="field-error-text" id="ee-date-error">End date must be after the start date.</div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><i class="ti ti-device-floppy"></i> Save Changes</button>
                </form>
            </div>

            <!-- Add positions -->
            <div class="card" style="margin-top:16px;">
                <div class="card-title"><i class="ti ti-plus"></i> Add Positions</div>
                <form method="POST" id="add-positions-form">
                    <input type="hidden" name="action" value="add_positions">
                    <input type="hidden" name="eid" value="<?= $editElection['id'] ?>">
                    <div class="form-group">
                        <label>New Positions</label>
                        <input type="hidden" name="new_positions" id="ap-positions-hidden">
                        <div class="positions-builder" id="ap-positions-builder"></div>
                        <button type="button" class="pos-add-btn" onclick="posBuilderAddRow('ap-positions-builder','ap-positions-hidden','','ex. Auditor'); document.querySelector('#ap-positions-builder .pos-row:last-child .pos-input').focus();">
                            <i class="ti ti-plus"></i> Add Position
                        </button>
                        <small style="color:#9CA3AF;font-size:11px;display:block;margin-top:6px;">These will be added to the end of the positions list, in this order.</small>
                    </div>
                    <button type="submit" class="btn btn-gold btn-block"><i class="ti ti-plus"></i> Add Positions</button>
                </form>
            </div>
            <script>
                // Deferred to DOMContentLoaded because the shared helper functions
                // (posBuilderInit / attachDateOrderCheck) are
                // defined in a <script> block further down the page.
                document.addEventListener('DOMContentLoaded', function() {
                    posBuilderInit('ap-positions-builder', 'ap-positions-hidden', [''], ['ex. Auditor']);
                    attachDateOrderCheck('edit-election-form', 'ee-start-date', 'ee-end-date', 'ee-date-error');
                });
            </script>
        </div>

        <!-- Current positions -->
        <div>
            <div class="card">
                <div class="card-title"><i class="ti ti-list"></i> Current Positions (<?= count($editPositions) ?>)</div>
                <?php if (empty($editPositions)): ?>
                    <p style="color:#9CA3AF;font-size:13px;">No positions yet.</p>
                <?php else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><th>#</th><th>Position</th><th>Votes</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($editPositions as $i => $pos): ?>
                    <tr>
                        <td style="color:#9CA3AF;"><?= $i+1 ?></td>
                        <td style="font-weight:500;"><?= e($pos['title']) ?></td>
                        <td>
                            <?php if ($pos['vote_count'] > 0): ?>
                                <span class="badge badge-active"><?= $pos['vote_count'] ?> vote(s)</span>
                            <?php else: ?>
                                <span style="color:#9CA3AF;font-size:12px;">none</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($pos['vote_count'] == 0): ?>
                            <button type="button" class="btn btn-danger btn-sm"
                               onclick="confirmDeletePos(<?= $pos['id'] ?>, '<?= e($pos['title']) ?>', <?= $editElection['id'] ?>)">
                                <i class="ti ti-trash"></i>
                            </button>
                            <?php else: ?>
                            <span title="Cannot delete — votes have been cast" style="color:#D1D5DB;font-size:18px;"><i class="ti ti-lock"></i></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- ====== LIST MODE ====== -->
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="card-title" style="margin-bottom:0;"><i class="ti ti-trophy"></i> Elections <span style="background:#6B1A2A;color:#fff;border-radius:20px;padding:2px 10px;font-size:11px;margin-left:6px;"><?= count($elections) ?></span></div>
                <button onclick="openNewElectionModal()" class="btn btn-primary btn-sm" style="display:flex;align-items:center;gap:6px;white-space:nowrap;">
                    <i class="ti ti-circle-plus"></i> New Election
                </button>
            </div>
        </div>
        <?php if (empty($elections)): ?>
            <p style="color:#9CA3AF;font-size:13px;text-align:center;padding:24px 0;">No elections yet. Click "New Election" to create one.</p>
        <?php endif; ?>
        <div class="el-tabs">
            <?php foreach ($electionTabs as $tk => [$tLabel, $tIcon, $tList, $tEmpty]): ?>
            <button type="button" class="el-tab <?= $startTab === $tk ? 'active' : '' ?>" id="el-tab-btn-<?= $tk ?>" onclick="switchElTab('<?= $tk ?>')">
                <i class="ti <?= $tIcon ?>"></i> <?= $tLabel ?>
                <span class="el-tab-badge el-tab-badge-<?= $tk ?>"><?= count($tList) ?></span>
            </button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($electionTabs as $tk => [$tLabel, $tIcon, $tList, $tEmpty]): ?>
        <div class="el-tabpanel" id="el-tabpanel-<?= $tk ?>" data-pager="6" data-pager-sizes="6,12,24" data-pager-item=":scope > [data-search]" style="<?= $startTab === $tk ? '' : 'display:none;' ?>">
        <?php if ($tk === 'completed' && !empty($tList)): ?>
            <input type="text" id="el-completed-search" placeholder="Search completed elections (title or course)..." oninput="filterCompletedElections(this.value)"
                   style="width:100%;max-width:360px;padding:8px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;margin-bottom:12px;">
            <p id="el-completed-none" style="display:none;color:#9CA3AF;font-size:13px;text-align:center;padding:16px 0;">No matching elections.</p>
        <?php endif; ?>
        <?php if (empty($tList)): ?>
            <p style="color:#9CA3AF;font-size:13px;text-align:center;padding:24px 0;"><?= e($tEmpty) ?></p>
        <?php endif; ?>
        <?php foreach ($tList as $el): ?>
        <div class="<?= !empty($el['is_completed']) ? 'el-completed-card' : '' ?>" data-search="<?= e(strtolower($el['title'].' '.($el['course'] !== '' ? $el['course'] : 'all courses'))) ?>" style="border:1px solid #E5E7EB;border-radius:8px;padding:14px;margin-bottom:10px;<?= !empty($el['is_completed']) ? 'background:#F9FAFB;' : '' ?>">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <div style="font-size:13px;font-weight:600;color:#1F2937;"><?= e($el['title']) ?></div>
                    <div style="margin-top:4px;"><span style="background:#EDE9FE;color:#5B21B6;border-radius:20px;padding:2px 8px;font-size:11px;font-weight:600;"><?= $el['course'] !== '' ? e($el['course']) : '🏫 All Courses' ?></span></div>
                    <div style="font-size:11px;color:#9CA3AF;margin-top:3px;">
                        <?= date('M j', strtotime($el['start_date'])) ?> – <?= date('M j, Y', strtotime($el['end_date'])) ?>
                        · <?= $el['pos_count'] ?> positions · <?= $el['vote_count'] ?> votes
                        <?php if (!empty($el['is_completed']) && !empty($el['eligible'])): ?>
                        · <strong style="color:#1D4ED8;"><?= round($el['vote_count'] / $el['eligible'] * 100, 1) ?>% turnout</strong> (<?= number_format($el['vote_count']) ?> of <?= number_format($el['eligible']) ?> students)
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($el['is_completed'])): ?>
                    <span class="badge" style="background:#DBEAFE;color:#1D4ED8;"><i class="ti ti-circle-check" style="font-size:12px;"></i> Completed</span>
                <?php elseif (!$el['is_active']): ?>
                    <span class="badge badge-closed">Closed</span>
                <?php elseif ($el['is_hold']): ?>
                    <span class="badge" style="background:#FEF3C7;color:#92400E;"><i class="ti ti-player-pause" style="font-size:12px;"></i> On Hold</span>
                <?php else: ?>
                    <span class="badge badge-active">Active</span>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
                <?php if ($el['is_active']): ?>
                    <?php if ($el['is_hold']): ?>
                        <a href="?toggle_hold=<?= $el['id'] ?>&val=0" class="btn btn-primary btn-sm"><i class="ti ti-player-play"></i> Resume</a>
                    <?php else: ?>
                        <a href="?toggle_hold=<?= $el['id'] ?>&val=1" class="btn btn-outline btn-sm" style="border-color:#F59E0B;color:#92400E;"><i class="ti ti-player-pause"></i> Hold</a>
                    <?php endif; ?>
                    <a href="?toggle=<?= $el['id'] ?>&val=0" class="btn btn-danger btn-sm"><i class="ti ti-player-stop"></i> Close</a>
                <?php elseif (!empty($el['is_completed'])): ?>
                    <a href="?uncomplete=<?= $el['id'] ?>" class="btn btn-outline btn-sm"
                       onclick="confirmReopenElection(<?= (int)$el['id'] ?>, <?= htmlspecialchars(json_encode($el['title']), ENT_QUOTES) ?>); return false;"><i class="ti ti-arrow-back-up"></i> Reopen</a>
                <?php else: ?>
                    <a href="?toggle=<?= $el['id'] ?>&val=1" class="btn btn-primary btn-sm"><i class="ti ti-player-play"></i> Open</a>
                    <a href="?complete=<?= $el['id'] ?>" class="btn btn-outline btn-sm" style="border-color:#1D4ED8;color:#1D4ED8;"
                       onclick="confirmCompleteElection(<?= (int)$el['id'] ?>, <?= htmlspecialchars(json_encode($el['title']), ENT_QUOTES) ?>); return false;"><i class="ti ti-circle-check"></i> Complete</a>
                <?php endif; ?>
                <a href="?toggle_results=<?= $el['id'] ?>&val=<?= $el['show_results']?0:1 ?>" class="btn btn-outline btn-sm">
                    <i class="ti ti-eye"></i> <?= $el['show_results']?'Hide':'Show' ?> Results
                </a>
                <a href="results.php?id=<?= $el['id'] ?>" class="btn btn-outline btn-sm"><i class="ti ti-chart-pie"></i> Results</a>
                <?php if (!$el['is_active'] && empty($el['is_completed'])): ?>
                    <?= exportMenu('export.php?type=winners&election='.(int)$el['id'], 'btn btn-outline btn-sm', 'border-color:#059669;color:#059669;white-space:nowrap;', 'Winners', ['xlsx','pdf','docx']) ?>
                <?php endif; ?>
                <?php if (empty($el['is_completed'])): ?>
                <a href="?edit=<?= $el['id'] ?>" class="btn btn-outline btn-sm"><i class="ti ti-edit"></i> Edit</a>
                <?php endif; ?>
                <?php if ($el['vote_count'] == 0): ?>
                <button type="button" class="btn btn-danger btn-sm"
                   onclick="confirmDeleteElection(<?= $el['id'] ?>, '<?= e($el['title']) ?>')">
                    <i class="ti ti-trash"></i> Delete
                </button>
                <?php else: ?>
                <span title="Cannot delete — votes have been cast" style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;background:#F3F4F6;color:#9CA3AF;font-size:12px;"><i class="ti ti-lock" style="font-size:14px;"></i> Locked</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <style>
    .el-tabs { display:flex; gap:4px; border-bottom:2px solid #E5E7EB; margin-bottom:16px; flex-wrap:wrap; }
    .el-tab { display:flex; align-items:center; gap:6px; padding:10px 16px; font-size:13px; font-weight:600; color:#6B7280; background:none; border:none; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; transition:all .15s; }
    .el-tab:hover { color:#7C3AED; }
    .el-tab.active { color:#7C3AED; border-bottom-color:#7C3AED; }
    .el-tab-badge { background:#9CA3AF; color:#fff; border-radius:20px; padding:1px 8px; font-size:11px; }
    .el-tab-badge-active { background:#059669; }
    .el-tab-badge-completed { background:#1D4ED8; }
    </style>
    <script>
    function filterCompletedElections(q) {
        function words(t) { return ' ' + String(t).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim() + ' '; }
        var tokens = words(q).trim().split(' ').filter(Boolean);
        var shown = 0;
        document.querySelectorAll('.el-completed-card').forEach(function(c) {
            var hay = words(c.getAttribute('data-search'));
            var ok = tokens.every(function(t) { return hay.indexOf(' ' + t) !== -1; });
            c.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        document.getElementById('el-completed-none').style.display = shown ? 'none' : '';
    }
    function switchElTab(tab) {
        ['active','closed','completed'].forEach(function(k) {
            document.getElementById('el-tabpanel-' + k).style.display = (k === tab) ? '' : 'none';
            document.getElementById('el-tab-btn-' + k).classList.toggle('active', k === tab);
        });
    }
    </script>
    <?php endif; ?>

</div></div>

<!-- ===== NEW ELECTION MODAL ===== -->
<div id="new-election-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9100;align-items:center;justify-content:center;overflow-y:auto;padding:16px;">
    <div class="modal-panel" style="max-width:560px;">
        <div class="modal-panel-header">
            <div class="title-wrap">
                <div class="icon-circle"><i class="ti ti-circle-plus"></i></div>
                <div class="title-text">
                    <div class="t">New Election</div>
                    <div class="s">Set the course, schedule, and positions to be filled</div>
                </div>
            </div>
            <button type="button" onclick="closeNewElectionModal()" class="close-btn"><i class="ti ti-x"></i></button>
        </div>
        <form method="POST" id="new-election-form">
            <input type="hidden" name="action" value="create">
            <div class="modal-panel-body">

                <div class="section-label"><i class="ti ti-info-circle"></i> Election Details</div>
                <div class="form-group course-search-wrap">
                    <label>Course *</label>
                    <select name="course" class="form-control" id="ne-course-select" required>
                        <option value="">— Select Course —</option>
                        <option value="__ALL__">🏫 All Courses (Supreme Council)</option>
                        <?php foreach($allCourses as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Title *</label>
                    <div class="field-icon-input">
                        <i class="ti ti-typography"></i>
                        <input type="text" name="title" id="ne-title" class="form-control" placeholder="ex. BSIT S.Y. 2026-2027 Election" required>
                    </div>
                </div>
                <div class="form-group"><label>Description</label><textarea name="description" id="ne-description" class="form-control" placeholder="Optional..."></textarea></div>

                <div class="section-label"><i class="ti ti-calendar-time"></i> Schedule</div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label>Start Date *</label>
                        <div class="field-icon-input">
                            <i class="ti ti-calendar"></i>
                            <input type="datetime-local" name="start_date" id="ne-start-date" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>End Date *</label>
                        <div class="field-icon-input">
                            <i class="ti ti-calendar"></i>
                            <input type="datetime-local" name="end_date" id="ne-end-date" class="form-control" required>
                        </div>
                        <div class="field-error-text" id="ne-date-error">End date must be after the start date.</div>
                    </div>
                </div>

                <div class="section-label"><i class="ti ti-list-numbers"></i> Positions</div>
                <div class="form-group">
                    <input type="hidden" name="positions" id="ne-positions-hidden">
                    <div class="positions-builder" id="ne-positions-builder"></div>
                    <button type="button" class="pos-add-btn" onclick="posBuilderAddRow('ne-positions-builder','ne-positions-hidden','','ex. Auditor'); document.querySelector('#ne-positions-builder .pos-row:last-child .pos-input').focus();">
                        <i class="ti ti-plus"></i> Add Position
                    </button>
                    <small style="color:#9CA3AF;font-size:11px;display:block;margin-top:6px;">Use the arrows to set the order they'll appear on the ballot.</small>
                </div>

            </div>
            <div class="modal-panel-footer">
                <button type="button" onclick="closeNewElectionModal()" class="btn btn-outline btn-sm">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-device-floppy"></i> Create Election</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== CUSTOM CONFIRM MODAL ===== -->
<div id="gnc-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:28px 28px 22px;width:100%;max-width:380px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div id="gnc-modal-icon" style="width:42px;height:42px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px;">
                <i class="ti ti-trash" style="color:#991B1B;"></i>
            </div>
            <div>
                <div id="gnc-modal-title" style="font-size:15px;font-weight:600;color:#1F2937;"></div>
                <div id="gnc-modal-body" style="font-size:13px;color:#6B7280;margin-top:3px;"></div>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button onclick="gncCloseModal()" class="btn btn-outline btn-sm">Cancel</button>
            <button id="gnc-modal-confirm-btn" class="btn btn-danger btn-sm">Delete</button>
        </div>
    </div>
</div>
<script>
// ============================================
// POSITIONS BUILDER — reusable dynamic add/remove/reorder rows
// Keeps a hidden field in sync with newline-joined values so the
// PHP backend (which still expects "one per line") needs no changes.
// ============================================
function posBuilderAddRow(containerId, hiddenInputId, value, placeholder) {
    var container = document.getElementById(containerId);
    var row = document.createElement('div');
    row.className = 'pos-row';
    var safeVal = value ? String(value).replace(/"/g, '&quot;') : '';
    row.innerHTML =
        '<input type="text" class="form-control pos-input" placeholder="'+ (placeholder || 'ex. Position name') +'" value="'+ safeVal +'">' +
        '<button type="button" class="pos-row-btn pos-up" title="Move up"><i class="ti ti-chevron-up"></i></button>' +
        '<button type="button" class="pos-row-btn pos-down" title="Move down"><i class="ti ti-chevron-down"></i></button>' +
        '<button type="button" class="pos-row-btn pos-remove" title="Remove"><i class="ti ti-x"></i></button>';
    container.appendChild(row);
    row.querySelector('.pos-input').addEventListener('input', function() { posBuilderSync(containerId, hiddenInputId); });
    row.querySelector('.pos-up').addEventListener('click', function() {
        var prev = row.previousElementSibling;
        if (prev) container.insertBefore(row, prev);
        posBuilderSync(containerId, hiddenInputId);
    });
    row.querySelector('.pos-down').addEventListener('click', function() {
        var next = row.nextElementSibling;
        if (next) container.insertBefore(next, row);
        posBuilderSync(containerId, hiddenInputId);
    });
    row.querySelector('.pos-remove').addEventListener('click', function() {
        if (container.querySelectorAll('.pos-row').length <= 1) {
            row.querySelector('.pos-input').value = '';
            posBuilderSync(containerId, hiddenInputId);
            return;
        }
        row.remove();
        posBuilderSync(containerId, hiddenInputId);
    });
    posBuilderSync(containerId, hiddenInputId);
}
function posBuilderInit(containerId, hiddenInputId, values, placeholders) {
    var container = document.getElementById(containerId);
    container.innerHTML = '';
    values.forEach(function(v, i) { posBuilderAddRow(containerId, hiddenInputId, v, placeholders ? placeholders[i] : null); });
}
function posBuilderSync(containerId, hiddenInputId) {
    var container = document.getElementById(containerId);
    var hidden = document.getElementById(hiddenInputId);
    var vals = Array.prototype.map.call(container.querySelectorAll('.pos-input'), function(i) { return i.value.trim(); })
        .filter(function(v) { return v.length > 0; });
    hidden.value = vals.join('\n');
}

// ============================================
// End date must be after start date — inline validation, no page reload
// ============================================
function attachDateOrderCheck(formId, startId, endId, errorId) {
    var form = document.getElementById(formId);
    if (!form) return;
    form.addEventListener('submit', function(e) {
        var start = document.getElementById(startId).value;
        var end = document.getElementById(endId).value;
        var group = document.getElementById(endId).closest('.form-group');
        if (start && end && end <= start) {
            e.preventDefault();
            group.classList.add('has-error');
            document.getElementById(errorId).style.display = 'block';
            document.getElementById(endId).focus();
        } else {
            group.classList.remove('has-error');
        }
    });
}
attachDateOrderCheck('new-election-form', 'ne-start-date', 'ne-end-date', 'ne-date-error');

function openNewElectionModal() {
    var now = new Date();
    now.setSeconds(0, 0);
    var local = now.getFullYear() + '-' +
        String(now.getMonth()+1).padStart(2,'0') + '-' +
        String(now.getDate()).padStart(2,'0') + 'T' +
        String(now.getHours()).padStart(2,'0') + ':' +
        String(now.getMinutes()).padStart(2,'0');
    document.getElementById('ne-title').value = '';
    document.getElementById('ne-description').value = '';
    document.getElementById('ne-course-select').value = '';
    document.getElementById('ne-start-date').value = local;
    document.getElementById('ne-end-date').value = '';
    document.getElementById('ne-end-date').closest('.form-group').classList.remove('has-error');
    document.getElementById('ne-date-error').style.display = 'none';
    posBuilderInit('ne-positions-builder', 'ne-positions-hidden',
        ['', '', '', ''],
        ['ex. President', 'ex. Vice President', 'ex. Secretary', 'ex. Treasurer']);
    document.getElementById('new-election-modal').style.display = 'flex';
    setTimeout(function() { document.getElementById('ne-course-select').focus(); }, 50);
}
function closeNewElectionModal() {
    document.getElementById('new-election-modal').style.display = 'none';
}
document.getElementById('new-election-modal').addEventListener('click', function(e) {
    if (e.target === this) closeNewElectionModal();
});
</script>
<script>
var _gncCallback = null;
function gncOpenModal(title, body, callback, opts) {
    // opts (optional): { icon:'ti-circle-check', iconColor:'#1D4ED8', iconBg:'#DBEAFE', confirmText:'Complete', confirmStyle:'background:#1D4ED8;color:#fff;' }
    // Without opts it looks like the original red "Delete" dialog.
    opts = opts || {};
    var iconBox = document.getElementById('gnc-modal-icon');
    iconBox.style.background = opts.iconBg || '#FEE2E2';
    iconBox.innerHTML = '<i class="ti ' + (opts.icon || 'ti-trash') + '" style="color:' + (opts.iconColor || '#991B1B') + ';"></i>';
    var okBtn = document.getElementById('gnc-modal-confirm-btn');
    okBtn.textContent = opts.confirmText || 'Delete';
    okBtn.className   = opts.confirmStyle ? 'btn btn-sm' : 'btn btn-danger btn-sm';
    okBtn.setAttribute('style', opts.confirmStyle || '');
    document.getElementById('gnc-modal-title').textContent = title;
    document.getElementById('gnc-modal-body').textContent  = body;
    _gncCallback = callback;
    var overlay = document.getElementById('gnc-modal-overlay');
    overlay.style.display = 'flex';
    setTimeout(function(){ okBtn.focus(); }, 50);
}
function gncCloseModal() {
    document.getElementById('gnc-modal-overlay').style.display = 'none';
    _gncCallback = null;
}
document.getElementById('gnc-modal-confirm-btn').addEventListener('click', function() {
    var cb = _gncCallback;
    gncCloseModal();
    if (cb) cb();
});
document.getElementById('gnc-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) gncCloseModal();
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') gncCloseModal(); });

function confirmDeletePos(posId, posTitle, eid) {
    gncOpenModal(
        'Delete Position',
        'Delete "' + posTitle + '"? This cannot be undone.',
        function() { window.location.href = '?del_pos=' + posId + '&eid=' + eid; }
    );
}
function confirmCompleteElection(eid, etitle) {
    gncOpenModal(
        'Complete Election',
        'Mark "' + etitle + '" as Completed? Do this only once voting is over and the results are final.',
        function() { window.location.href = '?complete=' + eid; },
        { icon: 'ti-circle-check', iconColor: '#1D4ED8', iconBg: '#DBEAFE', confirmText: 'Complete', confirmStyle: 'background:#1D4ED8;color:#fff;' }
    );
}
function confirmReopenElection(eid, etitle) {
    gncOpenModal(
        'Reopen Election',
        'Move "' + etitle + '" back to Closed? It will leave the Completed tab.',
        function() { window.location.href = '?uncomplete=' + eid; },
        { icon: 'ti-arrow-back-up', iconColor: '#92400E', iconBg: '#FEF3C7', confirmText: 'Reopen', confirmStyle: 'background:#F59E0B;color:#fff;' }
    );
}
function confirmDeleteElection(eid, etitle) {
    gncOpenModal(
        'Delete Election',
        'Delete "' + etitle + '"? All positions and candidates will also be removed.',
        function() { window.location.href = '?del_election=' + eid; }
    );
}
</script>
<script src="../assets/js/alerts.js"></script>
<script src="../assets/js/client-pager.js?v=3"></script>
<script src="../assets/js/sidebar.js?v=4"></script>
<?php include __DIR__.'/../includes/push-widget.php'; ?>
</body></html>
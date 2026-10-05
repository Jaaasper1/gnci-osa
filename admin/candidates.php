<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireAdmin();
require_once __DIR__ . '/election-picker.php';
require_once __DIR__ . '/list-search.php';
$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);

$success = $error = '';
$elections = $pdo->query("SELECT * FROM elections ORDER BY course, created_at DESC")->fetchAll();
$eid = (int)($_GET['election'] ?? defaultElectionId($elections));

// AJAX: lookup student by ID (partial search)
if (isset($_GET['lookup'])) {
    $sid = trim($_GET['lookup']);
    header('Content-Type: application/json');
    if (strlen($sid) < 2) { echo json_encode(['results' => []]); exit; }
    $like = '%' . $sid . '%';
    // Get the election's course to filter students
    $elec_stmt = $pdo->prepare("SELECT course FROM elections WHERE id = ?");
    $elec_stmt->execute([(int)($_GET['eid'] ?? 0)]);
    $elec_course = $elec_stmt->fetchColumn();
    if ($elec_course) {
        $stmt = $pdo->prepare("SELECT student_id, first_name, last_name, course FROM students WHERE (student_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?) AND course = ? AND is_active = 1 LIMIT 6");
        $stmt->execute([$like, $like, $like, $elec_course]);
    } else {
        $stmt = $pdo->prepare("SELECT student_id, first_name, last_name, course FROM students WHERE (student_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?) AND is_active = 1 LIMIT 6");
        $stmt->execute([$like, $like, $like]);
    }
    $rows = $stmt->fetchAll();
    $results = [];
    foreach ($rows as $s) {
        $results[] = [
            'student_id' => $s['student_id'],
            'name'       => $s['first_name'] . ' ' . $s['last_name'],
            'info'       => $s['course'],
        ];
    }
    echo json_encode(['results' => $results]);
    exit;
}

// DELETE
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM candidates WHERE id=?")->execute([(int)$_GET['delete']]);
    header('Location: candidates.php?election='.$eid); exit;
}

// EDIT (AJAX POST)
if (isset($_POST['edit_candidate_id'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
    }
    $cid  = (int)$_POST['edit_candidate_id'];
    $bio  = trim($_POST['edit_bio'] ?? '');
    $credentials = trim($_POST['edit_credentials'] ?? '');
    $edit_party_list_id = !empty($_POST['edit_party_list_id']) ? (int)$_POST['edit_party_list_id'] : null;
    $photo = null;
    $photoMsg = '';
    if (!empty($_FILES['edit_photo']['name'])) {
        $up = uploadPhoto($_FILES['edit_photo']);
        if (isset($up['error'])) {
            echo json_encode(['success' => false, 'message' => $up['error']]); exit;
        }
        $photo = $up['filename'];
    }
    if ($photo) {
        $pdo->prepare("UPDATE candidates SET bio=?, credentials=?, photo=?, party_list_id=? WHERE id=?")->execute([$bio, $credentials, $photo, $edit_party_list_id, $cid]);
    } else {
        $pdo->prepare("UPDATE candidates SET bio=?, credentials=?, party_list_id=? WHERE id=?")->execute([$bio, $credentials, $edit_party_list_id, $cid]);
    }
    $row = $pdo->prepare("SELECT photo FROM candidates WHERE id=?");
    $row->execute([$cid]);
    $updated = $row->fetch();
    echo json_encode(['success' => true, 'message' => 'Candidate updated!', 'photo' => $updated['photo'] ?? null]);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

// Read and clear flash messages
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']);   }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $election_id  = (int)$_POST['election_id'];
    $position_id  = (int)$_POST['position_id'];
    $party_list_id = !empty($_POST['party_list_id']) ? (int)$_POST['party_list_id'] : null;
    $student_sid  = trim($_POST['student_id'] ?? '');
    $bio          = trim($_POST['bio'] ?? '');
    $credentials  = trim($_POST['credentials'] ?? '');

    $student = $pdo->prepare("SELECT id FROM students WHERE student_id = ? AND is_active = 1");
    $student->execute([$student_sid]);
    $s = $student->fetch();

    if (!$s) {
        $_SESSION['flash_error'] = 'Student ID not found.';
    } else {
        // Check: student must not already be a candidate in ANY position of this election
        $dupCheck = $pdo->prepare("SELECT p.title FROM candidates c JOIN positions p ON c.position_id = p.id WHERE c.student_id = ? AND c.election_id = ?");
        $dupCheck->execute([$s['id'], $election_id]);
        $existing = $dupCheck->fetch();
        if ($existing) {
            $_SESSION['flash_error'] = 'This student is already running as a candidate in this election. A student may only run for one position per election.';
        } else {
            $photo = null;
            if (!empty($_FILES['photo']['name'])) {
                $up = uploadPhoto($_FILES['photo']);
                if (isset($up['error'])) { $_SESSION['flash_error'] = $up['error']; }
                else { $photo = $up['filename']; }
            }
            if (!isset($_SESSION['flash_error'])) {
                $pdo->prepare("INSERT INTO candidates (election_id, position_id, student_id, party_list_id, bio, credentials, photo) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$election_id, $position_id, $s['id'], $party_list_id, $bio, $credentials, $photo]);
                $_SESSION['flash_success'] = 'Candidate added!';
            }
        }
    }
    header('Location: candidates.php?election=' . $election_id);
    exit;
}

$positions = [];
if ($eid) {
    $stmt = $pdo->prepare("SELECT * FROM positions WHERE election_id=? ORDER BY sort_order");
    $stmt->execute([$eid]);
    $positions = $stmt->fetchAll();
}

$partyLists = [];
if ($eid) {
    $plStmt = $pdo->prepare("SELECT * FROM party_lists WHERE election_id=? ORDER BY name");
    $plStmt->execute([$eid]);
    $partyLists = $plStmt->fetchAll();
}

$candidates = [];
if ($eid) {
    $candStmt = $pdo->prepare("SELECT c.*, p.title as position, p.sort_order as pos_order, s.first_name, s.last_name, s.student_id as sid, s.course, pl.name as party_name, pl.color as party_color FROM candidates c JOIN positions p ON c.position_id=p.id JOIN students s ON c.student_id=s.id LEFT JOIN party_lists pl ON c.party_list_id=pl.id WHERE c.election_id=? ORDER BY p.sort_order, s.last_name");
    $candStmt->execute([$eid]);
    $candidates = $candStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidates — GNC-OSA Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
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
    <a class="sitem" href="elections.php"><i class="ti ti-trophy"></i> Elections</a>
    <a class="sitem active" href="candidates.php"><i class="ti ti-users"></i> Candidates</a>
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
    <div class="page-header"><h1><?= t('admin.candidates.title', 'Manage Candidates') ?></h1><p><?= t('admin.candidates.subtitle', 'Add candidates to each position') ?></p></div>
    <?php if ($success): ?><div class="alert alert-success"><i class="ti ti-check"></i><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><i class="ti ti-alert-circle"></i><?= e($error) ?></div><?php endif; ?>
    <?php renderElectionPicker($elections, $eid); ?>
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <div class="card-title" style="margin-bottom:0;"><i class="ti ti-users"></i> Candidates <span style="background:#6B1A2A;color:#fff;border-radius:20px;padding:2px 10px;font-size:11px;margin-left:6px;"><?= count($candidates) ?></span></div>
                <?php if (!empty($elections)): ?>
                <div class="io-group">
                <button onclick="openAddCandModal()" class="btn btn-primary btn-sm io-full" style="display:flex;align-items:center;gap:6px;white-space:nowrap;">
                    <i class="ti ti-circle-plus"></i> Add Candidate
                </button>
                <button onclick="openImportModal()" class="btn btn-outline btn-sm" style="display:flex;align-items:center;gap:6px;white-space:nowrap;">
                    <i class="ti ti-file-spreadsheet"></i> Import Excel
                </button>
                <?php if (!empty($candidates)): ?>
                <?= exportMenu('export.php?type=candidates&election='.$eid, 'btn btn-outline btn-sm', 'white-space:nowrap;color:#059669;border-color:#059669;') ?>
                <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php if (empty($elections)): ?>
            <a href="elections.php" class="btn btn-primary btn-sm"><i class="ti ti-plus"></i> Create Election</a>
            <?php endif; ?>
        </div>
        <?php if (empty($candidates)): ?>
            <p style="color:#9CA3AF;font-size:13px;text-align:center;padding:24px 0;">
                <?= empty($elections) ? 'No election found. Create one to get started.' : 'No candidates yet. Click "Add Candidate" to begin.' ?>
            </p>
        <?php else: ?>
        <?php
            $candPositions = array_values(array_unique(array_column($candidates, 'position')));
            $candParties   = array_values(array_unique(array_map(function ($c) { return $c['party_name'] ?: 'Independent'; }, $candidates)));
            sort($candParties);
            $candCourses = array_values(array_unique(array_filter(array_map(function ($c) { return trim($c['course'] ?? ''); }, $candidates))));
            sort($candCourses);
            // [value, label] pairs for the header menus (value = what the row attribute holds)
            $pair = function ($v) { return [strtolower($v), $v]; };
            $optPositions = array_map($pair, $candPositions);   // keeps the ballot order (President first)
            $optParties   = array_map($pair, $candParties);
            $optCourses   = array_map($pair, $candCourses);
            renderListSearch('cand-table', 'Search name, student ID, position, party...');
        ?>
        <div class="table-wrap"><table id="cand-table" data-tt data-pager="10" data-pager-sizes="10,25,50">
            <thead><tr>
                <th data-tt-cfilter="data-position" data-tt-all="All Positions" data-tt-icon="ti-award" data-tt-options="<?= e(json_encode($optPositions)) ?>">Position</th>
                <th data-sort-key="name" data-type="text" data-tt-group="data-pos-order">Candidate</th>
                <th data-tt-cfilter="data-party" data-tt-all="All Parties" data-tt-icon="ti-flag" data-tt-options="<?= e(json_encode($optParties)) ?>">Party List</th>
                <th data-tt-cfilter="data-course" data-tt-all="All Courses" data-tt-icon="ti-school" data-tt-options="<?= e(json_encode($optCourses)) ?>">Course</th>
                <th data-nosort>Credentials / Honors</th>
                <th></th>
            </tr></thead>
            <tbody><?php foreach ($candidates as $c): ?>
            <tr data-search="<?= e(strtolower($c['position'].' '.$c['first_name'].' '.$c['last_name'].' '.$c['sid'].' '.($c['party_name'] ?: 'independent').' '.($c['course'] ?? '').' '.($c['credentials'] ?? ''))) ?>"
                data-position="<?= e(strtolower($c['position'])) ?>"
                data-pos-order="<?= (int)$c['pos_order'] * 1000000 + (int)$c['position_id'] ?>"
                data-course="<?= e(strtolower(trim($c['course'] ?? ''))) ?>"
                data-party="<?= e(strtolower($c['party_name'] ?: 'Independent')) ?>">
                <td><?= e($c['position']) ?></td>
                <td data-sort="<?= e(strtolower($c['last_name'].' '.$c['first_name'])) ?>">
                    <?php if ($c['photo']): ?>
                        <img loading="lazy" decoding="async" width="32" height="32" src="../uploads/<?= e($c['photo']) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;margin-right:8px;vertical-align:middle;">
                    <?php else: ?>
                        <span style="display:inline-flex;width:32px;height:32px;border-radius:50%;background:#F9EEF0;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#6B1A2A;margin-right:8px;vertical-align:middle;"><?= strtoupper(substr($c['first_name'],0,1).substr($c['last_name'],0,1)) ?></span>
                    <?php endif; ?>
                    <?= e($c['first_name'].' '.$c['last_name']) ?><br>
                    <small style="color:#9CA3AF;"><?= e($c['sid']) ?></small>
                </td>
                <td>
                    <?php if ($c['party_name']): ?>
                        <span style="font-weight:600;color:<?= e($c['party_color'] ?: '#6B1A2A') ?>;"><i class="ti ti-flag" style="margin-right:4px;"></i><?= e($c['party_name']) ?></span>
                    <?php else: ?>
                        <span style="color:#9CA3AF;font-size:12px;">Independent</span>
                    <?php endif; ?>
                </td>
                <td><?= e($c['course'] ?? '') ?></td>
                <td style="max-width:220px;">
                    <?php if (!empty($c['credentials'])): ?>
                        <span style="font-size:12px;color:#374151;"><?= nl2br(e($c['credentials'])) ?></span>
                    <?php else: ?>
                        <span style="color:#D1D5DB;font-size:12px;">—</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <button type="button" class="btn btn-outline btn-sm edit-cand-btn" style="margin-right:4px;"
                        data-id="<?= $c['id'] ?>"
                        data-name="<?= e($c['first_name'].' '.$c['last_name']) ?>"
                        data-bio="<?= e($c['bio'] ?? '') ?>"
                        data-credentials="<?= e($c['credentials'] ?? '') ?>"
                        data-photo="<?= e($c['photo'] ?? '') ?>"
                        data-party="<?= e($c['party_list_id'] ?? '') ?>">
                        <i class="ti ti-edit"></i>
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteCandidate(<?= $c['id'] ?>, '<?= e($c['first_name'].' '.$c['last_name']) ?>', <?= $eid ?>)"><i class="ti ti-trash"></i></button>
                </td>
            </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div></div>

<!-- ===== ADD CANDIDATE MODAL ===== -->
<div id="add-cand-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9100;align-items:center;justify-content:center;overflow-y:auto;padding:16px;box-sizing:border-box;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:480px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:20px auto;max-height:90vh;overflow-y:auto;box-sizing:border-box;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
            <div style="font-size:15px;font-weight:600;color:#1F2937;"><i class="ti ti-circle-plus" style="margin-right:6px;color:#6B1A2A;"></i> Add Candidate</div>
            <button onclick="closeAddCandModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9CA3AF;line-height:1;">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="cand-form">
            <div class="form-group">
                <label>Election *</label>
                <select name="election_id" class="form-control" onchange="location.href='?election='+this.value" required>
                    <?php foreach ($elections as $el): ?>
                    <option value="<?= $el['id'] ?>" <?= $el['id']==$eid?'selected':'' ?>>[<?= e($el['course']) ?>] <?= e($el['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Position *</label>
                <select name="position_id" class="form-control" required>
                    <option value="">— Select —</option>
                    <?php foreach ($positions as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= e($p['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Party List <span style="color:#9CA3AF;font-weight:400;">(optional)</span></label>
                <select name="party_list_id" class="form-control">
                    <option value="">— Independent (no party) —</option>
                    <?php foreach ($partyLists as $pl): ?>
                    <option value="<?= $pl['id'] ?>"><?= e($pl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($partyLists)): ?>
                <div style="font-size:11px;color:#9CA3AF;margin-top:4px;">No party lists yet for this election. <a href="party-lists.php?election=<?= $eid ?>" target="_blank">Create one here</a>.</div>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Student ID ng Candidate *</label>
                <input type="text" name="student_id" id="sid-input" class="form-control"
                       placeholder="ex. 20193372" autocomplete="off" required>
                <div id="sid-feedback" style="font-size:12px;margin-top:5px;min-height:16px;"></div>
            </div>
            <div id="student-preview" style="display:none;align-items:center;gap:12px;background:#F9EEF0;border-radius:8px;padding:10px 14px;margin-bottom:16px;">
                <div style="width:44px;height:44px;border-radius:50%;background:#C9943A;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;color:#fff;flex-shrink:0;overflow:hidden;">
                    <span id="preview-initials"></span>
                </div>
                <div>
                    <div id="preview-name" style="font-size:13px;font-weight:600;color:#4A0F1B;"></div>
                    <div id="preview-info" style="font-size:11px;color:#8C2438;"></div>
                </div>
            </div>
            <div class="form-group">
                <label>Short Bio (optional)</label>
                <textarea name="bio" class="form-control" placeholder="Short bio..."></textarea>
            </div>
            <div class="form-group">
                <label>Credentials / Honors (optional)</label>
                <textarea name="credentials" class="form-control" placeholder="ex. SHS With Honors, President's Lister, Dean's Lister"></textarea>
                <div style="font-size:11px;color:#9CA3AF;margin-top:4px;">Enter the candidate's honors or achievements here. These will be shown to voters.</div>
            </div>
            <div class="form-group">
                <label>Photo (optional)</label>
                <input type="file" name="photo" id="photo-input" class="form-control" accept=".jpg,.jpeg,.png">
                <div id="photo-preview-wrap" style="display:none;margin-top:10px;text-align:center;">
                    <img id="photo-preview-img" src="" alt="" style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:2px solid #C9943A;">
                    <div style="font-size:11px;color:#9CA3AF;margin-top:4px;">Preview ng photo</div>
                </div>
                <div style="font-size:11px;color:#9CA3AF;margin-top:4px;">JPG o PNG · max 2MB</div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
                <button type="button" onclick="closeAddCandModal()" class="btn btn-outline btn-sm">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-circle-plus"></i> Add Candidate</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== EDIT CANDIDATE MODAL ===== -->
<div id="edit-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9100;align-items:center;justify-content:center;overflow-y:auto;padding:16px;box-sizing:border-box;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:420px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:20px auto;max-height:90vh;overflow-y:auto;box-sizing:border-box;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
            <div style="font-size:15px;font-weight:600;color:#1F2937;"><i class="ti ti-edit" style="margin-right:6px;color:#6B1A2A;"></i> Edit Candidate</div>
            <button onclick="closeEditModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9CA3AF;line-height:1;">&times;</button>
        </div>
        <div id="edit-candidate-name" style="font-size:13px;font-weight:600;color:#4A0F1B;margin-bottom:16px;"></div>

        <div style="text-align:center;margin-bottom:16px;">
            <img id="edit-photo-preview" src="" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid #C9943A;display:none;">
            <div id="edit-photo-initials" style="width:72px;height:72px;border-radius:50%;background:#F9EEF0;display:inline-flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:#6B1A2A;"></div>
        </div>

        <div class="form-group">
            <label style="font-size:13px;font-weight:500;">Change Photo <span style="color:#9CA3AF;font-weight:400;">(optional)</span></label>
            <input type="file" id="edit-photo-input" class="form-control" accept=".jpg,.jpeg,.png">
            <div style="font-size:11px;color:#9CA3AF;margin-top:4px;">JPG or PNG · max 2MB</div>
        </div>
        <div class="form-group">
            <label style="font-size:13px;font-weight:500;">Bio <span style="color:#9CA3AF;font-weight:400;">(optional)</span></label>
            <textarea id="edit-bio-input" class="form-control" rows="3" placeholder="Short bio..."></textarea>
        </div>
        <div class="form-group">
            <label style="font-size:13px;font-weight:500;">Credentials / Honors <span style="color:#9CA3AF;font-weight:400;">(optional)</span></label>
            <textarea id="edit-credentials-input" class="form-control" rows="2" placeholder="ex. SHS With Honors, President's Lister, Dean's Lister"></textarea>
        </div>
        <div class="form-group">
            <label style="font-size:13px;font-weight:500;">Party List <span style="color:#9CA3AF;font-weight:400;">(optional)</span></label>
            <select id="edit-party-input" class="form-control">
                <option value="">— Independent (no party) —</option>
                <?php foreach ($partyLists as $pl): ?>
                <option value="<?= $pl['id'] ?>"><?= e($pl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="edit-msg" style="font-size:13px;min-height:18px;margin-bottom:10px;"></div>

        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button onclick="closeEditModal()" class="btn btn-outline btn-sm">Cancel</button>
            <button onclick="submitEdit()" id="edit-save-btn" class="btn btn-primary btn-sm"><i class="ti ti-device-floppy"></i> Save</button>
        </div>
    </div>
</div>
<script>
function openAddCandModal() {
    document.getElementById('add-cand-modal-overlay').style.display = 'flex';
    setTimeout(function(){ var s = document.getElementById('sid-input'); if(s) s.focus(); }, 80);
}
function closeAddCandModal() {
    document.getElementById('add-cand-modal-overlay').style.display = 'none';
}
document.getElementById('add-cand-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeAddCandModal();
});
</script>

<script>
var lookupTimer = null;
var sidInput    = document.getElementById('sid-input');
var feedback    = document.getElementById('sid-feedback');
var preview     = document.getElementById('student-preview');
var photoInput  = document.getElementById('photo-input');

// Suggestion dropdown element
var suggestBox = document.createElement('div');
suggestBox.id = 'sid-suggestions';
suggestBox.style.cssText = 'position:absolute;z-index:999;background:#fff;border:1px solid #E5E7EB;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,0.10);width:100%;max-height:260px;overflow-y:auto;display:none;top:100%;left:0;margin-top:4px;';
var sidWrap = sidInput.parentElement;
sidWrap.style.position = 'relative';
sidWrap.appendChild(suggestBox);

function closeSuggestions() { suggestBox.style.display = 'none'; }

function selectSuggestion(student_id, name, info) {
    sidInput.value = student_id;
    closeSuggestions();
    feedback.innerHTML = '<span style="color:#059669;"><i class="ti ti-circle-check" style="vertical-align:-2px;"></i> Found!</span>';
    var parts = name.trim().split(' ');
    var initials = (parts[0][0] + (parts[parts.length-1][0] || '')).toUpperCase();
    document.getElementById('preview-initials').textContent = initials;
    document.getElementById('preview-name').textContent = name;
    document.getElementById('preview-info').textContent = info;
    preview.style.display = 'flex';
}

if (sidInput) {
    sidInput.addEventListener('input', function() {
        var val = this.value.trim();
        clearTimeout(lookupTimer);
        feedback.innerHTML = '';
        preview.style.display = 'none';
        suggestBox.style.display = 'none';
        if (val.length < 2) return;
        feedback.innerHTML = '<span style="color:#9CA3AF;"><i class="ti ti-loader" style="vertical-align:-2px;"></i> Searching...</span>';
        lookupTimer = setTimeout(function() {
            var electionSelect = document.querySelector('select[name="election_id"]');
            const eid = electionSelect ? electionSelect.value : '<?= $eid ?>';
            fetch('candidates.php?lookup=' + encodeURIComponent(val) + '&eid=' + eid)
                .then(function(r){ return r.json(); })
                .then(function(d) {
                    if (!d.results || d.results.length === 0) {
                        feedback.innerHTML = '<span style="color:#991B1B;"><i class="ti ti-alert-circle" style="vertical-align:-2px;"></i> No student found.</span>';
                        suggestBox.style.display = 'none';
                        return;
                    }
                    feedback.innerHTML = '';
                    // If exact match, auto-select
                    if (d.results.length === 1 && d.results[0].student_id === val) {
                        selectSuggestion(d.results[0].student_id, d.results[0].name, d.results[0].info);
                        return;
                    }
                    // Build dropdown
                    suggestBox.innerHTML = '';
                    d.results.forEach(function(s) {
                        var item = document.createElement('div');
                        item.style.cssText = 'padding:10px 14px;cursor:pointer;display:flex;align-items:center;gap:10px;border-bottom:1px solid #F3F4F6;transition:background 0.15s;';
                        item.onmouseover = function(){ this.style.background='#F9FAFB'; };
                        item.onmouseout  = function(){ this.style.background=''; };
                        var parts = s.name.trim().split(' ');
                        var initials = (parts[0][0] + (parts[parts.length-1][0]||'')).toUpperCase();
                        item.innerHTML =
                            '<div style="width:34px;height:34px;border-radius:50%;background:#7C1D2E;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;">' + initials + '</div>' +
                            '<div><div style="font-size:13px;font-weight:600;color:#1F2937;">' + s.name + '</div>' +
                            '<div style="font-size:11px;color:#9CA3AF;">' + s.student_id + ' · ' + s.info + '</div></div>';
                        item.addEventListener('mousedown', function(e) {
                            e.preventDefault();
                            selectSuggestion(s.student_id, s.name, s.info);
                        });
                        suggestBox.appendChild(item);
                    });
                    suggestBox.style.display = 'block';
                });
        }, 300);
    });

    sidInput.addEventListener('blur', function() {
        setTimeout(closeSuggestions, 200);
    });

    sidInput.addEventListener('keydown', function(e) {
        var items = suggestBox.querySelectorAll('div');
        var active = suggestBox.querySelector('.suggest-active');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            var next = active ? active.nextElementSibling : items[0];
            if (active) active.classList.remove('suggest-active'), active.style.background='';
            if (next) { next.classList.add('suggest-active'); next.style.background='#F9FAFB'; next.scrollIntoView({block:'nearest'}); }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            var prev = active ? active.previousElementSibling : null;
            if (active) active.classList.remove('suggest-active'), active.style.background='';
            if (prev) { prev.classList.add('suggest-active'); prev.style.background='#F9FAFB'; prev.scrollIntoView({block:'nearest'}); }
        } else if (e.key === 'Enter' && active) {
            e.preventDefault();
            active.dispatchEvent(new MouseEvent('mousedown'));
        } else if (e.key === 'Escape') {
            closeSuggestions();
        }
    });
}

if (photoInput) {
    photoInput.addEventListener('change', function() {
        var file = this.files[0];
        var wrap = document.getElementById('photo-preview-wrap');
        var img  = document.getElementById('photo-preview-img');
        if (file) {
            var reader = new FileReader();
            reader.onload = function(e) {
                img.src = e.target.result;
                wrap.style.display = 'block';
            };
            reader.readAsDataURL(file);
        } else {
            wrap.style.display = 'none';
        }
    });
}
</script>

<script>
var _editCandId = null;

function openEditModal(id, name, bio, photo, credentials, party) {
    _editCandId = id;
    document.getElementById('edit-candidate-name').textContent = name;
    document.getElementById('edit-bio-input').value = bio || '';
    document.getElementById('edit-credentials-input').value = credentials || '';
    document.getElementById('edit-party-input').value = party || '';
    document.getElementById('edit-msg').textContent = '';
    document.getElementById('edit-photo-input').value = '';

    var img = document.getElementById('edit-photo-preview');
    var ini = document.getElementById('edit-photo-initials');
    if (photo) {
        img.src = '../uploads/' + photo;
        img.style.display = 'inline-block';
        ini.style.display = 'none';
    } else {
        img.style.display = 'none';
        var parts = name.trim().split(' ');
        ini.textContent = (parts[0][0] + (parts[parts.length-1][0] || '')).toUpperCase();
        ini.style.display = 'inline-flex';
    }

    // Live photo preview
    document.getElementById('edit-photo-input').onchange = function() {
        var file = this.files[0];
        if (file) {
            var reader = new FileReader();
            reader.onload = function(e) {
                img.src = e.target.result;
                img.style.display = 'inline-block';
                ini.style.display = 'none';
            };
            reader.readAsDataURL(file);
        }
    };

    document.getElementById('edit-modal-overlay').style.display = 'flex';
    setTimeout(function(){ document.getElementById('edit-bio-input').focus(); }, 50);
}

function closeEditModal() {
    document.getElementById('edit-modal-overlay').style.display = 'none';
    _editCandId = null;
}

function submitEdit() {
    if (!_editCandId) return;
    var btn = document.getElementById('edit-save-btn');
    var msg = document.getElementById('edit-msg');
    btn.disabled = true;
    btn.textContent = 'Saving...';
    msg.textContent = '';

    var fd = new FormData();
    fd.append('edit_candidate_id', _editCandId);
    fd.append('edit_bio', document.getElementById('edit-bio-input').value);
    fd.append('edit_credentials', document.getElementById('edit-credentials-input').value);
    fd.append('edit_party_list_id', document.getElementById('edit-party-input').value);
    var photoFile = document.getElementById('edit-photo-input').files[0];
    if (photoFile) fd.append('edit_photo', photoFile);

    fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.success) {
                msg.innerHTML = '<span style="color:#059669;"><i class="ti ti-circle-check"></i> ' + data.message + '</span>';
                setTimeout(function(){ closeEditModal(); location.reload(); }, 800);
            } else {
                msg.innerHTML = '<span style="color:#991B1B;"><i class="ti ti-alert-circle"></i> ' + data.message + '</span>';
                btn.disabled = false;
                btn.innerHTML = '<i class="ti ti-device-floppy"></i> Save';
            }
        })
        .catch(function() {
            msg.innerHTML = '<span style="color:#991B1B;">Network error. Try again.</span>';
            btn.disabled = false;
            btn.innerHTML = '<i class="ti ti-device-floppy"></i> Save';
        });
}

document.getElementById('edit-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeEditModal(); });

// Event delegation for edit buttons — safe with any character in name/bio
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.edit-cand-btn');
    if (!btn) return;
    openEditModal(
        btn.dataset.id,
        btn.dataset.name,
        btn.dataset.bio,
        btn.dataset.photo,
        btn.dataset.credentials,
        btn.dataset.party
    );
});
</script>

<!-- ===== CUSTOM CONFIRM MODAL ===== -->
<div id="gnc-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:28px 28px 22px;width:100%;max-width:380px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div style="width:42px;height:42px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px;">
                <i class="ti ti-user-x" style="color:#991B1B;"></i>
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
var _gncCallback = null;
function gncOpenModal(title, body, callback) {
    document.getElementById('gnc-modal-title').textContent = title;
    document.getElementById('gnc-modal-body').textContent  = body;
    _gncCallback = callback;
    document.getElementById('gnc-modal-overlay').style.display = 'flex';
    setTimeout(function(){ document.getElementById('gnc-modal-confirm-btn').focus(); }, 50);
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

function confirmDeleteCandidate(id, name, eid) {
    gncOpenModal(
        'Remove Candidate',
        'Remove "' + name + '" from the candidates list?',
        function() { window.location.href = '?delete=' + id + '&election=' + eid; }
    );
}
</script>
<script src="../assets/js/alerts.js"></script>
<script src="../assets/js/sidebar.js?v=5"></script>
<script src="../assets/js/table-tools.js?v=6"></script>
<script src="../assets/js/client-pager.js?v=3"></script>

<!-- ===== IMPORT EXCEL MODAL ===== -->
<div id="import-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9200;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:500px;box-shadow:0 8px 32px rgba(0,0,0,0.18);max-height:90vh;overflow-y:auto;">

        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <div style="font-size:15px;font-weight:600;color:#1F2937;">
                <i class="ti ti-file-spreadsheet" style="margin-right:6px;color:#059669;"></i> Import Candidates via Excel
            </div>
            <button onclick="closeImportModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9CA3AF;">&times;</button>
        </div>

        <!-- Step 1: Download template -->
        <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
            <div style="font-size:13px;font-weight:600;color:#065F46;margin-bottom:6px;">
                <i class="ti ti-download" style="margin-right:4px;"></i> Step 1 — Download the template
            </div>
            <div style="font-size:12px;color:#047857;margin-bottom:10px;line-height:1.5;">
                Fill in the Excel template with <strong>student_id</strong> and <strong>position</strong> columns.<br>
                Position name must match exactly what's in the election (e.g. "President", "Secretary").
            </div>
            <a href="import-candidates.php?template=1" class="btn btn-sm" style="background:#059669;color:#fff;gap:6px;">
                <i class="ti ti-table-export"></i> Download Template (.xlsx)
            </a>
            <div style="font-size:11.5px;color:#065F46;margin-top:10px;">
                <i class="ti ti-info-circle" style="margin-right:3px;"></i> Tip: already have a Candidates List you exported earlier? You can edit that file directly and upload it in Step 2 — no need to start from the template.
            </div>
        </div>

        <!-- Step 2: Upload -->
        <div style="font-size:13px;font-weight:600;color:#1F2937;margin-bottom:10px;">
            <i class="ti ti-upload" style="margin-right:4px;color:#6B1A2A;"></i> Step 2 — Upload filled Excel file
        </div>

        <!-- Drop zone -->
        <div id="drop-zone" style="border:2px dashed #D1D5DB;border-radius:10px;padding:24px;text-align:center;cursor:pointer;transition:border-color 0.2s,background 0.2s;margin-bottom:14px;"
             onclick="document.getElementById('excel-file-input').click()"
             ondragover="event.preventDefault();this.style.borderColor='#6B1A2A';this.style.background='#FDF2F4';"
             ondragleave="this.style.borderColor='#D1D5DB';this.style.background='';"
             ondrop="handleDrop(event)">
            <i class="ti ti-file-spreadsheet" style="font-size:32px;color:#9CA3AF;display:block;margin-bottom:8px;"></i>
            <div id="drop-label" style="font-size:13px;color:#6B7280;">Click to browse or drag & drop your Excel file here</div>
            <div style="font-size:11px;color:#9CA3AF;margin-top:4px;">.xlsx or .xls · max 5MB</div>
        </div>
        <input type="file" id="excel-file-input" accept=".xlsx,.xls" style="display:none;" onchange="handleFileSelect(this.files[0])">

        <!-- Result area -->
        <div id="import-result" style="display:none;"></div>

        <!-- Actions -->
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px;">
            <button onclick="closeImportModal()" class="btn btn-outline btn-sm">Cancel</button>
            <button id="import-submit-btn" onclick="submitImport()" class="btn btn-primary btn-sm" disabled>
                <i class="ti ti-eye"></i> Preview Changes
            </button>
        </div>
    </div>
</div>

<script>
var _importFile = null;

function openImportModal() {
    resetImportModal();
    document.getElementById('import-modal-overlay').style.display = 'flex';
}
function closeImportModal() {
    document.getElementById('import-modal-overlay').style.display = 'none';
}
document.getElementById('import-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeImportModal();
});

function resetImportModal() {
    _importFile = null;
    document.getElementById('excel-file-input').value = '';
    document.getElementById('drop-label').textContent = 'Click to browse or drag & drop your Excel file here';
    document.getElementById('drop-zone').style.borderColor = '#D1D5DB';
    document.getElementById('drop-zone').style.background  = '';
    document.getElementById('import-result').style.display = 'none';
    document.getElementById('import-result').innerHTML = '';
    document.getElementById('import-submit-btn').disabled = true;
    submitImportReset();
}

function handleFileSelect(file) {
    if (!file) return;
    _importFile = file;
    document.getElementById('drop-label').innerHTML =
        '<strong style="color:#1F2937;">' + file.name + '</strong>' +
        ' <span style="color:#9CA3AF;">(' + (file.size / 1024).toFixed(1) + ' KB)</span>';
    document.getElementById('drop-zone').style.borderColor = '#059669';
    document.getElementById('drop-zone').style.background  = '#F0FDF4';
    document.getElementById('import-submit-btn').disabled  = false;
    document.getElementById('import-result').style.display = 'none';
    submitImportReset();
}

function handleDrop(event) {
    event.preventDefault();
    document.getElementById('drop-zone').style.borderColor = '#D1D5DB';
    document.getElementById('drop-zone').style.background  = '';
    var file = event.dataTransfer.files[0];
    if (file) handleFileSelect(file);
}

// One button, two steps: "Preview Changes" first, then "Confirm Import".
var _candPreview = false;

function _impEsc(v) {
    return String(v == null ? '' : v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function _impSkippedHTML(list) {
    if (!list || !list.length) return '';
    return '<div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:12px 14px;margin-top:10px;">' +
        '<div style="font-size:12px;font-weight:600;color:#92400E;margin-bottom:6px;">' +
        '<i class="ti ti-alert-triangle" style="margin-right:4px;"></i>' + list.length + ' row(s) will be skipped:</div>' +
        '<ul style="margin:0;padding-left:16px;max-height:120px;overflow-y:auto;">' +
        list.map(function(s) { return '<li style="font-size:11px;color:#92400E;margin-bottom:3px;">' + _impEsc(s) + '</li>'; }).join('') +
        '</ul></div>';
}
function _impErrorHTML(msg) {
    return '<div style="background:#FEE2E2;border:1px solid #FECACA;border-radius:8px;padding:12px 14px;">' +
        '<i class="ti ti-alert-circle" style="color:#991B1B;margin-right:6px;"></i>' +
        '<span style="color:#991B1B;">' + _impEsc(msg) + '</span></div>';
}
function submitImportReset() {
    _candPreview = false;
    var b = document.getElementById('import-submit-btn');
    b.innerHTML = '<i class="ti ti-eye"></i> Preview Changes';
}

function submitImport() {
    if (!_importFile) return;
    var isConfirm = _candPreview;

    var electionId = <?= $eid ?>;
    var btn = document.getElementById('import-submit-btn');
    var result = document.getElementById('import-result');

    btn.disabled = true;
    btn.innerHTML = '<i class="ti ti-loader"></i> ' + (isConfirm ? 'Importing...' : 'Checking...');
    if (!isConfirm) { result.innerHTML = appSkeletonHTML(3); result.style.display = 'block'; }

    var fd = new FormData();
    fd.append('excel_file', _importFile);
    fd.append('election_id', electionId);
    if (!isConfirm) fd.append('preview', '1');

    fetch('import-candidates.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;

            if (!data.success) {
                submitImportReset();
                result.innerHTML = _impErrorHTML(data.message || 'Something went wrong.');
                result.style.display = 'block';
                return;
            }

            // ── Step 1 result: show what WOULD happen, wait for confirmation ──
            if (data.preview) {
                if (data.new === 0) {
                    submitImportReset();
                    result.innerHTML =
                        '<div style="background:#F3F4F6;border:1px solid #E5E7EB;border-radius:8px;padding:12px 14px;color:#374151;font-size:13px;">' +
                        'Nothing to import — no valid new candidate(s) found in this file.</div>' + _impSkippedHTML(data.skipped);
                    result.style.display = 'block';
                    return;
                }
                var html =
                    '<div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:12px 14px;margin-bottom:10px;">' +
                    '<i class="ti ti-info-circle" style="color:#1D4ED8;margin-right:6px;"></i>' +
                    '<strong style="color:#1E3A8A;">' + data.new + ' candidate(s) will be added.</strong> ' +
                    '<span style="color:#1E40AF;font-size:12px;">Nothing is saved yet — review below, then click Confirm Import.</span></div>' +
                    '<div style="max-height:220px;overflow:auto;border:1px solid #E5E7EB;border-radius:8px;">' +
                    '<table style="width:100%;border-collapse:collapse;font-size:12px;">' +
                    '<thead><tr style="background:#F9FAFB;text-align:left;"><th style="padding:8px 10px;">Row</th><th style="padding:8px 10px;">Student ID</th><th style="padding:8px 10px;">Name</th><th style="padding:8px 10px;">Position</th><th style="padding:8px 10px;">Party List</th></tr></thead><tbody>' +
                    data.rows.map(function(r) {
                        return '<tr style="border-top:1px solid #F3F4F6;"><td style="padding:8px 10px;">' + _impEsc(r.row) + '</td><td style="padding:8px 10px;">' + _impEsc(r.student_id) + '</td><td style="padding:8px 10px;">' + _impEsc(r.name) + '</td><td style="padding:8px 10px;">' + _impEsc(r.position) + '</td><td style="padding:8px 10px;">' + _impEsc(r.party || 'Independent') + '</td></tr>';
                    }).join('') + '</tbody></table></div>';
                if (data.truncated) html += '<div style="font-size:11px;color:#6B7280;margin-top:6px;">Showing the first ' + data.rows.length + ' rows only. All ' + data.new + ' will be imported.</div>';
                html += _impSkippedHTML(data.skipped);
                result.innerHTML = html;
                result.style.display = 'block';
                _candPreview = true;
                btn.innerHTML = '<i class="ti ti-check"></i> Confirm Import (' + data.new + ')';
                return;
            }

            // ── Step 2 result: actually saved ──
            _candPreview = false;
            btn.innerHTML = '<i class="ti ti-check"></i> Done';
            btn.disabled = true;
            var ok = '<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:12px 14px;">' +
                '<i class="ti ti-circle-check" style="color:#059669;margin-right:6px;"></i>' +
                '<strong style="color:#065F46;">' + data.added + ' candidate(s) added successfully!</strong></div>';
            result.innerHTML = ok + _impSkippedHTML(data.skipped).replace('will be skipped', 'skipped');
            result.style.display = 'block';
            if (data.added > 0) setTimeout(function() { location.reload(); }, 2000);
        })
        .catch(function() {
            btn.disabled = false;
            submitImportReset();
            result.innerHTML = _impErrorHTML('Network error. Please try again.');
            result.style.display = 'block';
        });
}
</script>
<?php include __DIR__.'/../includes/push-widget.php'; ?>
</body></html>

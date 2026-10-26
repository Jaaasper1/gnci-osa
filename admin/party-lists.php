<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireStaff();
require_once '../includes/csrf.php';
require_once '../includes/admin-otp.php';
adminOtpHandleAjax($pdo);
require_once __DIR__ . '/election-picker.php';
require_once __DIR__ . '/list-search.php';
$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);

if (session_status() === PHP_SESSION_NONE) session_start();

$success = $error = '';
$elections = $pdo->query("SELECT * FROM elections ORDER BY course, created_at DESC")->fetchAll();
$eid = (int)($_GET['election'] ?? defaultElectionId($elections));

// DELETE
if (isset($_POST['delete'])) {
    requireCsrf();
    // Candidates linked to this party list simply become independent (party_list_id -> NULL via FK ON DELETE SET NULL)
    $__before = auditRow($pdo, 'party_lists', (int)$_POST['delete']);
    $pdo->prepare("DELETE FROM party_lists WHERE id=?")->execute([(int)$_POST['delete']]);
    auditLog($pdo, 'delete', 'party_list', (int)$_POST['delete'], $__before);
    header('Location: party-lists.php?election=' . $eid); exit;
}

// Read and clear flash messages
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']);   }

// ADD / EDIT (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $party_id    = (int)($_POST['party_id'] ?? 0);
    $election_id = (int)$_POST['election_id'];
    $name        = trim($_POST['name'] ?? '');
    $platform    = trim($_POST['platform'] ?? '');
    $color       = trim($_POST['color'] ?? '#6B1A2A');

    if ($name === '') {
        $_SESSION['flash_error'] = 'Party list name is required.';
    } elseif ($party_id && !adminOtpConsumeApproval('edit_party', $party_id)) {
        $_SESSION['flash_error'] = t('msg.please_confirm_the_change_with_the_email_1213', 'Please confirm the change with the emailed code first.');
    } else {
        $logo = null;
        if (!empty($_FILES['logo']['name'])) {
            $up = uploadPhoto($_FILES['logo']);
            if (isset($up['error'])) { $_SESSION['flash_error'] = $up['error']; }
            else { $logo = $up['filename']; }
        }

        if (!isset($_SESSION['flash_error'])) {
            $__before = $party_id ? auditRow($pdo, 'party_lists', $party_id) : [];
            if ($party_id) {
                if ($logo) {
                    $pdo->prepare("UPDATE party_lists SET name=?, platform=?, color=?, logo=? WHERE id=?")
                        ->execute([$name, $platform, $color, $logo, $party_id]);
                } else {
                    $pdo->prepare("UPDATE party_lists SET name=?, platform=?, color=? WHERE id=?")
                        ->execute([$name, $platform, $color, $party_id]);
                }
                auditLog($pdo, 'update', 'party_list', $party_id, $__before, ['name' => $name, 'platform' => $platform, 'color' => $color]);
                $_SESSION['flash_success'] = 'Party list updated!';
            } else {
                $pdo->prepare("INSERT INTO party_lists (election_id, name, platform, color, logo) VALUES (?,?,?,?,?)")
                    ->execute([$election_id, $name, $platform, $color, $logo]);
                $_SESSION['flash_success'] = 'Party list added!';
                auditLog($pdo, 'create', 'party_list', (int)$pdo->lastInsertId(), [], ['name' => $name]);
            }
        }
    }
    header('Location: party-lists.php?election=' . $election_id);
    exit;
}

$partyLists = [];
if ($eid) {
    $stmt = $pdo->prepare("SELECT pl.*, (SELECT COUNT(*) FROM candidates c WHERE c.party_list_id = pl.id) as candidate_count FROM party_lists pl WHERE pl.election_id=? ORDER BY pl.name");
    $stmt->execute([$eid]);
    $partyLists = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.party_lists', 'Party Lists') ?> — GNC-OSA Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body class="theme-election">
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="<?= t('ui.toggle_menu_641f', 'Toggle menu') ?>"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Admin</div><div class="sub"><?= t('ui.administrator_7b7b', 'Administrator') ?></div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><div class="avatar" style="background:#7C3AED;color:#fff;">AD</div><span class="uname"><?= e($_SESSION['name']) ?></span></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.overview', 'Overview') ?></div>
    <a class="sitem" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <div class="sidebar-section"><?= t('sidebar.complaints', 'Complaints') ?></div>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.all_complaints', 'All Complaints') ?></a><a class="sitem" href="../osa/messages.php"><i class="ti ti-messages"></i> <?= t('sidebar.messages', 'Messages') ?></a>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="elections.php"><i class="ti ti-trophy"></i> <?= t('sidebar.elections', 'Elections') ?></a>
    <a class="sitem" href="candidates.php"><i class="ti ti-users"></i> <?= t('sidebar.candidates', 'Candidates') ?></a>
    <a class="sitem active" href="party-lists.php"><i class="ti ti-flag"></i> <?= t('sidebar.party_lists', 'Party Lists') ?></a>
    <a class="sitem" href="vouchers.php"><i class="ti ti-ticket"></i> <?= t('sidebar.vouchers', 'Vouchers') ?></a>
    <a class="sitem" href="results.php"><i class="ti ti-chart-pie"></i> <?= t('sidebar.live_results', 'Live Results') ?></a>
    <div class="sidebar-section"><?= t('sidebar.events', 'Events') ?></div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> <?= t('sidebar.events', 'Events') ?></a>
    <div class="sidebar-section"><?= t('sidebar.system', 'System') ?></div>
    <a class="sitem" href="students.php"><i class="ti ti-user-plus"></i> <?= t('sidebar.students', 'Students') ?></a>
    <?php if ($_SESSION['user_type'] === 'admin'): ?><a class="sitem" href="staff.php"><i class="ti ti-shield-check"></i> <?= t('sidebar.osa_staff', 'OSA Staff') ?></a><?php endif; ?><?php if ($_SESSION['user_type'] === 'admin'): ?><a class="sitem" href="audit-log.php"><i class="ti ti-history"></i> <?= t('sidebar.audit_log', 'Audit Log') ?></a><?php endif; ?>
    <a class="sitem" href="reports.php"><i class="ti ti-report-analytics"></i> <?= t('sidebar.reports', 'Reports') ?></a>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('sidebar.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">
    <div class="page-header"><h1><?= t('sidebar.party_lists', 'Party Lists') ?></h1><p><?= t('admin.partylists.subtitle', 'Group candidates under a party list / ticket for each election') ?></p></div>
    <?php if ($success): ?><div class="alert alert-success"><i class="ti ti-check"></i><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><i class="ti ti-alert-circle"></i><?= e($error) ?></div><?php endif; ?>

    <?php renderElectionPicker($elections, $eid); ?>

    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
            <div class="card-title" style="margin-bottom:0;"><i class="ti ti-flag"></i> <?= t('ui.party_lists_e975', 'Party Lists') ?> <span style="background:#6B1A2A;color:#fff;border-radius:20px;padding:2px 10px;font-size:11px;margin-left:6px;"><?= count($partyLists) ?></span></div>
            <?php if (!empty($elections)): ?>
            <div class="io-group">
                <button onclick="openImportModal()" class="btn btn-outline btn-sm" style="display:flex;align-items:center;gap:6px;white-space:nowrap;">
                    <i class="ti ti-file-spreadsheet"></i> <?= t('ui.import_excel_508e', 'Import Excel') ?>
                </button>
                <?php
                $__pOpts = []; foreach ($partyLists as $__pl) $__pOpts[$__pl['id']] = $__pl['name'];
                if ($eid) {
                    $__ind = $pdo->prepare("SELECT COUNT(*) FROM candidates WHERE election_id=? AND party_list_id IS NULL"); $__ind->execute([$eid]);
                    if ((int)$__ind->fetchColumn() > 0) $__pOpts[0] = 'Independent (no party list)';
                }
                ?>
                <?= exportMenu('export.php?type=party_lists&election='.$eid, 'btn btn-outline btn-sm', 'white-space:nowrap;color:#059669;border-color:#059669;', 'Export', null, [
                    ['key' => 'party', 'label' => 'Party List', 'options' => $__pOpts],
                ]) ?>
                <button onclick="openAddPartyModal()" class="btn btn-primary btn-sm io-full" style="display:flex;align-items:center;gap:6px;white-space:nowrap;">
                    <i class="ti ti-circle-plus"></i> <?= t('ui.add_party_list_8db3', 'Add Party List') ?>
                </button>
            </div>
            <?php else: ?>
            <a href="elections.php" class="btn btn-primary btn-sm"><i class="ti ti-plus"></i> <?= t('ui.create_election_78c4', 'Create Election') ?></a>
            <?php endif; ?>
        </div>

        <?php if (empty($partyLists)): ?>
            <p style="color:#9CA3AF;font-size:13px;text-align:center;padding:24px 0;">
                <?= empty($elections) ? 'No election found. Create one to get started.' : 'No party lists yet for this election. Candidates without a party list are shown as "Independent" on the ballot.' ?>
            </p>
        <?php else: ?>
        <?php renderListSearch('party-table', 'Search party list or platform...'); ?>
        <div class="table-wrap"><table id="party-table" data-pager="10" data-pager-sizes="10,25,50">
            <thead><tr><th></th><th>Party List</th><th><?= t('ui.platform_419f', 'Platform') ?></th><th><?= t('ui.candidates_211d', 'Candidates') ?></th><th></th></tr></thead>
            <tbody><?php foreach ($partyLists as $pl): ?>
            <tr data-bulk-id="<?= (int)$pl['id'] ?>" data-search="<?= e(strtolower($pl['name'].' '.($pl['platform'] ?? ''))) ?>">
                <td>
                    <?php if ($pl['logo']): ?>
                        <img src="../uploads/<?= e($pl['logo']) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;">
                    <?php else: ?>
                        <span style="display:inline-block;width:20px;height:20px;border-radius:6px;background:<?= e($pl['color']) ?>;"></span>
                    <?php endif; ?>
                </td>
                <td><strong style="color:<?= e($pl['color']) ?>;"><?= e($pl['name']) ?></strong></td>
                <td style="max-width:260px;">
                    <?php if (!empty($pl['platform'])): ?>
                        <span style="font-size:12px;color:#374151;"><?= nl2br(e($pl['platform'])) ?></span>
                    <?php else: ?>
                        <span style="color:#D1D5DB;font-size:12px;">—</span>
                    <?php endif; ?>
                </td>
                <td><span style="background:#F3F4F6;border-radius:20px;padding:2px 10px;font-size:12px;"><?= (int)$pl['candidate_count'] ?></span></td>
                <td style="white-space:nowrap;">
                    <button type="button" class="btn btn-outline btn-sm edit-party-btn" style="margin-right:4px;"
                        data-id="<?= $pl['id'] ?>"
                        data-name="<?= e($pl['name']) ?>"
                        data-platform="<?= e($pl['platform'] ?? '') ?>"
                        data-color="<?= e($pl['color'] ?? '#6B1A2A') ?>">
                        <i class="ti ti-edit"></i>
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteParty(<?= $pl['id'] ?>, '<?= e($pl['name']) ?>')"><i class="ti ti-trash"></i></button>
                </td>
            </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <p style="font-size:11px;color:#9CA3AF;margin-top:10px;"><?= t('ui.deleting_a_party_list_does_not_rem_89fd', 'Deleting a party list does not remove its candidates — they simply become "Independent".') ?></p>
        <?php endif; ?>
    </div>
</div></div>

<!-- ===== ADD/EDIT PARTY LIST MODAL ===== -->
<div id="party-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9100;align-items:center;justify-content:center;overflow-y:auto;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:460px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:20px auto;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
            <div id="party-modal-title" style="font-size:15px;font-weight:600;color:#1F2937;"><i class="ti ti-circle-plus" style="margin-right:6px;color:#6B1A2A;"></i> <?= t('ui.add_party_list_8db3', 'Add Party List') ?></div>
            <button onclick="closePartyModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9CA3AF;line-height:1;">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="party-form">
            <input type="hidden" name="party_id" id="party-id-input" value="">
            <input type="hidden" name="election_id" value="<?= $eid ?>">
            <div class="form-group">
                <label><?= t('ui.party_list_name_95ce', 'Party List Name *') ?></label>
                <input type="text" name="name" id="party-name-input" class="form-control" placeholder="<?= t('ui.ex_unity_coalition_5d69', 'ex. Unity Coalition') ?>" required>
            </div>
            <div class="form-group">
                <label><?= t('ui.platform_advocacy_optional_1049', 'Platform / Advocacy (optional)') ?></label>
                <textarea name="platform" id="party-platform-input" class="form-control" placeholder="<?= htmlspecialchars(t('auto.short_description_of_the_party_s_platfor_3f95', 'Short description of the party\'s platform...'), ENT_QUOTES) ?>"></textarea>
            </div>
            <div class="form-group">
                <label><?= t('ui.party_color_a0bc', 'Party Color') ?></label>
                <input type="color" name="color" id="party-color-input" class="form-control" value="#6B1A2A" style="height:40px;padding:4px;">
            </div>
            <div class="form-group">
                <label><?= t('ui.logo_optional_6dfd', 'Logo (optional)') ?></label>
                <input type="file" name="logo" class="form-control" accept=".jpg,.jpeg,.png">
                <div style="font-size:11px;color:#9CA3AF;margin-top:4px;"><?= t('ui.jpg_o_png_max_2mb_532b', 'JPG o PNG · max 2MB') ?></div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
                <button type="button" onclick="closePartyModal()" class="btn btn-outline btn-sm"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-device-floppy"></i> <?= t('ui.save_c9cc', 'Save') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddPartyModal() {
    document.getElementById('party-modal-title').innerHTML = '<i class="ti ti-circle-plus" style="margin-right:6px;color:#6B1A2A;"></i> Add Party List';
    document.getElementById('party-id-input').value = '';
    document.getElementById('party-name-input').value = '';
    document.getElementById('party-platform-input').value = '';
    document.getElementById('party-color-input').value = '#6B1A2A';
    document.getElementById('party-modal-overlay').style.display = 'flex';
}
function closePartyModal() {
    document.getElementById('party-modal-overlay').style.display = 'none';
}
document.getElementById('party-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closePartyModal();
});
document.querySelectorAll('.edit-party-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('party-modal-title').innerHTML = '<i class="ti ti-edit" style="margin-right:6px;color:#6B1A2A;"></i> Edit Party List';
        document.getElementById('party-id-input').value = this.dataset.id;
        document.getElementById('party-name-input').value = this.dataset.name;
        document.getElementById('party-platform-input').value = this.dataset.platform;
        document.getElementById('party-color-input').value = this.dataset.color;
        document.getElementById('party-modal-overlay').style.display = 'flex';
    });
});
</script>

<!-- ===== IMPORT PARTY LISTS MODAL ===== -->
<div id="import-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9100;align-items:center;justify-content:center;overflow-y:auto;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:500px;box-shadow:0 8px 32px rgba(0,0,0,0.18);max-height:90vh;overflow-y:auto;margin:20px auto;">

        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <div style="font-size:15px;font-weight:600;color:#1F2937;">
                <i class="ti ti-file-spreadsheet" style="margin-right:6px;color:#059669;"></i> <?= t('ui.import_party_lists_via_excel_8da4', 'Import Party Lists via Excel') ?>
            </div>
            <button onclick="closeImportModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9CA3AF;">&times;</button>
        </div>

        <!-- Step 1: Download template -->
        <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
            <div style="font-size:13px;font-weight:600;color:#065F46;margin-bottom:6px;">
                <i class="ti ti-download" style="margin-right:4px;"></i> <?= t('ui.step_1_download_the_template_1a58', 'Step 1 — Download the template') ?>
            </div>
            <div style="font-size:12px;color:#047857;margin-bottom:10px;line-height:1.5;">
                <?= t('ui.fill_in_the_excel_template_with_1a76', 'Fill in the Excel template with') ?> <strong>name</strong> <?= t('ui.required_ec80', '(required),') ?> <strong>platform</strong><?= t('ui.and_8a0e', ', and') ?> <strong>color</strong> <?= t('ui.columns_7af3', 'columns.') ?><br>
                <?= t('ui.logos_can_t_be_imported_via_excel__5fd6', 'Logos can\'t be imported via Excel — add those individually via Edit after importing.') ?>
            </div>
            <a href="import-party-lists.php?template=1" class="btn btn-sm" style="background:#059669;color:#fff;gap:6px;">
                <i class="ti ti-table-export"></i> <?= t('ui.download_template_xlsx_68a5', 'Download Template (.xlsx)') ?>
            </a>
            <div style="font-size:11.5px;color:#065F46;margin-top:10px;">
                <i class="ti ti-info-circle" style="margin-right:3px;"></i> <?= t('ui.tip_already_have_a_party_lists_fil_8b08', 'Tip: already have a Party Lists file you exported earlier? You can edit that file directly and upload it in Step 2 — no need to start from the template.') ?>
            </div>
        </div>

        <!-- Step 2: Upload -->
        <div style="font-size:13px;font-weight:600;color:#1F2937;margin-bottom:10px;">
            <i class="ti ti-upload" style="margin-right:4px;color:#6B1A2A;"></i> <?= t('ui.step_2_upload_filled_excel_file_cce2', 'Step 2 — Upload filled Excel file') ?>
        </div>

        <!-- Drop zone -->
        <div id="party-drop-zone" style="border:2px dashed #D1D5DB;border-radius:10px;padding:24px;text-align:center;cursor:pointer;transition:border-color 0.2s,background 0.2s;margin-bottom:14px;"
             onclick="document.getElementById('party-excel-file-input').click()"
             ondragover="event.preventDefault();this.style.borderColor='#6B1A2A';this.style.background='#FDF2F4';"
             ondragleave="this.style.borderColor='#D1D5DB';this.style.background='';"
             ondrop="handlePartyDrop(event)">
            <i class="ti ti-file-spreadsheet" style="font-size:32px;color:#9CA3AF;display:block;margin-bottom:8px;"></i>
            <div id="party-drop-label" style="font-size:13px;color:#6B7280;"><?= t('ui.click_to_browse_or_drag_drop_your__9481', 'Click to browse or drag & drop your Excel file here') ?></div>
            <div style="font-size:11px;color:#9CA3AF;margin-top:4px;"><?= t('ui.xlsx_or_xls_max_5mb_45c7', '.xlsx or .xls · max 5MB') ?></div>
        </div>
        <input type="file" id="party-excel-file-input" accept=".xlsx,.xls" style="display:none;" onchange="handlePartyFileSelect(this.files[0])">

        <!-- Result area -->
        <div id="party-import-result" style="display:none;"></div>

        <!-- Actions -->
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px;">
            <button onclick="closeImportModal()" class="btn btn-outline btn-sm"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
            <button id="party-import-submit-btn" onclick="submitPartyImport()" class="btn btn-primary btn-sm" disabled>
                <i class="ti ti-eye"></i> <?= t('ui.preview_changes_b2e8', 'Preview Changes') ?>
            </button>
        </div>
    </div>
</div>

<script>
var _partyImportFile = null;

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
    _partyImportFile = null;
    document.getElementById('party-excel-file-input').value = '';
    document.getElementById('party-drop-label').textContent = __t('Click to browse or drag & drop your Excel file here');
    document.getElementById('party-drop-zone').style.borderColor = '#D1D5DB';
    document.getElementById('party-drop-zone').style.background  = '';
    document.getElementById('party-import-result').style.display = 'none';
    document.getElementById('party-import-result').innerHTML = '';
    document.getElementById('party-import-submit-btn').disabled = true;
    submitPartyImportReset();
}

function handlePartyFileSelect(file) {
    if (!file) return;
    _partyImportFile = file;
    document.getElementById('party-drop-label').innerHTML =
        '<strong style="color:#1F2937;">' + file.name + '</strong>' +
        ' <span style="color:#9CA3AF;">(' + (file.size / 1024).toFixed(1) + ' KB)</span>';
    document.getElementById('party-drop-zone').style.borderColor = '#059669';
    document.getElementById('party-drop-zone').style.background  = '#F0FDF4';
    document.getElementById('party-import-submit-btn').disabled  = false;
    document.getElementById('party-import-result').style.display = 'none';
    submitPartyImportReset();
}

function handlePartyDrop(event) {
    event.preventDefault();
    document.getElementById('party-drop-zone').style.borderColor = '#D1D5DB';
    document.getElementById('party-drop-zone').style.background  = '';
    var file = event.dataTransfer.files[0];
    if (file) handlePartyFileSelect(file);
}

// One button, two steps: __t("Preview Changes") first, then __t("Confirm Import").
var _partyPreview = false;

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
function submitPartyImportReset() {
    _partyPreview = false;
    var b = document.getElementById('party-import-submit-btn');
    b.innerHTML = '<i class="ti ti-eye"></i> Preview Changes';
}

function submitPartyImport() {
    if (!_partyImportFile) return;
    var isConfirm = _partyPreview;
    if (isConfirm && !window._partyGateOK) {
        AdminOtp.gate({
            op: 'import_party_lists', title: __t('Confirm party list import'), subtitle: __t('Import party lists from Excel'),
            body: __t('The party lists in this file will be added or updated after you confirm.'), confirmLabel: __t('Import'),
            proceed: function () { window._partyGateOK = true; try { submitPartyImport(); } finally { window._partyGateOK = false; } }
        });
        return;
    }

    var electionId = <?= $eid ?>;
    var btn = document.getElementById('party-import-submit-btn');
    var result = document.getElementById('party-import-result');

    btn.disabled = true;
    btn.innerHTML = '<i class="ti ti-loader"></i> ' + (isConfirm ? 'Importing...' : 'Checking...');
    if (!isConfirm) { result.innerHTML = appSkeletonHTML(3); result.style.display = 'block'; }

    var fd = new FormData();
    fd.append('excel_file', _partyImportFile);
    fd.append('election_id', electionId);
    if (!isConfirm) fd.append('preview', '1');

    fetch('import-party-lists.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;

            if (!data.success) {
                submitPartyImportReset();
                result.innerHTML = _impErrorHTML(data.message || __t('Something went wrong.'));
                result.style.display = 'block';
                return;
            }

            // ── Step 1 result: show what WOULD happen, wait for confirmation ──
            if (data.preview) {
                var nUpd = data.updates || 0;
                if (data.new === 0 && nUpd === 0) {
                    submitPartyImportReset();
                    result.innerHTML =
                        '<div style="background:#F3F4F6;border:1px solid #E5E7EB;border-radius:8px;padding:12px 14px;color:#374151;font-size:13px;">' +
                        __t('Nothing to import — no new or changed party list(s) found in this file') + (data.unchanged ? ' (' + data.unchanged + ' unchanged).' : '.') + '</div>' + _impSkippedHTML(data.skipped);
                    result.style.display = 'block';
                    return;
                }
                var parts = [];
                if (data.new) parts.push(data.new + ' will be added');
                if (nUpd)     parts.push(nUpd + ' will be updated');
                var th = 'padding:8px 10px;', td = 'padding:8px 10px;';
                var html =
                    '<div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:12px 14px;margin-bottom:10px;">' +
                    '<i class="ti ti-info-circle" style="color:#1D4ED8;margin-right:6px;"></i>' +
                    '<strong style="color:#1E3A8A;">' + parts.join(', ') + '.</strong> ' +
                    '<span style="color:#1E40AF;font-size:12px;">Nothing is saved yet — review below, then click Confirm Import.' +
                    (data.unchanged ? ' (' + data.unchanged + ' unchanged row(s) ignored.)' : '') + '</span></div>' +
                    '<div style="max-height:220px;overflow:auto;border:1px solid #E5E7EB;border-radius:8px;">' +
                    '<table style="width:100%;border-collapse:collapse;font-size:12px;">' +
                    '<thead><tr style="background:#F9FAFB;text-align:left;"><th style="' + th + '">Action</th><th style="' + th + '">Row</th><th style="' + th + '">Party List</th><th style="' + th + '">Platform</th><th style="' + th + '">Color</th></tr></thead><tbody>' +
                    (data.update_rows || []).map(function(r) {
                        return '<tr style="border-top:1px solid #F3F4F6;"><td style="' + td + 'color:#B45309;font-weight:600;">Update</td><td style="' + td + '">' + _impEsc(r.row) + '</td><td style="' + td + '">' +
                            (r.old_name !== r.name ? '<span style="color:#9CA3AF;text-decoration:line-through;">' + _impEsc(r.old_name) + '</span> → ' : '') + _impEsc(r.name) +
                            '</td><td style="' + td + '">' + _impEsc(r.platform || "—") + '</td><td style="' + td + '">' + _impEsc(r.color) + '</td></tr>';
                    }).join('') +
                    data.rows.map(function(r) {
                        return '<tr style="border-top:1px solid #F3F4F6;"><td style="' + td + 'color:#059669;font-weight:600;">Add</td><td style="' + td + '">' + _impEsc(r.row) + '</td><td style="' + td + '">' + _impEsc(r.name) + '</td><td style="' + td + '">' + _impEsc(r.platform || "—") + '</td><td style="' + td + '">' + _impEsc(r.color) + '</td></tr>';
                    }).join('') + '</tbody></table></div>';
                if (data.truncated) html += '<div style="font-size:11px;color:#6B7280;margin-top:6px;">Showing the first 500 rows of each type only. All will be applied.</div>';
                html += _impSkippedHTML(data.skipped);
                result.innerHTML = html;
                result.style.display = 'block';
                _partyPreview = true;
                btn.innerHTML = '<i class="ti ti-check"></i> Confirm Import (' + (data.new + nUpd) + ')';
                return;
            }

            // ── Step 2 result: actually saved ──
            _partyPreview = false;
            btn.innerHTML = '<i class="ti ti-check"></i> Done';
            btn.disabled = true;
            var ok = '<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:12px 14px;">' +
                '<i class="ti ti-circle-check" style="color:#059669;margin-right:6px;"></i>' +
                '<strong style="color:#065F46;">' + data.added + ' added, ' + (data.updated || 0) + ' updated successfully!</strong></div>';
            result.innerHTML = ok + _impSkippedHTML(data.skipped).replace('will be skipped', 'skipped');
            result.style.display = 'block';
            if ((data.added || 0) + (data.updated || 0) > 0) setTimeout(function() { location.reload(); }, 2000);
        })
        .catch(function() {
            btn.disabled = false;
            submitPartyImportReset();
            result.innerHTML = _impErrorHTML(__t('Network error. Please try again.'));
            result.style.display = 'block';
        });
}
</script>

<!-- ===== CUSTOM CONFIRM MODAL ===== -->
<div id="gnc-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:28px 28px 22px;width:100%;max-width:380px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div style="width:42px;height:42px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px;">
                <i class="ti ti-flag-off" style="color:#991B1B;"></i>
            </div>
            <div>
                <div id="gnc-modal-title" style="font-size:15px;font-weight:600;color:#1F2937;"></div>
                <div id="gnc-modal-body" style="font-size:13px;color:#6B7280;margin-top:3px;"></div>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
            <button onclick="gncCloseModal()" class="btn btn-outline btn-sm"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
            <button id="gnc-modal-confirm-btn" class="btn btn-danger btn-sm"><?= t('ui.delete_f2a6', 'Delete') ?></button>
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

function confirmDeleteParty(id, name) {
    gncOpenModal(
        __t('Delete Party List'),
        'Delete party list "' + name + __t('"? Its candidates will become Independent.'),
        function() { postAction({ delete: id }); }
    );
}
</script>
<script src="../assets/js/client-pager.js?v=3"></script>
<script src="../assets/js/bulk-select.js?v=5"></script>
<script>
(function () {
  var t = document.getElementById('party-table'); if (!t) return;
  BulkSelect.init({
    rows: '#party-table tbody tr[data-bulk-id]', before: t.closest('.table-wrap'), noun: 'party list', exportType: 'party_lists',
    actions: [{ label: __t('Delete'), icon: 'ti-trash', danger: true,
      confirm: function (n) { return __t('Permanently delete ') + n + __t(' party list(s)? Their candidates become independent.'); },
      run: function (id) { return fetch('party-lists.php?election=<?= (int)$eid ?>', { method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'delete=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || '') }).then(function (r) { return r.ok; }); } }]
  });
})();
</script>
<script src="../assets/js/sidebar.js?v=5"></script>
<?php include __DIR__.'/../includes/push-widget.php'; ?>
<script>window.CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;</script>
<script src="../assets/js/admin-otp.js?v=2"></script>
<script>
document.getElementById('party-form').addEventListener('submit', function (e) {
    var form = this;
    if (e.defaultPrevented || form.dataset.otpOk === '1') return;
    var pid = form.querySelector('input[name="party_id"]').value;
    if (!pid) return;                       // adding a new party list needs no code
    e.preventDefault();
    AdminOtp.confirmThen({
        op: 'edit_party',
        targetId: pid,
        title: __t('Save Party List Changes'),
        subtitle: document.getElementById('party-name-input').value,
        body: __t('This party list will be changed after you confirm.'),
        confirmLabel: __t('Save Changes')
    }, function () { form.dataset.otpOk = '1'; form.submit(); });
});
</script>
<script>window.CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;</script>
<script src="../assets/js/post-action.js"></script>
</body></html>

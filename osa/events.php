<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/csrf.php';
require_once '../includes/facebook.php';
requireAdmin();
$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);

$success = $error = '';

// Read and clear flash messages
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']);   }

// DELETE
if (isset($_GET['delete'])) {
    requireCsrf();
    $pdo->prepare("DELETE FROM events WHERE id=?")->execute([(int)$_GET['delete']]);
    $_SESSION['flash_success'] = 'Event deleted.';
    header('Location: events.php'); exit;
}

// TOGGLE CANCEL
if (isset($_GET['toggle_cancel'])) {
    requireCsrf();
    $pdo->prepare("UPDATE events SET is_cancelled = 1 - is_cancelled WHERE id=?")->execute([(int)$_GET['toggle_cancel']]);
    $_SESSION['flash_success'] = 'Event status updated.';
    header('Location: events.php'); exit;
}

// ADD / EDIT (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $event_id    = (int)($_POST['event_id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $event_date  = trim($_POST['event_date'] ?? '');
    $end_date    = trim($_POST['end_date'] ?? '');
    $event_time  = trim($_POST['event_time'] ?? '');
    $location    = trim($_POST['location'] ?? '');
    $notify      = isset($_POST['notify_students']) ? 1 : 0;
    $postToFb    = isset($_POST['post_to_facebook']) ? 1 : 0;

    if ($title === '' || $event_date === '') {
        $_SESSION['flash_error'] = 'Title and event date are required.';
    } else {
        $image = null;
        if (!empty($_FILES['image']['name'])) {
            $up = uploadPhoto($_FILES['image']);
            if (isset($up['error'])) { $_SESSION['flash_error'] = $up['error']; }
            else { $image = $up['filename']; }
        }

        if (!isset($_SESSION['flash_error'])) {
            $endDateVal = $end_date !== '' ? $end_date : null;
            $timeVal    = $event_time !== '' ? $event_time : null;

            if ($event_id) {
                if ($image) {
                    $pdo->prepare("UPDATE events SET title=?, description=?, event_date=?, end_date=?, event_time=?, location=?, image=? WHERE id=?")
                        ->execute([$title, $description, $event_date, $endDateVal, $timeVal, $location, $image, $event_id]);
                } else {
                    $pdo->prepare("UPDATE events SET title=?, description=?, event_date=?, end_date=?, event_time=?, location=? WHERE id=?")
                        ->execute([$title, $description, $event_date, $endDateVal, $timeVal, $location, $event_id]);
                }
                $_SESSION['flash_success'] = 'Event updated!';
            } else {
                $pdo->prepare("INSERT INTO events (title, description, event_date, end_date, event_time, location, image, created_by_type, created_by_id, created_by_name) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$title, $description, $event_date, $endDateVal, $timeVal, $location, $image, 'admin', $_SESSION['user_id'], $_SESSION['name']]);
                $newEventId = (int)$pdo->lastInsertId();
                $_SESSION['flash_success'] = 'Event created!';

                if ($notify) {
                    $students = $pdo->query("SELECT id FROM students WHERE is_active = 1")->fetchAll();
                    $msg = 'New event: ' . $title . ' — ' . date('M j, Y', strtotime($event_date));
                    foreach ($students as $s) {
                        addNotification($pdo, $s['id'], $msg, '../student/events.php');
                    }
                }

                // Optionally push this event to the school's Facebook Page.
                if ($postToFb) {
                    $fbMessage = $title . "\n\n";
                    if ($description !== '') { $fbMessage .= $description . "\n\n"; }
                    $fbMessage .= '📅 ' . date('F j, Y', strtotime($event_date));
                    if ($endDateVal && $endDateVal !== $event_date) { $fbMessage .= ' – ' . date('F j, Y', strtotime($endDateVal)); }
                    if ($timeVal) { $fbMessage .= ' · ' . date('g:i A', strtotime($timeVal)); }
                    if ($location !== '') { $fbMessage .= "\n📍 " . $location; }

                    $fbImagePath = $image ? (__DIR__ . '/../uploads/' . $image) : null;
                    $fbResult = fbPostToPage($fbMessage, null, $fbImagePath);
                    if ($fbResult['ok']) {
                        $pdo->prepare("UPDATE events SET fb_post_id = ? WHERE id = ?")->execute([$fbResult['post_id'], $newEventId]);
                        $_SESSION['flash_success'] = 'Event created and posted to Facebook!';
                    } else {
                        // Event still saved successfully — just flag that the FB push failed.
                        $_SESSION['flash_success'] = 'Event created! (Facebook post failed: ' . $fbResult['error'] . ')';
                    }
                }
            }
        }
    }
    header('Location: events.php'); exit;
}

$filter = $_GET['status'] ?? 'all';
$events = $pdo->query("SELECT * FROM events ORDER BY event_date DESC, id DESC")->fetchAll();

// Facebook Page feed (cached — see includes/facebook.php)
$fbRefresh = isset($_GET['fb_refresh']);
if ($fbRefresh) { session_write_close(); }   // the manual refresh can take a few seconds: don't block other pages
$fbFeed = fbGetPagePosts($pdo, 6, $fbRefresh, $fbRefresh);   // page loads use the cache only; JS below refreshes it in the background
if ($fbRefresh) { header('Location: events.php'); exit; }

// Compute status for each + apply filter
$filtered = [];
foreach ($events as $ev) {
    $ev['_status'] = eventStatus($ev);
    if ($filter === 'all' || strtolower($filter) === strtolower($ev['_status'])) {
        $filtered[] = $ev;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events — GNC-OSA Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
</head>
<body class="theme-events">
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Admin</div><div class="sub">Administrator</div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><div class="avatar">AD</div><span class="uname"><?= e($_SESSION['name']) ?></span></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section">Overview</div>
    <a class="sitem" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <div class="sidebar-section">Complaints</div>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> All Complaints</a>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="elections.php"><i class="ti ti-trophy"></i> Elections</a>
    <a class="sitem" href="candidates.php"><i class="ti ti-users"></i> Candidates</a>
    <a class="sitem" href="party-lists.php"><i class="ti ti-flag"></i> Party Lists</a>
    <a class="sitem" href="vouchers.php"><i class="ti ti-ticket"></i> <?= t('sidebar.vouchers', 'Vouchers') ?></a>
    <a class="sitem" href="results.php"><i class="ti ti-chart-pie"></i> Live Results</a>
    <div class="sidebar-section">Events</div>
    <a class="sitem active" href="events.php"><i class="ti ti-calendar-event"></i> Events</a>
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
    <div class="events-banner" style="position:relative;width:100%;height:clamp(140px,28vw,300px);border-radius:12px;overflow:hidden;margin-bottom:20px;background:#6B1A2B;"><img src="../assets/img/gnc-banner.jpg" alt="Guagua National Colleges" style="width:100%;height:100%;object-fit:cover;object-position:center 35%;display:block;"><div style="position:absolute;inset:0;background:rgba(0,0,0,0.35);"></div></div>
    <div class="page-header"><h1><i class="ti ti-calendar-event"></i> Events</h1><p>Create and manage school events &amp; announcements</p></div>
    <?php if ($success): ?><div class="alert alert-success"><i class="ti ti-check"></i><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><i class="ti ti-alert-circle"></i><?= e($error) ?></div><?php endif; ?>

    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="?status=all" class="btn btn-sm <?= $filter==='all'?'btn-primary':'btn-outline' ?>">All</a>
                <a href="?status=upcoming" class="btn btn-sm <?= $filter==='upcoming'?'btn-primary':'btn-outline' ?>">Upcoming</a>
                <a href="?status=ongoing" class="btn btn-sm <?= $filter==='ongoing'?'btn-primary':'btn-outline' ?>">Ongoing</a>
                <a href="?status=completed" class="btn btn-sm <?= $filter==='completed'?'btn-primary':'btn-outline' ?>">Completed</a>
                <a href="?status=cancelled" class="btn btn-sm <?= $filter==='cancelled'?'btn-primary':'btn-outline' ?>">Cancelled</a>
            </div>
            <button onclick="openAddEventModal()" class="btn btn-primary btn-sm" style="display:flex;align-items:center;gap:6px;white-space:nowrap;">
                <i class="ti ti-circle-plus"></i> Add Event
            </button>
        </div>

        <?php if (empty($filtered)): ?>
            <div style="text-align:center;padding:48px 0;color:#9CA3AF;">
                <i class="ti ti-calendar-event" style="font-size:36px;display:block;margin-bottom:10px;opacity:0.5;"></i>
                <?php if ($filter === 'all'): ?>
                    <p style="font-size:13px;margin-bottom:14px;">No events yet.</p>
                    <button onclick="openAddEventModal()" class="btn btn-primary btn-sm"><i class="ti ti-circle-plus"></i> Add your first event</button>
                <?php else: ?>
                    <p style="font-size:13px;">No <?= e(strtolower($filter)) ?> events found.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div class="event-grid" data-more-group="events"><?php $evIdx = 0; foreach ($filtered as $ev): $evIdx++; ?>
            <div class="event-card<?= $ev['is_cancelled'] ? ' is-cancelled' : '' ?><?= $evIdx > 3 ? ' more-item' : '' ?>">
                <div class="event-card-cover">
                    <?php if ($ev['image']): ?>
                        <img src="../uploads/<?= e($ev['image']) ?>" alt="">
                    <?php else: ?>
                        <div class="no-image-icon"><img class="no-image-logo" src="../assets/img/osa-logo.jpg" alt=""></div>
                    <?php endif; ?>
                    <div class="event-date-chip">
                        <span class="m"><?= date('M', strtotime($ev['event_date'])) ?></span>
                        <span class="d"><?= date('j', strtotime($ev['event_date'])) ?></span>
                    </div>
                    <div class="event-status-pill"><?= eventStatusBadge($ev['_status']) ?></div>
                    <?php if (!empty($ev['fb_post_id'])): ?><div class="fb-pill" title="Posted to Facebook"><i class="ti ti-brand-facebook"></i></div><?php endif; ?>
                </div>
                <div class="event-card-body">
                    <div class="event-card-title"><?= e($ev['title']) ?></div>
                    <?php if (!empty($ev['description'])): ?><div class="event-card-desc"><?= e($ev['description']) ?></div><?php endif; ?>
                    <div class="event-card-meta">
                        <span><i class="ti ti-calendar"></i>
                            <?= date('M j, Y', strtotime($ev['event_date'])) ?><?php if (!empty($ev['end_date']) && $ev['end_date'] !== $ev['event_date']): ?> &ndash; <?= date('M j, Y', strtotime($ev['end_date'])) ?><?php endif; ?>
                            <?php if (!empty($ev['event_time'])): ?> · <?= date('g:i A', strtotime($ev['event_time'])) ?><?php endif; ?>
                        </span>
                        <?php if (!empty($ev['location'])): ?><span><i class="ti ti-map-pin"></i> <?= e($ev['location']) ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="event-card-footer">
                    <span class="event-card-poster"><?= e($ev['created_by_name']) ?> (<?= e(ucfirst($ev['created_by_type'])) ?>)</span>
                    <div class="event-card-actions">
                        <button type="button" class="btn btn-outline btn-sm edit-event-btn" title="Edit"
                            data-id="<?= $ev['id'] ?>"
                            data-title="<?= e($ev['title']) ?>"
                            data-description="<?= e($ev['description'] ?? '') ?>"
                            data-date="<?= e($ev['event_date']) ?>"
                            data-enddate="<?= e($ev['end_date'] ?? '') ?>"
                            data-time="<?= e($ev['event_time'] ?? '') ?>"
                            data-location="<?= e($ev['location'] ?? '') ?>">
                            <i class="ti ti-edit"></i>
                        </button>
                        <a href="events.php?toggle_cancel=<?= $ev['id'] ?>&<?= csrfParam() ?>" class="btn btn-outline btn-sm" title="<?= $ev['is_cancelled'] ? 'Restore' : 'Cancel' ?>">
                            <i class="ti ti-<?= $ev['is_cancelled'] ? 'refresh' : 'ban' ?>"></i>
                        </a>
                        <button type="button" class="btn btn-danger btn-sm" title="Delete" onclick="confirmDeleteEvent(<?= $ev['id'] ?>, '<?= e($ev['title']) ?>')"><i class="ti ti-trash"></i></button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?></div>
        <?php if (count($filtered) > 3): ?>
        <div class="more-wrap"><button type="button" class="btn btn-outline btn-sm more-toggle" data-more-toggle="events" data-label-more="Show all (<?= count($filtered) ?>)" data-label-less="Show less"><i class="ti ti-chevron-down"></i> <span>Show all (<?= count($filtered) ?>)</span></button></div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ===== FACEBOOK PAGE FEED ===== -->
    <div class="card" style="margin-top:20px;">
        <div class="card-title" style="justify-content:space-between;">
            <span><i class="ti ti-brand-facebook" style="color:#1877F2;"></i> Facebook Page Feed</span>
            <button type="button" id="fb-feed-refresh-btn" class="btn btn-outline btn-sm" onclick="fbFeedRefresh()"><i class="ti ti-refresh"></i> Refresh</button>
        </div>
        <div id="fb-feed-content">
            <?php $fbUploadsPrefix = '../uploads/'; include __DIR__.'/../includes/fb-feed-partial.php'; ?>
        </div>
    </div>
</div></div>

<script>
// Auto-refresh the Facebook feed in the background so admins don't have
// to manually click Refresh. fb-feed-ajax.php reuses the same cache logic
// as the initial load, so polling this often does NOT spam Facebook's API.
(function() {
    var container = document.getElementById('fb-feed-content');
    var btn = document.getElementById('fb-feed-refresh-btn');

    function loadFeed() {
        fetch('fb-feed-ajax.php', { credentials: 'same-origin' })
            .then(function(res) { return res.text(); })
            .then(function(html) { container.innerHTML = html; })
            .catch(function() { /* silent fail — keep showing whatever's there */ });
    }

    window.fbFeedRefresh = function() {
        btn.disabled = true;
        var icon = btn.querySelector('i');
        if (icon) icon.classList.add('ti-spin');
        fetch('events.php?fb_refresh=1', { credentials: 'same-origin' })
            .then(function() { loadFeed(); })
            .finally(function() {
                btn.disabled = false;
                if (icon) icon.classList.remove('ti-spin');
            });
    };

    loadFeed();                   // fetch the latest feed right after the page shows
    setInterval(loadFeed, 60000); // poll every 60 seconds
})();
</script>

<!-- ===== ADD/EDIT EVENT MODAL ===== -->
<div id="event-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9100;align-items:center;justify-content:center;overflow-y:auto;padding:16px;">
    <div class="modal-panel" style="max-width:500px;">
        <div class="modal-panel-header">
            <div class="title-wrap">
                <div class="icon-circle"><i class="ti ti-circle-plus" id="event-modal-icon"></i></div>
                <div class="title-text">
                    <div class="t" id="event-modal-title">Add Event</div>
                    <div class="s" id="event-modal-subtitle">Create a new event or announcement</div>
                </div>
            </div>
            <button type="button" onclick="closeEventModal()" class="close-btn"><i class="ti ti-x"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="event-form">
            <div class="modal-panel-body">
                <?= csrfField() ?>
                <input type="hidden" name="event_id" id="event-id-input" value="">

                <div class="section-label"><i class="ti ti-info-circle"></i> Details</div>
                <div class="form-group">
                    <label>Event Title *</label>
                    <div class="field-icon-input">
                        <i class="ti ti-typography"></i>
                        <input type="text" name="title" id="event-title-input" class="form-control" placeholder="ex. Foundation Week 2026" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Description (optional)</label>
                    <textarea name="description" id="event-description-input" class="form-control" placeholder="Short details about the event..."></textarea>
                </div>

                <div class="section-label"><i class="ti ti-calendar-time"></i> Schedule &amp; Location</div>
                <div class="ev-row" style="display:flex;gap:10px;">
                    <div class="form-group" style="flex:1;">
                        <label>Start Date *</label>
                        <div class="field-icon-input">
                            <i class="ti ti-calendar"></i>
                            <input type="date" name="event_date" id="event-date-input" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>End Date (optional)</label>
                        <div class="field-icon-input">
                            <i class="ti ti-calendar"></i>
                            <input type="date" name="end_date" id="event-enddate-input" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="ev-row" style="display:flex;gap:10px;">
                    <div class="form-group" style="flex:1;">
                        <label>Time (optional)</label>
                        <div class="field-icon-input">
                            <i class="ti ti-clock"></i>
                            <input type="time" name="event_time" id="event-time-input" class="form-control">
                        </div>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Location (optional)</label>
                        <div class="field-icon-input">
                            <i class="ti ti-map-pin"></i>
                            <input type="text" name="location" id="event-location-input" class="form-control" placeholder="ex. Gymnasium">
                        </div>
                    </div>
                </div>

                <div class="section-label"><i class="ti ti-photo"></i> Cover Photo</div>
                <div class="form-group">
                    <label class="file-drop" id="file-drop-label">
                        <input type="file" name="image" id="event-image-input" accept=".jpg,.jpeg,.png" onchange="handleFilePreview(this)">
                        <div class="file-drop-icon"><i class="ti ti-cloud-upload" id="file-drop-icon"></i></div>
                        <div class="file-drop-text">
                            <div class="file-drop-main-text" id="file-drop-main">Click to upload cover photo</div>
                            <div class="sub" id="file-drop-sub">JPG or PNG · max 2MB</div>
                        </div>
                    </label>
                </div>

                <div class="section-label"><i class="ti ti-bell-ringing"></i> Sharing</div>
                <div id="notify-row">
                    <label class="toggle-option">
                        <span class="to-label"><i class="ti ti-users"></i> Notify all students about this event</span>
                        <span class="toggle-switch"><input type="checkbox" name="notify_students" value="1"><span class="slider"></span></span>
                    </label>
                </div>
                <div id="post-fb-row">
                    <label class="toggle-option">
                        <span class="to-label"><i class="ti ti-brand-facebook" style="color:#1877F2;"></i> Also post to Facebook Page</span>
                        <span class="toggle-switch"><input type="checkbox" name="post_to_facebook" value="1"><span class="slider"></span></span>
                    </label>
                </div>
            </div>
            <div class="modal-panel-footer">
                <button type="button" onclick="closeEventModal()" class="btn btn-outline btn-sm">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-device-floppy"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddEventModal() {
    document.getElementById('event-modal-title').textContent = 'Add Event';
    document.getElementById('event-modal-subtitle').textContent = 'Create a new event or announcement';
    document.getElementById('event-modal-icon').className = 'ti ti-circle-plus';
    resetFilePreview();
    document.getElementById('event-id-input').value = '';
    document.getElementById('event-title-input').value = '';
    document.getElementById('event-description-input').value = '';
    document.getElementById('event-date-input').value = '';
    document.getElementById('event-enddate-input').value = '';
    document.getElementById('event-time-input').value = '';
    document.getElementById('event-location-input').value = '';
    document.getElementById('notify-row').style.display = 'block';
    document.getElementById('post-fb-row').style.display = 'block';
    document.getElementById('event-modal-overlay').style.display = 'flex';
}
function closeEventModal() {
    document.getElementById('event-modal-overlay').style.display = 'none';
}
document.getElementById('event-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeEventModal();
});
document.querySelectorAll('.edit-event-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('event-modal-title').textContent = 'Edit Event';
        document.getElementById('event-modal-subtitle').textContent = 'Update this event\'s details';
        document.getElementById('event-modal-icon').className = 'ti ti-edit';
        resetFilePreview();
        document.getElementById('event-id-input').value = this.dataset.id;
        document.getElementById('event-title-input').value = this.dataset.title;
        document.getElementById('event-description-input').value = this.dataset.description;
        document.getElementById('event-date-input').value = this.dataset.date;
        document.getElementById('event-enddate-input').value = this.dataset.enddate;
        document.getElementById('event-time-input').value = this.dataset.time;
        document.getElementById('event-location-input').value = this.dataset.location;
        document.getElementById('notify-row').style.display = 'none'; // only notify on create
        document.getElementById('post-fb-row').style.display = 'none'; // only post-to-FB on create
        document.getElementById('event-modal-overlay').style.display = 'flex';
    });
});

function handleFilePreview(input) {
    var label = document.getElementById('file-drop-label');
    var icon  = document.getElementById('file-drop-icon');
    var main  = document.getElementById('file-drop-main');
    var sub   = document.getElementById('file-drop-sub');
    if (input.files && input.files.length > 0) {
        label.classList.add('has-file');
        icon.className = 'ti ti-circle-check';
        main.textContent = input.files[0].name;
        sub.textContent = 'Click to choose a different photo';
    } else {
        resetFilePreview();
    }
}
function resetFilePreview() {
    var input = document.getElementById('event-image-input');
    var label = document.getElementById('file-drop-label');
    if (input) input.value = '';
    if (label) label.classList.remove('has-file');
    document.getElementById('file-drop-icon').className = 'ti ti-cloud-upload';
    document.getElementById('file-drop-main').textContent = 'Click to upload cover photo';
    document.getElementById('file-drop-sub').textContent = 'JPG or PNG · max 2MB';
}
</script>

<!-- ===== CUSTOM CONFIRM MODAL ===== -->
<div id="gnc-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:28px 28px 22px;width:100%;max-width:380px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div style="width:42px;height:42px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px;">
                <i class="ti ti-calendar-off" style="color:#991B1B;"></i>
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

function confirmDeleteEvent(id, title) {
    gncOpenModal(
        'Delete Event',
        'Delete event "' + title + '"? This cannot be undone.',
        function() { window.location.href = 'events.php?delete=' + id + '&<?= csrfParam() ?>'; }
    );
}
</script>
<script src="../assets/js/sidebar.js?v=5"></script>
<script src="../assets/js/show-more.js"></script>
<?php include __DIR__.'/../includes/push-widget.php'; ?>
</body></html>
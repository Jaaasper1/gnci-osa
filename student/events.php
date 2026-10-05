<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/facebook.php';
requireStudent();

$student_id = $_SESSION['user_id'];
$name       = $_SESSION['name'];
$notifCount = getUnreadNotifCount($pdo, $student_id);
$election   = getAnyActiveOrRecentElection($pdo);

$filter = $_GET['status'] ?? 'all';
$events = $pdo->query("SELECT * FROM events ORDER BY event_date ASC, id DESC")->fetchAll();

// Facebook Page feed (cached — see includes/facebook.php)
$fbRefresh = isset($_GET['fb_refresh']);
if ($fbRefresh) { session_write_close(); }   // the manual refresh can take a few seconds: don't block other pages
$fbFeed = fbGetPagePosts($pdo, 6, $fbRefresh, $fbRefresh);   // page loads use the cache only; JS below refreshes it in the background
if ($fbRefresh) { header('Location: events.php'); exit; }

// Counts for the filter tabs (Cancelled events are not listed under "All")
$evCounts = ['all' => 0, 'ongoing' => 0, 'upcoming' => 0, 'completed' => 0];
foreach ($events as $ev0) {
    $st0 = strtolower(eventStatus($ev0));
    if (isset($evCounts[$st0])) { $evCounts[$st0]++; $evCounts['all']++; }
}

$filtered = [];
foreach ($events as $ev) {
    $ev['_status'] = eventStatus($ev);
    if ($filter === 'all' || strtolower($filter) === strtolower($ev['_status'])) {
        $filtered[] = $ev;
    }
}
// Show upcoming/ongoing first when viewing "all"
if ($filter === 'all') {
    usort($filtered, function($a, $b) {
        $order = ['Ongoing' => 0, 'Upcoming' => 1, 'Completed' => 2, 'Cancelled' => 3];
        $oa = $order[$a['_status']] ?? 4;
        $ob = $order[$b['_status']] ?? 4;
        if ($oa !== $ob) return $oa <=> $ob;
        return strtotime($a['event_date']) <=> strtotime($b['event_date']);
    });
}

// Past events: most recent first
if ($filter === 'completed') {
    usort($filtered, function($a, $b) { return strtotime($b['event_date']) <=> strtotime($a['event_date']); });
}

// ── UI helpers for the redesigned cards ────────────────────────────────────
function evDays(string $from, string $to): int { return (int)round((strtotime($to) - strtotime($from)) / 86400); }
/** Human-friendly timing: "Happening now", "Tomorrow", "In 5 days", "Ended 3 days ago" … */
function evWhen(array $ev): string {
    $today = date('Y-m-d');
    $start = $ev['event_date'];
    $end   = !empty($ev['end_date']) ? $ev['end_date'] : $start;
    switch ($ev['_status']) {
        case 'Cancelled': return 'Cancelled';
        case 'Ongoing':
            $left = evDays($today, $end);
            return $left <= 0 ? 'Happening now · ends today' : 'Happening now · ' . $left . ' day' . ($left > 1 ? 's' : '') . ' left';
        case 'Upcoming':
            $d = evDays($today, $start);
            if ($d === 1) return 'Tomorrow';
            if ($d < 14)  return 'In ' . $d . ' days';
            if ($d < 60)  return 'In ' . (int)round($d / 7) . ' weeks';
            return 'In ' . (int)round($d / 30) . ' months';
        default:
            $d = evDays($end, $today);
            if ($d <= 1) return 'Ended yesterday';
            if ($d < 14) return 'Ended ' . $d . ' days ago';
            if ($d < 60) return 'Ended ' . (int)round($d / 7) . ' weeks ago';
            return 'Ended ' . (int)round($d / 30) . ' months ago';
    }
}
function evRange(array $ev): string {
    $t = date('M j, Y', strtotime($ev['event_date']));
    if (!empty($ev['end_date']) && $ev['end_date'] !== $ev['event_date']) $t .= ' – ' . date('M j, Y', strtotime($ev['end_date']));
    return $t;
}
/** Section header for the list. "All" is grouped by status, other tabs by month. */
function evGroup(array $ev, string $filter): string {
    $month = date('F Y', strtotime($ev['event_date']));
    if ($filter !== 'all') return $month;
    switch ($ev['_status']) {
        case 'Ongoing':   return 'Happening now';
        case 'Upcoming':  return 'Upcoming · ' . $month;
        default:          return 'Past · ' . $month;
    }
}

// Featured banner: the soonest ongoing / upcoming event (not shown on the Past tab)
$visibleAll = array_values(array_filter($filtered, function ($x) use ($filter) { return !($x['_status'] === 'Cancelled' && $filter === 'all'); }));
$featured = null;
if ($filter !== 'completed' && !empty($visibleAll) && in_array($visibleAll[0]['_status'], ['Ongoing', 'Upcoming'], true)) {
    $featured = $visibleAll[0];
}
$listEvents = $featured ? array_slice($visibleAll, 1) : $visibleAll;

// Data for the "View" popup (formatted server-side; rendered with textContent in JS)
$evModalData = [];
foreach ($filtered as $ev) {
    $dateTxt = date('F j, Y', strtotime($ev['event_date']));
    if (!empty($ev['end_date']) && $ev['end_date'] !== $ev['event_date']) {
        $dateTxt .= ' – ' . date('F j, Y', strtotime($ev['end_date']));
    }
    $evModalData[(int)$ev['id']] = [
        'title'  => $ev['title'],
        'status' => $ev['_status'],
        'badge'  => eventStatusBadge($ev['_status']),
        'date'   => $dateTxt,
        'time'   => !empty($ev['event_time']) ? date('g:i A', strtotime($ev['event_time'])) : '',
        'place'  => (string)($ev['location'] ?? ''),
        'desc'   => (string)($ev['description'] ?? ''),
        'image'  => !empty($ev['image']) ? '../uploads/' . $ev['image'] : '',
    ];
}
$openEventId = isset($_GET['event']) ? (int)$_GET['event'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Events — GNC-OSA Portal</title>
<link rel="stylesheet" href="../assets/css/style.css?v=27">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
</head>
<body class="theme-events">
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Portal</div><div class="sub">Student</div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?>
        <button class="notif-btn" onclick="location.href='notifications.php'">
            <i class="ti ti-bell"></i>
            <?php if ($notifCount > 0): ?><span class="notif-badge"><?= $notifCount ?></span><?php endif; ?>
        </button>
        <div style="display:flex;align-items:center;gap:8px;">
            <div class="avatar"><?= strtoupper(substr($name, 0, 2)) ?></div>
            <span class="uname"><?= htmlspecialchars($name) ?></span>
        </div>
    </div>
</nav>

<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div>
    <a class="sitem active" href="events.php"><i class="ti ti-calendar-event"></i> Events</a>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?></a>
    <a class="sitem" href="new-complaint.php"><i class="ti ti-circle-plus"></i> <?= t('sidebar.new_complaint', 'New Complaint') ?></a>
    <?php if ($election): ?>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="voting.php"><i class="ti ti-checkbox"></i> <?= t('sidebar.vote_now', 'Vote Now') ?></a>
    <?php endif; ?>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="settings.php"><i class="ti ti-settings"></i> <?= t('nav.settings', 'Settings') ?></a>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('nav.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>

<div class="main">
    <div class="content">
        <div class="page-header">
            <h1><i class="ti ti-calendar-event"></i> Events</h1>
            <p>School events and announcements from OSA</p>
        </div>

        <div class="ev-tabs" role="tablist">
            <?php foreach (['all' => ['All', 'ti-layout-grid'], 'ongoing' => ['Ongoing', 'ti-player-play'], 'upcoming' => ['Upcoming', 'ti-calendar-time'], 'completed' => ['Past', 'ti-history']] as $k => [$lbl, $ic]): ?>
            <a href="?status=<?= $k ?>" class="ev-tab<?= $filter === $k ? ' active' : '' ?>" role="tab" aria-selected="<?= $filter === $k ? 'true' : 'false' ?>">
                <i class="ti <?= $ic ?>"></i> <?= $lbl ?> <span class="cnt"><?= (int)$evCounts[$k] ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($filtered)): ?>
            <div class="card" style="text-align:center;padding:40px 20px;color:#9CA3AF;">
                <i class="ti ti-calendar-off" style="font-size:32px;display:block;margin-bottom:10px;"></i>
                No events to show yet. Check back soon!
            </div>
        <?php else: ?>

        <?php if ($featured): $ev = $featured; $isLiveEv = $ev['_status'] === 'Ongoing'; ?>
        <div class="ev-hero" tabindex="0" role="button" aria-label="View event: <?= e($ev['title']) ?>" onclick="openEventModal(<?= (int)$ev['id'] ?>)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openEventModal(<?= (int)$ev['id'] ?>);}">
            <?php if ($ev['image']): ?><img class="ev-hero-img" src="../uploads/<?= e($ev['image']) ?>" alt="">
            <?php else: ?><div class="ev-hero-ph"><img src="../assets/img/osa-logo.jpg" alt=""></div><?php endif; ?>
            <div class="ev-hero-shade"></div>
            <div class="ev-hero-body">
                <span class="ev-when<?= $isLiveEv ? ' live' : '' ?>"><?php if ($isLiveEv): ?><span class="ev-dot"></span><?php else: ?><i class="ti ti-hourglass-high"></i><?php endif; ?> <?= e(evWhen($ev)) ?></span>
                <h2 class="ev-hero-title"><?= e($ev['title']) ?></h2>
                <div class="ev-hero-meta">
                    <span><i class="ti ti-calendar"></i> <?= e(evRange($ev)) ?></span>
                    <?php if (!empty($ev['event_time'])): ?><span><i class="ti ti-clock"></i> <?= date('g:i A', strtotime($ev['event_time'])) ?></span><?php endif; ?>
                    <?php if (!empty($ev['location'])): ?><span><i class="ti ti-map-pin"></i> <?= e($ev['location']) ?></span><?php endif; ?>
                </div>
                <span class="ev-hero-cta">View details <i class="ti ti-arrow-right"></i></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($listEvents)): ?>
        <div class="ev-grid" id="ev-grid" data-step="6">
            <?php $evIdx = 0; $lastGroup = null; foreach ($listEvents as $ev):
                $evIdx++;
                $grp = evGroup($ev, $filter);
                if ($grp !== $lastGroup) { echo '<div class="ev-month">' . e($grp) . '</div>'; $lastGroup = $grp; }
                $dt = strtotime($ev['event_date']);
                $st = $ev['_status'];
            ?>
            <div class="card ev-card ev-<?= strtolower($st) ?><?= $evIdx > 6 ? ' ev-more' : '' ?>" tabindex="0" role="button" aria-label="View event: <?= e($ev['title']) ?>" onclick="openEventModal(<?= (int)$ev['id'] ?>)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openEventModal(<?= (int)$ev['id'] ?>);}">
                <div class="ev-media">
                    <?php if ($ev['image']): ?>
                        <img class="ev-img" src="../uploads/<?= e($ev['image']) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <div class="ev-ph"><img src="../assets/img/osa-logo.jpg" alt=""></div>
                    <?php endif; ?>
                    <div class="ev-date"><span class="m"><?= date('M', $dt) ?></span><span class="d"><?= date('j', $dt) ?></span></div>
                    <span class="ev-chip ev-chip-<?= strtolower($st) ?>"><?php if ($st === 'Ongoing'): ?><span class="ev-dot"></span><?php endif; ?><?= $st === 'Completed' ? 'Past' : e($st) ?></span>
                </div>
                <div class="ev-body">
                    <div class="ev-title"><?= e($ev['title']) ?></div>
                    <div class="ev-rel<?= $st === 'Ongoing' ? ' live' : '' ?>"><?= e(evWhen($ev)) ?></div>
                    <div class="ev-meta">
                        <?php if (!empty($ev['event_time'])): ?><span><i class="ti ti-clock"></i> <?= date('g:i A', strtotime($ev['event_time'])) ?></span><?php endif; ?>
                        <?php if (!empty($ev['end_date']) && $ev['end_date'] !== $ev['event_date']): ?><span><i class="ti ti-calendar-event"></i> until <?= date('M j', strtotime($ev['end_date'])) ?></span><?php endif; ?>
                        <?php if (!empty($ev['location'])): ?><span><i class="ti ti-map-pin"></i> <?= e($ev['location']) ?></span><?php endif; ?>
                    </div>
                    <?php if (!empty($ev['description'])): ?><p class="ev-desc-clamp"><?= e($ev['description']) ?></p><?php endif; ?>
                    <div class="ev-more-link">Details <i class="ti ti-arrow-right"></i></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (count($listEvents) > 6): ?>
        <div class="ev-more-wrap">
            <div class="ev-more-info" id="ev-more-info"></div>
            <button type="button" class="btn btn-outline btn-sm" id="ev-more-btn"><i class="ti ti-chevron-down"></i> <span></span></button>
            <button type="button" class="ev-less-btn" id="ev-less-btn" style="display:none;"><i class="ti ti-chevron-up"></i> Show less</button>
        </div>
        <script>
        (function () {
            var grid = document.getElementById('ev-grid'); if (!grid) return;
            var step = parseInt(grid.dataset.step, 10) || 6;
            var kids = Array.prototype.slice.call(grid.children);
            var cards = kids.filter(function (k) { return k.classList.contains('ev-card'); });
            var shown = step;
            var btn = document.getElementById('ev-more-btn'), less = document.getElementById('ev-less-btn'), info = document.getElementById('ev-more-info');
            function render() {
                cards.forEach(function (c, i) { c.classList.toggle('ev-more', i >= shown); });
                // a month header is visible only if the card right after it is
                kids.forEach(function (k, i) {
                    if (!k.classList.contains('ev-month')) return;
                    var nx = kids[i + 1];
                    k.style.display = (nx && !nx.classList.contains('ev-more')) ? '' : 'none';
                });
                var left = Math.max(0, cards.length - shown);
                info.textContent = 'Showing ' + Math.min(shown, cards.length) + ' of ' + cards.length;
                btn.style.display = left ? '' : 'none';
                btn.querySelector('span').textContent = 'Show ' + Math.min(step, left) + ' more (' + left + ' left)';
                less.style.display = shown > step ? '' : 'none';
            }
            btn.addEventListener('click', function () { shown += step; render(); });
            less.addEventListener('click', function () {
                shown = step; render();
                grid.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
            render();
        })();
        </script>
        <?php endif; ?>
        <?php endif; ?>

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
    </div>
</div>

<script>
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


<!-- ===== EVENT DETAILS POPUP ===== -->
<style>
/* Equal-size cards: .card + .card adds a top margin that pushed every card after the first
   lower, so reset it in the grid; stretch each card to the tallest in its row and pin View to the bottom. */
.ev-grid > .card{margin-top:0;}
.ev-grid > .ev-more{display:none;}
/* Filter tabs (pill style, same as Notifications) */
.ev-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;}
.ev-tab{display:inline-flex;align-items:center;gap:6px;font-size:13px;padding:7px 14px;border-radius:999px;text-decoration:none;color:#4B5563;background:#fff;border:1px solid #E5E7EB;transition:background .15s,color .15s;}
.ev-tab:hover{background:var(--maroon-pale);color:var(--maroon);}
.ev-tab.active{background:var(--maroon);color:#fff;border-color:var(--maroon);}
.ev-tab .cnt{font-size:11px;font-weight:600;padding:1px 7px;border-radius:999px;background:#F3F4F6;color:#6B7280;}
.ev-tab.active .cnt{background:rgba(255,255,255,.22);color:#fff;}
@media (max-width:520px){.ev-tab i{display:none;}.ev-tab{padding:7px 11px;font-size:12.5px;}.ev-tabs{flex-wrap:nowrap;overflow-x:auto;margin-right:-4px;padding-bottom:4px;-webkit-overflow-scrolling:touch;scrollbar-width:none;}.ev-tabs::-webkit-scrollbar{display:none;}.ev-tab{white-space:nowrap;flex-shrink:0;}}
/* Show more */
.ev-more-wrap{display:flex;flex-direction:column;align-items:center;gap:8px;margin-top:18px;}
.ev-more-info{font-size:12px;color:#9CA3AF;}
.ev-less-btn{background:none;border:0;color:var(--maroon);font-size:13px;font-weight:600;cursor:pointer;padding:4px 8px;display:inline-flex;align-items:center;gap:4px;font-family:inherit;}
.ev-less-btn:hover{text-decoration:underline;}
/* ── Redesigned event cards ── */
.ev-grid{gap:18px;}
.ev-month{grid-column:1/-1;font-size:12px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#9CA3AF;display:flex;align-items:center;gap:12px;margin-top:6px;}
.ev-month::after{content:"";flex:1;height:1px;background:#E5E7EB;}
.ev-month:first-child{margin-top:0;}
.ev-card{padding:0;overflow:hidden;cursor:pointer;display:flex;flex-direction:column;height:100%;border:1px solid #F0E4E6;transition:transform .18s ease,box-shadow .18s ease;}
.ev-card:hover,.ev-card:focus-visible{transform:translateY(-3px);box-shadow:0 10px 24px rgba(107,26,42,.14);outline:none;}
.ev-media{position:relative;aspect-ratio:16/9;background:#F3F4F6;overflow:hidden;flex-shrink:0;}
.ev-img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s ease;}
.ev-card:hover .ev-img{transform:scale(1.04);}
.ev-ph,.ev-hero-ph{width:100%;height:100%;background:linear-gradient(135deg,#6B1A2A,#8C2438 55%,#C9943A);display:flex;align-items:center;justify-content:center;}
.ev-ph img{width:64px;height:64px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.9);}
.ev-date{position:absolute;top:10px;left:10px;background:#fff;border-radius:10px;min-width:44px;padding:5px 8px 4px;text-align:center;line-height:1;box-shadow:0 2px 8px rgba(0,0,0,.18);}
.ev-date .m{display:block;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--maroon);}
.ev-date .d{display:block;font-size:20px;font-weight:800;color:#1F2937;margin-top:2px;}
.ev-chip{position:absolute;top:10px;right:10px;display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;color:#fff;box-shadow:0 2px 6px rgba(0,0,0,.2);}
.ev-chip-ongoing{background:#059669;}
.ev-chip-upcoming{background:var(--gold);}
.ev-chip-completed{background:rgba(31,41,55,.82);}
.ev-chip-cancelled{background:#991B1B;}
.ev-dot{width:8px;height:8px;border-radius:50%;background:#fff;display:inline-block;animation:evPulse 1.4s ease-in-out infinite;}
@keyframes evPulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.35;transform:scale(.7);}}
.ev-completed .ev-media > img,.ev-completed .ev-ph,.ev-cancelled .ev-media > img,.ev-cancelled .ev-ph{filter:grayscale(.55) brightness(.92);}
.ev-cancelled .ev-title{text-decoration:line-through;color:#6B7280;}
.ev-body{display:flex;flex-direction:column;flex:1;padding:14px 16px 14px;}
.ev-title{font-size:15px;font-weight:700;color:#1F2937;line-height:1.3;}
.ev-rel{font-size:12px;font-weight:600;color:var(--gold-dark,#8A6116);margin-top:3px;}
.ev-rel.live{color:#059669;}
.ev-completed .ev-rel,.ev-cancelled .ev-rel{color:#6B7280;}
.ev-meta{display:flex;flex-wrap:wrap;gap:4px 12px;font-size:12px;color:#6B7280;margin-top:8px;}
.ev-meta i{vertical-align:-2px;color:var(--maroon);}
.ev-desc-clamp{font-size:13px;color:#4B5563;margin:10px 0 0;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.ev-more-link{margin-top:auto;padding-top:12px;font-size:13px;font-weight:600;color:var(--maroon);display:flex;align-items:center;gap:4px;}
.ev-more-link i{transition:transform .15s;}
.ev-card:hover .ev-more-link i{transform:translateX(4px);}
/* Featured banner */
.ev-hero{position:relative;border-radius:16px;overflow:hidden;min-height:280px;margin-bottom:22px;cursor:pointer;background:#4A0F1B;box-shadow:0 8px 24px rgba(107,26,42,.18);display:flex;align-items:flex-end;transition:box-shadow .18s ease,transform .18s ease;}
.ev-hero:hover,.ev-hero:focus-visible{box-shadow:0 12px 30px rgba(107,26,42,.28);transform:translateY(-2px);outline:none;}
.ev-hero-img,.ev-hero-ph{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;}
.ev-hero-ph{align-items:flex-start;padding-top:30px;box-sizing:border-box;}
.ev-hero-ph img{width:84px;height:84px;border-radius:50%;object-fit:cover;border:4px solid rgba(255,255,255,.9);opacity:.95;}
.ev-hero-shade{position:absolute;inset:0;background:linear-gradient(to top,rgba(30,6,12,.88) 0%,rgba(30,6,12,.55) 45%,rgba(30,6,12,.05) 100%);}
.ev-hero-body{position:relative;padding:22px 24px 22px;color:#fff;max-width:760px;}
.ev-when{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:700;padding:5px 12px;border-radius:999px;background:var(--gold);color:#fff;}
.ev-when.live{background:#059669;}
.ev-hero-title{font-size:26px;font-weight:800;line-height:1.2;margin:10px 0 10px;color:#fff;}
.ev-hero-meta{display:flex;flex-wrap:wrap;gap:6px 18px;font-size:13px;color:rgba(255,255,255,.9);}
.ev-hero-meta i{vertical-align:-2px;margin-right:2px;}
.ev-hero-cta{display:inline-flex;align-items:center;gap:6px;margin-top:14px;background:#fff;color:var(--maroon);font-size:13px;font-weight:700;padding:8px 16px;border-radius:999px;}
@media (max-width:640px){.ev-hero{min-height:300px;border-radius:14px;}.ev-hero-title{font-size:21px;}.ev-hero-body{padding:18px;}}
@media (prefers-reduced-motion:reduce){.ev-card,.ev-hero,.ev-img,.ev-dot{transition:none;animation:none;}}
.ev-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;z-index:1000;}
.ev-modal-overlay.show{display:flex;}
.ev-modal-overlay .modal-panel{max-width:560px;margin:auto;}
.ev-modal-img{width:100%;max-height:280px;object-fit:cover;display:block;}
.ev-modal-row{display:flex;gap:10px;align-items:flex-start;font-size:14px;color:#374151;margin-bottom:10px;}
.ev-modal-row i{font-size:17px;color:#6B1A2A;margin-top:1px;}
.ev-modal-desc{white-space:pre-wrap;word-wrap:break-word;font-size:14px;color:#374151;line-height:1.6;margin-top:14px;padding-top:14px;border-top:1px solid #F3F4F6;}
</style>
<div class="ev-modal-overlay" id="ev-modal" aria-hidden="true">
    <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="ev-modal-title">
        <img id="ev-modal-img" class="ev-modal-img" alt="" style="display:none;">
        <div class="modal-panel-header">
            <div class="title-wrap">
                <div class="icon-circle"><i class="ti ti-calendar-event"></i></div>
                <div class="title-text"><div class="t" id="ev-modal-title"></div><div class="s" id="ev-modal-badge"></div></div>
            </div>
            <button type="button" class="close-btn" onclick="closeEventModal()" aria-label="Close"><i class="ti ti-x"></i></button>
        </div>
        <div class="modal-panel-body">
            <div class="ev-modal-row"><i class="ti ti-calendar"></i><span id="ev-modal-date"></span></div>
            <div class="ev-modal-row" id="ev-modal-time-row"><i class="ti ti-clock"></i><span id="ev-modal-time"></span></div>
            <div class="ev-modal-row" id="ev-modal-place-row"><i class="ti ti-map-pin"></i><span id="ev-modal-place"></span></div>
            <div class="ev-modal-desc" id="ev-modal-desc"></div>
        </div>
    </div>
</div>
<script>
(function() {
    var EVENTS  = <?= json_encode($evModalData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    var modal   = document.getElementById('ev-modal');
    var lastFocus = null;

    function $(id) { return document.getElementById(id); }

    window.openEventModal = function(id) {
        var ev = EVENTS[id];
        if (!ev) return;
        lastFocus = document.activeElement;
        $('ev-modal-title').textContent = ev.title;
        $('ev-modal-badge').innerHTML = ev.badge;          // fixed, server-escaped badge markup
        $('ev-modal-date').textContent = ev.date;
        $('ev-modal-time').textContent = ev.time;
        $('ev-modal-time-row').style.display = ev.time ? '' : 'none';
        $('ev-modal-place').textContent = ev.place;
        $('ev-modal-place-row').style.display = ev.place ? '' : 'none';
        var d = $('ev-modal-desc');
        d.textContent = ev.desc || 'No additional details provided.';
        d.style.color = ev.desc ? '' : '#9CA3AF';
        var img = $('ev-modal-img');
        if (ev.image) { img.src = ev.image; img.style.display = 'block'; } else { img.removeAttribute('src'); img.style.display = 'none'; }
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        modal.querySelector('.close-btn').focus();
    };

    window.closeEventModal = function() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    };

    modal.addEventListener('click', function(e) { if (e.target === modal) closeEventModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && modal.classList.contains('show')) closeEventModal(); });

    <?php if ($openEventId && isset($evModalData[$openEventId])): ?>
    openEventModal(<?= $openEventId ?>);
    <?php endif; ?>
})();
</script>

<script src="../assets/js/sidebar.js?v=5"></script>
<script src="../assets/js/show-more.js"></script>
<?php include __DIR__.'/../includes/push-widget.php'; ?>
</body>
</html>

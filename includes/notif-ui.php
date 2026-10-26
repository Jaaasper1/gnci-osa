<?php
/**
 * Shared helpers for the Notifications pages (student/, osa/, admin/).
 * Adds: tabs (filters), pagination, date grouping, unread highlighting, "Mark all as read".
 * Works for both `notifications` (student_id) and `staff_notifications` (staff_id).
 */

require_once __DIR__ . '/pager.php';

const NOTIF_PER_PAGE = 20;
/** Rows per page (?pp=10|20|50). */
function notifPerPage(): int { return pagerSize([10, 20, 50], NOTIF_PER_PAGE); }

/** Staff/admin only ever receive complaint notifications, so they get fewer tabs. */
function notifTabs(string $table = 'notifications'): array {
    if ($table === 'staff_notifications') {
        return ['all' => t('notif.tab_all', 'All'), 'unread' => t('notif.tab_unread', 'Unread'), 'complaints' => t('notif.tab_complaints', 'Complaints')];
    }
    return ['all' => t('notif.tab_all', 'All'), 'unread' => t('notif.tab_unread', 'Unread'), 'complaints' => t('notif.tab_complaints', 'Complaints'),
            'voting' => t('notif.tab_voting', 'Voting'), 'events' => t('notif.tab_events', 'Events'), 'account' => t('notif.tab_account', 'Account')];
}

/** SQL fragment per filter. Only hard-coded strings, never user input. */
function notifFilterSql(string $f): string {
    switch ($f) {
        case 'unread':     return 'is_read = 0';
        case 'complaints': return "message LIKE '%complaint%'";
        case 'voting':     return "(message LIKE '%vote%' OR message LIKE '%voting%' OR message LIKE '%election%')";
        case 'events':     return "message LIKE '%event%'";
        case 'account':    return "message LIKE '%account%'";
        default:           return '1=1';
    }
}

function notifNormalizeFilter($f, string $table = 'notifications'): string {
    return array_key_exists((string)$f, notifTabs($table)) ? (string)$f : 'all';
}

/** $table is 'notifications' or 'staff_notifications' (hard-coded by callers). */
function notifOwnerCol(string $table): string {
    return $table === 'staff_notifications' ? 'staff_id' : 'student_id';
}

/** Count per tab in ONE query. */
function notifTabCounts(PDO $pdo, string $table, int $ownerId): array {
    $col = notifOwnerCol($table);
    $parts = [];
    foreach (array_keys(notifTabs($table)) as $k) {
        $parts[] = "SUM(" . notifFilterSql($k) . ") AS `$k`";
    }
    $st = $pdo->prepare("SELECT " . implode(',', $parts) . " FROM $table WHERE $col = ?");
    $st->execute([$ownerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return array_map('intval', $row);
}

/** Returns one page of rows (is_read as it was BEFORE this visit). */
function notifFetchPage(PDO $pdo, string $table, int $ownerId, string $filter, int $page, int $perPage = 0): array {
    if ($perPage <= 0) $perPage = notifPerPage();
    $col   = notifOwnerCol($table);
    $where = "$col = ? AND " . notifFilterSql($filter);
    $off   = max(0, ($page - 1) * $perPage);
    $st = $pdo->prepare("SELECT * FROM $table WHERE $where ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $off");
    $st->execute([$ownerId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Marks only the given rows as read. Returns how many changed. */
function notifMarkRead(PDO $pdo, string $table, int $ownerId, array $rows): int {
    $ids = [];
    foreach ($rows as $r) { if (!$r['is_read']) $ids[] = (int)$r['id']; }
    if (!$ids) return 0;
    $col = notifOwnerCol($table);
    $in  = implode(',', $ids); // ints only
    $st  = $pdo->prepare("UPDATE $table SET is_read = 1 WHERE $col = ? AND id IN ($in)");
    $st->execute([$ownerId]);
    return $st->rowCount();
}

/**
 * Handles the bulk / unread AJAX actions shared by all notification pages:
 *   mark_unread_one (id), mark_read_many (ids), mark_unread_many (ids), delete_many (ids)
 * `ids` is a comma-separated list of ints. Only rows owned by $ownerId are touched.
 * Prints JSON and returns true when the action was handled.
 */
function notifHandleBulk(PDO $pdo, string $table, int $ownerId): bool {
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['mark_unread_one', 'mark_read_many', 'mark_unread_many', 'delete_many'], true)) return false;
    $raw = $action === 'mark_unread_one' ? (string)($_POST['id'] ?? '') : (string)($_POST['ids'] ?? '');
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), fn($i) => $i > 0)));
    $ids = array_slice($ids, 0, 500);
    if (!$ids) { echo json_encode(['ok' => false]); return true; }
    $col = notifOwnerCol($table);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$ownerId], $ids);
    if ($action === 'delete_many') {
        $sql = "DELETE FROM $table WHERE $col = ? AND id IN ($in)";
    } else {
        $val = ($action === 'mark_read_many') ? 1 : 0;
        $sql = "UPDATE $table SET is_read = $val WHERE $col = ? AND id IN ($in)";
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    echo json_encode(['ok' => true, 'count' => $st->rowCount()]);
    return true;
}

function notifType(string $msg): array {
    $m = strtolower($msg);
    if (strpos($m, 'complaint') !== false) return ['ti-message-report', tx('Complaint')];
    if (strpos($m, 'vote') !== false || strpos($m, 'election') !== false) return ['ti-checkbox', tx('Voting')];
    if (strpos($m, 'event') !== false)     return ['ti-calendar-event', tx('Event')];
    if (strpos($m, 'account') !== false)   return ['ti-user-check', tx('Account')];
    return ['ti-bell', tx('Notification')];
}

function notifGroupLabel(string $dt): string {
    $d    = strtotime(date('Y-m-d', strtotime($dt)));
    $today = strtotime(date('Y-m-d'));
    $diff = (int)round(($today - $d) / 86400);
    if ($diff <= 0)  return tx('Today');
    if ($diff === 1) return tx('Yesterday');
    if ($diff < 7)   return tx('This week');
    if ($diff < 30)  return tx('This month');
    return tx('Older');
}

function notifTimeAgo(string $dt): string {
    $s = time() - strtotime($dt);
    if ($s < 60)    return 'just now';
    if ($s < 3600)  return floor($s / 60) . ' min ago';
    if ($s < 86400) return floor($s / 3600) . ' hr ago';
    return formatDate($dt);
}

function notifUrl(string $filter, int $page, ?int $pp = null): string {
    $q = [];
    if ($filter !== 'all') $q['f'] = $filter;
    if ($page > 1)         $q['p'] = $page;
    $pp = $pp ?? notifPerPage();
    if ($pp !== NOTIF_PER_PAGE) $q['pp'] = $pp;
    return 'notifications.php' . ($q ? '?' . http_build_query($q) : '');
}

/** Page styles. $accent = CSS colour for tabs/unread highlight. */
function notifCss(string $accent = 'var(--maroon)', string $accentPale = 'var(--maroon-pale)'): void { ?>
        /* ── Notification tabs / groups / pager (includes/notif-ui.php) ── */
        .notif-wrap { --nacc: <?= $accent ?>; --nacc-pale: <?= $accentPale ?>; }
        .notif-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; }
        .notif-tab {
            font-size:12.5px; padding:6px 12px; border-radius:999px; text-decoration:none;
            color:#4B5563; background:#F3F4F6; border:1px solid transparent;
            display:inline-flex; align-items:center; gap:6px;
        }
        .notif-tab:hover { background:#E5E7EB; }
        .notif-tab.active { background:var(--nacc); color:#fff; }
        .notif-tab .cnt { font-size:11px; opacity:.8; }
        .notif-tab.active .cnt { opacity:.9; }
        .notif-group {
            font-size:11px; font-weight:600; letter-spacing:.06em; text-transform:uppercase;
            color:#9CA3AF; padding:14px 0 4px; border-bottom:1px solid #F3F4F6;
        }
        .notif-row.unread { background:var(--nacc-pale); margin:0 -12px; padding-left:12px; padding-right:12px; border-radius:8px; }
        .notif-row.unread .notif-msg { font-weight:600; }
        .notif-dot { width:8px; height:8px; border-radius:50%; background:var(--nacc); display:inline-block; margin-right:6px; }
        .notif-type { font-size:10.5px; color:#6B7280; background:#F3F4F6; border-radius:4px; padding:1px 6px; margin-right:6px; }
        .notif-actions { display:flex; gap:8px; }
        .notif-read-btn {
            flex-shrink:0; background:none; border:none; cursor:pointer; color:var(--nacc);
            padding:4px 6px; border-radius:6px; font-size:16px; line-height:1; opacity:.55;
        }
        .notif-read-btn:hover { opacity:1; background:#fff; }
        .btn-mark-read {
            font-size:13px; color:var(--nacc); background:#fff; border:1px solid var(--nacc);
            border-radius:8px; padding:6px 14px; cursor:pointer; display:flex; align-items:center; gap:5px;
        }
        .btn-mark-read:hover { background:var(--nacc-pale); }
        /* ── Multi-select / bulk actions ── */
        .notif-check { display:none; flex-shrink:0; width:20px; height:20px; margin-top:7px; accent-color:var(--nacc); cursor:pointer; }
        @media (hover:hover) and (min-width:769px) { .notif-check { display:block; } }
        .notif-wrap.selecting .notif-check { display:block; }
        .notif-row.selected { background:var(--nacc-pale); margin:0 -12px; padding-left:12px; padding-right:12px; border-radius:8px; outline:2px solid var(--nacc); outline-offset:-2px; }
        .notif-wrap.selecting .notif-row { cursor:pointer; -webkit-user-select:none; user-select:none; }
        .notif-wrap.selecting .notif-row .notif-link { pointer-events:none; }
        .notif-row { -webkit-touch-callout:none; }
        .notif-row .notif-unread-btn {
            flex-shrink:0; background:none; border:none; cursor:pointer; color:#9CA3AF;
            padding:4px 6px; border-radius:6px; font-size:16px; line-height:1;
        }
        .notif-row .notif-unread-btn:hover { color:var(--nacc); background:#fff; }
        .notif-wrap.selecting .notif-read-btn, .notif-wrap.selecting .notif-unread-btn, .notif-wrap.selecting .notif-delete-btn { display:none; }
        .btn-select {
            font-size:13px; color:#4B5563; background:#fff; border:1px solid #D1D5DB;
            border-radius:8px; padding:6px 14px; cursor:pointer; display:flex; align-items:center; gap:5px;
        }
        .btn-select:hover { background:#F3F4F6; }
        .notif-bulkbar {
            display:none; align-items:center; gap:8px; flex-wrap:wrap;
            position:sticky; top:0; z-index:20; background:#fff;
            padding:8px 0 10px; margin-bottom:6px; border-bottom:1px solid #F3F4F6;
        }
        .notif-wrap.selecting .notif-bulkbar { display:flex; }
        .notif-wrap.selecting .notif-header-row, .notif-wrap.selecting .notif-tabs { display:none; }
        .notif-bulkbar label { display:flex; align-items:center; gap:6px; font-size:13px; color:#374151; cursor:pointer; }
        .notif-bulkbar input { width:18px; height:18px; accent-color:var(--nacc); }
        .notif-bulkbar .sel-count { font-size:13px; font-weight:600; color:#1F2937; margin-right:auto; }
        .notif-bulkbar button {
            font-size:13px; border-radius:8px; padding:6px 12px; cursor:pointer;
            display:inline-flex; align-items:center; gap:5px; background:#fff; color:#374151; border:1px solid #D1D5DB;
        }
        .notif-bulkbar button:disabled { opacity:.45; cursor:not-allowed; }
        .notif-bulkbar button:not(:disabled):hover { background:#F3F4F6; }
        .notif-bulkbar .b-del { color:#EF4444; border-color:#FECACA; background:#FEF2F2; }
        .notif-bulkbar .b-del:not(:disabled):hover { background:#FEE2E2; }
        @media (max-width:560px) {
            .notif-bulkbar .b-lbl { display:none; }
            .notif-bulkbar button { padding:8px 11px; }
        }
        /* ── Empty state ── */
        .notif-empty { flex-direction:column; align-items:center; justify-content:center; gap:10px; padding:36px 16px; color:#6B7280; font-size:13.5px; text-align:center; }
        .notif-empty .ne-ic { width:54px; height:54px; border-radius:50%; background:var(--nacc-pale); color:var(--nacc); display:flex; align-items:center; justify-content:center; font-size:26px; }
<?php }

/** Tabs + header + grouped list + pager. */
function notifRenderCard(array $rows, array $counts, string $filter, int $page, int $totalForFilter, int $perPage = NOTIF_PER_PAGE, string $table = 'notifications'): void {
    $pages = max(1, (int)ceil($totalForFilter / $perPage));
    ?>
    <div class="card notif-wrap">
        <div class="notif-tabs">
            <?php foreach (notifTabs($table) as $k => $label): ?>
                <a class="notif-tab <?= $k === $filter ? 'active' : '' ?>" href="<?= e(notifUrl($k, 1)) ?>">
                    <?= e($label) ?>
                    <?php if ($k === 'unread' || $k === 'all' || ($counts[$k] ?? 0) > 0): ?>
                        <span class="cnt"><?= number_format($counts[$k] ?? 0) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="notif-bulkbar" id="notif-bulkbar">
            <label><input type="checkbox" id="sel-all" onchange="notifSelectAll(this.checked)"> <span class="b-lbl"><?= t('notif.all', 'All') ?></span></label>
            <span class="sel-count" id="sel-count">0 <?= t('notif.selected', 'selected') ?></span>
            <button type="button" id="b-read"   onclick="notifBulk('mark_read_many')"   disabled title="<?= htmlspecialchars(t('notif.mark_as_read', 'Mark as read'), ENT_QUOTES) ?>"><i class="ti ti-mail-opened"></i><span class="b-lbl"><?= t('notif.mark_read', 'Mark read') ?></span></button>
            <button type="button" id="b-unread" onclick="notifBulk('mark_unread_many')" disabled title="<?= htmlspecialchars(t('notif.mark_as_unread', 'Mark as unread'), ENT_QUOTES) ?>"><i class="ti ti-mail"></i><span class="b-lbl"><?= t('notif.mark_unread', 'Mark unread') ?></span></button>
            <button type="button" id="b-del" class="b-del" onclick="notifBulkDelete()" disabled title="<?= htmlspecialchars(t('notif.delete', 'Delete'), ENT_QUOTES) ?>"><i class="ti ti-trash"></i><span class="b-lbl"><?= t('notif.delete', 'Delete') ?></span></button>
            <button type="button" onclick="notifExitSelect()" title="<?= htmlspecialchars(t('notif.cancel', 'Cancel'), ENT_QUOTES) ?>"><i class="ti ti-x"></i><span class="b-lbl"><?= t('notif.cancel', 'Cancel') ?></span></button>
        </div>

        <div class="notif-header-row" id="notif-header" <?= ($counts['all'] ?? 0) == 0 ? 'style="display:none"' : '' ?>>
            <span style="font-size:13px;color:#6B7280;">
                <?php if ($totalForFilter > 0): ?>
                    <?= number_format($totalForFilter) ?> <?= tx($totalForFilter != 1 ? 'notifications' : 'notification') ?>
                <?php else: ?>0 <?= tx('notifications') ?><?php endif; ?>
            </span>
            <div class="notif-actions">
                <button class="btn-select" type="button" onclick="notifEnterSelect()"><i class="ti ti-checkbox"></i> <?= t('notif.select', 'Select') ?></button>
                <?php if (($counts['unread'] ?? 0) > 0): ?>
                <button class="btn-mark-read" onclick="markAllRead()"><i class="ti ti-checks"></i> <?= t('notif.mark_all_read', 'Mark all as read') ?></button>
                <?php endif; ?>
                <button class="btn-delete-all" onclick="askDeleteAll()"><i class="ti ti-trash"></i> <?= t('notif.delete_all', 'Delete All') ?></button>
            </div>
        </div>

        <div class="notif-empty" id="no-notifs" style="display:<?= empty($rows) ? 'flex' : 'none' ?>;">
            <div class="ne-ic"><i class="ti ti-bell-off"></i></div>
            <div><?= ($counts['all'] ?? 0) == 0 ? tx('No notifications yet.') : tx('No notifications in this view.') ?></div>
        </div>

        <div id="notif-list">
        <?php $lastGroup = null; foreach ($rows as $n):
            $g = notifGroupLabel($n['created_at']);
            if ($g !== $lastGroup) { echo '<div class="notif-group">' . e($g) . '</div>'; $lastGroup = $g; }
            [$icon, $typeLabel] = notifType($n['message']);
            $unread = !$n['is_read'];
        ?>
            <div class="notif-row <?= $unread ? 'unread' : '' ?>" id="notif-<?= (int)$n['id'] ?>" data-id="<?= (int)$n['id'] ?>">
                <input type="checkbox" class="notif-check" aria-label="<?= htmlspecialchars(t('notif.select_notification', 'Select notification'), ENT_QUOTES) ?>" onclick="event.stopPropagation(); notifToggle(<?= (int)$n['id'] ?>, this.checked)">
                <div class="notif-icon"><i class="ti <?= $icon ?>" style="color:var(--nacc);font-size:17px;"></i></div>
                <div class="notif-body">
                    <div class="notif-msg"><?= $unread ? '<span class="notif-dot"></span>' : '' ?><?= e(tlNotifMsg($n['message'])) ?></div>
                    <div class="notif-time"><span class="notif-type"><?= e($typeLabel) ?></span><?= e(notifTimeAgo($n['created_at'])) ?></div>
                    <?php if ($n['link']): ?><a href="<?= e($n['link']) ?>" class="notif-link"><?= t('notif.view', 'View →') ?></a><?php endif; ?>
                </div>
                <?php if ($unread): ?>
                <button class="notif-read-btn" onclick="markOneRead(<?= (int)$n['id'] ?>)" title="Mark as read"><i class="ti ti-check"></i></button>
                <?php else: ?>
                <button class="notif-unread-btn" onclick="markOneUnread(<?= (int)$n['id'] ?>)" title="Mark as unread"><i class="ti ti-mail"></i></button>
                <?php endif; ?>
                <button class="notif-delete-btn" onclick="askDeleteOne(<?= (int)$n['id'] ?>)" title="<?= htmlspecialchars(t('notif.delete', 'Delete'), ENT_QUOTES) ?>"><i class="ti ti-x"></i></button>
            </div>
        <?php endforeach; ?>
        </div>

        <?php gpBar($page, $perPage, $totalForFilter, function (int $p, ?int $pp = null) use ($filter, $perPage) {
            return $pp !== null ? notifUrl($filter, 1, $pp) : notifUrl($filter, $p, $perPage);
        }, [10, 20, 50], false, 'notification'); ?>
    </div>
<?php }


/** JS (no <script> tags): opening a notification via "View →" (or its check button) = mark that one read. Echo inside a <script> block. */
function notifJs(): void { ?>
function updateUnreadUi(n) {
    document.querySelectorAll('.badge-count').forEach(b => {
        const v = Math.max(0, (parseInt(b.textContent, 10) || 0) - n);
        if (v === 0) b.remove(); else b.textContent = v;
    });
    document.querySelectorAll('.notif-tab').forEach(t => {
        if (/^\s*Unread/.test(t.textContent)) {
            const c = t.querySelector('.cnt');
            if (c) c.textContent = Math.max(0, (parseInt(c.textContent.replace(/,/g, ''), 10) || 0) - n).toLocaleString();
        }
    });
    const left = document.querySelectorAll('.notif-row.unread').length;
    if (left === 0) { const m = document.querySelector('.btn-mark-read'); if (m) m.remove(); }
}
function markOneRead(id, thenGo) {
    const row = document.getElementById('notif-' + id);
    if (!row || !row.classList.contains('unread')) { if (thenGo) location.href = thenGo; return; }
    row.classList.remove('unread');
    const dot = row.querySelector('.notif-dot'); if (dot) dot.remove();
    const btn = row.querySelector('.notif-read-btn'); if (btn) btn.remove();
    updateUnreadUi(1);
    fetch('notifications.php', {
        method: 'POST', keepalive: true,
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=mark_one&id=' + id
    });
}
document.addEventListener('click', function (e) {
    const row = e.target.closest('.notif-row');
    if (notifSuppressClick) { notifSuppressClick = false; if (row) { e.preventDefault(); e.stopPropagation(); } return; }
    if (!row) return;
    if (notifSelecting) {                       // in select mode a tap toggles the row
        if (e.target.closest('.notif-check')) return;
        e.preventDefault(); notifToggle(row.dataset.id); return;
    }
    // Only actually opening the notification (the "View →" link) marks it as read.
    // Clicking elsewhere on the row does nothing; use the check button to mark it manually.
    const link = e.target.closest('.notif-link');
    if (link) markOneRead(row.dataset.id);   // link navigates normally; request is keepalive
}, true);

/* ── Mark as unread (single) ── */
function markOneUnread(id) {
    fetch('notifications.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=mark_unread_one&id=' + id
    }).then(r => r.json()).then(d => { if (d.ok) location.reload(); });
}

/* ── Multi-select (checkbox on desktop, long-press on mobile) ── */
const notifSel = new Set();
let notifSelecting = false, notifAutoExit = false, notifSuppressClick = false;
function notifWrap() { return document.querySelector('.notif-wrap'); }
function notifRows() { return Array.from(document.querySelectorAll('#notif-list .notif-row')); }
function notifRow(id) { return document.getElementById('notif-' + id); }
function notifRender() {
    const rows = notifRows();
    rows.forEach(r => {
        const on = notifSel.has(r.dataset.id);
        r.classList.toggle('selected', on);
        const cb = r.querySelector('.notif-check'); if (cb) cb.checked = on;
    });
    const n = notifSel.size;
    document.getElementById('sel-count').textContent = n + ' ' + <?= json_encode(t('notif.selected', 'selected')) ?>;
    const all = document.getElementById('sel-all');
    all.checked = n > 0 && n === rows.length;
    all.indeterminate = n > 0 && n < rows.length;
    let hasUnread = false, hasRead = false;
    notifSel.forEach(id => { const r = notifRow(id); if (!r) return; if (r.classList.contains('unread')) hasUnread = true; else hasRead = true; });
    document.getElementById('b-read').disabled   = !hasUnread;
    document.getElementById('b-unread').disabled = !hasRead;
    document.getElementById('b-del').disabled    = n === 0;
}
function notifEnterSelect(autoExit) {
    notifSelecting = true; notifAutoExit = !!autoExit;
    notifWrap().classList.add('selecting'); notifRender();
}
function notifExitSelect() {
    notifSelecting = false; notifAutoExit = false; notifSel.clear();
    notifWrap().classList.remove('selecting'); notifRender();
}
function notifToggle(id, force) {
    id = String(id);
    const on = (typeof force === 'boolean') ? force : !notifSel.has(id);
    if (on) notifSel.add(id); else notifSel.delete(id);
    if (!notifSelecting) notifEnterSelect(false);
    if (notifSelecting && notifAutoExit && notifSel.size === 0) { notifExitSelect(); return; }
    notifRender();
}
function notifSelectAll(on) {
    notifRows().forEach(r => { if (on) notifSel.add(r.dataset.id); else notifSel.delete(r.dataset.id); });
    if (notifAutoExit && notifSel.size === 0) { notifExitSelect(); return; }
    notifRender();
}
function notifPost(action) {
    return fetch('notifications.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=' + action + '&ids=' + encodeURIComponent(Array.from(notifSel).join(','))
    }).then(r => r.json());
}
function notifBulk(action) {
    if (!notifSel.size) return;
    notifPost(action).then(d => { if (d.ok) location.reload(); });
}
function notifBulkDelete() {
    const n = notifSel.size; if (!n) return;
    openModal(
        <?= json_encode(t('notif.del_n', 'Delete %d notification(s)')) ?>.replace('%d', n),
        <?= json_encode(t('notif.del_n_msg', 'The selected notification(s) will be permanently removed. This cannot be undone.')) ?>,
        () => notifPost('delete_many').then(d => { if (d.ok) location.reload(); })
    );
}

/* Long-press on a row (touch / pen) = start selecting */
(function () {
    let timer = null, sx = 0, sy = 0;
    const list = document.getElementById('notif-list');
    if (!list) return;
    function cancel() { if (timer) { clearTimeout(timer); timer = null; } }
    list.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'mouse') return;
        const row = e.target.closest('.notif-row');
        if (!row || e.target.closest('button, input')) return;
        sx = e.clientX; sy = e.clientY; cancel();
        timer = setTimeout(function () {
            timer = null;
            notifSuppressClick = true;
            if (navigator.vibrate) { try { navigator.vibrate(30); } catch (_) {} }
            if (!notifSelecting) notifEnterSelect(true);
            notifToggle(row.dataset.id, true);
        }, 450);
    });
    list.addEventListener('pointermove', function (e) {
        if (timer && (Math.abs(e.clientX - sx) > 10 || Math.abs(e.clientY - sy) > 10)) cancel();
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(ev => list.addEventListener(ev, cancel));
    list.addEventListener('contextmenu', function (e) { if (e.target.closest('.notif-row') && 'ontouchstart' in window) e.preventDefault(); });
})();
document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && notifSelecting) notifExitSelect(); });
<?php }

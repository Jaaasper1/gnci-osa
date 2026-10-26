<?php
if (!function_exists('t')) { require_once __DIR__ . '/../includes/lang.php'; }
/**
 * Shared searchable election dropdown (used by candidates.php, party-lists.php, results.php and live-results.php; $param = the URL query name).
 * Groups elections into Active / Closed / Completed and lets the admin search by
 * title or course. Replaces the old row of election "pills".
 */

// Status of one election row: 'active' | 'closed' | 'completed'
function electionStatusKey(array $el): string {
    if (!empty($el['is_completed'])) return 'completed';
    return !empty($el['is_active']) ? 'active' : 'closed';
}

// Which election opens by default when no ?election= is given:
// first Active, else first Closed, else first Completed.
function defaultElectionId(array $elections): int {
    foreach (['active', 'closed', 'completed'] as $k) {
        foreach ($elections as $el) {
            if (electionStatusKey($el) === $k) return (int)$el['id'];
        }
    }
    return 0;
}

function renderElectionPicker(array $elections, int $eid, string $param = 'election'): void {
    if (count($elections) < 1) return;

    $groups = [
        'active'    => [t('ui.active_4d3d', 'Active'),    'ti-player-play',  '#059669', []],
        'closed'    => [t('ui.closed_03f4', 'Closed'),    'ti-lock',         '#6B7280', []],
        'completed' => [t('ui.completed_07ca', 'Completed'), 'ti-circle-check', '#1D4ED8', []],
    ];
    $current = null;
    foreach ($elections as $el) {
        $groups[electionStatusKey($el)][3][] = $el;
        if ((int)$el['id'] === $eid) $current = $el;
    }
    if (!$current) $current = $elections[0];
    $curKey = electionStatusKey($current);
    ?>
    <div class="elpick" id="elpick">
        <div class="elpick-label"><?= t('auto.election_28c1', 'Election') ?></div>
        <button type="button" class="elpick-btn" id="elpick-btn" aria-haspopup="listbox" aria-expanded="false">
            <span class="elpick-course"><?= $current['course'] !== '' ? e($current['course']) : 'All' ?></span>
            <span class="elpick-title"><?= e($current['title']) ?></span>
            <span class="elpick-status elpick-status-<?= $curKey ?>"><?= $groups[$curKey][0] ?></span>
            <i class="ti ti-chevron-down elpick-caret"></i>
        </button>
        <div class="elpick-panel" id="elpick-panel" style="display:none;">
            <div class="elpick-search">
                <i class="ti ti-search"></i>
                <input type="text" id="elpick-q" placeholder="<?= htmlspecialchars(t('auto.search_election_or_course_e2bb', 'Search election or course...'), ENT_QUOTES) ?>" autocomplete="off">
            </div>
            <div class="elpick-list" id="elpick-list" role="listbox">
                <?php foreach ($groups as $gk => [$gLabel, $gIcon, $gColor, $gList]): if (empty($gList)) continue; ?>
                <div class="elpick-group" data-group="<?= $gk ?>">
                    <div class="elpick-group-head" style="color:<?= $gColor ?>;">
                        <i class="ti <?= $gIcon ?>"></i> <?= $gLabel ?> <span>(<?= count($gList) ?>)</span>
                    </div>
                    <?php foreach ($gList as $el): $isSel = ((int)$el['id'] === (int)$current['id']); ?>
                    <a href="?<?= e($param) ?>=<?= (int)$el['id'] ?>" role="option"
                       class="elpick-item<?= $isSel ? ' selected' : '' ?>"
                       data-search="<?= e(strtolower($el['title'] . ' ' . ($el['course'] !== '' ? $el['course'] : 'all courses'))) ?>">
                        <span class="elpick-course"><?= $el['course'] !== '' ? e($el['course']) : 'All' ?></span>
                        <span class="elpick-title"><?= e($el['title']) ?></span>
                        <?php if ($isSel): ?><i class="ti ti-check" style="color:#6B1A2A;"></i><?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <div class="elpick-none" id="elpick-none" style="display:none;"><?= t('auto.no_matching_elections_fe5c', 'No matching elections.') ?></div>
            </div>
        </div>
    </div>

    <style>
    .elpick { position:relative; margin-bottom:16px; max-width:640px; }
    .elpick-label { font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#9CA3AF; margin-bottom:6px; }
    .elpick-btn { width:100%; display:flex; align-items:center; gap:10px; padding:10px 14px; background:#fff; border:1px solid #D1D5DB; border-radius:8px; cursor:pointer; font-size:13px; text-align:left; transition:border-color .15s, box-shadow .15s; }
    .elpick-btn:hover, .elpick-btn[aria-expanded="true"] { border-color:#6B1A2A; box-shadow:0 0 0 3px rgba(107,26,42,.08); }
    .elpick-course { flex:none; background:#EDE9FE; color:#5B21B6; border-radius:20px; padding:2px 9px; font-size:11px; font-weight:700; }
    .elpick-title { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:600; color:#1F2937; }
    .elpick-status { flex:none; border-radius:20px; padding:2px 9px; font-size:11px; font-weight:600; }
    .elpick-status-active { background:#D1FAE5; color:#065F46; }
    .elpick-status-closed { background:#F3F4F6; color:#6B7280; }
    .elpick-status-completed { background:#DBEAFE; color:#1D4ED8; }
    .elpick-caret { flex:none; color:#6B7280; transition:transform .15s; }
    .elpick-btn[aria-expanded="true"] .elpick-caret { transform:rotate(180deg); }
    .elpick-panel { position:absolute; z-index:500; left:0; right:0; top:100%; margin-top:6px; background:#fff; border:1px solid #E5E7EB; border-radius:10px; box-shadow:0 12px 32px rgba(0,0,0,.14); overflow:hidden; }
    .elpick-search { display:flex; align-items:center; gap:8px; padding:10px 12px; border-bottom:1px solid #F3F4F6; color:#9CA3AF; }
    .elpick-search input { flex:1; border:none; outline:none; font-size:13px; background:transparent; color:#1F2937; }
    .elpick-list { max-height:340px; overflow-y:auto; padding:6px; }
    .elpick-group-head { display:flex; align-items:center; gap:6px; padding:8px 10px 4px; font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
    .elpick-group-head span { color:#9CA3AF; font-weight:600; }
    .elpick-item { display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:6px; text-decoration:none; font-size:13px; color:#1F2937; }
    .elpick-item:hover, .elpick-item.kbd { background:#F9FAFB; }
    .elpick-item.selected { background:#FEF3F5; }
    .elpick-none { padding:18px; text-align:center; font-size:13px; color:#9CA3AF; }
    </style>
    <script>
    (function () {
        var btn = document.getElementById('elpick-btn'),
            panel = document.getElementById('elpick-panel'),
            q = document.getElementById('elpick-q'),
            wrap = document.getElementById('elpick');

        function open()  { panel.style.display = ''; btn.setAttribute('aria-expanded', 'true'); q.value = ''; filter(''); q.focus(); }
        function close() { panel.style.display = 'none'; btn.setAttribute('aria-expanded', 'false'); }
        function visibleItems() {
            return Array.prototype.filter.call(panel.querySelectorAll('.elpick-item'), function (i) { return i.style.display !== 'none'; });
        }
        function words(t) { return ' ' + String(t).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim() + ' '; }
        function filter(text) {
            var tokens = words(text).trim().split(' ').filter(Boolean);
            var shown = 0;
            panel.querySelectorAll('.elpick-group').forEach(function (g) {
                var any = 0;
                g.querySelectorAll('.elpick-item').forEach(function (it) {
                    var hay = words(it.getAttribute('data-search'));
                    var ok = tokens.every(function (t) { return hay.indexOf(' ' + t) !== -1; });
                    it.style.display = ok ? '' : 'none';
                    it.classList.remove('kbd');
                    if (ok) any++;
                });
                g.style.display = any ? '' : 'none';
                shown += any;
            });
            document.getElementById('elpick-none').style.display = shown ? 'none' : '';
        }

        btn.addEventListener('click', function () { panel.style.display === 'none' ? open() : close(); });
        q.addEventListener('input', function () { filter(q.value); });
        document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

        // Arrow keys + Enter inside the search box
        q.addEventListener('keydown', function (e) {
            var items = visibleItems();
            if (!items.length) return;
            var idx = items.findIndex(function (i) { return i.classList.contains('kbd'); });
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (idx >= 0) items[idx].classList.remove('kbd');
                idx = e.key === 'ArrowDown' ? Math.min(idx + 1, items.length - 1) : Math.max(idx - 1, 0);
                items[idx].classList.add('kbd');
                items[idx].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                (items[idx >= 0 ? idx : 0]).click();
            }
        });
    })();
    </script>
    <?php
}


/**
 * Active / Closed / Completed tabs (same look as Admin > Elections). Each tab lists
 * its elections; clicking one loads it via ?$param=ID. The tab of the selected election opens first.
 * Used by results.php and live-results.php.
 */
function renderElectionTabs(array $elections, int $eid, string $param = 'id'): void {
    if (count($elections) < 1) return;
    $statusTabs = [
        'active'    => [t('ui.active_4d3d', 'Active'),    'ti-player-play',  []],
        'closed'    => [t('ui.closed_03f4', 'Closed'),    'ti-lock',         []],
        'completed' => [t('ui.completed_07ca', 'Completed'), 'ti-circle-check', []],
    ];
    $current = null;
    foreach ($elections as $el) {
        $statusTabs[electionStatusKey($el)][2][] = $el;
        if ((int)$el['id'] === $eid) $current = $el;
    }
    if (!$current) $current = $elections[0];
    $curStatus = electionStatusKey($current);
    $eid = (int)$current['id'];
    ?>
    <!-- Status tabs: Active / Closed / Completed -->
    <div class="lr-tabs">
        <?php foreach ($statusTabs as $tk => [$tLabel, $tIcon, $tList]): ?>
        <button type="button" class="lr-tab <?= $curStatus === $tk ? 'active' : '' ?>" id="lr-tab-btn-<?= $tk ?>" onclick="switchLrTab('<?= $tk ?>')">
            <i class="ti <?= $tIcon ?>"></i> <?= $tLabel ?>
            <span class="lr-tab-badge lr-tab-badge-<?= $tk ?>"><?= count($tList) ?></span>
        </button>
        <?php endforeach; ?>
    </div>
    <?php foreach ($statusTabs as $tk => [$tLabel, $tIcon, $tList]): ?>
    <div class="lr-tabpanel" id="lr-tabpanel-<?= $tk ?>" style="<?= $curStatus === $tk ? '' : 'display:none;' ?>">
        <?php if (empty($tList)): ?>
            <p class="lr-empty">No <?= strtolower($tLabel) ?> elections.</p>
        <?php else: ?>
            <?php $inTab = ($curStatus === $tk); ?>
            <div class="lp-pick">
                <button type="button" class="lp-btn" aria-haspopup="listbox" aria-expanded="false">
                    <?php if ($inTab): ?>
                    <span class="lp-course"><?= $current['course'] !== '' ? e($current['course']) : 'All' ?></span>
                    <span class="lp-title"><?= e($current['title']) ?></span>
                    <?php else: ?>
                    <span class="lp-title lp-placeholder">Select a <?= strtolower($tLabel) ?> election...</span>
                    <?php endif; ?>
                    <i class="ti ti-chevron-down lp-caret"></i>
                </button>
                <div class="lp-panel" style="display:none;">
                    <div class="lp-search">
                        <i class="ti ti-search"></i>
                        <input type="text" class="lp-q" placeholder="<?= htmlspecialchars(t('auto.search_election_or_course_e2bb', 'Search election or course...'), ENT_QUOTES) ?>" autocomplete="off">
                    </div>
                    <div class="lp-list" role="listbox">
                        <?php foreach ($tList as $el): $isSel = ((int)$el['id'] === (int)$eid); ?>
                        <a href="?<?= e($param) ?>=<?= (int)$el['id'] ?>" role="option"
                           class="lp-item<?= $isSel ? ' selected' : '' ?>"
                           data-search="<?= e(strtolower($el['title'] . ' ' . ($el['course'] !== '' ? $el['course'] : 'all courses'))) ?>">
                            <span class="lp-course"><?= $el['course'] !== '' ? e($el['course']) : 'All' ?></span>
                            <span class="lp-title"><?= e($el['title']) ?></span>
                            <?php if ($isSel): ?><i class="ti ti-check" style="color:#6B1A2A;"></i><?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                        <div class="lp-none" style="display:none;"><?= t('auto.no_matching_elections_fe5c', 'No matching elections.') ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <style>
    .lr-tabs { display:flex; gap:4px; border-bottom:2px solid #E5E7EB; margin-bottom:14px; flex-wrap:wrap; }
    .lr-tab { display:flex; align-items:center; gap:6px; padding:10px 16px; font-size:13px; font-weight:600; color:#6B7280; background:none; border:none; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; transition:all .15s; }
    .lr-tab:hover { color:#6B1A2A; }
    .lr-tab.active { color:#6B1A2A; border-bottom-color:#6B1A2A; }
    .lr-tab-badge { background:#9CA3AF; color:#fff; border-radius:20px; padding:1px 8px; font-size:11px; }
    .lr-tab-badge-active { background:#059669; }
    .lr-tab-badge-completed { background:#1D4ED8; }
    .lp-pick { position:relative; margin-bottom:16px; max-width:640px; }
    .lp-btn { width:100%; display:flex; align-items:center; gap:10px; padding:10px 14px; background:#fff; border:1px solid #D1D5DB; border-radius:8px; cursor:pointer; font-size:13px; text-align:left; transition:border-color .15s, box-shadow .15s; }
    .lp-btn:hover, .lp-btn[aria-expanded="true"] { border-color:#6B1A2A; box-shadow:0 0 0 3px rgba(107,26,42,.08); }
    .lp-course { flex:none; background:#EDE9FE; color:#5B21B6; border-radius:20px; padding:2px 9px; font-size:11px; font-weight:700; }
    .lp-title { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:600; color:#1F2937; }
    .lp-placeholder { color:#9CA3AF; font-weight:500; }
    .lp-caret { flex:none; color:#6B7280; transition:transform .15s; }
    .lp-btn[aria-expanded="true"] .lp-caret { transform:rotate(180deg); }
    .lp-panel { position:absolute; z-index:500; left:0; right:0; top:100%; margin-top:6px; background:#fff; border:1px solid #E5E7EB; border-radius:10px; box-shadow:0 12px 32px rgba(0,0,0,.14); overflow:hidden; }
    .lp-search { display:flex; align-items:center; gap:8px; padding:10px 12px; border-bottom:1px solid #F3F4F6; color:#9CA3AF; }
    .lp-search input { flex:1; border:none; outline:none; font-size:13px; background:transparent; color:#1F2937; }
    .lp-list { max-height:340px; overflow-y:auto; padding:6px; }
    .lp-item { display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:6px; text-decoration:none; font-size:13px; color:#1F2937; }
    .lp-item:hover, .lp-item.kbd { background:#F9FAFB; }
    .lp-item.selected { background:#FEF3F5; }
    .lp-none { padding:18px; text-align:center; font-size:13px; color:#9CA3AF; }
    .lr-empty { color:#9CA3AF; font-size:13px; text-align:center; padding:18px 0; }
    </style>
    <script>
    function switchLrTab(tab) {
        ['active','closed','completed'].forEach(function (k) {
            document.getElementById('lr-tabpanel-' + k).style.display = (k === tab) ? '' : 'none';
            document.getElementById('lr-tab-btn-' + k).classList.toggle('active', k === tab);
        });
    }
    (function () {
        function words(t) { return ' ' + String(t).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim() + ' '; }
        document.querySelectorAll('.lp-pick').forEach(function (wrap) {
            var btn = wrap.querySelector('.lp-btn'), panel = wrap.querySelector('.lp-panel'), q = wrap.querySelector('.lp-q'), none = wrap.querySelector('.lp-none');
            function items() { return Array.prototype.filter.call(panel.querySelectorAll('.lp-item'), function (i) { return i.style.display !== 'none'; }); }
            function filter(text) {
                var tokens = words(text).trim().split(' ').filter(Boolean), shown = 0;
                panel.querySelectorAll('.lp-item').forEach(function (it) {
                    var hay = words(it.getAttribute('data-search'));
                    var ok = tokens.every(function (t) { return hay.indexOf(' ' + t) !== -1; });
                    it.style.display = ok ? '' : 'none';
                    it.classList.remove('kbd');
                    if (ok) shown++;
                });
                none.style.display = shown ? 'none' : '';
            }
            function open()  { panel.style.display = ''; btn.setAttribute('aria-expanded', 'true'); q.value = ''; filter(''); q.focus(); }
            function close() { panel.style.display = 'none'; btn.setAttribute('aria-expanded', 'false'); }
            btn.addEventListener('click', function () { panel.style.display === 'none' ? open() : close(); });
            q.addEventListener('input', function () { filter(q.value); });
            document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
            q.addEventListener('keydown', function (e) {
                var list = items(); if (!list.length) return;
                var idx = list.findIndex(function (i) { return i.classList.contains('kbd'); });
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (idx >= 0) list[idx].classList.remove('kbd');
                    idx = e.key === 'ArrowDown' ? Math.min(idx + 1, list.length - 1) : Math.max(idx - 1, 0);
                    list[idx].classList.add('kbd'); list[idx].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'Enter') {
                    e.preventDefault(); list[idx >= 0 ? idx : 0].click();
                }
            });
        });
    })();
    </script>
    <?php
}

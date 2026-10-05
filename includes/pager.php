<?php
/**
 * Shared server-side pager. Styles live in assets/css/style.css (.gp-bar / .gp-nav / .gp-size),
 * the same look as assets/js/client-pager.js:  Showing a–b of N | ‹ Prev 1 2 3 Next › | Rows [n]
 */
function pagerCss(): void { /* styles are in assets/css/style.css */ }

/** Builds a URL keeping current query + overrides (used by pagerRender / vouchers). */
function pagerUrl(array $overrides): string {
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k => $v) { if ($v === '' || $v === null || ($k === 'p' && (int)$v <= 1)) unset($q[$k]); }
    return strtok($_SERVER['REQUEST_URI'], '?') . ($q ? '?' . http_build_query($q) : '');
}

/**
 * Generic bar. $url(int $page, ?int $perPage = null): string  builds the link for a page / for a new page size.
 * $ajax = true when the page swaps the list in place (no inline onchange; the page binds `.gp-size select`).
 */
function gpBar(int $page, int $perPage, int $total, callable $url, array $sizes, bool $ajax = false, string $unit = ''): void {
    if ($total === 0) return;
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min(max(1, $page), $pages);
    $from = max(1, $page - 2); $to = min($pages, $page + 2);
    $first = ($page - 1) * $perPage + 1; $last = min($page * $perPage, $total);
    ?>
    <div class="gp-bar">
        <span>Showing <?= number_format($first) ?>–<?= number_format($last) ?> of <?= number_format($total) ?><?= $unit !== '' ? ' ' . e($unit) . ($total != 1 ? 's' : '') : '' ?></span>
        <?php if ($pages > 1): ?>
        <nav class="gp-nav" aria-label="Pages">
            <?php if ($page > 1): ?><a href="<?= e($url($page - 1)) ?>" rel="prev">‹ Prev</a><?php else: ?><span class="dis">‹ Prev</span><?php endif; ?>
            <?php if ($from > 1): ?><a href="<?= e($url(1)) ?>">1</a><?php if ($from > 2): ?><span class="dis gap">…</span><?php endif; endif; ?>
            <?php for ($i = $from; $i <= $to; $i++): ?>
                <?php if ($i === $page): ?><span class="cur" aria-current="page"><?= $i ?></span><?php else: ?><a href="<?= e($url($i)) ?>"><?= $i ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($to < $pages): ?><?php if ($to < $pages - 1): ?><span class="dis gap">…</span><?php endif; ?><a href="<?= e($url($pages)) ?>"><?= $pages ?></a><?php endif; ?>
            <?php if ($page < $pages): ?><a href="<?= e($url($page + 1)) ?>" rel="next">Next ›</a><?php else: ?><span class="dis">Next ›</span><?php endif; ?>
        </nav>
        <?php endif; ?>
        <label class="gp-size">Rows
            <select <?= $ajax ? '' : 'onchange="location.href=this.value"' ?>>
                <?php foreach ($sizes as $s): ?>
                <option value="<?= e($url(1, (int)$s)) ?>" <?= (int)$s === $perPage ? 'selected' : '' ?>><?= (int)$s ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
<?php }

function pagerRender(int $page, int $perPage, int $total, array $sizes = [25, 50, 100]): void {
    gpBar($page, $perPage, $total, function (int $p, ?int $pp = null) {
        return pagerUrl($pp !== null ? ['pp' => $pp, 'p' => 1] : ['p' => $p]);
    }, $sizes);
}

/** Rows-per-page from ?pp=, limited to the allowed sizes. */
function pagerSize(array $sizes, int $default): int {
    $v = (int)($_GET['pp'] ?? 0);
    return in_array($v, $sizes, true) ? $v : $default;
}

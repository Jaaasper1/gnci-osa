<?php
if (!function_exists('t')) { require_once __DIR__ . '/lang.php'; }
// Expects $fbFeed (from fbGetPagePosts) and $fbUploadsPrefix (relative path
// to the uploads/ folder from wherever this is included, e.g. "../uploads/").
// Rendered as a fragment so it can be swapped in-place via AJAX polling.
?>
<?php if (!fbIsConfigured()): ?>
    <p style="color:#9CA3AF;font-size:13px;padding:12px 0;">
        <?= t('auto.facebook_page_is_not_configured_yet_c869', t('msg.facebook_page_is_not_configured_yet_c869', 'Facebook Page is not configured yet.')) ?>
    </p>
<?php elseif ($fbFeed['error'] && empty($fbFeed['posts'])): ?>
    <p style="color:#DC2626;font-size:13px;padding:12px 0;"><i class="ti ti-alert-circle"></i> <?= e($fbFeed['error']) ?></p>
<?php elseif (empty($fbFeed['posts'])): ?>
    <p style="color:#9CA3AF;font-size:13px;text-align:center;padding:24px 0;"><?= t('auto.no_posts_on_the_facebook_page_yet_93b5', 'No posts on the Facebook Page yet.') ?></p>
<?php else: ?>
    <?php if ($fbFeed['error']): ?>
        <p style="color:#D97706;font-size:12px;margin-bottom:10px;"><i class="ti ti-alert-triangle"></i> <?= e(tx('Could not refresh (%s) — showing the last cached posts.', $fbFeed['error'])) ?></p>
    <?php endif; ?>
    <div class="fb-grid" data-more-group="fb">
        <?php $fbIdx = 0; foreach ($fbFeed['posts'] as $post): $fbIdx++; ?>
        <?php
            $fbMsg   = trim((string)($post['message'] ?? ''));
            $fbHasImg = !empty($post['full_picture']);
        ?>
        <div class="fb-post<?= $fbIdx > 3 ? ' more-item' : '' ?>" style="border:1px solid #E5E7EB;border-radius:10px;overflow:hidden;display:flex;flex-direction:column;">
            <?php if ($fbHasImg): ?>
                <img src="<?= e($fbUploadsPrefix) ?><?= e($post['full_picture']) ?>" style="width:100%;aspect-ratio:16/9;object-fit:cover;display:block;">
            <?php endif; ?>
            <div style="padding:10px 12px;display:flex;flex-direction:column;flex:1;">
                <?php if ($fbMsg !== ''): ?>
                    <div style="font-size:12px;color:#1F2937;max-height:60px;overflow:hidden;text-overflow:ellipsis;white-space:pre-line;">
                        <?= e(mb_strimwidth($fbMsg, 0, 150, '…')) ?>
                    </div>
                <?php elseif (!$fbHasImg): ?>
                    <!-- No photo and no caption (shared post, video/link post, or an image that couldn't be loaded) -->
                    <div style="flex:1;min-height:130px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:6px;padding:14px 10px;background:#F3F4F6;border-radius:8px;color:#6B7280;">
                        <i class="ti ti-brand-facebook" style="font-size:26px;color:#1877F2;"></i>
                        <div style="font-size:12px;font-weight:600;color:#374151;"><?= t('auto.no_preview_available_1921', 'No preview available') ?></div>
                        <div style="font-size:11px;"><?= t('auto.this_post_has_no_photo_or_caption_to_sho_5797', 'This post has no photo or caption to show here. Tap “View on Facebook” to see it.') ?></div>
                    </div>
                <?php else: ?>
                    <div style="font-size:12px;color:#9CA3AF;font-style:italic;"><?= t('auto.no_caption_31aa', '(no caption)') ?></div>
                <?php endif; ?>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:auto;padding-top:8px;">
                    <span style="font-size:11px;color:#9CA3AF;"><?= date('M j, Y', strtotime($post['created_time'])) ?></span>
                    <?php if (!empty($post['permalink_url'])): ?>
                        <a href="<?= e($post['permalink_url']) ?>" target="_blank" rel="noopener" style="font-size:11px;color:#1877F2;"><?= t('auto.view_on_facebook_3f4a', 'View on Facebook →') ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (count($fbFeed['posts']) > 3): ?>
    <div class="more-wrap"><button type="button" class="btn btn-outline btn-sm more-toggle" data-more-toggle="fb" data-label-more="<?= e(tx('Show all (%d)', count($fbFeed['posts']))) ?>" data-label-less="<?= e(tx('Show less')) ?>"><i class="ti ti-chevron-down"></i> <span><?= e(tx('Show all (%d)', count($fbFeed['posts']))) ?></span></button></div>
    <?php endif; ?>
<?php endif; ?>

<?php
// Expects $fbFeed (from fbGetPagePosts) and $fbUploadsPrefix (relative path
// to the uploads/ folder from wherever this is included, e.g. "../uploads/").
// Rendered as a fragment so it can be swapped in-place via AJAX polling.
?>
<?php if (!fbIsConfigured()): ?>
    <p style="color:#9CA3AF;font-size:13px;padding:12px 0;">
        Facebook Page is not configured yet.
    </p>
<?php elseif ($fbFeed['error'] && empty($fbFeed['posts'])): ?>
    <p style="color:#DC2626;font-size:13px;padding:12px 0;"><i class="ti ti-alert-circle"></i> <?= e($fbFeed['error']) ?></p>
<?php elseif (empty($fbFeed['posts'])): ?>
    <p style="color:#9CA3AF;font-size:13px;text-align:center;padding:24px 0;">No posts on the Facebook Page yet.</p>
<?php else: ?>
    <?php if ($fbFeed['error']): ?>
        <p style="color:#D97706;font-size:12px;margin-bottom:10px;"><i class="ti ti-alert-triangle"></i> Could not refresh (<?= e($fbFeed['error']) ?>) — showing the last cached posts.</p>
    <?php endif; ?>
    <div class="fb-grid" data-more-group="fb">
        <?php $fbIdx = 0; foreach ($fbFeed['posts'] as $post): $fbIdx++; ?>
        <div class="fb-post<?= $fbIdx > 3 ? ' more-item' : '' ?>" style="border:1px solid #E5E7EB;border-radius:10px;overflow:hidden;">
            <?php if (!empty($post['full_picture'])): ?>
                <img src="<?= e($fbUploadsPrefix) ?><?= e($post['full_picture']) ?>" style="width:100%;aspect-ratio:16/9;object-fit:cover;display:block;">
            <?php endif; ?>
            <div style="padding:10px 12px;">
                <div style="font-size:12px;color:#1F2937;max-height:60px;overflow:hidden;text-overflow:ellipsis;white-space:pre-line;">
                    <?= e(mb_strimwidth($post['message'] ?? '(no caption)', 0, 150, '…')) ?>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
                    <span style="font-size:11px;color:#9CA3AF;"><?= date('M j, Y', strtotime($post['created_time'])) ?></span>
                    <?php if (!empty($post['permalink_url'])): ?>
                        <a href="<?= e($post['permalink_url']) ?>" target="_blank" rel="noopener" style="font-size:11px;color:#1877F2;">View on Facebook →</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (count($fbFeed['posts']) > 3): ?>
    <div class="more-wrap"><button type="button" class="btn btn-outline btn-sm more-toggle" data-more-toggle="fb" data-label-more="Show all (<?= count($fbFeed['posts']) ?>)" data-label-less="Show less"><i class="ti ti-chevron-down"></i> <span>Show all (<?= count($fbFeed['posts']) ?>)</span></button></div>
    <?php endif; ?>
<?php endif; ?>

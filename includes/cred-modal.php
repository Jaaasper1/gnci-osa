<?php
// Shared "View credentials" popup (used by admin/candidates.php and student/voting.php).
// Candidate pages render the clipped preview as  <div class="cred-src [cred-collapsed]">…</div>
// followed by  <button class="cred-open" data-name="…">…</button>.
?>
<style>
.cred-src, .cred-body { overflow-wrap: anywhere; word-break: break-word; }
.cred-src.cred-collapsed { max-height: 5.2em; overflow: hidden; -webkit-mask-image: linear-gradient(#000 60%, transparent); mask-image: linear-gradient(#000 60%, transparent); }
.cred-open { display: inline-flex; align-items: center; justify-content: center; gap: 5px; margin: 4px 0 8px; padding: 4px 12px; font-size: 11.5px; font-weight: 600; font-family: inherit; line-height: 1.3; white-space: nowrap; color: var(--maroon, #6B1A2A); background: var(--maroon-pale, #F9EEF0); border: 0; border-radius: 999px; cursor: pointer; }
.cred-open i { font-size: 13px; }
.cred-open:hover { filter: brightness(.95); text-decoration: underline; }
.cred-open:focus-visible { outline: 2px solid var(--maroon, #6B1A2A); outline-offset: 2px; }
.cred-overlay { position: fixed; inset: 0; z-index: 3000; display: none; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.5); }
.cred-overlay.open { display: flex; }
.cred-dialog { width: 100%; max-width: 520px; max-height: 85vh; display: flex; flex-direction: column; background: var(--white, #fff); color: var(--gray-800, #1F2937); border-radius: 14px; box-shadow: 0 12px 40px rgba(0,0,0,.25); overflow: hidden; text-align: left; }
.cred-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--gray-200, #E5E7EB); }
.cred-head .cred-sub { font-size: 11.5px; color: var(--gray-400, #9CA3AF); }
.cred-head .cred-title { font-size: 15px; font-weight: 700; }
.cred-x { background: none; border: 0; font-size: 20px; line-height: 1; cursor: pointer; color: var(--gray-400, #9CA3AF); padding: 4px 8px; border-radius: 8px; }
.cred-x:hover { background: var(--gray-100, #F3F4F6); }
.cred-body { padding: 16px 20px; overflow-y: auto; overflow-x: hidden; font-size: 13px; line-height: 1.55; color: var(--gray-800, #1F2937); }
</style>
<div class="cred-overlay" id="cred-overlay" role="dialog" aria-modal="true" aria-labelledby="cred-title">
    <div class="cred-dialog">
        <div class="cred-head">
            <div>
                <div class="cred-sub"><?= htmlspecialchars(t('ui.credentials_honors_b3f7', 'Credentials / Honors')) ?></div>
                <div class="cred-title" id="cred-title"></div>
            </div>
            <button type="button" class="cred-x" id="cred-x" aria-label="<?= htmlspecialchars(t('ui.close', 'Close')) ?>">&times;</button>
        </div>
        <div class="cred-body" id="cred-body"></div>
    </div>
</div>
<script>
(function () {
    var ov = document.getElementById('cred-overlay'), body = document.getElementById('cred-body'),
        ttl = document.getElementById('cred-title'), x = document.getElementById('cred-x'), last = null;
    function close() { ov.classList.remove('open'); document.body.style.overflow = ''; if (last) last.focus(); }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.cred-open');
        if (b) {
            var src = b.parentNode.querySelector('.cred-src');
            if (!src) return;
            last = b; ttl.textContent = b.getAttribute('data-name') || '';
            body.innerHTML = src.innerHTML;          // already escaped by the server (nl2br + htmlspecialchars)
            ov.classList.add('open'); document.body.style.overflow = 'hidden'; x.focus();
            return;
        }
        if (e.target === ov || e.target.closest('#cred-x')) close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && ov.classList.contains('open')) close(); });
})();
</script>

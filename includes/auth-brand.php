<?php // Left-hand panel for index.php, register.php and staff-login.php: plain background video only.
// Bandwidth saver: the 2.8 MB video is only fetched on wide screens (desktop/tablet) with Save-Data off
// and motion allowed. Phones and everyone else just get the small poster image. ?>
<aside class="auth-brand">
    <video class="login-bg-video" autoplay muted loop playsinline preload="none" disablepictureinpicture poster="assets/video/login-bg-poster.jpg" aria-hidden="true"></video>
    <script>
    (function () {
        var v = document.querySelector('.login-bg-video');
        if (!v) return;
        var c = navigator.connection || {};
        var wide = window.matchMedia && window.matchMedia('(min-width: 900px)').matches;
        var calm = !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        if (!wide || !calm || c.saveData || /(^|-)2g$/.test(c.effectiveType || '')) return;
        v.src = 'assets/video/login-bg.mp4';
        v.load();
    })();
    </script>
</aside>

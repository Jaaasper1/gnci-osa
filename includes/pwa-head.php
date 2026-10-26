<?php
// PWA tags: include right after <head> on every page. Works from root and one-level subfolders.
// Pages inside these folders need "../" to reach the project root. (Based on the URL, so it
// works on hosting where realpath() differs between __DIR__ and SCRIPT_FILENAME.)
$__sub = basename(dirname(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '')));
$pwaBase = in_array($__sub, ['admin', 'osa', 'student', 'staff-otp'], true) ? '../' : '';
// Auto cache-busting: the ?v= changes whenever the file changes, so browsers, hosting caches and the service worker always fetch the new dark mode files.
$__v = function ($rel) { $f = dirname(__DIR__) . '/' . $rel; return is_file($f) ? filemtime($f) : 1; };
?>
<link rel="manifest" href="<?= $pwaBase ?>manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#6B1A2A">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="OSA Portal">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="<?= $pwaBase ?>assets/icons/apple-touch-icon-v5.png">
<link rel="icon" href="<?= $pwaBase ?>favicon.ico?v=5" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="<?= $pwaBase ?>assets/icons/favicon-32.png?v=5">
<link rel="icon" type="image/png" sizes="16x16" href="<?= $pwaBase ?>assets/icons/favicon-16.png?v=5">
<script>
/* Dark mode: light is the default. Dark is applied only if this user turned it on (saved in their browser), before first paint so there is no flash. */
(function(){try{if(localStorage.getItem('osaTheme')==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();
</script>
<link rel="stylesheet" href="<?= $pwaBase ?>assets/css/dark-auto.css?v=<?= $__v('assets/css/dark-auto.css') ?>">
<link rel="stylesheet" href="<?= $pwaBase ?>assets/css/dark.css?v=<?= $__v('assets/css/dark.css') ?>">
<script src="<?= $pwaBase ?>i18n-js.php?l=<?= (isset($_COOKIE['gnc_lang']) && $_COOKIE['gnc_lang'] === 'tl') ? 'tl' : 'en' ?>"></script>
<script src="<?= $pwaBase ?>assets/js/dark.js?v=<?= $__v('assets/js/dark.js') ?>"></script>
<link rel="stylesheet" href="<?= $pwaBase ?>assets/css/a11y.css?v=<?= $__v('assets/css/a11y.css') ?>">
<script src="<?= $pwaBase ?>assets/js/a11y.js?v=<?= $__v('assets/js/a11y.js') ?>"></script>
<script>
/* Branded splash: only when opened as the installed app, once per session. */
(function(){
  try{
    var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone;
    if(!standalone) return;
    if(sessionStorage.getItem('osaSplash')) return;
    sessionStorage.setItem('osaSplash','1');
  }catch(e){ return; }
  var css = document.createElement('style');
  css.textContent =
   '#osa-splash{position:fixed;inset:0;z-index:2147483647;background:radial-gradient(circle at 50% 38%,#8a2639 0,#6B1A2A 55%,#4a1120 100%);display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#fff;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:24px;transition:opacity .45s ease,visibility .45s}' +
   '#osa-splash.hide{opacity:0;visibility:hidden}' +
   '#osa-splash img{width:min(46vw,190px);height:auto;filter:drop-shadow(0 8px 24px rgba(0,0,0,.35));animation:osaPop .9s cubic-bezier(.2,.8,.2,1) both}' +
   '#osa-splash h1{font-size:clamp(18px,5.2vw,24px);margin:22px 0 4px;font-weight:700;letter-spacing:.2px;animation:osaUp .7s .25s ease both}' +
   '#osa-splash p{margin:0;font-size:clamp(13px,3.8vw,16px);opacity:.88;animation:osaUp .7s .4s ease both}' +
   '#osa-splash .motto{margin-top:18px;font-size:12px;letter-spacing:.22em;text-transform:uppercase;opacity:.75;animation:osaUp .7s .6s ease both}' +
   '#osa-splash .bar{margin-top:34px;width:min(52vw,200px);height:4px;border-radius:4px;background:rgba(255,255,255,.22);overflow:hidden;animation:osaUp .7s .7s ease both}' +
   '#osa-splash .bar i{display:block;height:100%;width:40%;border-radius:4px;background:#fff;animation:osaBar 1.1s ease-in-out infinite}' +
   '@keyframes osaPop{from{opacity:0;transform:scale(.7)}to{opacity:1;transform:scale(1)}}' +
   '@keyframes osaUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}' +
   '@keyframes osaBar{0%{transform:translateX(-110%)}100%{transform:translateX(260%)}}' +
   '@media (prefers-reduced-motion:reduce){#osa-splash *{animation:none!important}}';
  document.head.appendChild(css);
  var d = document.createElement('div'); d.id = 'osa-splash'; d.setAttribute('aria-hidden','true');
  d.innerHTML =
   '<img src="<?= $pwaBase ?>assets/icons/icon-512-v5.png" alt="">' +
   '<h1>Office of Student Affairs Portal</h1>' +
   '<p>Guagua National Colleges, Inc.</p>' +
   '<div class="motto">Auxilium &bull; Cura &bull; Consilium</div>' +
   '<div class="bar"><i></i></div>';
  document.documentElement.appendChild(d);
  var start = Date.now(), gone = false;
  function close(){ if(gone) return; gone = true; d.classList.add('hide'); setTimeout(function(){ d.remove(); }, 600); }
  function ready(){ var wait = Math.max(0, 1500 - (Date.now() - start)); setTimeout(close, wait); }
  if(document.readyState === 'complete') ready(); else window.addEventListener('load', ready);
  setTimeout(close, 4000);   /* safety: never block the app */
})();
</script>
<?php if (!empty($_SESSION['user_id'])): ?>
<script>
/* Auto-logout when the installed app is closed (swiped away from Recent Apps).
   sessionStorage is wiped when the app is killed, so a missing flag = cold start of the installed app.
   Switching apps and coming back does NOT trigger this (the flag survives). */
(function(){
  try{
    var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone;
    if(!standalone) return;
    if(sessionStorage.getItem('osaAlive')) return;
    sessionStorage.setItem('osaAlive','1');
    location.replace('<?= $pwaBase ?>logout.php');
  }catch(e){}
})();
</script>
<?php else: ?>
<script>try{ sessionStorage.setItem('osaAlive','1'); }catch(e){}</script>
<?php endif; ?>
<script>
(function(){
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function(){
      navigator.serviceWorker.register('<?= $pwaBase ?>sw.js').catch(function(){});
    });
  }
  var deferred = null;
  if (window.matchMedia('(display-mode: standalone)').matches || navigator.standalone) return;

  /* iOS (iPhone/iPad) has NO install prompt (beforeinstallprompt does not exist there). Show the same Install App
     button, but it opens a short how-to: Share > Add to Home Screen. Facebook/Messenger/Instagram in-app browsers
     cannot add to the Home Screen at all, so those users are told to open the page in Safari first. */
  var ua = navigator.userAgent || '';
  var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  if (isIOS) {
    var inApp = /FBAN|FBAV|FB_IAB|Instagram|Line\/|MicroMessenger|Messenger|TikTok|Snapchat|Twitter/i.test(ua);
    var showHelp = function(){
      if (document.getElementById('osa-ios-help')) return;
      var steps = inApp
        ? [__t('This in-app browser cannot install apps. Tap the menu (the three dots or the compass icon) and choose <b>Open in Safari</b>.'),
           __t('In Safari, tap the <b>Share</b> button (the square with an arrow pointing up).'),
           __t('Scroll down and tap <b>Add to Home Screen</b>, then tap <b>Add</b>.')]
        : [__t('Tap the <b>Share</b> button (the square with an arrow pointing up) in the browser toolbar.'),
           __t('Scroll down and tap <b>Add to Home Screen</b>.'),
           __t('Tap <b>Add</b>. The OSA Portal icon will appear on your Home Screen.')];
      var o = document.createElement('div');
      o.id = 'osa-ios-help';
      o.setAttribute('style','position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);display:flex;align-items:flex-end;justify-content:center;padding:16px;padding-bottom:calc(16px + env(safe-area-inset-bottom, 0px));font-family:system-ui,-apple-system,sans-serif');
      var li = '';
      for (var i = 0; i < steps.length; i++) li += '<li style="margin:0 0 10px;line-height:1.45">' + steps[i] + '</li>';
      o.innerHTML =
        '<div style="background:#fff;color:#222;max-width:420px;width:100%;border-radius:16px;padding:20px 20px 16px;box-shadow:0 10px 40px rgba(0,0,0,.4)">' +
        '<div style="font-size:17px;font-weight:700;color:#6B1A2A;margin:0 0 12px">' + __t('Install OSA Portal on your iPhone') + '</div>' +
        '<ol style="margin:0 0 14px;padding-left:20px;font-size:14.5px;color:#222">' + li + '</ol>' +
        '<button type="button" style="width:100%;background:#6B1A2A;color:#fff;border:0;border-radius:10px;padding:12px;font:600 15px system-ui,sans-serif;cursor:pointer">' + __t('Got it') + '</button>' +
        '</div>';
      o.onclick = function(ev){ if (ev.target === o || ev.target.tagName === 'BUTTON') o.remove(); };
      document.body.appendChild(o);
    };
    var addBtn = function(){
      var b = document.createElement('button');
      b.dataset.osaInstall = '1';
      b.type = 'button';
      b.textContent = __t('Install App');
      b.setAttribute('style','position:fixed;left:16px;bottom:calc(16px + env(safe-area-inset-bottom, 0px));z-index:9000;background:#6B1A2A;color:#fff;border:2px solid #fff;border-radius:999px;padding:10px 18px;font:600 14px system-ui,sans-serif;box-shadow:0 4px 14px rgba(0,0,0,.3);cursor:pointer');
      b.onclick = showHelp;
      document.body.appendChild(b);
    };
    if (document.body) addBtn(); else document.addEventListener('DOMContentLoaded', addBtn);
    return;
  }

  window.addEventListener('beforeinstallprompt', function(e){
    e.preventDefault(); deferred = e;
    var b = document.createElement('button');
    b.dataset.osaInstall = '1';
    b.textContent = __t('Install App');
    b.setAttribute('style','position:fixed;left:16px;bottom:calc(16px + env(safe-area-inset-bottom, 0px));z-index:9000;background:#6B1A2A;color:#fff;border:2px solid #fff;border-radius:999px;padding:10px 18px;font:600 14px system-ui,sans-serif;box-shadow:0 4px 14px rgba(0,0,0,.3);cursor:pointer');
    b.onclick = function(){ deferred.prompt(); deferred.userChoice.then(function(){ b.remove(); deferred = null; }); };
    // Desktop split-screen login: sit at the bottom-left of the form panel (right of the photo).
    if (document.querySelector('.auth-split') && window.matchMedia('(min-width: 901px)').matches) { b.style.left = 'calc(58vw + 24px)'; }
    document.body.appendChild(b);
  });
  window.addEventListener('appinstalled', function(){ document.querySelectorAll('button').forEach(function(x){ if (x.dataset.osaInstall === '1') x.remove(); }); });
})();
</script>

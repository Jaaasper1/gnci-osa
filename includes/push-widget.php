<?php
require_once __DIR__ . '/push-config.php';
// Only render for logged-in admins, and only once OneSignal is configured.
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') return;
if (ONESIGNAL_APP_ID === '') return;
?>
<script defer src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js"></script>
<script>
  window.OneSignalDeferred = window.OneSignalDeferred || [];
  OneSignalDeferred.push(async function(OneSignal) {
    await OneSignal.init({
      appId: "<?= htmlspecialchars(ONESIGNAL_APP_ID, ENT_QUOTES) ?>",
      allowLocalhostAsSecureOrigin: true,
    });
    // Tag this browser as belonging to an admin so sendAdminPush() can target it.
    await OneSignal.User.addTag("role", "admin");
    // Ask for permission if not yet decided (does nothing if already granted/denied).
    if (OneSignal.Notifications.permission !== true) {
      OneSignal.Slidedown.promptPush();
    }
  });
</script>

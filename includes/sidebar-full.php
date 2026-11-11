<?php
// Shared sidebar for admin + staff (OSA) pages. Set $activePage before including.
// Needs: $pdo, $notifCount. Links go to /admin/ so they work from any folder.
require_once __DIR__ . '/messages.php';
$__admin = ($_SESSION['user_type'] ?? '') === 'admin';
$__a = $activePage ?? '';
$__c = function ($k) use ($__a) { return $k === $__a ? ' active' : ''; };
?>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.overview', 'Overview') ?></div>
    <a class="sitem<?= $__c('dashboard') ?>" href="../admin/dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <div class="sidebar-section"><?= t('sidebar.complaints', 'Complaints') ?></div>
    <a class="sitem<?= $__c('complaints') ?>" href="../admin/complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.all_complaints', 'All Complaints') ?></a>
    <a class="sitem<?= $__c('messages') ?>" href="../osa/messages.php"><i class="ti ti-messages"></i> <?= t('sidebar.messages', 'Messages') ?><?= msgSidebarBadge($pdo) ?></a>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="../admin/elections.php"><i class="ti ti-trophy"></i> <?= t('sidebar.elections', 'Elections') ?></a>
    <a class="sitem" href="../admin/candidates.php"><i class="ti ti-users"></i> <?= t('sidebar.candidates', 'Candidates') ?></a>
    <a class="sitem" href="../admin/party-lists.php"><i class="ti ti-flag"></i> <?= t('sidebar.party_lists', 'Party Lists') ?></a>
    <a class="sitem" href="../admin/vouchers.php"><i class="ti ti-ticket"></i> <?= t('sidebar.vouchers', 'Vouchers') ?></a>
    <a class="sitem" href="../admin/results.php"><i class="ti ti-chart-pie"></i> <?= t('sidebar.live_results', 'Live Results') ?></a>
    <div class="sidebar-section"><?= t('sidebar.events', 'Events') ?></div>
    <a class="sitem" href="../admin/events.php"><i class="ti ti-calendar-event"></i> <?= t('sidebar.events', 'Events') ?></a>
    <div class="sidebar-section"><?= t('sidebar.system', 'System') ?></div>
    <a class="sitem" href="../admin/students.php"><i class="ti ti-user-plus"></i> <?= t('sidebar.students', 'Students') ?></a>
    <?php if ($__admin): ?><a class="sitem" href="../admin/staff.php"><i class="ti ti-shield-check"></i> <?= t('sidebar.osa_staff', 'OSA Staff') ?></a><?php endif; ?>
    <a class="sitem" href="../admin/reports.php"><i class="ti ti-report-analytics"></i> <?= t('sidebar.reports', 'Reports') ?></a>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem<?= $__c('settings') ?>" href="../osa/settings.php"><i class="ti ti-settings"></i> <?= t('sidebar.settings', 'Settings') ?></a>
    <a class="sitem<?= $__c('notifications') ?>" href="../admin/notifications.php"><i class="ti ti-bell"></i> <?= t('sidebar.notifications', 'Notifications') ?><?php if (($notifCount ?? 0) > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?></a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>

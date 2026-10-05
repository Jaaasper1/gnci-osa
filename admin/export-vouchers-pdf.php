<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$elections = $pdo->query("SELECT * FROM elections WHERE is_active=1 ORDER BY created_at DESC")->fetchAll();
$election_id = (int)($_GET['election_id'] ?? ($elections[0]['id'] ?? 0));
$election = null;
$vouchers = [];

if ($election_id) {
    $se = $pdo->prepare("SELECT * FROM elections WHERE id=?");
    $se->execute([$election_id]);
    $election = $se->fetch();

    if ($election) {
        $showAll = isset($_GET['all']) && $_GET['all'] == '1';
        $whereUsed = $showAll ? '' : 'AND vv.is_used = 0';

        $vs = $pdo->prepare("
            SELECT vv.*, s.first_name, s.last_name, s.student_id as sid, s.course, s.year_level
            FROM voting_vouchers vv
            JOIN students s ON vv.student_id = s.id
            WHERE vv.election_id = ? $whereUsed
            ORDER BY s.last_name, s.first_name
        ");
        $vs->execute([$election_id]);
        $vouchers = $vs->fetchAll();
    }
}

$generatedDate = date('F j, Y \a\t g:i A');
$showAll = isset($_GET['all']) && $_GET['all'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voucher PDF — <?= $election ? e($election['title']) : 'GNC-OSA' ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
    <style>
        :root {
            --maroon:      #6B1A2A;
            --maroon-dark: #4A0F1B;
            --maroon-pale: #F9EEF0;
            --gold:        #C9943A;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #E5E7EB; color: #1F2937; }

        /* ── Screen toolbar ── */
        .toolbar {
            background: var(--maroon);
            color: #fff;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .toolbar .brand { display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 600; }
        .toolbar .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .btn-t {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 16px; border-radius: 7px; font-size: 13px;
            font-weight: 600; cursor: pointer; border: none;
            text-decoration: none; transition: opacity 0.15s;
        }
        .btn-t:hover { opacity: 0.85; }
        .btn-print  { background: var(--gold); color: #fff; }
        .btn-back   { background: rgba(255,255,255,0.15); color: #fff; }
        .btn-toggle { background: rgba(255,255,255,0.12); color: #fff; font-size: 12px; }
        .ctrl-info  { font-size: 12px; opacity: 0.75; }

        .election-bar {
            background: #fff;
            border-bottom: 1px solid #E5E7EB;
            padding: 10px 24px;
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .election-bar label { font-size: 13px; font-weight: 600; color: var(--maroon-dark); }
        .election-bar select {
            padding: 6px 10px; border: 1px solid #D1D5DB;
            border-radius: 7px; font-size: 13px; color: #374151; background: #fff;
        }

        /* ── Print page ── */
        .print-area { padding: 24px; max-width: 900px; margin: 0 auto; }

        .page-header-block {
            background: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 18px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
        }
        .phb-title { font-size: 16px; font-weight: 700; color: var(--maroon-dark); }
        .phb-sub   { font-size: 12px; color: #6B7280; margin-top: 2px; }
        .phb-right { text-align: right; font-size: 12px; color: #9CA3AF; }
        .phb-count {
            display: inline-block; background: var(--maroon-pale);
            color: var(--maroon); font-size: 12px; font-weight: 700;
            padding: 4px 10px; border-radius: 20px;
        }

        /* ── Voucher grid: 2 per row ── */
        .voucher-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .voucher-slip {
            background: #fff;
            border: 2px solid var(--maroon);
            border-radius: 10px;
            overflow: hidden;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .slip-header {
            background: var(--maroon);
            color: #fff;
            padding: 10px 14px;
            display: flex; align-items: center; gap: 8px;
        }
        .slip-logo {
            width: 32px; height: 32px; border-radius: 50%;
            overflow: hidden; flex-shrink: 0;
        }
        .slip-logo img { width: 100%; height: 100%; object-fit: cover; }
        .slip-school-name  { font-size: 11px; font-weight: 700; letter-spacing: 0.03em; }
        .slip-portal-name  { font-size: 10px; opacity: 0.75; }
        .slip-ticket-label {
            margin-left: auto;
            background: rgba(255,255,255,0.15);
            border-radius: 5px; padding: 3px 8px;
            font-size: 10px; font-weight: 700;
            letter-spacing: 0.05em; text-transform: uppercase;
            display: flex; align-items: center; gap: 4px;
        }

        .slip-body { padding: 12px 14px; }

        .slip-election {
            font-size: 11px; font-weight: 700;
            color: var(--maroon); text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 8px;
            border-bottom: 1px dashed #E5E7EB;
            padding-bottom: 8px;
        }
        .slip-name { font-size: 14px; font-weight: 700; color: #1F2937; margin-bottom: 2px; }
        .slip-meta { font-size: 11px; color: #6B7280; margin-bottom: 12px; }

        .slip-code-label {
            font-size: 10px; font-weight: 600; color: #9CA3AF;
            text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px;
        }
        .slip-code {
            font-family: 'Courier New', monospace;
            font-size: 20px; font-weight: 700; letter-spacing: 3px;
            color: var(--maroon-dark);
            background: var(--maroon-pale);
            border: 1.5px dashed var(--maroon);
            border-radius: 7px; padding: 8px 14px;
            display: inline-block; margin-bottom: 10px;
        }
        .slip-code.used { color: #9CA3AF; background: #F3F4F6; border-color: #D1D5DB; text-decoration: line-through; }

        .slip-footer {
            display: flex; align-items: center; justify-content: space-between;
            border-top: 1px dashed #E5E7EB; padding-top: 8px; margin-top: 4px;
        }
        .slip-note { font-size: 10px; color: #9CA3AF; line-height: 1.4; }
        .slip-status {
            font-size: 10px; font-weight: 700;
            padding: 3px 8px; border-radius: 12px; flex-shrink: 0;
        }
        .slip-status.active { background: #D1FAE5; color: #065F46; }
        .slip-status.used   { background: #F3F4F6; color: #6B7280; }

        .empty-state {
            text-align: center; padding: 60px 20px; color: #9CA3AF; font-size: 14px;
        }
        .empty-state i { font-size: 48px; display: block; margin-bottom: 10px; }

        /* ── Print styles ── */
        @media print {
            body { background: #fff; }
            .toolbar, .election-bar { display: none !important; }
            .print-area { padding: 0; max-width: 100%; }
            .voucher-grid { gap: 10px; }
            .voucher-slip { border: 1.5px solid var(--maroon); }
            .page-header-block {
                border: none; border-bottom: 1.5px solid #E5E7EB;
                border-radius: 0; padding: 0 0 12px 0; margin-bottom: 14px;
            }
            @page { size: A4; margin: 12mm 10mm; }
        }
    </style>
</head>
<body>

<!-- Toolbar (screen only) -->
<div class="toolbar">
    <div class="brand">
        <i class="ti ti-file-type-pdf" style="font-size:20px;"></i>
        PDF Export — Voting Vouchers
    </div>
    <div class="actions">
        <?php if ($election): ?>
        <span class="ctrl-info"><?= count($vouchers) ?> voucher<?= count($vouchers) !== 1 ? 's' : '' ?></span>
        <?php if (!$showAll): ?>
        <a class="btn-t btn-toggle" href="?election_id=<?= $election_id ?>&all=1">
            <i class="ti ti-eye"></i> Include Used
        </a>
        <?php else: ?>
        <a class="btn-t btn-toggle" href="?election_id=<?= $election_id ?>">
            <i class="ti ti-eye-off"></i> Active Only
        </a>
        <?php endif; ?>
        <button class="btn-t btn-print" onclick="window.print()">
            <i class="ti ti-printer"></i> Print / Save as PDF
        </button>
        <?php endif; ?>
        <a class="btn-t btn-back" href="vouchers.php<?= $election_id ? '?election_id='.$election_id : '' ?>">
            <i class="ti ti-arrow-left"></i> Back
        </a>
    </div>
</div>

<!-- Election selector (screen only) -->
<?php if (!empty($elections)): ?>
<div class="election-bar">
    <label>Election:</label>
    <select onchange="location.href='?election_id='+this.value+(<?= $showAll ? 'true' : 'false' ?> ? '&all=1' : '')">
        <?php foreach ($elections as $el): ?>
        <option value="<?= $el['id'] ?>" <?= $el['id'] == $election_id ? 'selected' : '' ?>>
            <?= e($el['title']) ?> (<?= e($el['course']) ?>)
        </option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>

<div class="print-area">
<?php if (!$election): ?>
    <div class="empty-state"><i class="ti ti-ticket"></i> No active election selected.</div>
<?php elseif (empty($vouchers)): ?>
    <div class="empty-state"><i class="ti ti-ticket-off"></i> No vouchers found.</div>
<?php else: ?>

    <div class="page-header-block">
        <div>
            <div class="phb-title">Voting Vouchers — <?= e($election['title']) ?></div>
            <div class="phb-sub">Guagua National College &nbsp;·&nbsp; <?= e($election['course']) ?><?= $showAll ? '' : ' &nbsp;·&nbsp; Active vouchers only' ?></div>
        </div>
        <div class="phb-right">
            <span class="phb-count"><?= count($vouchers) ?> voucher<?= count($vouchers) !== 1 ? 's' : '' ?></span><br>
            <span style="font-size:11px;margin-top:4px;display:block;">Printed: <?= $generatedDate ?></span>
        </div>
    </div>

    <div class="voucher-grid">
    <?php foreach ($vouchers as $v):
        $isUsed = (bool)$v['is_used'];
    ?>
        <div class="voucher-slip">
            <div class="slip-header">
                <div class="slip-logo">
                    <img src="../assets/img/osa-logo.jpg" alt="GNC">
                </div>
                <div>
                    <div class="slip-school-name">Guagua National College</div>
                    <div class="slip-portal-name">Office of Student Affairs — OSA Portal</div>
                </div>
                <div class="slip-ticket-label">
                    <i class="ti ti-ticket" style="font-size:11px;"></i> VOTING VOUCHER
                </div>
            </div>
            <div class="slip-body">
                <div class="slip-election"><?= e($election['title']) ?></div>
                <div class="slip-name"><?= e($v['last_name'] . ', ' . $v['first_name']) ?></div>
                <div class="slip-meta">
                    Student ID: <?= e($v['sid']) ?>
                    &nbsp;·&nbsp; <?= e($v['course']) ?>
                    <?= !empty($v['year_level']) ? ' · Year ' . e($v['year_level']) : '' ?>
                </div>
                <div class="slip-code-label">Voucher Code</div>
                <div class="slip-code <?= $isUsed ? 'used' : '' ?>"><?= e($v['code']) ?></div>
                <div class="slip-footer">
                    <div class="slip-note">Enter this code on the voting page.<br>One-time use only. Keep confidential.</div>
                    <div class="slip-status <?= $isUsed ? 'used' : 'active' ?>">
                        <?= $isUsed ? '✓ USED' : '● ACTIVE' ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

<?php endif; ?>
</div>

<script>
    // Auto-open print dialog when page loads (PDF mode)
    window.addEventListener('load', function() {
        <?php if (isset($_GET['autoprint']) && $_GET['autoprint'] == '1'): ?>
        window.print();
        <?php endif; ?>
    });
</script>
<?php include __DIR__.'/../includes/push-widget.php'; ?>
</body>
</html>

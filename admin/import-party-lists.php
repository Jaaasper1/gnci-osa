<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\IOFactory;

requireAdmin();

// ── Download template ──────────────────────────────────────────────
if (isset($_GET['template'])) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Party Lists');

    $headers = ['name', 'platform', 'color'];
    foreach ($headers as $i => $h) {
        $col = chr(65 + $i);
        $sheet->setCellValue($col . '1', strtoupper($h));
        $sheet->getStyle($col . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6B1A2A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ]);
        $sheet->getColumnDimension($col)->setWidth($h === 'platform' ? 45 : 22);
    }
    $sheet->getRowDimension(1)->setRowHeight(22);

    // Sample rows
    $samples = [
        ['Unity Coalition', 'Transparency, student welfare, and inclusive campus programs.', '#6B1A2A'],
        ['Progressive Alliance', 'Modernized facilities and accessible academic support.', '#1D4ED8'],
    ];
    foreach ($samples as $r => $row) {
        $sheet->setCellValue('A' . ($r + 2), $row[0]);
        $sheet->setCellValue('B' . ($r + 2), $row[1]);
        $sheet->setCellValue('C' . ($r + 2), $row[2]);
        $sheet->getStyle('A' . ($r + 2) . ':C' . ($r + 2))->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $r % 2 === 0 ? 'FDF2F4' : 'FFFFFF']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        ]);
    }

    // Instructions sheet
    $info = $spreadsheet->createSheet();
    $info->setTitle('Instructions');
    $info->setCellValue('A1', 'HOW TO USE THIS TEMPLATE');
    $info->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('6B1A2A');
    $instructions = [
        '',
        '1. Fill in the "Party Lists" sheet only.',
        '2. NAME     — required. Must be unique within the selected election.',
        '3. PLATFORM — optional short description of the party\'s platform/advocacy.',
        '4. COLOR    — optional hex color (e.g. #6B1A2A). Defaults to maroon if left blank.',
        '5. Do NOT edit or delete the header row.',
        '6. Remove the sample rows before importing.',
        '7. Logos cannot be imported via Excel — add them individually via Edit after import.',
        '8. Save as .xlsx format before uploading.',
    ];
    foreach ($instructions as $i => $line) {
        $info->setCellValue('A' . ($i + 2), $line);
    }
    $info->getColumnDimension('A')->setWidth(75);

    $spreadsheet->setActiveSheetIndex(0);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="party_lists_template.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// ── Import POST ────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

// preview=1 → work out what WOULD be added and send it back; nothing is saved.
$previewOnly = !empty($_POST['preview']);

$election_id = (int)($_POST['election_id'] ?? 0);
if (!$election_id) {
    echo json_encode(['success' => false, 'message' => 'No election selected.']);
    exit;
}

if (empty($_FILES['excel_file']['name'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
    exit;
}

$file = $_FILES['excel_file'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'xls'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Please upload .xlsx or .xls only.']);
    exit;
}
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File too large. Max 5MB.']);
    exit;
}

try {
    $spreadsheet = IOFactory::load($file['tmp_name']);
    // Pick the sheet that actually has the party-list header. An exported file can
    // have several sheets (a grouped "Party Lists & Candidates" view for reading,
    // plus a flat "Party Lists (for import)" one), so look through all of them,
    // trying the sheet named "Party Lists" first.
    $sheets = $spreadsheet->getAllSheets();
    usort($sheets, function ($a, $b) {
        return (int)($b->getTitle() === 'Party Lists') <=> (int)($a->getTitle() === 'Party Lists');
    });
    $rows = null;
    foreach ($sheets as $__sh) {
        $__rows = $__sh->toArray(null, true, true, false);
        for ($__i = 0; $__i < min(10, count($__rows)); $__i++) {
            $__norm = array_map(function ($v) { return strtolower(trim(preg_replace('/[\s_]+/', '_', (string)$v))); }, $__rows[$__i]);
            if (in_array('name', $__norm) || in_array('party_list', $__norm)) { $rows = $__rows; break 2; }
        }
    }
    if ($rows === null) {   // no header anywhere: fall back to the first sheet so the normal error message shows
        $rows = $spreadsheet->getSheet(0)->toArray(null, true, true, false);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Could not read file: ' . $e->getMessage()]);
    exit;
}

if (empty($rows) || count($rows) < 2) {
    echo json_encode(['success' => false, 'message' => 'Excel file is empty or has no data rows.']);
    exit;
}

// Find the actual header row. A plain import template has headers on row 1,
// but a file that started life as an Export (party lists list) has 3 title
// lines + a blank spacer row first, so the real header row is further down —
// scan the first several rows for whichever one looks like a header row.
$normalize = fn($v) => strtolower(trim(preg_replace('/[\s_]+/', '_', (string)$v)));
$headerRowIdx = null;
for ($i = 0; $i < min(10, count($rows)); $i++) {
    $norm = array_map($normalize, $rows[$i]);
    if (in_array('name', $norm) || in_array('party_list', $norm)) {
        $headerRowIdx = $i;
        break;
    }
}
if ($headerRowIdx === null) {
    echo json_encode(['success' => false, 'message' => 'Could not find a "name" column in this file. Make sure you\'re using the Import Template or an exported Party Lists list.']);
    exit;
}

// Map headers — accepts both the plain import template (name, platform, color)
// and an exported party lists list (#, Party List, Platform, Color, Candidates, Logo),
// whatever their column order or spacing/underscore style.
$header    = array_map($normalize, $rows[$headerRowIdx]);
$nameCol   = array_search('name', $header);
if ($nameCol === false) $nameCol = array_search('party_list', $header);
$platCol   = array_search('platform', $header);
$colorCol  = array_search('color', $header);
$idCol     = array_search('id', $header);   // present in exported files → lets us UPDATE existing party lists

if ($nameCol === false) {
    echo json_encode(['success' => false, 'message' => 'Header row must have a "name" column.']);
    exit;
}

// Pre-load existing party lists of this election
$existingStmt = $pdo->prepare("SELECT id, name, platform, color FROM party_lists WHERE election_id = ?");
$existingStmt->execute([$election_id]);
$byId = [];        // id => row
$nameOwner = [];   // lowercase name => id (0 = added earlier in this same file)
foreach ($existingStmt->fetchAll() as $ex) {
    $byId[(int)$ex['id']] = $ex;
    $nameOwner[mb_strtolower($ex['name'])] = (int)$ex['id'];
}

$added     = 0;
$updated   = 0;
$unchanged = 0;
$skipped   = [];
$plan      = [];   // rows that will be added
$updates   = [];   // rows that will be updated

for ($i = $headerRowIdx + 1; $i < count($rows); $i++) {
    $row = $rows[$i];
    $name  = trim((string)($row[$nameCol] ?? ''));
    $plat  = $platCol  !== false ? trim((string)($row[$platCol]  ?? '')) : '';
    $color = $colorCol !== false ? trim((string)($row[$colorCol] ?? '')) : '';
    $idVal = $idCol    !== false ? (int)trim((string)($row[$idCol] ?? '')) : 0;

    // Exported files show blank platform as an em dash ("—") — treat that as empty.
    if ($plat === '—') $plat = '';

    if ($name === '') continue; // fully blank row (e.g. the "Total" row in an exported file)

    $rowNum = $i + 1;

    if (mb_strlen($name) > 150) {
        $skipped[] = "Row $rowNum: Name too long (max 150 characters).";
        continue;
    }

    // ── UPDATE: row carries the ID of a party list that exists in THIS election ──
    if ($idVal && isset($byId[$idVal])) {
        $cur = $byId[$idVal];
        if ($color === '') {
            $color = $cur['color'] ?: '#6B1A2A';
        } elseif (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $skipped[] = "Row $rowNum: \"$color\" is not a valid hex color (e.g. #6B1A2A). Row skipped.";
            continue;
        }
        $lname = mb_strtolower($name);
        if (isset($nameOwner[$lname]) && $nameOwner[$lname] !== $idVal) {
            $skipped[] = "Row $rowNum: Can't rename \"{$cur['name']}\" to \"$name\" — another party list already uses that name.";
            continue;
        }
        $same = ($name === $cur['name']) && ($plat === trim((string)$cur['platform'])) && (strcasecmp($color, (string)$cur['color']) === 0);
        if ($same) { $unchanged++; continue; }

        unset($nameOwner[mb_strtolower($cur['name'])]);
        $nameOwner[$lname] = $idVal;
        $byId[$idVal]['name'] = $name;
        $updates[] = [
            'id' => $idVal, 'row' => $rowNum,
            'old_name' => $cur['name'], 'name' => $name,
            'platform' => $plat, 'color' => $color,
        ];
        continue;
    }

    // ── ADD: no ID (or ID from another election) → new party list ──
    if (isset($nameOwner[mb_strtolower($name)])) {
        $skipped[] = "Row $rowNum: Party list \"$name\" already exists in this election.";
        continue;
    }

    if ($color === '') {
        $color = '#6B1A2A';
    } elseif (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        $skipped[] = "Row $rowNum: \"$color\" is not a valid hex color (e.g. #6B1A2A). Row skipped.";
        continue;
    }

    $plan[] = ['row' => $rowNum, 'name' => $name, 'platform' => $plat, 'color' => $color];
    $nameOwner[mb_strtolower($name)] = 0; // prevent dupes within the same file
}

// ── PREVIEW: send the plan back, save nothing ───────────────────────
if ($previewOnly) {
    $LIST_CAP = 500;
    echo json_encode([
        'success'   => true,
        'preview'   => true,
        'new'       => count($plan),
        'updates'   => count($updates),
        'unchanged' => $unchanged,
        'skipped'   => $skipped,
        'rows'      => array_slice($plan, 0, $LIST_CAP),
        'update_rows' => array_slice($updates, 0, $LIST_CAP),
        'truncated' => (count($plan) > $LIST_CAP) || (count($updates) > $LIST_CAP),
    ]);
    exit;
}

// ── REAL IMPORT: same plan as the preview, saved in one transaction ─
try {
    $pdo->beginTransaction();
    $insert = $pdo->prepare("INSERT INTO party_lists (election_id, name, platform, color, logo) VALUES (?,?,?,?,NULL)");
    foreach ($plan as $p) {
        $insert->execute([$election_id, $p['name'], $p['platform'], $p['color']]);
        $added++;
    }
    $upd = $pdo->prepare("UPDATE party_lists SET name = ?, platform = ?, color = ? WHERE id = ? AND election_id = ?");
    foreach ($updates as $u) {
        $upd->execute([$u['name'], $u['platform'] !== '' ? $u['platform'] : null, $u['color'], $u['id'], $election_id]);
        $updated++;
    }
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Import failed, nothing was saved: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'success' => true,
    'added'   => $added,
    'updated' => $updated,
    'skipped' => $skipped,
    'message' => "$added party list(s) added, $updated updated.",
]);

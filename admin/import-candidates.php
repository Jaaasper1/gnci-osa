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

header('Content-Type: application/json; charset=utf-8');

// ── Download template ──────────────────────────────────────────────
if (isset($_GET['template'])) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Candidates');

    $headers = ['student_id', 'position'];
    foreach ($headers as $i => $h) {
        $col = chr(65 + $i);
        $sheet->setCellValue($col . '1', strtoupper($h));
        $sheet->getStyle($col . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6B1A2A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ]);
        $sheet->getColumnDimension($col)->setWidth($h === 'student_id' ? 20 : 30);
    }
    $sheet->getRowDimension(1)->setRowHeight(22);

    // Sample rows
    $samples = [
        ['2021-00123', 'President'],
        ['2021-00456', 'Vice President'],
        ['2022-00789', 'Secretary'],
        ['2022-00321', 'Treasurer'],
        ['2023-00654', 'Auditor'],
    ];
    foreach ($samples as $r => $row) {
        $sheet->setCellValue('A' . ($r + 2), $row[0]);
        $sheet->setCellValue('B' . ($r + 2), $row[1]);
        $sheet->getStyle('A' . ($r + 2) . ':B' . ($r + 2))->applyFromArray([
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
        '1. Fill in the "Candidates" sheet only.',
        '2. STUDENT_ID  — must match exactly the Student ID registered in the system.',
        '3. POSITION    — must match exactly the position title created in the election (case-sensitive).',
        '4. Do NOT edit or delete the header row.',
        '5. Remove the sample rows before importing.',
        '6. Save as .xlsx format before uploading.',
        '7. OPTIONAL: add a PARTY_LIST column (exact party list name). Empty or "Independent" = no party.',
    ];
    foreach ($instructions as $i => $line) {
        $info->setCellValue('A' . ($i + 2), $line);
    }
    $info->getColumnDimension('A')->setWidth(70);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="candidates_template.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// ── Import POST ────────────────────────────────────────────────────
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
    $sheet = $spreadsheet->getSheetByName('Candidates') ?? $spreadsheet->getActiveSheet();
    $rows  = $sheet->toArray(null, true, true, false);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Could not read file: ' . $e->getMessage()]);
    exit;
}

if (empty($rows) || count($rows) < 2) {
    echo json_encode(['success' => false, 'message' => 'Excel file is empty or has no data rows.']);
    exit;
}

// Find the actual header row. A plain import template has headers on row 1,
// but a file that started life as an Export (candidates list) has 3 title
// lines + a blank spacer row first, so the real header row is further down —
// scan the first several rows for whichever one looks like a header row.
$normalize = fn($v) => strtolower(trim(preg_replace('/[\s_]+/', '_', (string)$v)));
$headerRowIdx = null;
for ($i = 0; $i < min(10, count($rows)); $i++) {
    $norm = array_map($normalize, $rows[$i]);
    if ((in_array('student_id', $norm) || in_array('id', $norm)) && in_array('position', $norm)) {
        $headerRowIdx = $i;
        break;
    }
}
if ($headerRowIdx === null) {
    echo json_encode(['success' => false, 'message' => 'Could not find "student_id" and "position" columns in this file. Make sure you\'re using the Import Template or an exported Candidates List.']);
    exit;
}

// Map headers — accepts both the plain import template (student_id, position)
// and an exported candidates list (#, Position, Student ID, Last Name, ...),
// whatever their column order or spacing/underscore style.
$header = array_map($normalize, $rows[$headerRowIdx]);
$sidCol = array_search('student_id', $header);
if ($sidCol === false) $sidCol = array_search('id', $header);
$posCol = array_search('position', $header);
// Optional party column (present in an exported Candidates list). Accepts a few spellings.
$partyCol = false;
foreach (['party_list', 'party', 'partylist', 'party_list_name'] as $__k) {
    $__i = array_search($__k, $header);
    if ($__i !== false) { $partyCol = $__i; break; }
}

if ($sidCol === false || $posCol === false) {
    echo json_encode(['success' => false, 'message' => 'Header row must have "student_id" and "position" columns.']);
    exit;
}

// Pre-load party lists for this election (name => id, case-insensitive)
$partyStmt = $pdo->prepare("SELECT id, name FROM party_lists WHERE election_id = ?");
$partyStmt->execute([$election_id]);
$partyMap = [];
foreach ($partyStmt->fetchAll() as $__p) {
    $partyMap[strtolower(trim($__p['name']))] = (int)$__p['id'];
}

// Pre-load positions for this election
$posStmt = $pdo->prepare("SELECT id, title FROM positions WHERE election_id = ?");
$posStmt->execute([$election_id]);
$positionMap = [];
foreach ($posStmt->fetchAll() as $p) {
    $positionMap[strtolower(trim($p['title']))] = (int)$p['id'];
}

$added   = 0;
$skipped = [];
$plan    = [];          // rows that will be added
$seen    = [];          // student ids already queued from this file

$stuStmt = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE student_id = ? AND is_active = 1");
$dupStmt = $pdo->prepare("SELECT id FROM candidates WHERE election_id=? AND student_id=?");

for ($i = $headerRowIdx + 1; $i < count($rows); $i++) {
    $row = $rows[$i];
    $sid = trim((string)($row[$sidCol] ?? ''));
    $pos = trim((string)($row[$posCol] ?? ''));

    if ($sid === '' && $pos === '') continue;

    $rowNum = $i + 1;
    if ($sid === '') { $skipped[] = "Row $rowNum: Missing student_id."; continue; }
    if ($pos === '') { $skipped[] = "Row $rowNum: Missing position."; continue; }

    $stuStmt->execute([$sid]);
    $student = $stuStmt->fetch();
    if (!$student) { $skipped[] = "Row $rowNum: Student ID \"$sid\" not found."; continue; }

    $posKey = strtolower($pos);
    if (!isset($positionMap[$posKey])) {
        $skipped[] = "Row $rowNum: Position \"$pos\" not found in this election.";
        continue;
    }
    $position_id = $positionMap[$posKey];

    $dupStmt->execute([$election_id, $student['id']]);
    if ($dupStmt->fetch() || isset($seen[$student['id']])) {
        $skipped[] = "Row $rowNum: Student \"$sid\" is already a candidate.";
        continue;
    }

    // Party list (optional column). Empty / "Independent" / dash = no party.
    // A party name that doesn't exist in THIS election is imported as Independent
    // (and flagged in the preview) so the candidate isn't lost.
    $partyId = null; $partyLabel = 'Independent';
    if ($partyCol !== false) {
        $partyRaw = trim((string)($row[$partyCol] ?? ''));
        $pk = strtolower($partyRaw);
        if ($pk !== '' && !in_array($pk, ['independent', '—', '-', 'none', 'n/a'], true)) {
            if (isset($partyMap[$pk])) { $partyId = $partyMap[$pk]; $partyLabel = $partyRaw; }
            else { $partyLabel = 'Independent (party "' . $partyRaw . '" not found in this election)'; }
        }
    }

    $seen[$student['id']] = true;
    $plan[] = [
        'row'         => $rowNum,
        'student_pk'  => (int)$student['id'],
        'student_id'  => $sid,
        'name'        => trim($student['first_name'] . ' ' . $student['last_name']),
        'position'    => $pos,
        'position_id' => $position_id,
        'party_list_id' => $partyId,
        'party'       => $partyLabel,
    ];
}

// ── PREVIEW: send the plan back, save nothing ───────────────────────
if ($previewOnly) {
    $LIST_CAP = 500;
    $list = [];
    foreach ($plan as $p) {
        if (count($list) >= $LIST_CAP) break;
        $list[] = ['row' => $p['row'], 'student_id' => $p['student_id'],
                   'name' => $p['name'], 'position' => $p['position'], 'party' => $p['party']];
    }
    echo json_encode([
        'success'   => true,
        'preview'   => true,
        'new'       => count($plan),
        'skipped'   => $skipped,
        'rows'      => $list,
        'truncated' => count($plan) > count($list),
    ]);
    exit;
}

// ── REAL IMPORT: same plan as the preview, saved in one transaction ─
try {
    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO candidates (election_id, position_id, student_id, party_list_id, bio, photo) VALUES (?,?,?,?,?,?)");
    foreach ($plan as $p) {
        $ins->execute([$election_id, $p['position_id'], $p['student_pk'], $p['party_list_id'], '', null]);
        $added++;
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
    'skipped' => $skipped,
    'message' => "$added candidate(s) added successfully.",
]);

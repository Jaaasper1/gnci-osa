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
    $sheet->setTitle('Students');

    $headers = ['student_id','first_name','last_name','course','email'];
    $widths  = [18, 18, 18, 12, 30];

    foreach ($headers as $i => $h) {
        $col = chr(65 + $i);
        $sheet->setCellValue($col . '1', strtoupper($h));
        $sheet->getStyle($col . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6B1A2A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ]);
        $sheet->getColumnDimension($col)->setWidth($widths[$i]);
    }
    $sheet->getRowDimension(1)->setRowHeight(22);

    // Sample rows
    $samples = [
        ['20210123','Juan','Dela Cruz','BSIT','juan.delacruz@gnc.edu.ph'],
        ['20210456','Maria','Santos','BSN','maria.santos@gnc.edu.ph'],
        ['20220789','Pedro','Reyes','BSBA',''],
    ];
    foreach ($samples as $r => $row) {
        foreach ($row as $c => $val) {
            $sheet->setCellValue(chr(65 + $c) . ($r + 2), $val);
        }
        $sheet->getStyle('A' . ($r + 2) . ':E' . ($r + 2))->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $r % 2 === 0 ? 'FDF2F4' : 'FFFFFF']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        ]);
    }

    // Instructions sheet
    $info = $spreadsheet->createSheet();
    $info->setTitle('Instructions');
    $info->setCellValue('A1', 'HOW TO USE THIS TEMPLATE');
    $info->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('6B1A2A');
    $lines = [
        '',
        '1. Fill in the "Students" sheet only.',
        '2. STUDENT_ID  — required, numbers only, must be unique (e.g. 20210123).',
        '3. FIRST_NAME  — required.',
        '4. LAST_NAME   — required.',
        '5. COURSE      — optional (e.g. BSIT, BSN, BSBA).',
        '6. EMAIL       — optional.',
        '',
        '⚠ Do NOT edit or delete the header row.',
        '⚠ Remove sample rows before importing.',
        '⚠ Existing Student IDs will be UPDATED (name/course/email edited) — they won\'t be duplicated.',
        '⚠ A blank COURSE or EMAIL cell will CLEAR that student\'s existing value.',
        '⚠ You will see a preview of every change and must confirm before anything is saved.',
        '⚠ Default password will be the Student ID — students must change it on first login.',
    ];
    foreach ($lines as $i => $line) {
        $info->setCellValue('A' . ($i + 2), $line);
    }
    $info->getColumnDimension('A')->setWidth(70);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="students_import_template.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// ── Import POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit;
}

// CSRF check (JSON-friendly version of requireCsrf())
$sentToken = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sentToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session expired. Please refresh the page and try again.']); exit;
}

// preview=1 → only work out what WOULD change and send it back; nothing is saved.
$previewOnly = !empty($_POST['preview']);

if (empty($_FILES['excel_file']['name'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded.']); exit;
}

$file = $_FILES['excel_file'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'xls'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Upload .xlsx or .xls only.']); exit;
}
if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File too large. Max 10MB.']); exit;
}

try {
    $spreadsheet = IOFactory::load($file['tmp_name']);
    $sheet = $spreadsheet->getSheetByName('Students') ?? $spreadsheet->getActiveSheet();
    $rows  = $sheet->toArray(null, true, true, false);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Could not read file: ' . $e->getMessage()]); exit;
}

if (count($rows) < 2) {
    echo json_encode(['success' => false, 'message' => 'File is empty or has no data rows.']); exit;
}

// Find the actual header row. A plain import template has headers on row 1,
// but a file that started life as an Export (masterlist) has 3 title lines
// + a blank spacer row first, so the real header row is further down —
// scan the first several rows for whichever one looks like a header row.
$headerRowIdx = null;
$normalize = fn($v) => strtolower(trim(preg_replace('/[\s_]+/', '_', (string)$v)));
for ($i = 0; $i < min(10, count($rows)); $i++) {
    $norm = array_map($normalize, $rows[$i]);
    if (in_array('student_id', $norm) || in_array('studentid', $norm) || in_array('id', $norm)) {
        $headerRowIdx = $i;
        break;
    }
}
if ($headerRowIdx === null) {
    echo json_encode(['success' => false, 'message' => 'Could not find a "Student ID" column in this file. Make sure you\'re using the Import Template or an exported Student Masterlist.']); exit;
}

// Map headers — accepts both the plain import template (student_id, first_name...)
// and an exported masterlist (Student ID, Last Name, First Name..., Status),
// whatever their column order or spacing/underscore style.
$header = array_map($normalize, $rows[$headerRowIdx]);
$find = function(array $aliases) use ($header) {
    foreach ($aliases as $a) {
        $pos = array_search($a, $header);
        if ($pos !== false) return $pos;
    }
    return false;
};
$cols = [
    'student_id'  => $find(['student_id', 'studentid', 'id']),
    'first_name'  => $find(['first_name', 'firstname']),
    'last_name'   => $find(['last_name', 'lastname']),
    'course'      => $find(['course']),
    'email'       => $find(['email']),
    'status'      => $find(['status']),
];

if ($cols['student_id'] === false || $cols['first_name'] === false || $cols['last_name'] === false) {
    echo json_encode(['success' => false, 'message' => 'Missing required columns: Student ID, First Name, Last Name.']); exit;
}

// ── Work out what each row would do (used by BOTH preview and real import,
//    so what you preview is exactly what gets saved) ─────────────────────────
$plan    = [];        // one entry per usable row
$skipped = [];
$seenIds = [];        // Student IDs already seen in this file

$lookup = $pdo->prepare("SELECT id, first_name, last_name, course, email, is_active FROM students WHERE student_id = ?");
$norm   = fn($v) => trim((string)($v ?? ''));

for ($i = $headerRowIdx + 1; $i < count($rows); $i++) {
    $row   = $rows[$i];
    $sid   = trim((string)($row[$cols['student_id']] ?? ''));
    $fname = trim((string)($row[$cols['first_name']] ?? ''));
    $lname = trim((string)($row[$cols['last_name']]  ?? ''));

    if ($sid === '' && $fname === '' && $lname === '') continue;

    $rowNum = $i + 1;
    if ($sid   === '') { $skipped[] = "Row $rowNum: Missing student_id.";  continue; }
    if ($fname === '') { $skipped[] = "Row $rowNum: Missing first_name.";  continue; }
    if ($lname === '') { $skipped[] = "Row $rowNum: Missing last_name.";   continue; }

    // Excel sometimes turns numeric IDs into floats (e.g. "20193372.0") — clean that up first
    if (preg_match('/^\d+\.0+$/', $sid)) { $sid = substr($sid, 0, strpos($sid, '.')); }

    if (!ctype_digit($sid)) { $skipped[] = "Row $rowNum: Student ID \"$sid\" must contain numbers only (no letters or symbols)."; continue; }

    if (isset($seenIds[$sid])) {
        $skipped[] = "Row $rowNum: Student ID $sid appears more than once in this file (first seen on row {$seenIds[$sid]}) — this repeat was skipped.";
        continue;
    }
    $seenIds[$sid] = $rowNum;

    $course = $cols['course'] !== false ? trim((string)($row[$cols['course']] ?? '')) : '';
    $email  = $cols['email']  !== false ? trim((string)($row[$cols['email']]  ?? '')) : '';

    // "Status" column (e.g. from an exported masterlist) — "Active"/"Inactive"
    // toggles whether the student can log in. Left alone if the column isn't present.
    $statusVal = $cols['status'] !== false ? strtolower(trim((string)($row[$cols['status']] ?? ''))) : null;
    $isActive  = $statusVal === null ? 1 : ($statusVal === 'inactive' ? 0 : 1);

    $lookup->execute([$sid]);
    $existing = $lookup->fetch();

    $entry = [
        'row' => $rowNum, 'sid' => $sid, 'fname' => $fname, 'lname' => $lname,
        'course' => $course, 'email' => $email, 'is_active' => $isActive,
        'db_id' => $existing['id'] ?? null,
    ];

    if (!$existing) {
        $entry['action']  = 'add';
        $entry['changes'] = [];
    } else {
        // Existing Student ID → compare field by field so we can show exactly what changes.
        $changes = [];
        $cmp = function ($field, $label, $old, $new) use (&$changes, $norm) {
            if ($norm($old) !== $norm($new)) $changes[] = ['field' => $label, 'old' => $norm($old), 'new' => $norm($new)];
        };
        $cmp('first_name', 'First name', $existing['first_name'], $fname);
        $cmp('last_name',  'Last name',  $existing['last_name'],  $lname);
        $cmp('course',     'Course',     $existing['course'],     $course);
        $cmp('email',      'Email',      $existing['email'],      $email);
        if ($cols['status'] !== false && (int)$existing['is_active'] !== (int)$isActive) {
            $changes[] = ['field' => 'Status',
                          'old' => ((int)$existing['is_active'] ? 'Active' : 'Inactive'),
                          'new' => ($isActive ? 'Active' : 'Inactive')];
        }
        $entry['action']   = $changes ? 'update' : 'unchanged';
        $entry['changes']  = $changes;
        $entry['old_name'] = trim($existing['first_name'] . ' ' . $existing['last_name']);
    }
    $plan[] = $entry;
}

$countNew = $countUpd = $countSame = 0;
foreach ($plan as $p) {
    if     ($p['action'] === 'add')    $countNew++;
    elseif ($p['action'] === 'update') $countUpd++;
    else                               $countSame++;
}

// ── PREVIEW: send the plan back, save nothing ───────────────────────────────
if ($previewOnly) {
    $LIST_CAP = 500;
    $list = [];
    foreach ($plan as $p) {
        if ($p['action'] === 'unchanged') continue;      // no need to list untouched students
        if (count($list) >= $LIST_CAP) break;
        $list[] = [
            'row' => $p['row'], 'student_id' => $p['sid'], 'action' => $p['action'],
            'name' => trim($p['fname'] . ' ' . $p['lname']),
            'course' => $p['course'], 'email' => $p['email'],
            'changes' => $p['changes'],
        ];
    }
    echo json_encode([
        'success'   => true,
        'preview'   => true,
        'new'       => $countNew,
        'updated'   => $countUpd,
        'unchanged' => $countSame,
        'skipped'   => $skipped,
        'rows'      => $list,
        'truncated' => ($countNew + $countUpd) > count($list),
    ]);
    exit;
}

// ── REAL IMPORT: apply the plan ─────────────────────────────────────────────
$added   = 0;
$updated = 0;

foreach ($plan as $p) {
    try {
        if ($p['action'] === 'add') {
            $hashed = password_hash($p['sid'], PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO students (student_id, first_name, last_name, course, email, password, is_active, approved_at, must_change_password)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)")
                ->execute([$p['sid'], $p['fname'], $p['lname'], $p['course'] ?: null, $p['email'] ?: null, $hashed, $p['is_active'], $p['is_active'] ? date('Y-m-d H:i:s') : null]);
            $added++;
        } elseif ($p['action'] === 'update') {
            if ($cols['status'] !== false) {
                // If this import (re)activates the student, stamp approved_at the
                // first time — but don't touch it if it's already set.
                $pdo->prepare("UPDATE students SET first_name=?, last_name=?, course=?, email=?, is_active=?,
                                approved_at = CASE WHEN ? = 1 THEN COALESCE(approved_at, NOW()) ELSE approved_at END
                                WHERE id=?")
                    ->execute([$p['fname'], $p['lname'], $p['course'] ?: null, $p['email'] ?: null, $p['is_active'], $p['is_active'], $p['db_id']]);
            } else {
                $pdo->prepare("UPDATE students SET first_name=?, last_name=?, course=?, email=? WHERE id=?")
                    ->execute([$p['fname'], $p['lname'], $p['course'] ?: null, $p['email'] ?: null, $p['db_id']]);
            }
            $updated++;
        }
    } catch (PDOException $e) {
        $skipped[] = "Row {$p['row']}: Could not save this row (" . $e->getMessage() . ").";
    }
}

$msgParts = [];
if ($added)     $msgParts[] = "$added added";
if ($updated)   $msgParts[] = "$updated updated";
if ($countSame) $msgParts[] = "$countSame unchanged";
$message = ($added || $updated) ? implode(', ', $msgParts) . '.' : 'No changes made.';

echo json_encode([
    'success'   => true,
    'added'     => $added,
    'updated'   => $updated,
    'unchanged' => $countSame,
    'skipped'   => $skipped,
    'message'   => $message,
]);

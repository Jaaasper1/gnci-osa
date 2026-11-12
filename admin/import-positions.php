<?php
// import-positions.php — read a list of election positions from an uploaded file
// and return them as JSON. Nothing is saved here: the New Election / Add Positions
// form fills its rows with the result so the admin can review/reorder, then submits
// the normal form.
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/csrf.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\IOFactory;

requireStaff();

const IMPORT_POS_MAX      = 30;   // max positions per import
const IMPORT_POS_MAX_LEN  = 100;  // max characters per position title

// ── Download template ──────────────────────────────────────────────
if (isset($_GET['template'])) {
    $ss = new Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->setTitle('Positions');
    $sh->setCellValue('A1', 'POSITION');
    $sh->getStyle('A1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6B1A2A']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sh->getColumnDimension('A')->setWidth(40);
    $sh->getColumnDimension('C')->setWidth(4);
    $sh->getColumnDimension('D')->setWidth(45);
    $sh->setCellValue('D1', 'ELECTION TITLE');
    $sh->getStyle('D1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6B1A2A']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sh->setCellValue('D2', 'BSIT S.Y. 2026-2027 Election');
    foreach (['President', 'Vice President', 'Secretary', 'Treasurer', 'Auditor'] as $i => $p) {
        $sh->setCellValue('A' . ($i + 2), $p);
    }
    $info = $ss->createSheet();
    $info->setTitle('Instructions');
    $lines = [
        'HOW TO USE THIS TEMPLATE',
        '',
        '1. One position per row in the "Positions" sheet, column A.',
        '2. The order of the rows is the order on the ballot.',
        '3. ELECTION TITLE (column D, optional): the title of the election. It fills the Title box in the New Election form.',
        '4. Keep or delete the POSITION header row - both work. Replace the sample rows with your own.',
        '5. Save as .xlsx or .csv, then use Import in the New Election form.',
    ];
    foreach ($lines as $i => $l) $info->setCellValue('A' . ($i + 1), $l);
    $info->getStyle('A1')->getFont()->setBold(true);
    $info->getColumnDimension('A')->setWidth(70);
    $ss->setActiveSheetIndex(0);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="positions_template.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
}

// ── Parse uploaded file → JSON ─────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

function posImportFail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') posImportFail('Invalid request.', 405);

$sent = $_POST['csrf_token'] ?? '';
$real = $_SESSION['csrf_token'] ?? '';
if (!$real || !$sent || !hash_equals($real, $sent)) posImportFail('Session expired. Please refresh the page and try again.', 403);

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) posImportFail('No file received. Please choose a file.');
$f = $_FILES['file'];
if ($f['size'] > 1024 * 1024) posImportFail('File is too large (max 1MB).');

$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'xls', 'csv', 'txt'], true)) posImportFail('Only .xlsx, .xls, .csv or .txt files are allowed.');

$raw = [];
try {
    if ($ext === 'txt') {
        foreach (preg_split('/\R/u', (string) file_get_contents($f['tmp_name'])) as $ln) $raw[] = [$ln];
    } elseif ($ext === 'csv') {
        $h = fopen($f['tmp_name'], 'r');
        while (($row = fgetcsv($h)) !== false) $raw[] = $row;
        fclose($h);
    } else {
        $reader = IOFactory::createReaderForFile($f['tmp_name']);
        $reader->setReadDataOnly(true);
        $sheet  = $reader->load($f['tmp_name'])->getSheet(0);
        foreach ($sheet->toArray(null, true, true, false) as $row) $raw[] = $row;
    }
} catch (Throwable $e) {
    posImportFail('Could not read the file. Make sure it is a valid Excel/CSV file.');
}

$positions = [];
$title = '';
$seen = [];
$skipped = 0;
foreach ($raw as $i => $row) {
    // Column D = election title (first non-empty cell that is not the header)
    $t = trim((string) ($row[3] ?? ''));
    if ($title === '' && $t !== '' && strtolower($t) !== 'election title') $title = mb_substr($t, 0, 150);
    $v = (string) ($row[0] ?? '');
    $v = trim(preg_replace('/\s+/u', ' ', (string) $v));
    $v = ltrim($v, "\xEF\xBB\xBF");             // BOM on first CSV cell
    if ($v === '') continue;
    if ($i === 0 && in_array(strtolower($v), ['position', 'positions', 'title', 'position title'], true)) continue; // header
    if (mb_strlen($v) > IMPORT_POS_MAX_LEN) $v = mb_substr($v, 0, IMPORT_POS_MAX_LEN);
    $key = mb_strtolower($v);
    if (isset($seen[$key])) { $skipped++; continue; }
    $seen[$key] = true;
    if (count($positions) >= IMPORT_POS_MAX) { $skipped++; continue; }
    $positions[] = $v;
}

if (!$positions && $title === '') posImportFail('No positions found. Put one position per row in column A.');

echo json_encode(['ok' => true, 'positions' => $positions, 'title' => $title, 'skipped' => $skipped]);

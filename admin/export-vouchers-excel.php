<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$vendorPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendorPath)) {
    require_once $vendorPath;
}
require_once __DIR__ . '/../includes/export-helpers.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$election_id = (int)($_GET['election_id'] ?? 0);
if (!$election_id) { die('No election selected.'); }

$se = $pdo->prepare("SELECT * FROM elections WHERE id=?");
$se->execute([$election_id]);
$election = $se->fetch();
if (!$election) { die('Election not found.'); }

$vs = $pdo->prepare("
    SELECT s.last_name, s.first_name, s.student_id as sid, s.course,
           vv.code, vv.is_used, vv.generated_at
    FROM voting_vouchers vv
    JOIN students s ON vv.student_id = s.id
    WHERE vv.election_id = ?
    ORDER BY s.last_name, s.first_name
");
$vs->execute([$election_id]);
$vouchers = $vs->fetchAll();

if (file_exists($vendorPath)) {
    // ── PhpSpreadsheet .xlsx ─────────────────────────────────────────────────
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Vouchers');

    // Title row
    $sheet->mergeCells('A1:H1');
    $sheet->setCellValue('A1', 'Voting Vouchers — ' . $election['title'] . ' (' . $election['course'] . ')');
    $sheet->getStyle('A1')->applyFromArray([
        'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6B1A2A']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(28);

    // Sub-title
    $sheet->mergeCells('A2:H2');
    $sheet->setCellValue('A2', 'Generated: ' . date('F j, Y \a\t g:i A') . '   |   Total Vouchers: ' . count($vouchers));
    $sheet->getStyle('A2')->applyFromArray([
        'font'      => ['italic' => true, 'size' => 10, 'color' => ['rgb' => '6B7280']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    // Header row
    $headers = ['#', 'Last Name', 'First Name', 'Student ID', 'Course', 'Voucher Code', 'Status', 'Generated'];
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col . '3', $h);
        $col++;
    }
    $sheet->getStyle('A3:H3')->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C9943A']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
    ]);
    $sheet->getRowDimension(3)->setRowHeight(20);

    // Data rows
    $row = 4;
    $i   = 1;
    foreach ($vouchers as $v) {
        $isUsed  = (bool)$v['is_used'];
        $bgColor = $row % 2 === 0 ? 'F9FAFB' : 'FFFFFF';

        $sheet->setCellValue('A' . $row, $i);
        $sheet->setCellValue('B' . $row, $v['last_name']);
        $sheet->setCellValue('C' . $row, $v['first_name']);
        $sheet->setCellValue('D' . $row, $v['sid']);
        $sheet->setCellValue('E' . $row, $v['course']);
        $sheet->setCellValue('F' . $row, $v['code']);
        $sheet->setCellValue('G' . $row, $isUsed ? 'USED' : 'ACTIVE');
        $sheet->setCellValue('H' . $row, $v['generated_at'] ? date('M j, Y', strtotime($v['generated_at'])) : '');

        $sheet->getStyle('A' . $row . ':H' . $row)->applyFromArray([
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
        ]);
        $sheet->getStyle('F' . $row)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => $isUsed ? '9CA3AF' : '6B1A2A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle('G' . $row)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => $isUsed ? '9CA3AF' : '059669']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle('A' . $row)->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $row++;
        $i++;
    }

    // Column widths
    foreach (['A'=>5,'B'=>18,'C'=>18,'D'=>14,'E'=>12,'F'=>18,'G'=>10,'H'=>14] as $c => $w) {
        $sheet->getColumnDimension($c)->setWidth($w);
    }

    // Output
    $filename = 'vouchers_' . preg_replace('/[^a-z0-9]+/i', '_', $election['title']) . '_' . date('Ymd') . '.xlsx';
    outputSpreadsheet($spreadsheet, $filename);

} else {
    // ── CSV fallback ─────────────────────────────────────────────────────────
    $filename = 'vouchers_' . preg_replace('/[^a-z0-9]+/i', '_', $election['title']) . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['#', 'Last Name', 'First Name', 'Student ID', 'Course', 'Voucher Code', 'Status', 'Generated']);
    $i = 1;
    foreach ($vouchers as $v) {
        fputcsv($out, [
            $i++,
            $v['last_name'],
            $v['first_name'],
            $v['sid'],
            $v['course'],
            $v['code'],
            $v['is_used'] ? 'USED' : 'ACTIVE',
            $v['generated_at'] ? date('M j, Y', strtotime($v['generated_at'])) : '',
        ]);
    }
    fclose($out);
    exit;
}

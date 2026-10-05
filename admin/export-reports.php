<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

require_once '../vendor/autoload.php';
require_once '../includes/export-helpers.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$type = $_GET['type'] ?? '';
$ALLOWED = ['complaints_analytics', 'reviews_analytics', 'combined_overview', 'custom_range'];
if (!in_array($type, $ALLOWED)) { http_response_code(400); die('Invalid report type.'); }

// ── Style helpers (same conventions as export.php) ──────────────────
$MAROON = '6B1A2A'; $GOLD = 'C9943A'; $GRAY_T = '6B7280';
$GREEN  = '065F46'; $GREEN_L = 'D1FAE5';

function hStyle($rgb = '6B1A2A') {
    return ['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF'],'size'=>11],
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$rgb]],
            'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
            'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'FFFFFF']]]];
}
function bStyle() {
    return ['borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'E5E7EB']]]];
}
function titleBlock($sheet, $line1, $line2, $line3, $cols = 8) {
    $end = chr(64 + $cols);
    $sheet->mergeCells("A1:{$end}1");
    $sheet->setCellValue('A1', $line1);
    $sheet->getStyle('A1')->applyFromArray(['font'=>['bold'=>true,'size'=>14,'color'=>['rgb'=>'6B1A2A']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet->mergeCells("A2:{$end}2");
    $sheet->setCellValue('A2', $line2);
    $sheet->getStyle('A2')->applyFromArray(['font'=>['bold'=>true,'size'=>11,'color'=>['rgb'=>'6B7280']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet->mergeCells("A3:{$end}3");
    $sheet->setCellValue('A3', $line3);
    $sheet->getStyle('A3')->applyFromArray(['font'=>['italic'=>true,'size'=>10,'color'=>['rgb'=>'9CA3AF']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet->getRowDimension(4)->setRowHeight(8);
}
function zebraRow($sheet, $row, $cols, $isEven) {
    if ($isEven) $sheet->getStyle("A{$row}:{$cols}{$row}")->applyFromArray(['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'FDF2F4']]]);
    $sheet->getStyle("A{$row}:{$cols}{$row}")->applyFromArray(bStyle());
    $sheet->getStyle("A{$row}:{$cols}{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension($row)->setRowHeight(18);
}
// Period-breakdown block: writes a Today/This Week/This Month/This Year/All-Time table
// starting at $startRow, for an array of ['label'=>..., 'today'=>..,'this_week'=>..,'this_month'=>..,'this_year'=>..,'all_time'=>..]
function periodTable($sheet, $startRow, $rowsData, $labelHeader, $headerRgb) {
    $row = $startRow;
    foreach ([$labelHeader,'Today','This Week','This Month','This Year','All-Time'] as $i=>$h) {
        $sheet->setCellValue(chr(65+$i).$row, $h);
    }
    $sheet->getStyle("A{$row}:F{$row}")->applyFromArray(hStyle($headerRgb));
    $sheet->getRowDimension($row)->setRowHeight(22);
    $row++;
    foreach ($rowsData as $n => $d) {
        $sheet->setCellValue("A$row", $d['label']);
        $sheet->setCellValue("B$row", $d['today']);
        $sheet->setCellValue("C$row", $d['this_week']);
        $sheet->setCellValue("D$row", $d['this_month']);
        $sheet->setCellValue("E$row", $d['this_year']);
        $sheet->setCellValue("F$row", $d['all_time']);
        $sheet->getStyle("F$row")->getFont()->setBold(true);
        zebraRow($sheet, $row, 'F', $row%2===0);
        $row++;
    }
    return $row;
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$now = date('F j, Y \a\t g:i A');

// Reaction labels shared across reports
$reactionLabels = ['😊'=>'Satisfied','👍'=>'Good','😐'=>'Neutral','👎'=>'Not Satisfied','😢'=>'Disappointed'];

function getReactionBreakdown($pdo) {
    global $reactionLabels;
    $raw = $pdo->query(
        "SELECT reaction,
                SUM(DATE(created_at) = CURDATE()) AS today,
                SUM(YEARWEEK(created_at,1) = YEARWEEK(CURDATE(),1)) AS this_week,
                SUM(MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())) AS this_month,
                SUM(YEAR(created_at) = YEAR(CURDATE())) AS this_year,
                COUNT(*) AS all_time
         FROM complaint_reviews GROUP BY reaction"
    )->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($reactionLabels as $emoji => $label) {
        $out[$emoji] = ['label'=>$emoji.' '.$label,'today'=>0,'this_week'=>0,'this_month'=>0,'this_year'=>0,'all_time'=>0];
    }
    foreach ($raw as $r) {
        if (isset($out[$r['reaction']])) {
            $out[$r['reaction']] = [
                'label'=>$r['reaction'].' '.$reactionLabels[$r['reaction']],
                'today'=>(int)$r['today'],'this_week'=>(int)$r['this_week'],
                'this_month'=>(int)$r['this_month'],'this_year'=>(int)$r['this_year'],'all_time'=>(int)$r['all_time'],
            ];
        }
    }
    return array_values($out);
}

function getCategoryBreakdown($pdo) {
    $raw = $pdo->query(
        "SELECT category,
                SUM(DATE(created_at) = CURDATE()) AS today,
                SUM(YEARWEEK(created_at,1) = YEARWEEK(CURDATE(),1)) AS this_week,
                SUM(MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())) AS this_month,
                SUM(YEAR(created_at) = YEAR(CURDATE())) AS this_year,
                COUNT(*) AS all_time
         FROM complaints GROUP BY category ORDER BY all_time DESC, category ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($raw as $r) {
        $out[] = ['label'=>$r['category'],'today'=>(int)$r['today'],'this_week'=>(int)$r['this_week'],
                   'this_month'=>(int)$r['this_month'],'this_year'=>(int)$r['this_year'],'all_time'=>(int)$r['all_time']];
    }
    return $out;
}

// ══════════════════════════════════════════════════════════════════
// COMPLAINTS ANALYTICS
// ══════════════════════════════════════════════════════════════════
if ($type === 'complaints_analytics') {
    $totalComplaints = (int)$pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn();
    $statusCounts = $pdo->query("SELECT status, COUNT(*) c FROM complaints GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $categoryData = getCategoryBreakdown($pdo);
    $topCategory  = $categoryData[0]['label'] ?? 'N/A';

    // --- Sheet 1: Summary ---
    $sheet->setTitle('Summary');
    titleBlock($sheet, 'GUAGUA NATIONAL COLLEGE', 'Office of Student Affairs — Complaints Analytics Report', 'Generated: '.$now, 6);

    $sheet->setCellValue('A5', 'Total Complaints (All-Time)');
    $sheet->setCellValue('B5', $totalComplaints);
    $sheet->setCellValue('A6', 'Most Common Category');
    $sheet->setCellValue('B6', $topCategory);
    $sheet->mergeCells('B6:F6');
    $row = 7;
    foreach (['New','In Review','Resolved','Rejected'] as $st) {
        $sheet->setCellValue("A$row", $st.' (All-Time)');
        $sheet->setCellValue("B$row", (int)($statusCounts[$st] ?? 0));
        $row++;
    }
    $sheet->getStyle('A5:A'.($row-1))->getFont()->setBold(true);
    $sheet->getStyle('B5:B'.($row-1))->getFont()->applyFromArray(['bold'=>true,'color'=>['rgb'=>$MAROON]]);
    $row += 1;

    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->setCellValue("A{$row}", 'Complaints by Category — Today / This Week / This Month / This Year / All-Time');
    $sheet->getStyle("A{$row}")->applyFromArray(['font'=>['bold'=>true,'size'=>11,'color'=>['rgb'=>$MAROON]]]);
    $row += 1;
    $row = periodTable($sheet, $row, $categoryData, 'Category', $MAROON);

    foreach ([26,14,14,14,14,14] as $i=>$w) $sheet->getColumnDimension(chr(65+$i))->setWidth($w);

    // --- Sheet 2: Full Log ---
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('Full Log');
    $complaints = $pdo->query(
        "SELECT c.title, c.category, c.status, c.is_anonymous, c.created_at, s.first_name, s.last_name, s.student_id as sid
         FROM complaints c JOIN students s ON c.student_id=s.id ORDER BY c.created_at DESC"
    )->fetchAll();

    $sheet2->mergeCells('A1:G1'); $sheet2->setCellValue('A1','GUAGUA NATIONAL COLLEGE');
    $sheet2->getStyle('A1')->applyFromArray(['font'=>['bold'=>true,'size'=>14,'color'=>['rgb'=>$MAROON]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet2->mergeCells('A2:G2'); $sheet2->setCellValue('A2','Full Complaints Log');
    $sheet2->getStyle('A2')->applyFromArray(['font'=>['bold'=>true,'size'=>11,'color'=>['rgb'=>$GRAY_T]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet2->mergeCells('A3:G3'); $sheet2->setCellValue('A3', count($complaints).' complaint(s) · Generated: '.$now);
    $sheet2->getStyle('A3')->applyFromArray(['font'=>['italic'=>true,'size'=>10,'color'=>['rgb'=>'9CA3AF']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet2->getRowDimension(4)->setRowHeight(8);

    foreach (['#','Student ID','Student Name','Category','Title','Status','Date Filed'] as $i=>$h) $sheet2->setCellValue(chr(65+$i).'5',$h);
    $sheet2->getStyle('A5:G5')->applyFromArray(hStyle($MAROON)); $sheet2->getRowDimension(5)->setRowHeight(22);

    $r = 6;
    foreach ($complaints as $n=>$c) {
        $name = $c['is_anonymous'] ? 'Anonymous' : $c['last_name'].', '.$c['first_name'];
        $sid  = $c['is_anonymous'] ? '—' : $c['sid'];
        $sheet2->setCellValue("A$r",$n+1); $sheet2->setCellValue("B$r",$sid);
        $sheet2->setCellValue("C$r",$name); $sheet2->setCellValue("D$r",$c['category']);
        $sheet2->setCellValue("E$r",$c['title']); $sheet2->setCellValue("F$r",$c['status']);
        $sheet2->setCellValue("G$r",date('M j, Y',strtotime($c['created_at'])));
        zebraRow($sheet2, $r, 'G', $r%2===0); $r++;
    }
    foreach ([5,14,22,18,32,14,14] as $i=>$w) $sheet2->getColumnDimension(chr(65+$i))->setWidth($w);

    $filename = 'complaints_analytics_'.date('Ymd').'.xlsx';
}

// ══════════════════════════════════════════════════════════════════
// REVIEWS (EMOJI) ANALYTICS
// ══════════════════════════════════════════════════════════════════
elseif ($type === 'reviews_analytics') {
    $totalReviews = (int)$pdo->query("SELECT COUNT(*) FROM complaint_reviews")->fetchColumn();
    $reactionData = getReactionBreakdown($pdo);

    // --- Sheet 1: Summary ---
    $sheet->setTitle('Summary');
    titleBlock($sheet, 'GUAGUA NATIONAL COLLEGE', 'Office of Student Affairs — Emoji Reviews Analytics Report', 'Generated: '.$now, 6);

    $sheet->setCellValue('A5', 'Total Reviews (All-Time)');
    $sheet->setCellValue('B5', $totalReviews);
    $sheet->getStyle('A5')->getFont()->setBold(true);
    $sheet->getStyle('B5')->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>$MAROON]]]);

    $row = 7;
    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->setCellValue("A{$row}", 'Reviews by Reaction — Today / This Week / This Month / This Year / All-Time');
    $sheet->getStyle("A{$row}")->applyFromArray(['font'=>['bold'=>true,'size'=>11,'color'=>['rgb'=>$MAROON]]]);
    $row += 1;
    $row = periodTable($sheet, $row, $reactionData, 'Reaction', $GOLD);

    foreach ([22,14,14,14,14,14] as $i=>$w) $sheet->getColumnDimension(chr(65+$i))->setWidth($w);

    // --- Sheet 2: Full Log ---
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('Full Log');
    $reviews = $pdo->query(
        "SELECT cr.reaction, cr.comment, cr.created_at, c.title AS complaint_title, s.first_name, s.last_name
         FROM complaint_reviews cr
         JOIN complaints c ON cr.complaint_id = c.id
         JOIN students s ON cr.student_id = s.id
         ORDER BY cr.created_at DESC"
    )->fetchAll();

    $sheet2->mergeCells('A1:F1'); $sheet2->setCellValue('A1','GUAGUA NATIONAL COLLEGE');
    $sheet2->getStyle('A1')->applyFromArray(['font'=>['bold'=>true,'size'=>14,'color'=>['rgb'=>$MAROON]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet2->mergeCells('A2:F2'); $sheet2->setCellValue('A2','Full Reviews Log');
    $sheet2->getStyle('A2')->applyFromArray(['font'=>['bold'=>true,'size'=>11,'color'=>['rgb'=>$GRAY_T]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet2->mergeCells('A3:F3'); $sheet2->setCellValue('A3', count($reviews).' review(s) · Generated: '.$now);
    $sheet2->getStyle('A3')->applyFromArray(['font'=>['italic'=>true,'size'=>10,'color'=>['rgb'=>'9CA3AF']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet2->getRowDimension(4)->setRowHeight(8);

    foreach (['#','Student Name','Complaint','Reaction','Comment','Date'] as $i=>$h) $sheet2->setCellValue(chr(65+$i).'5',$h);
    $sheet2->getStyle('A5:F5')->applyFromArray(hStyle($GOLD)); $sheet2->getRowDimension(5)->setRowHeight(22);

    $r = 6;
    foreach ($reviews as $n=>$rv) {
        $sheet2->setCellValue("A$r",$n+1);
        $sheet2->setCellValue("B$r",$rv['last_name'].', '.$rv['first_name']);
        $sheet2->setCellValue("C$r",$rv['complaint_title']);
        $sheet2->setCellValue("D$r",$rv['reaction'].' '.($reactionLabels[$rv['reaction']] ?? ''));
        $sheet2->setCellValue("E$r",$rv['comment'] ?? '');
        $sheet2->setCellValue("F$r",date('M j, Y',strtotime($rv['created_at'])));
        zebraRow($sheet2, $r, 'F', $r%2===0); $r++;
    }
    foreach ([5,22,26,16,34,14] as $i=>$w) $sheet2->getColumnDimension(chr(65+$i))->setWidth($w);

    $filename = 'reviews_analytics_'.date('Ymd').'.xlsx';
}

// ══════════════════════════════════════════════════════════════════
// COMBINED OVERVIEW
// ══════════════════════════════════════════════════════════════════
elseif ($type === 'combined_overview') {
    $totalComplaints = (int)$pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn();
    $totalReviews    = (int)$pdo->query("SELECT COUNT(*) FROM complaint_reviews")->fetchColumn();
    $categoryData    = getCategoryBreakdown($pdo);
    $reactionData    = getReactionBreakdown($pdo);
    $topCategory     = $categoryData[0]['label'] ?? 'N/A';

    $positiveCount = 0;
    foreach ($reactionData as $r) { if (in_array(explode(' ', $r['label'])[0], ['😊','👍'])) $positiveCount += $r['all_time']; }
    $satisfactionRate = $totalReviews > 0 ? round($positiveCount / $totalReviews * 100, 1) : 0;

    $sheet->setTitle('Overview');
    titleBlock($sheet, 'GUAGUA NATIONAL COLLEGE', 'Office of Student Affairs — Combined Overview Report', 'Generated: '.$now, 6);

    $kpis = [
        ['Total Complaints', $totalComplaints],
        ['Total Reviews Submitted', $totalReviews],
        ['Most Common Complaint Category', $topCategory],
        ['Overall Satisfaction Rate (😊+👍)', $satisfactionRate.'%'],
    ];
    $row = 5;
    foreach ($kpis as $k) {
        $sheet->setCellValue("A$row", $k[0]);
        $sheet->mergeCells("B{$row}:F{$row}");
        $sheet->setCellValue("B$row", $k[1]);
        $sheet->getStyle("A$row")->getFont()->setBold(true);
        $sheet->getStyle("B$row")->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>$MAROON]]]);
        $row++;
    }
    $row += 1;

    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->setCellValue("A{$row}", 'Complaints by Category');
    $sheet->getStyle("A{$row}")->applyFromArray(['font'=>['bold'=>true,'size'=>11,'color'=>['rgb'=>$MAROON]]]);
    $row++;
    $row = periodTable($sheet, $row, $categoryData, 'Category', $MAROON);
    $row += 1;

    $sheet->mergeCells("A{$row}:F{$row}");
    $sheet->setCellValue("A{$row}", 'Reviews by Reaction');
    $sheet->getStyle("A{$row}")->applyFromArray(['font'=>['bold'=>true,'size'=>11,'color'=>['rgb'=>$MAROON]]]);
    $row++;
    periodTable($sheet, $row, $reactionData, 'Reaction', $GOLD);

    foreach ([26,14,14,14,14,14] as $i=>$w) $sheet->getColumnDimension(chr(65+$i))->setWidth($w);

    $filename = 'combined_overview_'.date('Ymd').'.xlsx';
}

// ══════════════════════════════════════════════════════════════════
// CUSTOM DATE RANGE
// ══════════════════════════════════════════════════════════════════
elseif ($type === 'custom_range') {
    $start = $_GET['start_date'] ?? '';
    $end   = $_GET['end_date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $start > $end) {
        http_response_code(400); die('Invalid date range.');
    }

    $catStmt = $pdo->prepare("SELECT category, COUNT(*) AS total FROM complaints WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY category ORDER BY total DESC, category ASC");
    $catStmt->execute([$start, $end]);
    $categoryData = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    $reactStmt = $pdo->prepare("SELECT reaction, COUNT(*) AS total FROM complaint_reviews WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY reaction");
    $reactStmt->execute([$start, $end]);
    $reactRaw = $reactStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $totalComplaintsRange = array_sum(array_column($categoryData, 'total'));
    $totalReviewsRange = array_sum($reactRaw);

    $sheet->setTitle('Custom Range');
    $rangeLabel = date('M j, Y', strtotime($start)).' – '.date('M j, Y', strtotime($end));
    titleBlock($sheet, 'GUAGUA NATIONAL COLLEGE', 'Office of Student Affairs — Custom Date Range Report', $rangeLabel.' · Generated: '.$now, 4);

    $sheet->setCellValue('A5', 'Total Complaints in Range'); $sheet->setCellValue('B5', $totalComplaintsRange);
    $sheet->setCellValue('A6', 'Total Reviews in Range'); $sheet->setCellValue('B6', $totalReviewsRange);
    $sheet->getStyle('A5:A6')->getFont()->setBold(true);
    $sheet->getStyle('B5:B6')->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>$MAROON]]]);

    $row = 8;
    $sheet->mergeCells("A{$row}:D{$row}");
    $sheet->setCellValue("A{$row}", 'Complaints by Category');
    $sheet->getStyle("A{$row}")->applyFromArray(['font'=>['bold'=>true,'size'=>11,'color'=>['rgb'=>$MAROON]]]);
    $row++;
    $sheet->setCellValue("A{$row}",'Category'); $sheet->setCellValue("B{$row}",'Count');
    $sheet->getStyle("A{$row}:B{$row}")->applyFromArray(hStyle($MAROON)); $sheet->getRowDimension($row)->setRowHeight(20);
    $row++;
    if ($categoryData) {
        foreach ($categoryData as $c) {
            $sheet->setCellValue("A$row",$c['category']); $sheet->setCellValue("B$row",(int)$c['total']);
            zebraRow($sheet, $row, 'B', $row%2===0); $row++;
        }
    } else {
        $sheet->setCellValue("A$row",'No complaints in this range.'); $sheet->mergeCells("A{$row}:B{$row}"); $row++;
    }
    $row += 1;

    $sheet->mergeCells("A{$row}:D{$row}");
    $sheet->setCellValue("A{$row}", 'Reviews by Reaction');
    $sheet->getStyle("A{$row}")->applyFromArray(['font'=>['bold'=>true,'size'=>11,'color'=>['rgb'=>$MAROON]]]);
    $row++;
    $sheet->setCellValue("A{$row}",'Reaction'); $sheet->setCellValue("B{$row}",'Count');
    $sheet->getStyle("A{$row}:B{$row}")->applyFromArray(hStyle($GOLD)); $sheet->getRowDimension($row)->setRowHeight(20);
    $row++;
    foreach ($reactionLabels as $emoji => $label) {
        $sheet->setCellValue("A$row", $emoji.' '.$label);
        $sheet->setCellValue("B$row", (int)($reactRaw[$emoji] ?? 0));
        zebraRow($sheet, $row, 'B', $row%2===0); $row++;
    }

    foreach ([30,12,12,12] as $i=>$w) $sheet->getColumnDimension(chr(65+$i))->setWidth($w);

    $filename = 'custom_range_'.$start.'_to_'.$end.'.xlsx';
}

// ── Output ────────────────────────────────────────────────────────
outputSpreadsheet($spreadsheet, $filename);

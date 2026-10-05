<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/export-helpers.php';
requireAdmin();

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$type = $_GET['type'] ?? '';
$eid  = (int)($_GET['election'] ?? 0);

$ALLOWED = ['candidates','results','students','complaints','voters','party_lists','winners'];
if (!in_array($type, $ALLOWED)) { http_response_code(400); die('Invalid export type.'); }

// ── Style helpers ─────────────────────────────────────────────────
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

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// ══════════════════════════════════════════════════════════════════
// CANDIDATES
// ══════════════════════════════════════════════════════════════════
if ($type === 'candidates') {
    if (!$eid) die('No election selected.');
    $elStmt = $pdo->prepare("SELECT * FROM elections WHERE id=?"); $elStmt->execute([$eid]);
    $election = $elStmt->fetch();

    $rows = $pdo->prepare("SELECT p.title as position, s.student_id as sid, s.first_name, s.last_name, s.course, pl.name as party_name FROM candidates c JOIN positions p ON c.position_id=p.id JOIN students s ON c.student_id=s.id LEFT JOIN party_lists pl ON c.party_list_id=pl.id WHERE c.election_id=? ORDER BY p.sort_order, s.last_name");
    $rows->execute([$eid]); $candidates = $rows->fetchAll();

    $sheet->setTitle('Candidates');
    titleBlock($sheet, 'GUAGUA NATIONAL COLLEGE', 'Office of Student Affairs — Candidates List', $election['title'].' · Exported: '.date('F j, Y'), 7);

    foreach (['#','Position','Student ID','Last Name','First Name','Course','Party List'] as $i=>$h) {
        $sheet->setCellValue(chr(65+$i).'5', $h);
    }
    $sheet->getStyle('A5:G5')->applyFromArray(hStyle($MAROON));
    $sheet->getRowDimension(5)->setRowHeight(22);

    $row = 6;
    foreach ($candidates as $n=>$c) {
        $sheet->setCellValue("A$row", $n+1); $sheet->setCellValue("B$row", $c['position']);
        $sheet->setCellValueExplicit("C$row", (string)$c['sid'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING); $sheet->setCellValue("D$row", $c['last_name']);
        $sheet->setCellValue("E$row", $c['first_name']); $sheet->setCellValue("F$row", $c['course']);
        $sheet->setCellValue("G$row", $c['party_name'] ?: 'Independent');
        
        zebraRow($sheet, $row, 'G', $row%2===0); $row++;
    }
    $sheet->mergeCells("A{$row}:F{$row}"); $sheet->setCellValue("A{$row}",'Total Candidates'); $sheet->setCellValue("G{$row}", $row > 6 ? "=COUNTA(C6:C".($row-1).")" : 0);
    $sheet->getStyle("A{$row}:G{$row}")->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>$MAROON]],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F9EEF0']]]);

    foreach ([5,22,16,18,18,14,26] as $i=>$w) $sheet->getColumnDimension(chr(65+$i))->setWidth($w);
    $filename = 'candidates_'.preg_replace('/[^a-z0-9]/i','_',$election['title']).'_'.date('Ymd').'.xlsx';
}

// ══════════════════════════════════════════════════════════════════
// PARTY LISTS
// ══════════════════════════════════════════════════════════════════
elseif ($type === 'party_lists') {
    // Party lists WITH the candidates inside each one.
    // xlsx / pdf / docx: grouped (a coloured banner per party, its candidates beneath).
    // csv: flat table (party name repeated on every row) so it opens cleanly in any tool.
    if (!$eid) die('No election selected.');
    $elStmt = $pdo->prepare("SELECT * FROM elections WHERE id=?"); $elStmt->execute([$eid]);
    $election = $elStmt->fetch();

    $plStmt = $pdo->prepare("SELECT id, name, platform, color FROM party_lists WHERE election_id=? ORDER BY name");
    $plStmt->execute([$eid]); $partyLists = $plStmt->fetchAll();

    $cStmt = $pdo->prepare("SELECT c.party_list_id, p.title AS position, s.student_id AS sid, s.last_name, s.first_name, s.course
                            FROM candidates c
                            JOIN positions p ON c.position_id = p.id
                            JOIN students  s ON c.student_id  = s.id
                            WHERE c.election_id=? ORDER BY p.sort_order, s.last_name, s.first_name");
    $cStmt->execute([$eid]);
    $byParty = [];   // party_list_id (0 = independent) => candidates
    foreach ($cStmt->fetchAll() as $c) $byParty[(int)$c['party_list_id']][] = $c;

    // Build the groups: every party list, then the independents
    $groups = [];
    foreach ($partyLists as $pl) {
        $groups[] = ['name'=>$pl['name'], 'platform'=>$pl['platform'], 'color'=>$pl['color'], 'cands'=>$byParty[(int)$pl['id']] ?? []];
    }
    if (!empty($byParty[0])) {
        $groups[] = ['name'=>'Independent (no party list)', 'platform'=>'', 'color'=>'6B7280', 'cands'=>$byParty[0]];
    }
    $isCsv = strtolower($_GET['format'] ?? '') === 'csv';

    $sheet->setTitle('Party Lists & Candidates');
    titleBlock($sheet, 'GUAGUA NATIONAL COLLEGE', 'Office of Student Affairs — Party Lists & Candidates', $election['title'].' · Exported: '.date('F j, Y'), 6);

    $headers = $isCsv ? ['Party List','Position','Student ID','Last Name','First Name','Course']
                      : ['#','Position','Student ID','Last Name','First Name','Course'];
    foreach ($headers as $i=>$h) $sheet->setCellValue(chr(65+$i).'5', $h);
    $sheet->getStyle('A5:F5')->applyFromArray(hStyle($MAROON));
    $sheet->getRowDimension(5)->setRowHeight(22);

    $row = 6; $totalCands = 0; $totalParties = 0;
    foreach ($groups as $g) {
        $isIndep = ($g['name'] === 'Independent (no party list)');
        if (!$isIndep) $totalParties++;
        $totalCands += count($g['cands']);

        if ($isCsv) {
            foreach ($g['cands'] as $c) {
                foreach ([$g['name'],$c['position'],$c['sid'],$c['last_name'],$c['first_name'],$c['course']] as $i=>$v) {
                    $sheet->setCellValueExplicit(chr(65+$i).$row, (string)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
                $row++;
            }
            continue;
        }

        // Banner row: party name + candidate count, in the party's own colour
        $hex = strtoupper(ltrim((string)$g['color'], '#'));
        if (!preg_match('/^[0-9A-F]{6}$/', $hex)) $hex = $MAROON;
        $lum = 0.299*hexdec(substr($hex,0,2)) + 0.587*hexdec(substr($hex,2,2)) + 0.114*hexdec(substr($hex,4,2));
        $txt = $lum > 170 ? '1F2937' : 'FFFFFF';
        $n = count($g['cands']);
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", mb_strtoupper($g['name']).'  —  '.$n.' candidate'.($n === 1 ? '' : 's'));
        $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
            'font'=>['bold'=>true,'size'=>12,'color'=>['rgb'=>$txt]],
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$hex]],
            'alignment'=>['horizontal'=>Alignment::HORIZONTAL_LEFT,'vertical'=>Alignment::VERTICAL_CENTER,'indent'=>1],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(24);
        $row++;

        // Platform line (italic, wraps)
        if (trim((string)$g['platform']) !== '') {
            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->setCellValue("A{$row}", 'Platform: '.trim($g['platform']));
            $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
                'font'=>['italic'=>true,'size'=>10,'color'=>['rgb'=>'4B5563']],
                'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F9FAFB']],
                'alignment'=>['wrap'=>true,'vertical'=>Alignment::VERTICAL_TOP,'indent'=>1],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(max(18, ceil(mb_strlen($g['platform'])/85)*15));
            $row++;
        }

        // Candidates
        if (!$n) {
            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->setCellValue("A{$row}", 'No candidates yet');
            $sheet->getStyle("A{$row}:F{$row}")->applyFromArray(['font'=>['italic'=>true,'color'=>['rgb'=>'9CA3AF']],'alignment'=>['indent'=>1]]);
            $row++;
        }
        foreach ($g['cands'] as $k=>$c) {
            $sheet->setCellValue("A$row", $k+1);
            $sheet->setCellValue("B$row", $c['position']);
            $sheet->setCellValueExplicit("C$row", (string)$c['sid'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("D$row", $c['last_name']);
            $sheet->setCellValue("E$row", $c['first_name']);
            $sheet->setCellValue("F$row", $c['course']);
            $sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            zebraRow($sheet, $row, 'F', $k%2===1);
            $row++;
        }
        $row++;   // blank spacer row between parties
    }

    if (!$isCsv) {
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", 'Total: '.$totalParties.' party list'.($totalParties === 1 ? '' : 's').' · '.$totalCands.' candidate'.($totalCands === 1 ? '' : 's'));
        $sheet->getStyle("A{$row}:F{$row}")->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>$MAROON]],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F9EEF0']]]);
    }

    foreach ([5,24,16,18,18,14] as $i=>$w) $sheet->getColumnDimension(chr(65+$i))->setWidth($w);

    // Excel only: add a flat "Party Lists (for import)" sheet in the layout the importer
    // reads (Party List, Platform, Color), so an exported file can be imported into another
    // election. PDF / Word / CSV don't get it, they'd just print an extra page.
    $fmt = strtolower($_GET['format'] ?? 'xlsx');
    if ($fmt === 'xlsx' || !isset(EXPORT_FORMATS[$fmt])) {
        $imp = $spreadsheet->createSheet();
        $imp->setTitle('Party Lists (for import)');
        titleBlock($imp, 'GUAGUA NATIONAL COLLEGE', 'Office of Student Affairs — Party Lists (import layout)', $election['title'].' · Exported: '.date('F j, Y'), 7);
        foreach (['#','Party List','Platform','Color','Candidates','Logo','ID'] as $i=>$h) $imp->setCellValue(chr(65+$i).'5', $h);
        $imp->getStyle('A5:G5')->applyFromArray(hStyle($MAROON));
        $imp->getRowDimension(5)->setRowHeight(22);
        $r2 = 6;
        foreach ($partyLists as $n=>$pl) {
            $imp->setCellValue("A$r2", $n+1);
            $imp->setCellValue("B$r2", $pl['name']);
            $imp->setCellValue("C$r2", $pl['platform'] ?: '—');
            $imp->setCellValue("D$r2", $pl['color'] ?: '#6B1A2A');
            $imp->setCellValue("E$r2", count($byParty[(int)$pl['id']] ?? []));
            $imp->setCellValue("F$r2", '');
            // Hidden-ish key: lets the importer UPDATE this exact party list (rename, new platform/color)
            // instead of adding a duplicate. Don't edit this column.
            $imp->setCellValue("G$r2", (int)$pl['id']);
            $imp->getStyle("G$r2")->getFont()->getColor()->setRGB('9CA3AF');
            zebraRow($imp, $r2, 'G', $r2%2===0); $r2++;
        }
        $imp->setCellValue('A'.($r2+1), 'To rename or edit an existing party list, change its Party List / Platform / Color here and re-import. Do NOT edit or delete the ID column.');
        $imp->getStyle('A'.($r2+1))->getFont()->setItalic(true)->getColor()->setRGB('6B7280');
        foreach ([5,24,45,12,12,8,8] as $i=>$w) $imp->getColumnDimension(chr(65+$i))->setWidth($w);
        $spreadsheet->setActiveSheetIndex(0);
    }
    $filename = 'party_lists_'.preg_replace('/[^a-z0-9]/i','_',$election['title']).'_'.date('Ymd').'.xlsx';
}

// ══════════════════════════════════════════════════════════════════
// RESULTS
// ══════════════════════════════════════════════════════════════════
elseif ($type === 'results') {
    if (!$eid) die('No election selected.');
    $elStmt = $pdo->prepare("SELECT * FROM elections WHERE id=?"); $elStmt->execute([$eid]);
    $election = $elStmt->fetch();

    $tvStmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM votes WHERE election_id=?");
    $tvStmt->execute([$eid]);
    $totalVoters = (int)$tvStmt->fetchColumn();
    $totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE is_active=1")->fetchColumn();
    $turnout       = $totalStudents > 0 ? round($totalVoters/$totalStudents*100,1) : 0;

    $posStmt = $pdo->prepare("SELECT * FROM positions WHERE election_id=? ORDER BY sort_order");
    $posStmt->execute([$eid]); $positions = $posStmt->fetchAll();
    foreach ($positions as &$p) {
        $cStmt = $pdo->prepare("SELECT s.first_name, s.last_name, s.student_id as sid, s.course, COUNT(v.id) as votes FROM candidates c JOIN students s ON c.student_id=s.id LEFT JOIN votes v ON v.candidate_id=c.id AND v.election_id=? WHERE c.position_id=? GROUP BY c.id ORDER BY votes DESC");
        $cStmt->execute([$eid,$p['id']]); $p['candidates']=$cStmt->fetchAll();
        $p['total_votes']=array_sum(array_column($p['candidates'],'votes'));
    } unset($p);

    $sheet->setTitle('Election Results');
    titleBlock($sheet, 'GUAGUA NATIONAL COLLEGE', 'Office of Student Affairs — Official Election Results', $election['title'].' · Exported: '.date('F j, Y \a\t g:i A'), 7);

    foreach (['Total Students','Total Voters','Did Not Vote','Voter Turnout'] as $i=>$label) {
        $col=chr(65+$i);
        $sheet->setCellValue($col.'5',$label); $sheet->setCellValue($col.'6',[$totalStudents,$totalVoters,$totalStudents-$totalVoters,$turnout.'%'][$i]);
        $sheet->getStyle($col.'5')->applyFromArray(['font'=>['bold'=>true,'size'=>9,'color'=>['rgb'=>'FFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$MAROON]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
        $sheet->getStyle($col.'6')->applyFromArray(['font'=>['bold'=>true,'size'=>13,'color'=>['rgb'=>$MAROON]],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F9EEF0']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    }

    $row = 8;
    foreach ($positions as $pos) {
        $sheet->mergeCells("A{$row}:G{$row}"); $sheet->setCellValue("A{$row}",strtoupper($pos['title']));
        $sheet->getStyle("A{$row}:G{$row}")->applyFromArray(['font'=>['bold'=>true,'size'=>12,'color'=>['rgb'=>'FFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$GOLD]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_LEFT,'indent'=>1]]);
        $sheet->getRowDimension($row)->setRowHeight(24); $row++;

        foreach (['Rank','Student ID','Last Name','First Name','Course','Votes','% of Votes'] as $i=>$h) $sheet->setCellValue(chr(65+$i).$row,$h);
        $sheet->getStyle("A{$row}:G{$row}")->applyFromArray(hStyle('4A0F1B')); $sheet->getRowDimension($row)->setRowHeight(20); $row++;

        foreach ($pos['candidates'] as $rank=>$c) {
            $pct = $pos['total_votes']>0 ? round($c['votes']/$pos['total_votes']*100,1) : 0;
            $sheet->setCellValue("A$row",$rank+1); $sheet->setCellValue("B$row",$c['sid']);
            $sheet->setCellValue("C$row",$c['last_name']); $sheet->setCellValue("D$row",$c['first_name']);
            $sheet->setCellValue("E$row",$c['course']); $sheet->setCellValue("F$row",$c['votes']); $sheet->setCellValue("G$row",$pct.'%');
            if ($rank===0 && $c['votes']>0) $sheet->getStyle("A{$row}:G{$row}")->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>$GREEN]],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$GREEN_L]]]);
            elseif ($row%2===0) $sheet->getStyle("A{$row}:G{$row}")->applyFromArray(['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F9FAFB']]]);
            $sheet->getStyle("A{$row}:G{$row}")->applyFromArray(bStyle());
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("F{$row}:G{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight(18); $row++;
        }
        $sheet->mergeCells("A{$row}:E{$row}"); $sheet->setCellValue("A{$row}",'Total votes for '.$pos['title']); $sheet->setCellValue("F{$row}",$pos['total_votes']); $sheet->setCellValue("G{$row}",'100%');
        $sheet->getStyle("A{$row}:G{$row}")->applyFromArray(['font'=>['bold'=>true,'italic'=>true,'color'=>['rgb'=>$GRAY_T]],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F3F4F6']],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'E5E7EB']]]]);
        $sheet->getStyle("F{$row}:G{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row += 2;
    }
    $sheet->mergeCells("A{$row}:G{$row}"); $sheet->setCellValue("A{$row}",'★ Green rows = winner per position.');
    $sheet->getStyle("A{$row}")->applyFromArray(['font'=>['italic'=>true,'size'=>9,'color'=>['rgb'=>$GREEN]],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$GREEN_L]]]);

    foreach ([8,16,18,18,14,10,14] as $i=>$w) $sheet->getColumnDimension(chr(65+$i))->setWidth($w);
    $filename = 'results_'.preg_replace('/[^a-z0-9]/i','_',$election['title']).'_'.date('Ymd').'.xlsx';
}

// ══════════════════════════════════════════════════════════════════
// STUDENTS
// ══════════════════════════════════════════════════════════════════
elseif ($type === 'students') {
    $students = $pdo->query("SELECT student_id, first_name, last_name, course, email, is_active, created_at FROM students ORDER BY last_name, first_name")->fetchAll();

    $sheet->setTitle('Students');
    titleBlock($sheet, 'GUAGUA NATIONAL COLLEGE', 'Office of Student Affairs — Student Masterlist', 'Exported: '.date('F j, Y \a\t g:i A').' · Total: '.count($students).' student(s)', 9);

    foreach (['#','Student ID','Last Name','First Name','Course','Email','Status'] as $i=>$h) $sheet->setCellValue(chr(65+$i).'5',$h);
    $sheet->getStyle('A5:G5')->applyFromArray(hStyle($MAROON)); $sheet->getRowDimension(5)->setRowHeight(22);

    $row = 6;
    foreach ($students as $n=>$s) {
        $sheet->setCellValue("A$row",$n+1); $sheet->setCellValue("B$row",$s['student_id']);
        $sheet->setCellValue("C$row",$s['last_name']); $sheet->setCellValue("D$row",$s['first_name']);
        $sheet->setCellValue("E$row",$s['course']??''); $sheet->setCellValue("F$row",$s['email']??'');
        $sheet->setCellValue("G$row",$s['is_active']?'Active':'Inactive');
        if (!$s['is_active']) $sheet->getStyle("G$row")->getFont()->getColor()->setRGB('9CA3AF');
        else $sheet->getStyle("G$row")->getFont()->setBold(true)->getColor()->setRGB($GREEN);
        zebraRow($sheet, $row, 'G', $row%2===0); $row++;
    }
    $sheet->mergeCells("A{$row}:F{$row}"); $sheet->setCellValue("A{$row}",'Total Students');
    $sheet->setCellValue("G{$row}", $row > 6 ? "=COUNTA(B6:B".($row-1).")" : 0);
    $sheet->getStyle("A{$row}:G{$row}")->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>$MAROON]],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F9EEF0']]]);

    foreach ([5,16,18,18,12,28,12] as $i=>$w) $sheet->getColumnDimension(chr(65+$i))->setWidth($w);
    $filename = 'students_masterlist_'.date('Ymd').'.xlsx';
}

// ══════════════════════════════════════════════════════════════════
// COMPLAINTS
// ══════════════════════════════════════════════════════════════════
elseif ($type === 'complaints') {
    $complaints = $pdo->query("SELECT c.title, c.category, c.status, c.is_anonymous, c.created_at, c.updated_at, s.first_name, s.last_name, s.student_id as sid, st.first_name as staff_fn, st.last_name as staff_ln FROM complaints c JOIN students s ON c.student_id=s.id LEFT JOIN osa_staff st ON c.assigned_to=st.id ORDER BY c.created_at DESC")->fetchAll();

    $sheet->setTitle('Complaints');
    titleBlock($sheet, 'GUAGUA NATIONAL COLLEGE', 'Office of Student Affairs — Complaints Report', 'Exported: '.date('F j, Y \a\t g:i A').' · Total: '.count($complaints).' complaint(s)', 8);

    foreach (['#','Student ID','Student Name','Category','Title','Status','Assigned To','Date Filed','Last Updated'] as $i=>$h) $sheet->setCellValue(chr(65+$i).'5',$h);
    $sheet->getStyle('A5:I5')->applyFromArray(hStyle($MAROON)); $sheet->getRowDimension(5)->setRowHeight(22);

    $statusColors = ['New'=>'EDE9FE','In Review'=>'FEF3C7','Resolved'=>'D1FAE5','Rejected'=>'FEE2E2'];
    $statusText   = ['New'=>'7C3AED','In Review'=>'92400E','Resolved'=>'065F46','Rejected'=>'991B1B'];

    $row = 6;
    foreach ($complaints as $n=>$c) {
        $name = $c['is_anonymous'] ? 'Anonymous' : $c['last_name'].', '.$c['first_name'];
        $sid  = $c['is_anonymous'] ? '—' : $c['sid'];
        $staff= $c['staff_fn'] ? $c['staff_fn'].' '.$c['staff_ln'] : 'Unassigned';

        $sheet->setCellValue("A$row",$n+1); $sheet->setCellValue("B$row",$sid);
        $sheet->setCellValue("C$row",$name); $sheet->setCellValue("D$row",$c['category']);
        $sheet->setCellValue("E$row",$c['title']); $sheet->setCellValue("F$row",$c['status']);
        $sheet->setCellValue("G$row",$staff);
        $sheet->setCellValue("H$row",date('M j, Y',strtotime($c['created_at'])));
        $sheet->setCellValue("I$row",date('M j, Y',strtotime($c['updated_at'])));

        $bg   = $statusColors[$c['status']] ?? 'F3F4F6';
        $fg   = $statusText[$c['status']]   ?? '374151';
        $sheet->getStyle("F$row")->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>$fg]],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$bg]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);

        if ($row%2===0) {
            $sheet->getStyle("A{$row}:E{$row}")->applyFromArray(['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F9FAFB']]]);
            $sheet->getStyle("G{$row}:I{$row}")->applyFromArray(['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F9FAFB']]]);
        }
        $sheet->getStyle("A{$row}:I{$row}")->applyFromArray(bStyle());
        $sheet->getStyle("A{$row}:I{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(18); $row++;
    }
    $sheet->mergeCells("A{$row}:H{$row}"); $sheet->setCellValue("A{$row}",'Total Complaints'); $sheet->setCellValue("I{$row}", $row > 6 ? "=COUNTA(C6:C".($row-1).")" : 0);
    $sheet->getStyle("A{$row}:I{$row}")->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>$MAROON]],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F9EEF0']]]);

    foreach ([5,14,22,16,30,14,20,14,14] as $i=>$w) $sheet->getColumnDimension(chr(65+$i))->setWidth($w);
    $filename = 'complaints_report_'.date('Ymd').'.xlsx';
}

// ══════════════════════════════════════════════════════════════════
// VOTERS LIST
// ══════════════════════════════════════════════════════════════════
elseif ($type === 'voters') {
    if (!$eid) die('No election selected.');
    $elStmt = $pdo->prepare("SELECT * FROM elections WHERE id=?"); $elStmt->execute([$eid]);
    $election = $elStmt->fetch();

    $voted = $pdo->prepare("SELECT DISTINCT s.student_id, s.first_name, s.last_name, s.course, MIN(v.voted_at) as voted_at FROM votes v JOIN students s ON v.student_id=s.id WHERE v.election_id=? GROUP BY s.id ORDER BY s.last_name");
    $voted->execute([$eid]); $voters = $voted->fetchAll();

    $notVoted = $pdo->prepare("SELECT s.student_id, s.first_name, s.last_name, s.course FROM students s WHERE s.is_active=1 AND s.id NOT IN (SELECT DISTINCT student_id FROM votes WHERE election_id=?) ORDER BY s.last_name");
    $notVoted->execute([$eid]); $nonVoters = $notVoted->fetchAll();

    // Sheet 1 — Voted
    $sheet->setTitle('Voted');
    titleBlock($sheet, 'GUAGUA NATIONAL COLLEGE', 'Office of Student Affairs — Voters List', $election['title'].' · Exported: '.date('F j, Y').' · '.count($voters).' voted', 7);

    foreach (['#','Student ID','Last Name','First Name','Course','Time Voted'] as $i=>$h) $sheet->setCellValue(chr(65+$i).'5',$h);
    $sheet->getStyle('A5:H5')->applyFromArray(hStyle('059669')); $sheet->getRowDimension(5)->setRowHeight(22);

    $row = 6;
    foreach ($voters as $n=>$v) {
        $sheet->setCellValue("A$row",$n+1); $sheet->setCellValue("B$row",$v['student_id']);
        $sheet->setCellValue("C$row",$v['last_name']); $sheet->setCellValue("D$row",$v['first_name']);
        $sheet->setCellValue("E$row",$v['course']??'');
        $sheet->setCellValue("H$row",date('M j, Y g:i A',strtotime($v['voted_at'])));
        zebraRow($sheet, $row, 'H', $row%2===0); $row++;
    }
    foreach ([5,16,18,18,12,8,12,22] as $i=>$w) $sheet->getColumnDimension(chr(65+$i))->setWidth($w);

    // Sheet 2 — Did Not Vote
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('Did Not Vote');

    $sheet2->mergeCells('A1:G1'); $sheet2->setCellValue('A1','GUAGUA NATIONAL COLLEGE');
    $sheet2->getStyle('A1')->applyFromArray(['font'=>['bold'=>true,'size'=>14,'color'=>['rgb'=>$MAROON]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet2->mergeCells('A2:G2'); $sheet2->setCellValue('A2','Students Who Did Not Vote — '.$election['title']);
    $sheet2->getStyle('A2')->applyFromArray(['font'=>['bold'=>true,'size'=>11,'color'=>['rgb'=>$GRAY_T]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet2->mergeCells('A3:G3'); $sheet2->setCellValue('A3',count($nonVoters).' student(s) · Exported: '.date('F j, Y'));
    $sheet2->getStyle('A3')->applyFromArray(['font'=>['italic'=>true,'size'=>10,'color'=>['rgb'=>'9CA3AF']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet2->getRowDimension(4)->setRowHeight(8);

    foreach (['#','Student ID','Last Name','First Name','Course'] as $i=>$h) $sheet2->setCellValue(chr(65+$i).'5',$h);
    $sheet2->getStyle('A5:G5')->applyFromArray(hStyle('991B1B')); $sheet2->getRowDimension(5)->setRowHeight(22);

    $row2 = 6;
    foreach ($nonVoters as $n=>$s) {
        $sheet2->setCellValue("A$row2",$n+1); $sheet2->setCellValue("B$row2",$s['student_id']);
        $sheet2->setCellValue("C$row2",$s['last_name']); $sheet2->setCellValue("D$row2",$s['first_name']);
        $sheet2->setCellValue("E$row2",$s['course']??'');
        if ($row2%2===0) $sheet2->getStyle("A{$row2}:G{$row2}")->applyFromArray(['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'FEF2F2']]]);
        $sheet2->getStyle("A{$row2}:G{$row2}")->applyFromArray(bStyle());
        $sheet2->getRowDimension($row2)->setRowHeight(18); $row2++;
    }
    foreach ([5,16,18,18,12,8,12] as $i=>$w) $sheet2->getColumnDimension(chr(65+$i))->setWidth($w);

    $filename = 'voters_'.preg_replace('/[^a-z0-9]/i','_',$election['title']).'_'.date('Ymd').'.xlsx';
}

// ══════════════════════════════════════════════════════════════════
// WINNERS  (official list of winning candidates per position)
//   - slots per position = positions.max_votes
//   - ties at the cut-off are flagged "TIE" instead of silently picking one
//   - only for elections that are no longer Active
// ══════════════════════════════════════════════════════════════════
elseif ($type === 'winners') {
    if (!$eid) die('No election selected.');
    $elStmt = $pdo->prepare("SELECT * FROM elections WHERE id=?"); $elStmt->execute([$eid]);
    $election = $elStmt->fetch();
    if (!$election) die('Election not found.');
    if (!empty($election['is_active'])) {
        http_response_code(400);
        die('This election is still Active, so the results are not final yet. Close the election first, then export the winners.');
    }
    $isCompleted = !empty($election['is_completed']);

    $tvStmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM votes WHERE election_id=?");
    $tvStmt->execute([$eid]);
    $totalVoters = (int)$tvStmt->fetchColumn();
    if ($election['course'] !== '') {
        $tsStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE is_active=1 AND course=?"); $tsStmt->execute([$election['course']]);
    } else {
        $tsStmt = $pdo->query("SELECT COUNT(*) FROM students WHERE is_active=1");
    }
    $totalEligible = (int)$tsStmt->fetchColumn();
    $turnout = $totalEligible > 0 ? round($totalVoters / $totalEligible * 100, 1) : 0;

    $posStmt = $pdo->prepare("SELECT * FROM positions WHERE election_id=? ORDER BY sort_order, id");
    $posStmt->execute([$eid]); $positions = $posStmt->fetchAll();

    $candSql = "SELECT s.first_name, s.last_name, s.student_id AS sid, s.course, pl.name AS party, COUNT(v.id) AS votes
                FROM candidates c
                JOIN students s ON c.student_id = s.id
                LEFT JOIN party_lists pl ON c.party_list_id = pl.id
                LEFT JOIN votes v ON v.candidate_id = c.id AND v.election_id = ?
                WHERE c.position_id = ?
                GROUP BY c.id ORDER BY votes DESC, s.last_name, s.first_name";
    $candStmt = $pdo->prepare($candSql);
    $absStmt  = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE election_id=? AND position_id=? AND is_abstain=1");

    // Work out winners / ties for each position
    $out = []; $tieCount = 0; $winnerCount = 0;
    foreach ($positions as $pos) {
        $candStmt->execute([$eid, $pos['id']]); $cands = $candStmt->fetchAll();
        $absStmt->execute([$eid, $pos['id']]);  $abstain = (int)$absStmt->fetchColumn();
        $total = array_sum(array_column($cands, 'votes'));
        $slots = max(1, (int)$pos['max_votes']);

        $pool = array_values(array_filter($cands, fn($c) => (int)$c['votes'] > 0));   // only candidates with votes can win
        $status = [];   // candidate index in $cands => 'Winner' | 'TIE'
        if ($pool) {
            if (count($pool) <= $slots) {
                foreach ($pool as $c) $status[$c['sid']] = 'Winner';
            } else {
                $cut   = (int)$pool[$slots - 1]['votes'];
                $sure  = array_filter($pool, fn($c) => (int)$c['votes'] > $cut);
                $tied  = array_filter($pool, fn($c) => (int)$c['votes'] === $cut);
                $left  = $slots - count($sure);
                foreach ($sure as $c) $status[$c['sid']] = 'Winner';
                foreach ($tied as $c) $status[$c['sid']] = (count($tied) <= $left) ? 'Winner' : 'TIE';
                if (count($tied) > $left) $tieCount++;
            }
        }
        foreach ($status as $st) if ($st === 'Winner') $winnerCount++;
        $out[] = ['pos' => $pos, 'cands' => $cands, 'status' => $status, 'total' => $total, 'abstain' => $abstain, 'slots' => $slots];
    }

    $pct = fn($v, $t) => $t > 0 ? round($v / $t * 100, 1) . '%' : '0%';
    $fullName = fn($c) => strtoupper($c['last_name']) . ', ' . $c['first_name'];
    $period = date('M j, Y', strtotime($election['start_date'])) . ' – ' . date('M j, Y', strtotime($election['end_date']));

    // ── Sheet 1: Winners ─────────────────────────────────────────
    $sheet->setTitle('Winners');
    titleBlock($sheet, 'GUAGUA NATIONAL COLLEGE', 'Office of Student Affairs — Official List of Winners', $election['title'] . ' · Exported: ' . date('F j, Y \a\t g:i A'), 9);

    $sheet->mergeCells('A5:I5');
    $sheet->setCellValue('A5', 'Election period: ' . $period . '  ·  Voters: ' . $totalVoters . ' of ' . $totalEligible . ' eligible (' . $turnout . '% turnout)  ·  Status: ' . ($isCompleted ? 'Completed' : 'Closed'));
    $sheet->getStyle('A5')->applyFromArray(['font' => ['size' => 10, 'color' => ['rgb' => '374151']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]]);
    $row = 6;
    if (!$isCompleted) {
        $sheet->mergeCells("A{$row}:I{$row}");
        $sheet->setCellValue("A{$row}", 'PROVISIONAL — this election is Closed but not yet marked Completed. Results may still change.');
        $sheet->getStyle("A{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '92400E']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']]]);
        $row++;
    }
    $row++;   // spacer
    $hdr = $row;
    foreach (['#','Position','Winner','Student ID','Course','Party List','Votes','% of Votes','Status'] as $i => $h) $sheet->setCellValue(chr(65 + $i) . $row, $h);
    $sheet->getStyle("A{$row}:I{$row}")->applyFromArray(hStyle($MAROON));
    $sheet->getRowDimension($row)->setRowHeight(22); $row++;

    $n = 0;
    foreach ($out as $o) {
        $shown = 0;
        foreach ($o['cands'] as $c) {
            $st = $o['status'][$c['sid']] ?? null;
            if (!$st) continue;
            $shown++; $n++;
            $sheet->setCellValue("A$row", $n);
            $sheet->setCellValue("B$row", $o['pos']['title']);
            $sheet->setCellValue("C$row", $fullName($c));
            $sheet->setCellValueExplicit("D$row", (string)$c['sid'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("E$row", $c['course']);
            $sheet->setCellValue("F$row", $c['party'] ?: 'Independent');
            $sheet->setCellValue("G$row", (int)$c['votes']);
            $sheet->setCellValue("H$row", $pct((int)$c['votes'], $o['total']));
            $sheet->setCellValue("I$row", $st === 'TIE' ? 'TIE — needs tie-break' : 'Winner');
            if ($st === 'TIE') $sheet->getStyle("A{$row}:I{$row}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => '92400E']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']]]);
            else               $sheet->getStyle("A{$row}:I{$row}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => $GREEN]], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $GREEN_L]]]);
            $sheet->getStyle("A{$row}:I{$row}")->applyFromArray(bStyle());
            foreach (['A','G','H'] as $cc) $sheet->getStyle("$cc$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight(18); $row++;
        }
        if ($shown === 0) {
            $n++;
            $sheet->setCellValue("A$row", $n);
            $sheet->setCellValue("B$row", $o['pos']['title']);
            $sheet->setCellValue("C$row", empty($o['cands']) ? '— No candidates —' : '— No votes cast —');
            $sheet->setCellValue("I$row", 'No winner');
            $sheet->getStyle("A{$row}:I{$row}")->applyFromArray(['font' => ['italic' => true, 'color' => ['rgb' => '9CA3AF']]]);
            $sheet->getStyle("A{$row}:I{$row}")->applyFromArray(bStyle());
            $sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight(18); $row++;
        }
    }

    $row++;
    $noteStyle = ['font' => ['size' => 9, 'color' => ['rgb' => $GRAY_T]], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]];
    $sheet->mergeCells("A{$row}:I{$row}");
    $sheet->setCellValue("A{$row}", 'Total: ' . count($out) . ' position' . (count($out) === 1 ? '' : 's') . ' · ' . $winnerCount . ' winner' . ($winnerCount === 1 ? '' : 's') . ($tieCount ? ' · ' . $tieCount . ' tie' . ($tieCount === 1 ? '' : 's') . ' to resolve' : ''));
    $sheet->getStyle("A{$row}:I{$row}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => $MAROON]], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9EEF0']]]);
    $row++;
    if ($tieCount) {
        $sheet->mergeCells("A{$row}:I{$row}");
        $sheet->setCellValue("A{$row}", 'TIE: candidates marked TIE have equal votes for the last available slot. Resolve per the election rules before proclaiming winners.');
        $sheet->getStyle("A{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '92400E']]]);
        $row++;
    }
    $sheet->mergeCells("A{$row}:I{$row}");
    $sheet->setCellValue("A{$row}", 'Percentages are computed from the votes cast for candidates in each position (abstentions are not counted). See the "Full Results" sheet for the complete count.');
    $sheet->getStyle("A{$row}")->applyFromArray($noteStyle);
    foreach ([5,22,28,14,10,22,9,11,22] as $i => $w) $sheet->getColumnDimension(chr(65 + $i))->setWidth($w);

    // ── Sheet 2: Full Results (every candidate + abstain) ────────
    $full = $spreadsheet->createSheet();
    $full->setTitle('Full Results');
    titleBlock($full, 'GUAGUA NATIONAL COLLEGE', 'Office of Student Affairs — Full Vote Count', $election['title'] . ' · Exported: ' . date('F j, Y \a\t g:i A'), 7);
    foreach (['Position','Rank','Candidate','Party List','Votes','% of Votes','Result'] as $i => $h) $full->setCellValue(chr(65 + $i) . '5', $h);
    $full->getStyle('A5:G5')->applyFromArray(hStyle($MAROON)); $full->getRowDimension(5)->setRowHeight(22);
    $r = 6;
    foreach ($out as $o) {
        foreach ($o['cands'] as $k => $c) {
            $st = $o['status'][$c['sid']] ?? '';
            $full->setCellValue("A$r", $o['pos']['title']);
            $full->setCellValue("B$r", $k + 1);
            $full->setCellValue("C$r", $fullName($c));
            $full->setCellValue("D$r", $c['party'] ?: 'Independent');
            $full->setCellValue("E$r", (int)$c['votes']);
            $full->setCellValue("F$r", $pct((int)$c['votes'], $o['total']));
            $full->setCellValue("G$r", $st === 'TIE' ? 'TIE' : $st);
            zebraRow($full, $r, 'G', $r % 2 === 0);
            if ($st === 'Winner') $full->getStyle("A{$r}:G{$r}")->getFont()->setBold(true)->getColor()->setRGB($GREEN);
            if ($st === 'TIE')    $full->getStyle("A{$r}:G{$r}")->getFont()->setBold(true)->getColor()->setRGB('92400E');
            foreach (['B','E','F'] as $cc) $full->getStyle("$cc$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $r++;
        }
        $full->setCellValue("A$r", $o['pos']['title']);
        $full->setCellValue("C$r", 'Abstain');
        $full->setCellValue("E$r", $o['abstain']);
        $full->getStyle("A{$r}:G{$r}")->applyFromArray(['font' => ['italic' => true, 'color' => ['rgb' => $GRAY_T]], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']]]);
        $full->getStyle("A{$r}:G{$r}")->applyFromArray(bStyle());
        $full->getStyle("E$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $r++;
    }
    foreach ([22,7,28,22,9,11,10] as $i => $w) $full->getColumnDimension(chr(65 + $i))->setWidth($w);
    $spreadsheet->setActiveSheetIndex(0);

    $filename = 'winners_' . preg_replace('/[^a-z0-9]/i', '_', $election['title']) . '_' . date('Ymd') . '.xlsx';
}

// ── Output ────────────────────────────────────────────────────────
outputSpreadsheet($spreadsheet, $filename);

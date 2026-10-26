<?php
/**
 * Export helpers — lets any export choose its file type.
 *
 *   exportMenu($url, ...)                 -> dropdown button (UI)
 *   outputSpreadsheet($spreadsheet, $fn)  -> sends XLSX / CSV / PDF / DOCX / Print page
 *
 * Formats:
 *   xlsx  Excel workbook (set up to print landscape, fit to 1 page wide)
 *   pdf   Real PDF download (uses the bundled lib/dompdf, or dompdf / mPDF / TCPDF if installed via composer)
 *   docx  Word document (built-in, no extra library needed; needs PHP zip extension)
 *   csv   Plain CSV (first sheet only)
 */

const EXPORT_FORMATS = [
    'xlsx'  => ['Excel (.xlsx)',        'ti-file-spreadsheet'],
    'pdf'   => ['PDF (.pdf)',           'ti-file-type-pdf'],
    'docx'  => ['Word (.docx)',         'ti-file-type-docx'],
    'csv'   => ['CSV (.csv)',           'ti-file-type-csv'],
];

/**
 * Dropdown button. $url is the export URL WITHOUT the format parameter.
 *
 * $filters (optional) adds a "pick what to export" panel above the formats. Each filter:
 *   ['key' => 'course', 'label' => 'Course', 'options' => [value => label, ...], 'selected' => [values pre-ticked]]
 * Ticked values are sent as ?f_<key>[]=value ; nothing ticked = no filtering (export all).
 * Read them server-side with exportFilter('<key>') / exportFilterInts('<key>').
 */
function exportMenu(string $url, string $btnClass = 'btn btn-outline btn-sm', string $style = '', string $label = 'Export', ?array $formats = null, array $filters = []): string {
    static $assets = false;
    $formats = $formats ?? array_keys(EXPORT_FORMATS);
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $h = '';
    if (!$assets) {
        $assets = true;
        $h .= '<style>
.xm{position:relative;display:inline-block}.xm>summary{list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.xm>summary::-webkit-details-marker{display:none}
.xm-list{position:absolute;right:0;top:calc(100% + 4px);z-index:500;min-width:210px;max-width:calc(100vw - 16px);background:#fff;border:1px solid #E5E7EB;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14);padding:6px}
@media(max-width:600px){.xm-list{left:0;right:auto}}
.xm-list a{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:6px;color:#374151;font-size:13px;white-space:nowrap}
.xm-list a:hover{background:#F9EEF0;color:#6B1A2A}
.xm-list.xm-wide{min-width:260px}
.xm-filters{padding:4px 4px 8px;border-bottom:1px solid #F3F4F6;margin-bottom:6px}
.xm-fh{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:#6B1A2A;padding:4px 6px}
.xm-fh .xm-fcount{margin-left:auto;font-weight:600;color:#6B7280;background:#F3F4F6;border-radius:10px;padding:1px 8px}
.xm-fh .xm-fclear{border:0;background:none;color:#6B7280;font-size:12px;cursor:pointer;text-decoration:underline;padding:0}
.xm-fl{font-size:11px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:.04em;padding:6px 6px 2px}
.xm-fopts{max-height:150px;overflow-y:auto;padding:0 2px}
.xm-fopts label{display:flex;align-items:center;gap:8px;padding:5px 6px;border-radius:6px;font-size:13px;color:#374151;cursor:pointer;margin:0;white-space:normal}
.xm-fopts label:hover{background:#F9EEF0}
.xm-fopts input{width:16px;height:16px;flex-shrink:0;accent-color:#6B1A2A;margin:0}
.xm-fnote{font-size:11px;color:#9CA3AF;padding:4px 6px 0}
</style><script>document.addEventListener("toggle",function(e){var d=e.target;if(!d.classList||!d.classList.contains("xm")||!d.open)return;var l=d.querySelector(".xm-list");if(!l)return;l.style.left="";l.style.right="0";l.style.marginLeft="";var r=l.getBoundingClientRect();if(r.left<8){l.style.right="auto";l.style.left="0";r=l.getBoundingClientRect();}if(r.right>window.innerWidth-8){l.style.marginLeft=(window.innerWidth-8-r.right)+"px";}},true);document.addEventListener("click",function(e){document.querySelectorAll("details.xm[open]").forEach(function(d){if(!d.contains(e.target))d.removeAttribute("open")})});</script>';
    }
    static $selAssets = false;
    if (!$selAssets) {
        // If rows are ticked (BulkSelect) in the table that feeds this export, the
        // export link gets &ids=... so only those rows are exported. Nothing ticked = all.
        $selAssets = true;
        $h .= <<<'SELJS'
<script>
(function(){
  document.addEventListener("click", function(e){
    var a = e.target.closest ? e.target.closest(".xm-list a") : null;
    if (!a) return;
    if (!a.hasAttribute("data-href0")) a.setAttribute("data-href0", a.getAttribute("href"));
    var u = new URL(a.getAttribute("data-href0"), location.href);
    // ticked table rows (BulkSelect) -> &ids=...
    if (window.BulkSelect && BulkSelect.selectedFor) {
      var ids = BulkSelect.selectedFor(u.searchParams.get("type"));
      if (ids.length) u.searchParams.set("ids", ids.join(","));
    }
    // ticked filter options -> &f_<key>[]=...
    var box = a.closest(".xm-list");
    var fl = box ? box.querySelectorAll(".xm-filters input[data-fkey]:checked") : [];
    for (var i = 0; i < fl.length; i++) u.searchParams.append("f_" + fl[i].getAttribute("data-fkey") + "[]", fl[i].value);
    a.setAttribute("href", u.pathname + u.search);
  }, true);

  function refresh(box){
    var n = box.querySelectorAll("input[data-fkey]:checked").length;
    var b = box.querySelector(".xm-fcount");
    if (b) b.textContent = n ? n + " selected" : "All";
  }
  document.addEventListener("change", function(e){
    var box = e.target.closest ? e.target.closest(".xm-filters") : null;
    if (box) refresh(box);
  });
  document.addEventListener("click", function(e){
    var c = e.target.closest ? e.target.closest(".xm-fclear") : null;
    if (!c) return;
    var box = c.closest(".xm-filters");
    box.querySelectorAll("input[data-fkey]").forEach(function(i){ i.checked = false; });
    refresh(box);
  });
  document.addEventListener("DOMContentLoaded", function(){
    document.querySelectorAll(".xm-filters").forEach(refresh);
  });
})();
</script>
SELJS;
    }
    $h .= '<details class="xm"><summary class="' . htmlspecialchars($btnClass) . '" style="' . htmlspecialchars($style) . '">'
        . '<i class="ti ti-table-export"></i> ' . htmlspecialchars($label) . ' <i class="ti ti-chevron-down" style="font-size:12px"></i></summary>'
        . '<div class="xm-list' . ($filters ? ' xm-wide' : '') . '">';
    $filters = array_values(array_filter($filters, fn($f) => !empty($f['options']) && !empty($f['key'])));
    if ($filters) {
        $h .= '<div class="xm-filters"><div class="xm-fh"><i class="ti ti-filter"></i> Choose what to export'
            . '<span class="xm-fcount">All</span><button type="button" class="xm-fclear">Clear</button></div>';
        foreach ($filters as $f) {
            $pre = array_map('strval', $f['selected'] ?? []);
            $h .= '<div class="xm-fl">' . htmlspecialchars($f['label'] ?? $f['key']) . '</div><div class="xm-fopts">';
            foreach ($f['options'] as $val => $lbl) {
                $h .= '<label><input type="checkbox" data-fkey="' . htmlspecialchars($f['key']) . '" value="' . htmlspecialchars((string)$val) . '"'
                    . (in_array((string)$val, $pre, true) ? ' checked' : '') . '> ' . htmlspecialchars((string)$lbl) . '</label>';
            }
            $h .= '</div>';
        }
        $h .= '<div class="xm-fnote">Nothing ticked = export everything.</div></div>';
    }
    foreach ($formats as $f) {
        if (!isset(EXPORT_FORMATS[$f])) continue;
        [$name, $icon] = EXPORT_FORMATS[$f];
        $gateOp = strpos($url, 'export-reports.php') === 0 ? 'export_reports' : (strpos($url, 'export-vouchers') === 0 ? 'export_vouchers' : 'export_data');
        $h .= '<a href="' . htmlspecialchars($url . $sep . 'format=' . $f) . '" data-admin-gate="' . $gateOp . '"' . ($f === 'print' ? ' target="_blank"' : '') . '><i class="ti ' . $icon . '"></i> ' . htmlspecialchars($name) . '</a>';
    }
    return $h . '</div></details>';
}

/** Send the spreadsheet in the format requested by ?format= . Ends the script. */
function outputSpreadsheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $ss, string $filename, ?string $format = null): void {
    $format = strtolower($format ?? ($_GET['format'] ?? 'xlsx'));
    if (!isset(EXPORT_FORMATS[$format])) $format = 'xlsx';
    $base = pathinfo($filename, PATHINFO_FILENAME);

    // Make every sheet print nicely: landscape, one page wide, centered
    foreach ($ss->getAllSheets() as $sh) {
        $ps = $sh->getPageSetup();
        $ps->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $ps->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);   // change to PAPERSIZE_LETTER for short bond
        $ps->setFitToWidth(1);
        $ps->setFitToHeight(0);
        $sh->getSheetView()->setView(\PhpOffice\PhpSpreadsheet\Worksheet\SheetView::SHEETVIEW_NORMAL);
        $sh->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
        $sh->getPageSetup()->setHorizontalCentered(true);
        $sh->getPageSetup()->setFitToPage(true);
        $sh->getHeaderFooter()->setOddFooter('&L&8GNC-OSA Portal&C&8Page &P of &N&R&8Printed &D');
    }
    $ss->setActiveSheetIndex(0);

    if ($format === 'pdf') {
        $bundled = __DIR__ . '/../lib/dompdf/autoload.inc.php';
        if (!class_exists('\Dompdf\Dompdf') && file_exists($bundled)) require_once $bundled;
        if (class_exists('\Dompdf\Dompdf')) {
            // Clean, purpose-built PDF layout (much better than converting the raw spreadsheet)
            $opt = new \Dompdf\Options();
            $opt->set('defaultFont', 'DejaVu Sans');
            $opt->set('isRemoteEnabled', false);
            $d = new \Dompdf\Dompdf($opt);
            $d->loadHtml(sheetsToPdfHtml($ss), 'UTF-8');
            $d->setPaper('A4', 'landscape');
            $d->render();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $base . '.pdf"');
            header('Cache-Control: max-age=0');
            echo $d->output();
            exit;
        }
        $w = null;
        if (class_exists('\Mpdf\Mpdf'))          $w = new \PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf($ss);
        elseif (class_exists('\TCPDF'))          $w = new \PhpOffice\PhpSpreadsheet\Writer\Pdf\Tcpdf($ss);
        if ($w) {
            $w->writeAllSheets();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $base . '.pdf"');
            header('Cache-Control: max-age=0');
            $w->save('php://output');
            exit;
        }
        http_response_code(500);
        die('PDF export needs a PDF library. Make sure the lib/dompdf folder is uploaded.');
    }

    if ($format === 'docx') {
        sendDocx($ss, $base);
        exit;
    }

    if ($format === 'csv') {
        $w = new \PhpOffice\PhpSpreadsheet\Writer\Csv($ss);
        $w->setUseBOM(true);
        $w->setSheetIndex(0);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $base . '.csv"');
        header('Cache-Control: max-age=0');
        $w->save('php://output');
        exit;
    }

    // xlsx (default)
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $base . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
    exit;
}

/** Minimal DOCX builder: title lines become paragraphs, tabular rows become Word tables. */
function sendDocx(\PhpOffice\PhpSpreadsheet\Spreadsheet $ss, string $base): void {
    if (!class_exists('ZipArchive')) { http_response_code(500); die('DOCX export needs the PHP zip extension (enable extension=zip in php.ini).'); }

    $x = function ($s) {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string)$s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };
    $para = function ($text, $bold, $size, $color, $center) use ($x) {
        return '<w:p><w:pPr><w:spacing w:after="60"/>' . ($center ? '<w:jc w:val="center"/>' : '') . '</w:pPr>'
            . '<w:r><w:rPr>' . ($bold ? '<w:b/>' : '') . '<w:color w:val="' . $color . '"/><w:sz w:val="' . $size . '"/></w:rPr>'
            . '<w:t xml:space="preserve">' . $x($text) . '</w:t></w:r></w:p>';
    };
    $table = function (array $rows) use ($x) {
        $cols = 0; foreach ($rows as $r) $cols = max($cols, count($r));
        $b = '<w:tblBorders>';
        foreach (['top','left','bottom','right','insideH','insideV'] as $s) $b .= '<w:' . $s . ' w:val="single" w:sz="4" w:color="D1D5DB"/>';
        $b .= '</w:tblBorders>';
        $o = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>' . $b . '<w:tblCellMar><w:left w:w="70" w:type="dxa"/><w:right w:w="70" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        for ($i = 0; $i < $cols; $i++) $o .= '<w:gridCol w:w="' . intdiv(14000, max(1, $cols)) . '"/>';
        $o .= '</w:tblGrid>';
        foreach ($rows as $ri => $r) {
            $o .= '<w:tr>' . ($ri === 0 ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
            for ($c = 0; $c < $cols; $c++) {
                $v = $r[$c] ?? '';
                $shd = $ri === 0 ? '<w:shd w:val="clear" w:color="auto" w:fill="6B1A2A"/>' : ($ri % 2 === 0 ? '<w:shd w:val="clear" w:color="auto" w:fill="FDF2F4"/>' : '');
                $o .= '<w:tc><w:tcPr>' . $shd . '</w:tcPr><w:p><w:pPr><w:spacing w:after="0"/></w:pPr>'
                    . ($v === '' ? '' : '<w:r><w:rPr>' . ($ri === 0 ? '<w:b/><w:color w:val="FFFFFF"/>' : '') . '<w:sz w:val="18"/></w:rPr><w:t xml:space="preserve">' . $x($v) . '</w:t></w:r>')
                    . '</w:p></w:tc>';
            }
            $o .= '</w:tr>';
        }
        return $o . '</w:tbl><w:p/>';
    };

    $body = '';
    $sheets = $ss->getAllSheets();
    foreach ($sheets as $si => $sh) {
        if ($si > 0) $body .= $para($sh->getTitle(), true, 26, '6B1A2A', false);
        $data = $sh->toArray(null, true, true, false);
        $tbl = []; $titleCount = 0;
        foreach ($data as $row) {
            $vals = array_map(fn($v) => is_scalar($v) || $v === null ? trim((string)$v) : '', $row);
            $filled = array_keys(array_filter($vals, fn($v) => $v !== ''));
            if (!$filled) continue;
            if (count($filled) === 1 && $filled[0] === 0 && count($vals) > 1) {           // single-cell line = title/note
                if ($tbl) { $body .= $table($tbl); $tbl = []; }
                $body .= ($si === 0 && $titleCount === 0) ? $para($vals[0], true, 32, '6B1A2A', true)
                       : (($si === 0 && $titleCount < 3)   ? $para($vals[0], $titleCount === 1, 22, '6B7280', true)
                       :                                      $para($vals[0], false, 20, '374151', false));
                $titleCount++;
                continue;
            }
            $last = max($filled);
            $tbl[] = array_slice($vals, 0, $last + 1);
        }
        if ($tbl) $body .= $table($tbl);
    }

    $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="360" w:footer="360" w:gutter="0"/></w:sectPr></w:body></w:document>';

    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', $doc);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $base . '.docx"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: max-age=0');
    readfile($tmp);
    unlink($tmp);
}


/** Turns the worksheet(s) into a clean, printable HTML page for the PDF writer. */
function sheetsToPdfHtml(\PhpOffice\PhpSpreadsheet\Spreadsheet $ss): string {
    $x = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $logo = '';
    $logoFile = __DIR__ . '/../assets/img/osa-logo.jpg';
    if (extension_loaded('gd') && is_file($logoFile)) {
        $logo = '<img class="logo" src="data:image/jpeg;base64,' . base64_encode(file_get_contents($logoFile)) . '">';
    }
    $h = '<html><head><meta charset="UTF-8"><style>
@page{margin:14mm 12mm}
body{font-family:"DejaVu Sans",sans-serif;font-size:9px;color:#1F2937}
.head{text-align:center;margin-bottom:10px;border-bottom:2px solid #C9943A;padding-bottom:8px}
.logo{height:46px;margin-bottom:4px}
.t1{font-size:17px;font-weight:bold;color:#6B1A2A}
.t2{font-size:11px;font-weight:bold;color:#6B7280;margin-top:2px}
.t3{font-size:8.5px;color:#9CA3AF;margin-top:2px}
.sec{font-size:12px;font-weight:bold;color:#6B1A2A;margin:14px 0 4px}
.note{font-size:9px;color:#374151;margin:6px 0}
table{width:100%;border-collapse:collapse;margin:4px 0 8px}
th{background:#6B1A2A;color:#fff;font-size:9px;padding:5px 6px;text-align:left;border:1px solid #6B1A2A}
td{padding:4px 6px;border:1px solid #E5E7EB;vertical-align:top}
tr.z td{background:#FDF2F4}
tr.tot td{background:#F9EEF0;font-weight:bold;color:#6B1A2A}
.c{text-align:center}
</style></head><body>';
    $sheets = $ss->getAllSheets();
    foreach ($sheets as $si => $sh) {
        $tbl = []; $titles = []; $out = ''; $started = false;
        $flush = function () use (&$tbl, &$out, $x) {
            if (!$tbl) return;
            $cols = 0; foreach ($tbl as $r) $cols = max($cols, count($r));
            $out .= '<table>';
            foreach ($tbl as $ri => $r) {
                $isTot = $ri > 0 && stripos((string)($r[0] ?? ''), 'total') === 0;
                $out .= '<tr' . ($ri > 0 ? ($isTot ? ' class="tot"' : ($ri % 2 === 0 ? ' class="z"' : '')) : '') . '>';
                for ($c = 0; $c < $cols; $c++) {
                    $v = $r[$c] ?? '';
                    $cls = ($c === 0 && (($tbl[0][0] ?? '') === '#')) ? ' class="c"' : '';
                    $out .= ($ri === 0 ? '<th' : '<td') . $cls . '>' . $x($v) . ($ri === 0 ? '</th>' : '</td>');
                }
                $out .= '</tr>';
            }
            $out .= '</table>'; $tbl = [];
        };
        foreach ($sh->toArray(null, true, true, false) as $row) {
            $vals = array_map(fn($v) => is_scalar($v) ? trim((string)$v) : '', $row);
            $filled = array_keys(array_filter($vals, fn($v) => $v !== ''));
            if (!$filled) continue;
            if (count($filled) === 1 && $filled[0] === 0 && count($vals) > 1) {
                $flush();
                if (!$started && count($titles) < 3) { $titles[] = $vals[0]; }
                else { $started = true; $out .= '<div class="note">' . $x($vals[0]) . '</div>'; }
                continue;
            }
            $started = true;
            $tbl[] = array_slice($vals, 0, max($filled) + 1);
        }
        $flush();
        if ($titles) {
            $h .= '<div class="head">' . ($si === 0 ? $logo : '')
                . '<div class="t1">' . $x($titles[0]) . '</div>'
                . (isset($titles[1]) ? '<div class="t2">' . $x($titles[1]) . '</div>' : '')
                . (isset($titles[2]) ? '<div class="t3">' . $x($titles[2]) . '</div>' : '') . '</div>';
        } else {
            $h .= '<div class="sec">' . $x($sh->getTitle()) . '</div>';
        }
        $h .= $out;
        if ($si < count($sheets) - 1) $h .= '<div style="page-break-after:always"></div>';
    }
    return $h . '</body></html>';
}


/* ── Export filters (server side) ─────────────────────────────────── */

/** Values ticked for filter $key (from ?f_<key>[]=...). Empty array = no filter. */
function exportFilter(string $key): array {
    $v = $_GET['f_' . $key] ?? [];
    if (!is_array($v)) $v = [$v];
    $out = [];
    foreach ($v as $x) {
        if (!is_scalar($x)) continue;
        $x = trim((string)$x);
        if ($x !== '') $out[] = $x;
    }
    return array_values(array_unique($out));
}

/** Same, but for numeric ids (keeps 0, e.g. "Independent"). */
function exportFilterInts(string $key): array {
    $out = [];
    foreach (exportFilter($key) as $x) if (ctype_digit($x)) $out[] = (int)$x;
    return array_values(array_unique($out));
}

/** Keep rows whose course is one of $courses ('__none__' matches rows with no course). Case-insensitive. */
function keepCourses(array $rows, array $courses, string $key = 'course'): array {
    if (!$courses) return $rows;
    $want = array_map(fn($c) => mb_strtolower(trim((string)$c)), $courses);
    return array_values(array_filter($rows, function ($r) use ($key, $want) {
        $c = mb_strtolower(trim((string)($r[$key] ?? '')));
        return in_array($c === '' ? '__none__' : $c, $want, true);
    }));
}

/** Ready-made "Course" filter for exportMenu(): every course found in the students table. */
function exportCourseFilter(PDO $pdo, bool $activeOnly = false, array $selected = []): array {
    $where = $activeOnly ? 'WHERE is_active=1' : '';
    $courses = $pdo->query("SELECT DISTINCT TRIM(course) FROM students $where ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    $opts = []; $hasNone = false;
    foreach ($courses as $c) { if ($c === null || $c === '') $hasNone = true; else $opts[$c] = $c; }
    if ($hasNone) $opts['__none__'] = '(No course)';
    return ['key' => 'course', 'label' => 'Course', 'options' => $opts, 'selected' => $selected];
}

/** " · Filtered: BSIT, BSCS" suffix for the sheet's title line (empty when nothing is filtered). */
function exportFilterNote(array $parts): string {
    $parts = array_values(array_filter(array_map('strval', $parts), fn($p) => $p !== ''));
    return $parts ? ' · Filtered: ' . implode(' | ', $parts) : '';
}

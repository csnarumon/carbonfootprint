<?php
/* ==============================================
   report/summary_export.php — Export รายงานสรุปรายเดือนเป็น Excel
   GHG Management System
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

requireRole(array(1, 2, 3, 4, 5, 6));
$conn = getConnection();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$filterMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$filterYear  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$filterSite  = isset($_GET['site'])  ? (int)$_GET['site']  : 0;
if ($filterMonth < 1 || $filterMonth > 12) { $filterMonth = (int)date('n'); }

$ymCur  = sprintf('%04d%02d', $filterYear, $filterMonth);
$ymPrev = sprintf('%04d%02d', $filterYear - 1, $filterMonth);

$siteName = 'ทุก Site';
if ($filterSite > 0) {
    $resS = sqlsrv_query($conn, "SELECT SiteName FROM CFP_Site WHERE SiteID=?", array($filterSite));
    if ($resS && ($rS = sqlsrv_fetch_array($resS, SQLSRV_FETCH_ASSOC))) { $siteName = $rS['SiteName']; }
}

function fetchCO2Sum($conn, $ym, $siteID) {
    $sql = "SELECT h.Scope, a.CategoryNo, SUM(a.CO2e) AS CO2eSum
            FROM CFP_MonthlyHeader h
            JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
            WHERE h.Status = 2 AND h.YearMonth = ?";
    $params = array($ym);
    if ($siteID > 0) { $sql .= " AND h.SiteID = ?"; $params[] = $siteID; }
    $sql .= " GROUP BY h.Scope, a.CategoryNo";

    $out = array('Scope1' => 0.0, 'Scope2_Loc' => 0.0, 'Scope2_Mkt' => 0.0, 'Scope3' => 0.0);
    $res = sqlsrv_query($conn, $sql, $params);
    if ($res) {
        while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $v = (float)$r['CO2eSum'];
            if ($r['Scope'] === 'Scope1') {
                $out['Scope1'] += $v;
            } elseif ($r['Scope'] === 'Scope2') {
                if ((int)$r['CategoryNo'] === 2) { $out['Scope2_Mkt'] += $v; }
                else                              { $out['Scope2_Loc'] += $v; }
            } elseif ($r['Scope'] === 'Scope3') {
                $out['Scope3'] += $v;
            }
        }
    }
    return $out;
}

$cur  = fetchCO2Sum($conn, $ymCur, $filterSite);
$prev = fetchCO2Sum($conn, $ymPrev, $filterSite);
foreach ($cur as $k => $v)  { $cur[$k]  = $v / 1000; }
foreach ($prev as $k => $v) { $prev[$k] = $v / 1000; }
$cur['Scope2']  = $cur['Scope2_Loc'] + $cur['Scope2_Mkt'];
$prev['Scope2'] = $prev['Scope2_Loc'] + $prev['Scope2_Mkt'];
$cur['Total']   = $cur['Scope1'] + $cur['Scope2'] + $cur['Scope3'];
$prev['Total']  = $prev['Scope1'] + $prev['Scope2'] + $prev['Scope3'];

function yoyPct($curV, $prevV) {
    if ($prevV <= 0) { return null; }
    return (($curV - $prevV) / $prevV) * 100;
}

$monthNames = array(1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',
                     7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม');
$thYearCur  = $filterYear + 543;
$thYearPrev = $filterYear - 1 + 543;

/* ===== สร้าง Excel ===== */
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('รายงานสรุป');

$sheet->setCellValue('A1', 'รายงานสรุปการปล่อยก๊าซเรือนกระจก');
$sheet->mergeCells('A1:D1');
$sheet->setCellValue('A2', 'เดือน ' . $monthNames[$filterMonth] . ' ' . $thYearCur . ' เทียบ ' . $monthNames[$filterMonth] . ' ' . $thYearPrev . ' — Site: ' . $siteName);
$sheet->mergeCells('A2:D2');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A2')->getFont()->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF6B8189'));

$headerRow = 4;
$headers = array('รายการ', $monthNames[$filterMonth] . ' ' . $thYearCur . ' (tCO2e)', $monthNames[$filterMonth] . ' ' . $thYearPrev . ' (tCO2e)', 'เปลี่ยนแปลง (%)');
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . $headerRow, $h);
    $col++;
}
$sheet->getStyle('A' . $headerRow . ':D' . $headerRow)->applyFromArray(array(
    'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
    'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => '2AABB8')),
    'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER),
));

$rows = array(
    array('Scope 1 — Direct Emissions', $cur['Scope1'], $prev['Scope1'], yoyPct($cur['Scope1'], $prev['Scope1']), false),
    array('Scope 2 — Energy Indirect', $cur['Scope2'], $prev['Scope2'], yoyPct($cur['Scope2'], $prev['Scope2']), false),
    array('  Location-based (Grid Mix)', $cur['Scope2_Loc'], $prev['Scope2_Loc'], yoyPct($cur['Scope2_Loc'], $prev['Scope2_Loc']), false),
    array('  Market-based (Solar PV / REC)', $cur['Scope2_Mkt'], $prev['Scope2_Mkt'], yoyPct($cur['Scope2_Mkt'], $prev['Scope2_Mkt']), false),
    array('Scope 3 — Other Indirect', $cur['Scope3'], $prev['Scope3'], yoyPct($cur['Scope3'], $prev['Scope3']), false),
    array('รวมทั้งหมด', $cur['Total'], $prev['Total'], yoyPct($cur['Total'], $prev['Total']), true),
);

$r = $headerRow + 1;
foreach ($rows as $row) {
    $sheet->setCellValue('A' . $r, $row[0]);
    $sheet->setCellValue('B' . $r, round($row[1], 2));
    $sheet->setCellValue('C' . $r, round($row[2], 2));
    $sheet->setCellValue('D' . $r, $row[3] === null ? '-' : round($row[3], 1) . '%');
    if ($row[4]) {
        $sheet->getStyle('A' . $r . ':D' . $r)->applyFromArray(array(
            'font' => array('bold' => true),
            'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => 'E4F7F9')),
        ));
    }
    $r++;
}

$sheet->getColumnDimension('A')->setWidth(34);
$sheet->getColumnDimension('B')->setWidth(22);
$sheet->getColumnDimension('C')->setWidth(22);
$sheet->getColumnDimension('D')->setWidth(16);
$sheet->getStyle('B' . ($headerRow + 1) . ':C' . ($r - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('D' . ($headerRow + 1) . ':D' . ($r - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$fileName = 'Report_Summary_' . $ymCur . ($filterSite > 0 ? '_Site' . $filterSite : '') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

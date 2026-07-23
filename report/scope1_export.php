<?php
/* ==============================================
   report/scope1_export.php — Export รายงาน Scope 1 เป็น Excel
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

$catLabels = array(
    1 => 'Stationary Combustion', 2 => 'Mobile Combustion — On-road', 3 => 'Mobile Combustion — Off-road',
    4 => 'Fugitive Emissions', 5 => 'Process Emissions',
);

function fetchScope1Cat($conn, $ym, $siteID) {
    $sql = "SELECT a.CategoryNo, ai.MobileRoadType, SUM(a.CO2e) AS CO2eSum
            FROM CFP_MonthlyHeader h
            JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
            LEFT JOIN CFP_ActivityItem ai ON ai.ItemID = a.ItemID
            WHERE h.Status = 2 AND h.Scope = 'Scope1' AND h.YearMonth = ?";
    $params = array($ym);
    if ($siteID > 0) { $sql .= " AND h.SiteID = ?"; $params[] = $siteID; }
    $sql .= " GROUP BY a.CategoryNo, ai.MobileRoadType";

    $out = array(1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0);
    $res = sqlsrv_query($conn, $sql, $params);
    if ($res) {
        while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $v = (float)$r['CO2eSum'];
            $cat = (int)$r['CategoryNo'];
            if ($cat === 1) { $out[1] += $v; }
            elseif ($cat === 2) { if ($r['MobileRoadType'] === 'OffRoad') { $out[3] += $v; } else { $out[2] += $v; } }
            elseif ($cat === 3) { $out[4] += $v; }
            elseif ($cat === 4) { $out[5] += $v; }
        }
    }
    return $out;
}

$cur  = fetchScope1Cat($conn, $ymCur, $filterSite);
$prev = fetchScope1Cat($conn, $ymPrev, $filterSite);
foreach ($cur as $k => $v)  { $cur[$k]  = $v / 1000; }
foreach ($prev as $k => $v) { $prev[$k] = $v / 1000; }

function yoyPct($curV, $prevV) { if ($prevV <= 0) { return null; } return (($curV - $prevV) / $prevV) * 100; }

$monthNames = array(1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',
                     7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม');
$thYearCur  = $filterYear + 543;
$thYearPrev = $filterYear - 1 + 543;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Scope 1');

$sheet->setCellValue('A1', 'รายงาน Scope 1 — Direct Emissions');
$sheet->mergeCells('A1:D1');
$sheet->setCellValue('A2', 'เดือน ' . $monthNames[$filterMonth] . ' ' . $thYearCur . ' เทียบ ' . $monthNames[$filterMonth] . ' ' . $thYearPrev . ' — Site: ' . $siteName);
$sheet->mergeCells('A2:D2');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A2')->getFont()->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF6B8189'));

$headerRow = 4;
$headers = array('Category', $monthNames[$filterMonth] . ' ' . $thYearCur . ' (tCO2e)', $monthNames[$filterMonth] . ' ' . $thYearPrev . ' (tCO2e)', 'เปลี่ยนแปลง (%)');
$col = 'A';
foreach ($headers as $h) { $sheet->setCellValue($col . $headerRow, $h); $col++; }
$sheet->getStyle('A' . $headerRow . ':D' . $headerRow)->applyFromArray(array(
    'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
    'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => '2AABB8')),
    'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER),
));

$r = $headerRow + 1;
foreach ($catLabels as $catNo => $label) {
    $t = yoyPct($cur[$catNo], $prev[$catNo]);
    $sheet->setCellValue('A' . $r, $label);
    $sheet->setCellValue('B' . $r, round($cur[$catNo], 2));
    $sheet->setCellValue('C' . $r, round($prev[$catNo], 2));
    $sheet->setCellValue('D' . $r, $t === null ? '-' : round($t, 1) . '%');
    $r++;
}
$curTotal = array_sum($cur); $prevTotal = array_sum($prev);
$sheet->setCellValue('A' . $r, 'รวม Scope 1');
$sheet->setCellValue('B' . $r, round($curTotal, 2));
$sheet->setCellValue('C' . $r, round($prevTotal, 2));
$t = yoyPct($curTotal, $prevTotal);
$sheet->setCellValue('D' . $r, $t === null ? '-' : round($t, 1) . '%');
$sheet->getStyle('A' . $r . ':D' . $r)->applyFromArray(array(
    'font' => array('bold' => true),
    'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => 'E4F7F9')),
));

$sheet->getColumnDimension('A')->setWidth(34);
$sheet->getColumnDimension('B')->setWidth(22);
$sheet->getColumnDimension('C')->setWidth(22);
$sheet->getColumnDimension('D')->setWidth(16);
$sheet->getStyle('B' . ($headerRow + 1) . ':C' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('D' . ($headerRow + 1) . ':D' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$fileName = 'Report_Scope1_' . $ymCur . ($filterSite > 0 ? '_Site' . $filterSite : '') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

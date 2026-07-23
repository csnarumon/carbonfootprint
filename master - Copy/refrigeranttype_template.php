<?php
/* ==============================================
   master/refrigeranttype_template.php
   สร้างไฟล์ Template Excel สำหรับ Import ประเภทสารทำความเย็น
   ============================================== */
require_once '../includes/auth_check.php';
require_once __DIR__ . '/../vendor/autoload.php';

requireRole(array(4, 5));

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('ประเภทสารทำความเย็น');

/* ===== Header ===== */
$headers = array('ชื่อประเภท*', 'GWP100', 'คำอธิบาย', 'ลำดับแสดง');
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . '1', $h);
    $col++;
}

$headerStyle = array(
    'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
    'fill' => array(
        'fillType'   => Fill::FILL_SOLID,
        'startColor' => array('rgb' => '2AABB8'),
    ),
);
$sheet->getStyle('A1:D1')->applyFromArray($headerStyle);

/* ===== แถวตัวอย่าง ===== */
$sampleRows = array(
    array('HFC-32', 675, 'สารทำความเย็นที่ใช้ในเครื่องปรับอากาศทั่วไป มีค่าศักยภาพการเกิดภาวะโลกร้อน (GWP) ต่ำกว่า R410A', 1),
);

$rowNum = 2;
foreach ($sampleRows as $row) {
    $sheet->setCellValue('A' . $rowNum, $row[0]);
    $sheet->setCellValue('B' . $rowNum, $row[1]);
    $sheet->setCellValue('C' . $rowNum, $row[2]);
    $sheet->setCellValue('D' . $rowNum, $row[3]);
    $rowNum++;
}

$sheet->getColumnDimension('A')->setWidth(18);
$sheet->getColumnDimension('B')->setWidth(12);
$sheet->getColumnDimension('C')->setWidth(45);
$sheet->getColumnDimension('D')->setWidth(12);

$fileName = 'Template_RefrigerantType_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

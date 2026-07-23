<?php
/* ==============================================
   master/fueltype_template.php
   สร้างไฟล์ Template Excel สำหรับ Import ประเภทเชื้อเพลิง
   ============================================== */
require_once '../includes/auth_check.php';
require_once __DIR__ . '/../vendor/autoload.php';

requireRole(array(4, 5));

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('ประเภทเชื้อเพลิง');

/* ===== Header ===== */
$headers = array('ชื่อประเภท*', 'กลุ่มเชื้อเพลิง', 'หน่วยปกติ', 'คำอธิบาย', 'ลำดับแสดง');
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
$sheet->getStyle('A1:E1')->applyFromArray($headerStyle);

/* ===== แถวตัวอย่าง ===== */
$sampleRows = array(

    array('ดีเซล B7', 'Fossil Fuel', 'ลิตร', 'เชื้อเพลิงสำหรับเครื่องจักรและยานพาหนะดีเซล ผสมไบโอดีเซล 7%', 1),
);

$rowNum = 2;
foreach ($sampleRows as $row) {
    $sheet->setCellValue('A' . $rowNum, $row[0]);
    $sheet->setCellValue('B' . $rowNum, $row[1]);
    $sheet->setCellValue('C' . $rowNum, $row[2]);
    $sheet->setCellValue('D' . $rowNum, $row[3]);
    $sheet->setCellValue('E' . $rowNum, $row[4]);
    $rowNum++;
}

$sheet->getColumnDimension('A')->setWidth(26);
$sheet->getColumnDimension('B')->setWidth(18);
$sheet->getColumnDimension('C')->setWidth(12);
$sheet->getColumnDimension('D')->setWidth(45);
$sheet->getColumnDimension('E')->setWidth(12);

$fileName = 'Template_FuelType_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

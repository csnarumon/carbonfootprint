<?php
/* ==============================================
   master/electrictype_template.php
   สร้างไฟล์ Template Excel สำหรับ Import แหล่งไฟฟ้า
   ============================================== */
require_once '../includes/auth_check.php';
require_once __DIR__ . '/../vendor/autoload.php';

requireRole(array(4, 5));

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('แหล่งไฟฟ้า');

/* ===== Header ===== */
$headers = array('ชื่อแหล่งไฟฟ้า*', 'Grid Factor', 'คำอธิบาย', 'ลำดับแสดง');
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
    array('ไฟฟ้าจากระบบสายส่ง (Grid)', 0.5986, 'ไฟฟ้าที่ซื้อจากการไฟฟ้า ใช้ค่า Emission Factor ตามประกาศ TGO', 1),
);

$rowNum = 2;
foreach ($sampleRows as $row) {
    $sheet->setCellValue('A' . $rowNum, $row[0]);
    $sheet->setCellValue('B' . $rowNum, $row[1]);
    $sheet->setCellValue('C' . $rowNum, $row[2]);
    $sheet->setCellValue('D' . $rowNum, $row[3]);
    $rowNum++;
}

$sheet->getColumnDimension('A')->setWidth(30);
$sheet->getColumnDimension('B')->setWidth(14);
$sheet->getColumnDimension('C')->setWidth(45);
$sheet->getColumnDimension('D')->setWidth(12);
$fileName = 'Template_ElectricSource_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

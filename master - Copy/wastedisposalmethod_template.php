<?php
/* ==============================================
   master/wastedisposalmethod_template.php
   สร้างไฟล์ Template Excel สำหรับ Import วิธีกำจัดขยะ
   ============================================== */
require_once '../includes/auth_check.php';
require_once __DIR__ . '/../vendor/autoload.php';

requireRole(array(4, 5));

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('วิธีกำจัดขยะ');

/* ===== Header ===== */
$headers = array('ชื่อประเภท*', 'คำอธิบาย', 'ลำดับแสดง');
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
$sheet->getStyle('A1:C1')->applyFromArray($headerStyle);  // 3 คอลัมน์ (รหัสระบบสร้างให้อัตโนมัติ)

/* ===== แถวตัวอย่าง ===== */
$sampleRows = array(
    array('ฝังกลบ', 'การกำจัดขยะโดยการฝังกลบในหลุมฝังกลบ', 1),
);

$rowNum = 2;
foreach ($sampleRows as $row) {
    $sheet->setCellValue('A' . $rowNum, $row[0]);
    $sheet->setCellValue('B' . $rowNum, $row[1]);
    $sheet->setCellValue('C' . $rowNum, $row[2]);
    $rowNum++;
}

$sheet->getColumnDimension('A')->setWidth(30);
$sheet->getColumnDimension('B')->setWidth(40);
$sheet->getColumnDimension('C')->setWidth(12);

/* ===== ส่งไฟล์ออก ===== */
$fileName = 'Template_WasteMethod_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit; 

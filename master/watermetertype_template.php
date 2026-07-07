<?php
/* ==============================================
   master/watermetertype_template.php
   สร้างไฟล์ Template Excel สำหรับ Import ประเภทมิเตอร์น้ำ
   ============================================== */
require_once '../includes/auth_check.php';
require_once __DIR__ . '/../vendor/autoload.php';

requireRole(array(4, 5));

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('ประเภทมิเตอร์น้ำ');

/* ===== Header ===== */
$headers = array('ชื่อประเภท*', 'คำอธิบาย', 'ลำดับแสดง');
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . '1', $h);
    $col++;
}

/* จัด style หัวตาราง */
$headerStyle = array(
    'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
    'fill' => array(
        'fillType'   => Fill::FILL_SOLID,
        'startColor' => array('rgb' => '2AABB8'),
    ),
);
$sheet->getStyle('A1:C1')->applyFromArray($headerStyle);

/* ===== แถวตัวอย่าง ===== */
$sheet->setCellValue('A2', 'มิเตอร์หลัก');
$sheet->setCellValue('B2', 'มิเตอร์น้ำหลักสำหรับวัดปริมาณการใช้น้ำรวมของทั้งอาคาร/โรงงาน');
$sheet->setCellValue('C2', 1);

/* ปรับความกว้างคอลัมน์ */
$sheet->getColumnDimension('A')->setWidth(30);
$sheet->getColumnDimension('B')->setWidth(40);
$sheet->getColumnDimension('C')->setWidth(12);

/* ===== ส่งไฟล์ออก ===== */
$fileName = 'Template_WaterMeterType_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

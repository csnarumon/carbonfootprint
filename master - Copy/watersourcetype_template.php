<?php
/* ==============================================
   master/watersourcetype_template.php
   สร้างไฟล์ Template Excel สำหรับ Import แหล่งน้ำ
   ============================================== */
require_once '../includes/auth_check.php';
require_once __DIR__ . '/../vendor/autoload.php';

requireRole(array(4, 5));

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('แหล่งน้ำ');

/* ===== Header ===== */
$headers = array('ชื่อแหล่งน้ำ*', 'คำอธิบาย', 'ลำดับแสดง');
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
$sheet->getStyle('A1:C1')->applyFromArray($headerStyle);

/* ===== แถวตัวอย่าง ===== */
$sheet->setCellValue('A2', 'น้ำผิวดิน');
$sheet->setCellValue('B2', 'น้ำจากแม่น้ำ คลอง หรือแหล่งน้ำธรรมชาติ (ไม่ผ่านกระบวนการผลิต)');
$sheet->setCellValue('C2', 1);

$sheet->getColumnDimension('A')->setWidth(28);
$sheet->getColumnDimension('B')->setWidth(40);
$sheet->getColumnDimension('C')->setWidth(12);

$fileName = 'Template_WaterSource_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

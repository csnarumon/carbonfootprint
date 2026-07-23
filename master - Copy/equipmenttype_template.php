<?php
/* master/equipmenttype_template.php — ดาวน์โหลด Template Excel */
require_once '../includes/auth_check.php';
require_once __DIR__ . '/../vendor/autoload.php';
requireRole(array(4, 5));

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('ประเภทเครื่องจักร');

/* Header */
foreach (array('A'=>'ชื่อประเภท*', 'B'=>'คำอธิบาย', 'C'=>'ลำดับแสดง') as $col => $h) {
    $sheet->setCellValue($col . '1', $h);
}
$sheet->getStyle('A1:C1')->applyFromArray(array(
    'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
    'fill' => array('fillType' => Fill::FILL_SOLID, 'startColor' => array('rgb' => '2AABB8')),
));


/* ตัวอย่าง */
$examples = array(
    array('เตาอบยางสกิม',               'ใช้พลังงานความร้อนจากการเผาไหม้เชื้อเพลิงในการอบแห้งยางสกิม จัดเป็นการปล่อยก๊าซเรือนกระจกจากการเผาไหม้เชื้อเพลิงแบบคงที่ (Stationary Combustion)',            1),
);
foreach ($examples as $i => $ex) {
    $row = $i + 2;
    $sheet->setCellValue('A'.$row, $ex[0]);
    $sheet->setCellValue('B'.$row, $ex[1]);
    $sheet->setCellValue('C'.$row, $ex[2]);
}

$sheet->getColumnDimension('A')->setWidth(35);
$sheet->getColumnDimension('B')->setWidth(45);
$sheet->getColumnDimension('C')->setWidth(14);

/* ส่งไฟล์ */
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Template_EquipmentType_'.date('Ymd').'.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;

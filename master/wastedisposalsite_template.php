<?php
/* master/wastedisposalsite_template.php */
require_once '../includes/auth_check.php';
require_once __DIR__ . '/../vendor/autoload.php';
requireRole(array(4, 5));
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('สถานที่กำจัดขยะ');
$headers = array('ชื่อสถานที่*', 'ที่อยู่', 'จังหวัด', 'คำอธิบาย', 'ลำดับแสดง');
$col = 'A';
foreach ($headers as $h) { $sheet->setCellValue($col.'1', $h); $col++; }
$sheet->getStyle('A1:E1')->applyFromArray(array(
    'font' => array('bold'=>true,'color'=>array('rgb'=>'FFFFFF')),
    'fill' => array('fillType'=>Fill::FILL_SOLID,'startColor'=>array('rgb'=>'2AABB8')),
));
$sheet->setCellValue('A2', 'ภายนอกโรงงาน');
$sheet->setCellValue('B2', '123 หมู่ 5 ต.บางพลี อ.บางพลี');
$sheet->setCellValue('C2', 'สมุทรปราการ');
$sheet->setCellValue('D2', 'สถานที่กำจัดขยะมูลฝอย');
$sheet->setCellValue('E2', 1);
$sheet->getColumnDimension('A')->setWidth(28);
$sheet->getColumnDimension('B')->setWidth(35);
$sheet->getColumnDimension('C')->setWidth(16);
$sheet->getColumnDimension('D')->setWidth(30);
$sheet->getColumnDimension('E')->setWidth(12);
$fileName = 'Template_WastePlace_'.date('Ymd').'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="'.$fileName.'"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;

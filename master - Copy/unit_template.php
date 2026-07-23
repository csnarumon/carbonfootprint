<?php
/* ==============================================
   master/unit_template.php
   สร้างไฟล์ Template Excel สำหรับ Import หน่วยวัด
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

requireRole(array(4));

$conn = getConnection();

/* ===== ดึงรหัสประเภทที่ใช้งานอยู่ สำหรับทำตัวอย่างในไฟล์ template ===== */
$resType = sqlsrv_query($conn, "SELECT TypeCode, TypeName FROM CFP_UnitType WHERE IsActive = 1 ORDER BY SortOrder, TypeName");
$unitTypes = array();
if ($resType !== false) {
    while ($t = sqlsrv_fetch_array($resType, SQLSRV_FETCH_ASSOC)) { $unitTypes[] = $t; }
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('หน่วยวัด');

/* ===== Header ===== */
$headers = array('รหัสหน่วย*', 'ชื่อหน่วย*', 'ประเภท (รหัสประเภทจาก CFP_UnitType)', 'คำอธิบาย', 'ลำดับแสดง');
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . '1', $h);
    $col++;
}

$headerStyle = array(
    'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
    'fill' => array(
        'fillType'   => Fill::FILL_SOLID,
        'startColor' => array('rgb' => '2AABB8'), // สีฟ้าเดียวกับ template อื่น
    ),
);
$sheet->getStyle('A1:E1')->applyFromArray($headerStyle);

/* ===== แถวตัวอย่าง — ใช้ TypeCode จริงจากระบบถ้ามี ไม่งั้น fallback เป็นตัวอย่างเปล่า ===== */
$sampleRows = array(
    array('UN-1001', 'กิโลกรัม', 0, 'หน่วยวัดน้ำหนัก'),
    array('UN-1002', 'ลิตร', 1, 'หน่วยวัดปริมาตรของเหลว'),
    array('UN-1003', 'กิโลวัตต์ชั่วโมง', 2, 'หน่วยพลังงานไฟฟ้า'),
);
$r = 2;
foreach ($sampleRows as $i => $s) {
    $typeCode = isset($unitTypes[$s[2]]) ? $unitTypes[$s[2]]['TypeCode'] : '';
    $sheet->setCellValue('A' . $r, $s[0]);
    $sheet->setCellValue('B' . $r, $s[1]);
    $sheet->setCellValue('C' . $r, $typeCode);
    $sheet->setCellValue('D' . $r, $s[3]);
    $sheet->setCellValue('E' . $r, $i + 1);
    $r++;
}

/* ===== แสดงรายการรหัสประเภทที่ใช้ได้จริง เป็น note ===== */
if (!empty($unitTypes)) {
    $codeList = array();
    foreach ($unitTypes as $t) { $codeList[] = $t['TypeCode'] . ' (' . $t['TypeName'] . ')'; }
    $sheet->setCellValue('A' . ($r + 1), 'รหัสประเภทที่ใช้ได้: ' . implode(', ', $codeList));
    $sheet->getStyle('A' . ($r + 1))->getFont()->setItalic(true)->setSize(9);
}

/* ===== ปรับขนาดคอลัมน์ ===== */
$sheet->getColumnDimension('A')->setWidth(16);
$sheet->getColumnDimension('B')->setWidth(25);
$sheet->getColumnDimension('C')->setWidth(35);
$sheet->getColumnDimension('D')->setWidth(35);
$sheet->getColumnDimension('E')->setWidth(14);

/* ===== ส่งไฟล์ออก ===== */
$fileName = 'Template_Unit_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
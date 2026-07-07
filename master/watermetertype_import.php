<?php
/* ==============================================
   master/watermetertype_import.php
   รับไฟล์ Excel (.xlsx) แล้วนำเข้าข้อมูลประเภทมิเตอร์น้ำ
   คอลัมน์ที่ต้องมีในไฟล์ (เรียงตามลำดับ):
   A: ชื่อประเภท*  B: คำอธิบาย  C: ลำดับแสดง
   (รหัสประเภทระบบสร้างให้อัตโนมัติ ไม่ต้องกรอกในไฟล์)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
require_once '../includes/excel_import_helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

define('CODE_PREFIX', 'WMT');

requireRole(array(4, 5));
verifyCsrf();

$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: watermetertype.php');
    exit;
}

/* ===== Helper: generate รหัสอัตโนมัติ รูปแบบ VT-0001 (เหมือนใน watermetertype_save.php) ===== */
function generateTypeCode($conn, $prefix) {
    $res = sqlsrv_query($conn, "
        SELECT MAX(CAST(SUBSTRING(TypeCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
        FROM CFP_WaterMeterType
        WHERE TypeCode LIKE ? + '-%'",
        array($prefix, $prefix));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    $nextNum = ($row && $row['MaxNum'] !== null) ? ((int)$row['MaxNum'] + 1) : 1;
    return $prefix . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
}

/* ===== ตรวจไฟล์ที่อัปโหลด ===== */
if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
    redirectWithToast('ไม่พบไฟล์ที่อัปโหลด หรือเกิดข้อผิดพลาดระหว่างอัปโหลด', 'error');
}

$fileTmp  = $_FILES['import_file']['tmp_name'];
$fileName = $_FILES['import_file']['name'];
$fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if ($fileExt !== 'xlsx') {
    redirectWithToast('รองรับเฉพาะไฟล์ .xlsx เท่านั้น', 'error');
}

if ($_FILES['import_file']['size'] > 5 * 1024 * 1024) {
    redirectWithToast('ไฟล์มีขนาดเกิน 5 MB', 'error');
}

/* ===== อ่านไฟล์ Excel ===== */
$rows = readExcelRows($fileTmp);
if ($rows === false) {
    redirectWithToast('ไม่สามารถอ่านไฟล์ Excel ได้ กรุณาตรวจสอบรูปแบบไฟล์', 'error');
}

if (count($rows) === 0) {
    redirectWithToast('ไฟล์ Excel ไม่มีข้อมูล (มีแต่หัวตาราง)', 'error');
}

/* ===== ดึงชื่อที่มีอยู่แล้วในระบบ (เช็คซ้ำ) ===== */
$res = sqlsrv_query($conn, "SELECT TypeName FROM CFP_WaterMeterType");
$existingNames = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $existingNames[$r['TypeName']] = true;
}

/* ===== ประมวลผลทีละแถว ===== */
$successCount = 0;
$failCount    = 0;
$skipCount    = 0;
$errors       = array();
$seenInFile   = array();

$rowNum = 1;  /* แถวที่ 1 คือ Header ไปแล้ว เริ่มนับแถวข้อมูลจาก 2 */
foreach ($rows as $row) {
    $rowNum++;

    $name = excelCell($row, 0);
    $desc = excelCell($row, 1);
    $sort = excelCell($row, 2);

    /* ข้ามแถวที่ว่างทั้งแถว (เผื่อมีแถวเปล่าท้ายไฟล์) */
    if ($name === '') {
        continue;
    }

    $sort = ($sort !== '' && is_numeric($sort)) ? (int)$sort : 99;

    /* ===== เช็คซ้ำด้วยชื่อ — ทั้งในระบบและในไฟล์เดียวกัน ===== */
    if (isset($existingNames[$name]) || isset($seenInFile[$name])) {
        $skipCount++;
        continue;
    }

    /* ===== Generate รหัสอัตโนมัติทีละแถว ===== */
    $code = generateTypeCode($conn, CODE_PREFIX);

    /* ===== Insert ===== */
    $sql = "INSERT INTO CFP_WaterMeterType
            (TypeCode, TypeName, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, 1, ?, GETDATE())";
    $r = sqlsrv_query($conn, $sql, array(
        $code, $name, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']
    ));

    if ($r === false) {
        $failCount++;
        $errors[] = 'แถวที่ ' . $rowNum . ': บันทึกไม่สำเร็จ (' . $name . ')';
        continue;
    }

    $seenInFile[$name] = true;
    $successCount++;
}

/* ===== บันทึก Log การ Import ===== */
$totalRows = count($rows);
logImportResult($conn, 'CFP_WaterMeterType', $fileName, $totalRows, $successCount, $failCount, $skipCount, $errors);

if ($successCount > 0) {
    logAction($conn, 'DATA_IMPORT', 'CFP_WaterMeterType', null, null, null, null,
        'Import Excel: สำเร็จ ' . $successCount . ' / ทั้งหมด ' . $totalRows . ' แถว');
}

/* ===== ส่งผลลัพธ์กลับไปแสดงผล ===== */
$_SESSION['import_result'] = array(
    'success' => $successCount,
    'fail'    => $failCount,
    'skip'    => $skipCount,
    'errors'  => array_slice($errors, 0, 20),  /* แสดง error แค่ 20 รายการแรก ป้องกัน popup ยาวเกินไป */
);

header('Location: watermetertype.php');
exit;

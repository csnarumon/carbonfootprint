<?php
/* ==============================================
   master/watersourcetype_import.php
   รับไฟล์ Excel (.xlsx) แล้วนำเข้าข้อมูลแหล่งน้ำ
   คอลัมน์ที่ต้องมีในไฟล์ (เรียงตามลำดับ):
   A: ชื่อแหล่งน้ำ*  B: คำอธิบาย  C: ลำดับแสดง
   (รหัสแหล่งน้ำระบบสร้างให้อัตโนมัติ ไม่ต้องกรอกในไฟล์)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
require_once '../includes/excel_import_helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

define('CODE_PREFIX', 'WS');

requireRole(array(4, 5));
verifyCsrf();

$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: watersourcetype.php');
    exit;
}

/* ===== Helper: generate รหัสอัตโนมัติ รูปแบบ WS-0001 ===== */
function generateSourceCode($conn, $prefix) {
    $res = sqlsrv_query($conn, "
        SELECT MAX(CAST(SUBSTRING(SourceCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
        FROM CFP_WaterSourceType
        WHERE SourceCode LIKE ? + '-%'",
        array($prefix, $prefix));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    $nextNum = ($row && $row['MaxNum'] !== null) ? ((int)$row['MaxNum'] + 1) : 1;
    return $prefix . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
}

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

$rows = readExcelRows($fileTmp);
if ($rows === false) {
    redirectWithToast('ไม่สามารถอ่านไฟล์ Excel ได้ กรุณาตรวจสอบรูปแบบไฟล์', 'error');
}

if (count($rows) === 0) {
    redirectWithToast('ไฟล์ Excel ไม่มีข้อมูล (มีแต่หัวตาราง)', 'error');
}

/* ===== ดึงชื่อที่มีอยู่แล้วในระบบ (เช็คซ้ำ) ===== */
$res = sqlsrv_query($conn, "SELECT SourceName FROM CFP_WaterSourceType");
$existingNames = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $existingNames[$r['SourceName']] = true;
}

$successCount = 0;
$failCount    = 0;
$skipCount    = 0;
$errors       = array();
$seenInFile   = array();

$rowNum = 1;
foreach ($rows as $row) {
    $rowNum++;

    $name = excelCell($row, 0);
    $desc = excelCell($row, 1);
    $sort = excelCell($row, 2);

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
    $code = generateSourceCode($conn, CODE_PREFIX);

    $sql = "INSERT INTO CFP_WaterSourceType
            (SourceCode, SourceName, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
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

$totalRows = count($rows);
logImportResult($conn, 'CFP_WaterSourceType', $fileName, $totalRows, $successCount, $failCount, $skipCount, $errors);

if ($successCount > 0) {
    logAction($conn, 'DATA_IMPORT', 'CFP_WaterSourceType', null, null, null, null,
        'Import Excel: สำเร็จ ' . $successCount . ' / ทั้งหมด ' . $totalRows . ' แถว');
}

$_SESSION['import_result'] = array(
    'success' => $successCount,
    'fail'    => $failCount,
    'skip'    => $skipCount,
    'errors'  => array_slice($errors, 0, 20),
);

header('Location: watersourcetype.php');
exit;

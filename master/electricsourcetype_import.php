<?php
/* ==============================================
   master/electrictype_import.php
   รับไฟล์ Excel (.xlsx) แล้วนำเข้าข้อมูลแหล่งไฟฟ้า
   คอลัมน์ที่ต้องมีในไฟล์ (เรียงตามลำดับ):
   A: ชื่อแหล่งไฟฟ้า*  B: Grid Factor  C: คำอธิบาย  D: ลำดับแสดง
   (รหัสแหล่งไฟฟ้าระบบสร้างให้อัตโนมัติ ไม่ต้องกรอกในไฟล์)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
require_once '../includes/excel_import_helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

define('CODE_PREFIX', 'ES');

requireRole(array(4, 5));
verifyCsrf();

$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: electrictype.php');
    exit;
}

/* ===== Helper: generate รหัสอัตโนมัติ รูปแบบ ES-0001 ===== */
function generateSourceCode($conn, $prefix) {
    $res = sqlsrv_query($conn, "
        SELECT MAX(CAST(SUBSTRING(SourceCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
        FROM CFP_ElectricSourceType
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
$res = sqlsrv_query($conn, "SELECT SourceCode, SourceName FROM CFP_ElectricSourceType");
$existingCodes = array();
$existingNames = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $existingCodes[$r['SourceCode']] = true;
    $existingNames[$r['SourceName']] = true;
}

$successCount = 0;
$failCount    = 0;
$skipCount    = 0;
$errors       = array();
$seenInFile   = array();
$seenInFileNames = array();

$rowNum = 1;
foreach ($rows as $row) {
    $rowNum++;

    $code = excelCell($row, 0); // A: รหัส*
    $name = excelCell($row, 1); // B: ชื่อ*
    $grid = excelCell($row, 2); // C: Grid Factor
    $desc = excelCell($row, 3); // D: คำอธิบาย
    $sort = excelCell($row, 4); // E: ลำดับ

    // ข้ามแถวที่ว่างทั้งรหัสและชื่อ
    if ($code === '' && $name === '') {
        continue;
    }

    // ตรวจสอบครบถ้วน
    if ($code === '' || $name === '') {
        $failCount++;
        $errors[] = 'แถวที่ ' . $rowNum . ': กรุณากรอกรหัสและชื่อให้ครบ';
        continue;
    }

    $sort      = ($sort !== '' && is_numeric($sort)) ? (int)$sort : 99;
    $gridValue = ($grid !== '' && is_numeric($grid)) ? (float)$grid : null;

    // ตรวจสอบรหัสซ้ำ
    if (isset($existingCodes[$code]) || isset($seenInFile[$code])) {
        $skipCount++;
        continue;
    }

    // ตรวจสอบชื่อซ้ำ
    if (isset($existingNames[$name]) || isset($seenInFileNames[$name])) {
        $skipCount++;
        continue;
    }

    // Insert โดยใช้รหัสที่อ่านได้ (ไม่ generate)
    $sql = "INSERT INTO CFP_ElectricSourceType
            (SourceCode, SourceName, GridFactor, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, ?, 1, ?, GETDATE())";
    $r = sqlsrv_query($conn, $sql, array(
        $code, $name, $gridValue, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']
    ));

    if ($r === false) {
        $failCount++;
        $errors[] = 'แถวที่ ' . $rowNum . ': บันทึกไม่สำเร็จ (' . $name . ')';
        continue;
    }

    $seenInFile[$code] = true;
    $seenInFileNames[$name] = true;
    $successCount++;
}

$totalRows = count($rows);
logImportResult($conn, 'CFP_ElectricSourceType', $fileName, $totalRows, $successCount, $failCount, $skipCount, $errors);

if ($successCount > 0) {
    logAction($conn, 'DATA_IMPORT', 'CFP_ElectricSourceType', null, null, null, null,
        'Import Excel: สำเร็จ ' . $successCount . ' / ทั้งหมด ' . $totalRows . ' แถว');
}

$_SESSION['import_result'] = array(
    'success' => $successCount,
    'fail'    => $failCount,
    'skip'    => $skipCount,
    'errors'  => array_slice($errors, 0, 20),
);

header('Location: electrictype.php');
exit;

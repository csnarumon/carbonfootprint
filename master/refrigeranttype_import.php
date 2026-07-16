<?php
/* ==============================================
   master/refrigeranttype_import.php
   รับไฟล์ Excel (.xlsx) แล้วนำเข้าข้อมูลประเภทสารทำความเย็น
   คอลัมน์: A:ชื่อ*  B:GWP100  C:คำอธิบาย  D:ลำดับ
   (รหัสประเภทระบบสร้างให้อัตโนมัติ ไม่ต้องกรอกในไฟล์)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
require_once '../includes/excel_import_helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

define('CODE_PREFIX', 'RT');

requireRole(array(4, 5));
verifyCsrf();

$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: refrigeranttype.php');
    exit;
}

/* ===== Helper: generate รหัสอัตโนมัติ รูปแบบ RT-0001 ===== */
function generateTypeCode($conn, $prefix) {
    $res = sqlsrv_query($conn, "
        SELECT MAX(CAST(SUBSTRING(TypeCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
        FROM CFP_RefrigerantType
        WHERE TypeCode LIKE ? + '-%'",
        array($prefix, $prefix));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    $nextNum = ($row && $row['MaxNum'] !== null) ? ((int)$row['MaxNum'] + 1) : 1;
    return $prefix . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
}

/* ===== ตรวจไฟล์ ===== */
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

/* ===== ดึงรหัสและชื่อที่มีอยู่แล้วในระบบ ===== */
$res = sqlsrv_query($conn, "SELECT TypeCode, TypeName FROM CFP_RefrigerantType");
$existingCodes = array();
$existingNames = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $existingCodes[$r['TypeCode']] = true;
    $existingNames[$r['TypeName']] = true;
}

$successCount = 0;
$failCount    = 0;
$skipCount    = 0;
$errors       = array();
$seenInFile   = array();      // ตรวจสอบรหัสซ้ำในไฟล์
$seenInFileNames = array();   // ตรวจสอบชื่อซ้ำในไฟล์

$rowNum = 1;
foreach ($rows as $row) {
    $rowNum++;

    $name = excelCell($row, 0); // A: ชื่อ*
    $gwp  = excelCell($row, 1); // B: GWP100
    $desc = excelCell($row, 2); // C: คำอธิบาย
    $sort = excelCell($row, 3); // D: ลำดับ

    // ข้ามแถวว่าง
    if ($name === '') {
        continue;
    }

    $sort     = ($sort !== '' && is_numeric($sort)) ? (int)$sort : 99;
    $gwpValue = ($gwp !== '' && is_numeric($gwp)) ? (float)$gwp : null;

    // ตรวจสอบชื่อซ้ำ (ในระบบ + ในไฟล์)
    if (isset($existingNames[$name]) || isset($seenInFileNames[$name])) {
        $skipCount++;
        continue;
    }

    // รหัสสร้างโดยระบบเสมอ ไม่รับค่าจากไฟล์
    $code = generateTypeCode($conn, CODE_PREFIX);

    $sql = "INSERT INTO CFP_RefrigerantType
            (TypeCode, TypeName, GWP100, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, ?, 1, ?, GETDATE())";
    $r = sqlsrv_query($conn, $sql, array(
        $code, $name, $gwpValue, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']
    ));

    if ($r === false) {
        $failCount++;
        $errors[] = 'แถวที่ ' . $rowNum . ': บันทึกไม่สำเร็จ (' . $name . ')';
        continue;
    }

    $existingCodes[$code] = true;
    $seenInFileNames[$name] = true;
    $successCount++;
}

$totalRows = count($rows);
logImportResult($conn, 'CFP_RefrigerantType', $fileName, $totalRows, $successCount, $failCount, $skipCount, $errors);

if ($successCount > 0) {
    logAction($conn, 'DATA_IMPORT', 'CFP_RefrigerantType', null, null, null, null,
        'Import Excel: สำเร็จ ' . $successCount . ' / ทั้งหมด ' . $totalRows . ' แถว');
}

$_SESSION['import_result'] = array(
    'success' => $successCount,
    'fail'    => $failCount,
    'skip'    => $skipCount,
    'errors'  => array_slice($errors, 0, 20),
);

header('Location: refrigeranttype.php');
exit;
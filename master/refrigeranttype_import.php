<?php
/* ==============================================
   master/refrigeranttype_import.php
   รับไฟล์ Excel (.xlsx) แล้วนำเข้าข้อมูลประเภทสารทำความเย็น
   คอลัมน์: A:รหัส*  B:ชื่อ*  C:GWP100  D:คำอธิบาย  E:ลำดับ
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

    $code = excelCell($row, 0); // A: รหัส*
    $name = excelCell($row, 1); // B: ชื่อ*
    $gwp  = excelCell($row, 2); // C: GWP100
    $desc = excelCell($row, 3); // D: คำอธิบาย
    $sort = excelCell($row, 4); // E: ลำดับ

    // ข้ามแถวว่าง
    if ($code === '' && $name === '') {
        continue;
    }

    // ตรวจสอบครบถ้วน
    if ($code === '' || $name === '') {
        $failCount++;
        $errors[] = 'แถวที่ ' . $rowNum . ': กรุณากรอกรหัสและชื่อให้ครบ';
        continue;
    }

    $sort     = ($sort !== '' && is_numeric($sort)) ? (int)$sort : 99;
    $gwpValue = ($gwp !== '' && is_numeric($gwp)) ? (float)$gwp : null;

    // ตรวจสอบรหัสซ้ำ (ในระบบ + ในไฟล์)
    if (isset($existingCodes[$code]) || isset($seenInFile[$code])) {
        $skipCount++;
        continue;
    }

    // ตรวจสอบชื่อซ้ำ (ในระบบ + ในไฟล์)
    if (isset($existingNames[$name]) || isset($seenInFileNames[$name])) {
        $skipCount++;
        continue;
    }

    // INSERT โดยใช้รหัสจากไฟล์ (ไม่ generate)
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

    $seenInFile[$code] = true;
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
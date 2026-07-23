<?php
/* ==============================================
   master/unit_import.php
   รับไฟล์ Excel (.xlsx) แล้วนำเข้าข้อมูลหน่วยวัด
   คอลัมน์ที่ต้องมีในไฟล์ (เรียงตามลำดับ):
   A: รหัสหน่วย*  B: ชื่อหน่วย*  C: ประเภท (รหัสประเภทจาก CFP_UnitType เช่น MASS, VOLUME)
   D: คำอธิบาย   E: ลำดับแสดง
   (รหัสต้องกรอกเอง ระบบจะไม่สร้างรหัสให้อัตโนมัติ)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
require_once '../includes/excel_import_helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

requireRole(array(4));
verifyCsrf();

$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: unit.php');
    exit;
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

/* ===== ดึงรหัส/ชื่อที่มีอยู่แล้วในระบบ (เช็คซ้ำ) ===== */
$resExist  = sqlsrv_query($conn, "SELECT UnitCode, UnitName FROM CFP_Unit");
$existingCodes = array();
$existingNames = array();
while ($r = sqlsrv_fetch_array($resExist, SQLSRV_FETCH_ASSOC)) {
    $existingCodes[$r['UnitCode']] = true;
    $existingNames[$r['UnitName']] = true;
}

/* ===== ดึง mapping TypeCode -> UnitTypeID จาก CFP_UnitType (สำหรับ lookup คอลัมน์ประเภท) ===== */
$resType = sqlsrv_query($conn, "SELECT UnitTypeID, TypeCode FROM CFP_UnitType WHERE IsActive = 1");
$typeCodeMap = array();
if ($resType !== false) {
    while ($t = sqlsrv_fetch_array($resType, SQLSRV_FETCH_ASSOC)) {
        $typeCodeMap[strtoupper(trim($t['TypeCode']))] = (int)$t['UnitTypeID'];
    }
}

/* ===== ประมวลผลทีละแถว ===== */
$successCount = 0;
$failCount    = 0;
$skipCount    = 0;
$errors       = array();
$seenInFileCodes = array();
$seenInFileNames = array();

$rowNum = 1;
foreach ($rows as $row) {
    $rowNum++;

    $code = excelCell($row, 0);   /* คอลัมน์ A: รหัสหน่วย* */
    $name = excelCell($row, 1);   /* คอลัมน์ B: ชื่อหน่วย* */
    $type = excelCell($row, 2);   /* คอลัมน์ C: ประเภท (TypeCode) */
    $desc = excelCell($row, 3);   /* คอลัมน์ D: คำอธิบาย */
    $sort = excelCell($row, 4);   /* คอลัมน์ E: ลำดับ */

    /* ตรวจสอบรหัสและชื่อ */
    if ($code === '' || $name === '') {
        $failCount++;
        $errors[] = 'แถวที่ ' . $rowNum . ': ขาดรหัสหรือชื่อ';
        continue;
    }

    $sort = ($sort !== '' && is_numeric($sort)) ? (int)$sort : 99;

    /* lookup UnitTypeID จาก TypeCode ที่กรอกมา (ถ้ากรอกแต่หาไม่เจอ ถือว่า error ไม่ข้ามเงียบๆ) */
    $typeIDVal = null;
    $typeInput = strtoupper(trim($type));
    if ($typeInput !== '') {
        if (isset($typeCodeMap[$typeInput])) {
            $typeIDVal = $typeCodeMap[$typeInput];
        } else {
            $failCount++;
            $errors[] = 'แถวที่ ' . $rowNum . ': ไม่พบรหัสประเภท "' . $type . '" ในระบบ (' . $name . ')';
            continue;
        }
    }

    $descVal = ($desc !== '') ? $desc : null;

    /* เช็ครหัสซ้ำ — ทั้งในระบบและในไฟล์เดียวกัน */
    if (isset($existingCodes[$code]) || isset($seenInFileCodes[$code])) {
        $skipCount++;
        continue;
    }
    /* เช็คชื่อซ้ำ — ทั้งในระบบและในไฟล์เดียวกัน */
    if (isset($existingNames[$name]) || isset($seenInFileNames[$name])) {
        $skipCount++;
        continue;
    }

    $sql = "INSERT INTO CFP_Unit
            (UnitCode, UnitName, UnitTypeID, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, ?, 1, ?, GETDATE())";

    $r = sqlsrv_query($conn, $sql, array(
        $code, $name, $typeIDVal, $descVal, $sort, (int)$_SESSION['user_id']
    ));

    if ($r === false) {
        $failCount++;
        $errors[] = 'แถวที่ ' . $rowNum . ': บันทึกไม่สำเร็จ (' . $name . ')';
        continue;
    }

    $seenInFileCodes[$code] = true;
    $seenInFileNames[$name] = true;
    $successCount++;
}

/* ===== บันทึก Log ===== */
$totalRows = count($rows);
logImportResult($conn, 'CFP_Unit', $fileName, $totalRows, $successCount, $failCount, $skipCount, $errors);

if ($successCount > 0) {
    logAction($conn, 'DATA_IMPORT', 'CFP_Unit', null, null, null, null,
        'Import Excel: สำเร็จ ' . $successCount . ' / ทั้งหมด ' . $totalRows . ' แถว');
}

/* ===== ส่งผลลัพธ์กลับ ===== */
$_SESSION['import_result'] = array(
    'success' => $successCount,
    'fail'    => $failCount,
    'skip'    => $skipCount,
    'errors'  => array_slice($errors, 0, 20),
);

header('Location: unit.php');
exit;

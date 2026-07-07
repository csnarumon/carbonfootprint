<?php
/* ==============================================
   master/equipmenttype_import.php
   คอลัมน์: A: ชื่อประเภท*  B: คำอธิบาย  C: ลำดับแสดง
   (รหัสระบบสร้างให้อัตโนมัติ EQ-0001)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
require_once '../includes/excel_import_helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

define('CODE_PREFIX', 'EQ');
requireRole(array(4, 5));
verifyCsrf();

$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: equipmenttype.php');
    exit;
}

function generateTypeCode($conn, $prefix) {
    $res = sqlsrv_query($conn,
        "SELECT MAX(CAST(SUBSTRING(TypeCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
         FROM CFP_EquipmentType WHERE TypeCode LIKE ? + '-%'",
        array($prefix, $prefix));
    $row     = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    $nextNum = ($row && $row['MaxNum'] !== null) ? ((int)$row['MaxNum'] + 1) : 1;
    return $prefix . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
}

/* ===== ตรวจไฟล์ ===== */
if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
    redirectWithToast('ไม่พบไฟล์ที่อัปโหลด', 'error');
}
$fileTmp  = $_FILES['import_file']['tmp_name'];
$fileName = $_FILES['import_file']['name'];
$fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if ($fileExt !== 'xlsx') { redirectWithToast('รองรับเฉพาะไฟล์ .xlsx', 'error'); }
if ($_FILES['import_file']['size'] > 5 * 1024 * 1024) { redirectWithToast('ไฟล์ขนาดเกิน 5 MB', 'error'); }

/* ===== อ่าน Excel ===== */
$rows = readExcelRows($fileTmp);
if ($rows === false) { redirectWithToast('ไม่สามารถอ่านไฟล์ได้', 'error'); }
if (count($rows) === 0) { redirectWithToast('ไม่มีข้อมูลในไฟล์', 'error'); }

/* ===== เช็คซ้ำ ===== */
$res = sqlsrv_query($conn, "SELECT TypeName FROM CFP_EquipmentType");
$existingNames = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $existingNames[$r['TypeName']] = true;
}

$successCount = 0; $failCount = 0; $skipCount = 0;
$errors = array(); $seenInFile = array();
$rowNum = 1;

foreach ($rows as $row) {
    $rowNum++;
    $name = excelCell($row, 0);
    $desc = excelCell($row, 1);
    $sort = excelCell($row, 2);

    if ($name === '') { continue; }
    $sort = ($sort !== '' && is_numeric($sort)) ? (int)$sort : 99;

    if (isset($existingNames[$name]) || isset($seenInFile[$name])) {
        $skipCount++;
        continue;
    }

    $code = generateTypeCode($conn, CODE_PREFIX);
    $r = sqlsrv_query($conn,
        "INSERT INTO CFP_EquipmentType (TypeCode,TypeName,Description,SortOrder,IsActive,CreatedBy,CreatedDate) VALUES (?,?,?,?,1,?,GETDATE())",
        array($code, $name, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']));

    if ($r === false) {
        $failCount++;
        $errors[] = 'แถวที่ ' . $rowNum . ': บันทึกไม่สำเร็จ (' . $name . ')';
        continue;
    }
    $seenInFile[$name] = true;
    $successCount++;
}

/* ===== Log ===== */
$totalRows = count($rows);
logImportResult($conn, 'CFP_EquipmentType', $fileName, $totalRows, $successCount, $failCount, $skipCount, $errors);
if ($successCount > 0) {
    logAction($conn, 'DATA_IMPORT', 'CFP_EquipmentType', null, null, null, null,
        'Import: สำเร็จ ' . $successCount . '/' . $totalRows . ' แถว');
}

$_SESSION['import_result'] = array(
    'success' => $successCount, 'fail' => $failCount, 'skip' => $skipCount,
    'errors'  => array_slice($errors, 0, 20),
);
header('Location: equipmenttype.php');
exit;

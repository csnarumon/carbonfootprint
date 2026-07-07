<?php
/* ==============================================
   master/ef_value_save.php
   actions: create | update | toggle
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(4, 5));
$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: ef_value.php');
    exit;
}

/* สร้างรหัส EF อัตโนมัติ เช่น EF-S1-001, EF-S2-001, EF-S3-001 */
function generateEFCode($conn, $scope, $gasType) {
    $scopeShort = str_replace('Scope', 'S', $scope ?? 'S1');
    $prefix     = 'EF-' . $scopeShort;
    $res = sqlsrv_query($conn,
        "SELECT MAX(CAST(SUBSTRING(EFCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
         FROM CFP_EFValue WHERE EFCode LIKE ? + '-%'",
        array($prefix, $prefix));
    $row     = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    $nextNum = ($row && $row['MaxNum'] !== null) ? ((int)$row['MaxNum'] + 1) : 1;
    return $prefix . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
}

verifyCsrf();
$action = $_POST['action'] ?? '';

/* ===== toggle ===== */
if ($action === 'toggle') {
    $id  = (int)($_POST['EFID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูล', 'error'); }

    $res = sqlsrv_query($conn, "SELECT IsActive, EFName FROM CFP_EFValue WHERE EFID=?", array($id));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบค่า EF', 'error'); }

    $newStatus = $row['IsActive'] ? 0 : 1;
    sqlsrv_query($conn,
        "UPDATE CFP_EFValue SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE EFID=?",
        array($newStatus, (int)$_SESSION['user_id'], $id));

    logAction($conn, 'DATA_UPDATE', 'CFP_EFValue', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['EFName']);
    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อย' : 'ปิดใช้งานเรียบร้อย');
}

/* ===== create / update ===== */
$name     = trim($_POST['EFName']    ?? '');
$scope    = trim($_POST['Scope']     ?? '');
$gasType  = trim($_POST['GasType']   ?? '');
$efVal    = trim($_POST['EFValue']   ?? '');
$gwp      = trim($_POST['GWP']       ?? '1');
$unit     = trim($_POST['Unit']      ?? '');
$year     = (int)($_POST['YearApply'] ?? 0);
$sourceID = isset($_POST['SourceID']) && $_POST['SourceID'] !== '' ? (int)$_POST['SourceID'] : null;
$refID    = isset($_POST['RefID'])       && $_POST['RefID']       !== '' ? (int)$_POST['RefID']    : null;
$isActive = isset($_POST['IsActive'])  ? (int)$_POST['IsActive'] : 1;
$refTable = $refID ? 'CFP_ActivityItem' : null;

/* ValidUntil — รับ date string YYYY-MM-DD หรือว่าง */
$validUntilRaw = trim($_POST['ValidUntil'] ?? '');
$validUntil    = ($validUntilRaw !== '' && strtotime($validUntilRaw)) ? $validUntilRaw : null;

/* Validate */
if ($name    === '') { redirectWithToast('กรุณากรอกชื่อ EF', 'error'); }
if ($scope   === '') { redirectWithToast('กรุณาเลือก Scope', 'error'); }
if ($gasType === '') { redirectWithToast('กรุณาเลือก Gas Type', 'error'); }
if ($efVal   === '' || !is_numeric($efVal)) { redirectWithToast('กรุณากรอกค่า EF', 'error'); }
if ($year    < 2000) { redirectWithToast('กรุณากรอกปีที่ถูกต้อง', 'error'); }

if ($action === 'create') {
    $code = generateEFCode($conn, $scope, $gasType);
    $sql  = "INSERT INTO CFP_EFValue
             (EFCode, EFName, EFValue, GWP, Unit, Scope, GasType,
              YearApply, ValidUntil, SourceID, RefID, RefTable, IsActive, CreatedBy, CreatedDate)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,GETDATE())";
    $r = sqlsrv_query($conn, $sql, array(
        $code, $name, (float)$efVal, (float)$gwp,
        ($unit !== '' ? $unit : null), $scope, $gasType,
        $year, $validUntil, $sourceID, $refID, $refTable,
        (int)$_SESSION['user_id']
    ));
    if ($r === false) {
        /* retry race condition */
        $code = generateEFCode($conn, $scope, $gasType);
        sqlsrv_query($conn, $sql, array(
            $code, $name, (float)$efVal, (float)$gwp,
            ($unit !== '' ? $unit : null), $scope, $gasType,
            $year, $validUntil, $sourceID, $refID, $refTable,
            (int)$_SESSION['user_id']
        ));
    }
    logAction($conn, 'DATA_CREATE', 'CFP_EFValue', null, null, null, null,
        'เพิ่ม EF: '.$code.' - '.$name.' ('.$year.')');
    redirectWithToast('เพิ่มค่า EF "'.$name.'" เรียบร้อย (รหัส '.$code.')');

} elseif ($action === 'update') {
    $id = (int)($_POST['EFID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }

    sqlsrv_query($conn,
        "UPDATE CFP_EFValue
         SET EFName=?, EFValue=?, GWP=?, Unit=?, Scope=?, GasType=?,
             YearApply=?, ValidUntil=?, SourceID=?, RefID=?, RefTable=?, IsActive=?,
             UpdatedBy=?, UpdatedDate=GETDATE()
         WHERE EFID=?",
        array(
            $name, (float)$efVal, (float)$gwp,
            ($unit !== '' ? $unit : null), $scope, $gasType,
            $year, $validUntil, $sourceID, $refID, $refTable, $isActive,
            (int)$_SESSION['user_id'], $id
        ));
    logAction($conn, 'DATA_UPDATE', 'CFP_EFValue', $id, null, null, null,
        'แก้ไข EF: '.$name.' ('.$year.')');
    redirectWithToast('บันทึกการแก้ไขเรียบร้อย');

} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}

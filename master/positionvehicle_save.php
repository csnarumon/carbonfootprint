<?php
/* master/positionvehicle_save.php
   Table: CFP_PositionVehicle — ทะเบียนรถประจำตำแหน่ง
   actions: create | update | toggle
*/
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4, 5));

$conn   = getConnection();
$userID = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

function jsonOut($success, $msg) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('success' => $success, 'msg' => $msg), JSON_UNESCAPED_UNICODE);
    exit;
}

function cleanStr($val, $maxLen = 200) {
    return mb_substr(trim($val ?? ''), 0, $maxLen);
}

verifyCsrf();

/* ---------- TOGGLE ---------- */
if ($action === 'toggle') {
    if ($id <= 0) { jsonOut(false, 'ไม่พบข้อมูล'); }
    $cur = (int)($_POST['is_active'] ?? 1);
    $newVal = $cur ? 0 : 1;
    sqlsrv_query($conn,
        "UPDATE CFP_PositionVehicle SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE PositionVehicleID=?",
        array($newVal, $userID, $id));
    logAction($conn, 'DATA_UPDATE', 'CFP_PositionVehicle', $id, null, null, null,
        ($newVal ? 'เปิดใช้งาน' : 'ปิดใช้งาน') . ' รถประจำตำแหน่ง ID=' . $id);
    jsonOut(true, ($newVal ? 'เปิดใช้งาน' : 'ปิดใช้งาน') . 'เรียบร้อยแล้ว');
}

/* ---------- parse ---------- */
$position  = cleanStr($_POST['Position'] ?? '', 200);
$plateNo   = cleanStr($_POST['PlateNo'] ?? '', 50);
$siteID    = (int)($_POST['SiteID'] ?? 0) ?: null;
$vtID      = (int)($_POST['VehicleTypeID'] ?? 0) ?: null;
$ftID      = (int)($_POST['FuelTypeID'] ?? 0) ?: null;
$price     = is_numeric($_POST['FuelPricePerLiter'] ?? '') ? (float)$_POST['FuelPricePerLiter'] : null;
$remark    = cleanStr($_POST['Remark'] ?? '', 500);

if ($position === '') { jsonOut(false, 'กรุณากรอกตำแหน่ง'); }

/* ---------- CREATE ---------- */
if ($action === 'create') {
    $resMax = sqlsrv_query($conn, "SELECT MAX(Code) AS MaxCode FROM CFP_PositionVehicle WHERE Code LIKE 'PV-%'");
    $rMax = sqlsrv_fetch_array($resMax, SQLSRV_FETCH_ASSOC);
    $nextNum = 1;
    if ($rMax && $rMax['MaxCode'] && preg_match('/(\d+)$/', $rMax['MaxCode'], $m)) { $nextNum = (int)$m[1] + 1; }
    $code = 'PV-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

    $res = sqlsrv_query($conn,
        "INSERT INTO CFP_PositionVehicle
         (Code, SiteID, Position, PlateNo, VehicleTypeID, FuelTypeID, FuelPricePerLiter, Remark, IsActive, CreatedBy, CreatedDate)
         VALUES (?,?,?,?,?,?,?,?,1,?,GETDATE())",
        array($code, $siteID, $position, $plateNo ?: null, $vtID, $ftID, $price, $remark ?: null, $userID));
    if (!$res) {
        $e = sqlsrv_errors();
        jsonOut(false, 'บันทึกไม่สำเร็จ: ' . ($e[0]['message'] ?? ''));
    }
    $rID = sqlsrv_query($conn, "SELECT @@IDENTITY AS NewID");
    $rw  = sqlsrv_fetch_array($rID, SQLSRV_FETCH_ASSOC);
    $newID = $rw ? (int)$rw['NewID'] : 0;

    logAction($conn, 'DATA_CREATE', 'CFP_PositionVehicle', $newID, null, null, null, 'เพิ่ม: ' . $code . ' ' . $position);
    jsonOut(true, 'เพิ่มรถประจำตำแหน่ง "' . $position . '" เรียบร้อยแล้ว (รหัส ' . $code . ')');
}

/* ---------- UPDATE ---------- */
if ($action === 'update') {
    if ($id <= 0) { jsonOut(false, 'ไม่พบข้อมูลที่ต้องการแก้ไข'); }

    $res = sqlsrv_query($conn,
        "UPDATE CFP_PositionVehicle
         SET SiteID=?, Position=?, PlateNo=?, VehicleTypeID=?, FuelTypeID=?, FuelPricePerLiter=?, Remark=?,
             UpdatedBy=?, UpdatedDate=GETDATE()
         WHERE PositionVehicleID=?",
        array($siteID, $position, $plateNo ?: null, $vtID, $ftID, $price, $remark ?: null, $userID, $id));
    if (!$res) {
        $e = sqlsrv_errors();
        jsonOut(false, 'แก้ไขไม่สำเร็จ: ' . ($e[0]['message'] ?? ''));
    }
    logAction($conn, 'DATA_UPDATE', 'CFP_PositionVehicle', $id, null, null, null, 'แก้ไข: ' . $position);
    jsonOut(true, 'แก้ไขรถประจำตำแหน่ง "' . $position . '" เรียบร้อยแล้ว');
}

jsonOut(false, 'คำขอไม่ถูกต้อง');

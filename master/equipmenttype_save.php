<?php
/* master/equipmenttype_save.php */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

define('CODE_PREFIX', 'EQ');
requireRole(array(4, 5));
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

verifyCsrf();
$action = $_POST['action'] ?? '';

/* ===== toggle ===== */
if ($action === 'toggle') {
    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูล', 'error'); }
    $res = sqlsrv_query($conn, "SELECT IsActive, TypeName FROM CFP_EquipmentType WHERE TypeID=?", array($id));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบข้อมูล', 'error'); }
    $newStatus = $row['IsActive'] ? 0 : 1;
    sqlsrv_query($conn, "UPDATE CFP_EquipmentType SET IsActive=?,UpdatedBy=?,UpdatedDate=GETDATE() WHERE TypeID=?",
        array($newStatus, (int)$_SESSION['user_id'], $id));
    logAction($conn, 'DATA_UPDATE', 'CFP_EquipmentType', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['TypeName']);
    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อย' : 'ปิดใช้งานเรียบร้อย');
}

/* ===== delete ===== */
if ($action === 'delete') {
    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูล', 'error'); }
    $res = @sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_Equipment WHERE EquipmentTypeID=?", array($id));
    if ($res) {
        $chk = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
        if ($chk && $chk['Cnt'] > 0) {
            /* มีการใช้งานอยู่ → บล็อกการลบ ให้ผู้ใช้ไปกดปิดใช้งาน (Toggle) เองแทน */
            redirectWithToast('ไม่สามารถลบได้ เนื่องจากมีเครื่องจักร ' . (int)$chk['Cnt'] . ' รายการใช้ประเภทนี้อยู่ — กรุณาปิดใช้งานแทน หรือย้ายไปใช้ประเภทอื่นก่อน', 'error');
        }
    }
    $rDel = sqlsrv_query($conn, "DELETE FROM CFP_EquipmentType WHERE TypeID=?", array($id));
    if ($rDel === false) {
        $err = sqlsrv_errors();
        redirectWithToast('ไม่สามารถลบข้อมูลได้: ' . ($err[0]['message'] ?? 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'), 'error');
    }
    logAction($conn, 'DATA_DELETE', 'CFP_EquipmentType', $id);
    redirectWithToast('ลบข้อมูลเรียบร้อย');
}

/* ===== create / update ===== */
$name   = trim($_POST['TypeName']    ?? '');
$desc   = trim($_POST['Description'] ?? '');
$sort   = (int)($_POST['SortOrder']  ?? 99);
$active = isset($_POST['IsActive'])  ? (int)$_POST['IsActive'] : 1;

if ($name === '') { redirectWithToast('กรุณากรอกชื่อประเภทเครื่องจักร', 'error'); }

if ($action === 'create') {
    $code = generateTypeCode($conn, CODE_PREFIX);
    $sql  = "INSERT INTO CFP_EquipmentType (TypeCode,TypeName,Description,SortOrder,IsActive,CreatedBy,CreatedDate) VALUES (?,?,?,?,1,?,GETDATE())";
    $r    = sqlsrv_query($conn, $sql, array($code, $name, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']));
    if ($r === false) {
        $code = generateTypeCode($conn, CODE_PREFIX);
        $r    = sqlsrv_query($conn, $sql, array($code, $name, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']));
    }
    if ($r === false) { redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error'); }
    logAction($conn, 'DATA_CREATE', 'CFP_EquipmentType', null, null, null, null, 'เพิ่ม: '.$code.' '.$name);
    redirectWithToast('เพิ่มประเภทเครื่องจักรเรียบร้อย (รหัส '.$code.')');

} elseif ($action === 'update') {
    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }
    $r = sqlsrv_query($conn,
        "UPDATE CFP_EquipmentType SET TypeName=?,Description=?,SortOrder=?,IsActive=?,UpdatedBy=?,UpdatedDate=GETDATE() WHERE TypeID=?",
        array($name, ($desc !== '' ? $desc : null), $sort, $active, (int)$_SESSION['user_id'], $id));
    if ($r === false) { redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error'); }
    logAction($conn, 'DATA_UPDATE', 'CFP_EquipmentType', $id, null, null, null, 'แก้ไข: '.$name);
    redirectWithToast('บันทึกการแก้ไขเรียบร้อย');

} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}

<?php
/* ==============================================
   master/vehicletype_save.php
   รับ POST จาก vehicletype.php
   actions: create | update | delete | toggle
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

define('CODE_PREFIX', 'VT');

requireRole(array(4, 5));

$conn = getConnection();

/* ===== Helper: redirect พร้อม toast ===== */
function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: vehicletype.php');
    exit;
}

/* ===== Helper: generate รหัสอัตโนมัติ รูปแบบ VT-0001 ===== */
function generateTypeCode($conn, $prefix) {
    $res = sqlsrv_query($conn, "
        SELECT MAX(CAST(SUBSTRING(TypeCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
        FROM CFP_VehicleType
        WHERE TypeCode LIKE ? + '-%'",
        array($prefix, $prefix));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    $nextNum = ($row && $row['MaxNum'] !== null) ? ((int)$row['MaxNum'] + 1) : 1;
    return $prefix . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
}

/* ===========================
   POST: create / update / delete
   =========================== */
verifyCsrf();

$action = $_POST['action'] ?? '';

/* ===========================
   action=toggle (เปิด/ปิดใช้งาน)
   =========================== */
if ($action === 'toggle') {

    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลประเภทยานพาหนะ', 'error'); }

    $res = sqlsrv_query($conn, "SELECT IsActive, TypeName FROM CFP_VehicleType WHERE TypeID=?", array($id));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบข้อมูลประเภทยานพาหนะ', 'error'); }

    $newStatus = $row['IsActive'] ? 0 : 1;

    sqlsrv_query($conn, "UPDATE CFP_VehicleType SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE TypeID=?",
        array($newStatus, (int)$_SESSION['user_id'], $id));

    logAction($conn, 'DATA_UPDATE', 'CFP_VehicleType', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['TypeName']);

    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อยแล้ว' : 'ปิดใช้งานเรียบร้อยแล้ว');
}

/* ===========================
   action=delete
   =========================== */
if ($action === 'delete') {

    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลประเภทยานพาหนะ', 'error'); }

    /* ตรวจว่ามีการใช้งานอยู่ใน CFP_Vehicle หรือ CFP_Employee หรือไม่ (เช็คผ่าน FK VehicleTypeID) */
    $res = sqlsrv_query($conn, "
        SELECT
            (SELECT COUNT(*) FROM CFP_Vehicle WHERE VehicleTypeID = ?) +
            (SELECT COUNT(*) FROM CFP_Employee WHERE VehicleTypeID = ?) AS Cnt",
        array($id, $id));
    $chk = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);

    if ($chk && $chk['Cnt'] > 0) {
        /* มีการใช้งานอยู่ → Soft delete (ปิดใช้งาน) แทนการลบจริง */
        sqlsrv_query($conn, "UPDATE CFP_VehicleType SET IsActive=0, UpdatedBy=?, UpdatedDate=GETDATE() WHERE TypeID=?",
            array((int)$_SESSION['user_id'], $id));
        logAction($conn, 'DATA_UPDATE', 'CFP_VehicleType', $id, null, null, null, 'ปิดใช้งาน (มีการใช้งานอยู่)');
        redirectWithToast('มียานพาหนะ/พนักงานใช้ประเภทนี้อยู่ ระบบปิดใช้งานให้แทนการลบ');
    }

    $rDel = sqlsrv_query($conn, "DELETE FROM CFP_VehicleType WHERE TypeID=?", array($id));
    if ($rDel === false) {
        $err = sqlsrv_errors();
        redirectWithToast('ไม่สามารถลบข้อมูลได้: ' . ($err[0]['message'] ?? 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'), 'error');
    }
    logAction($conn, 'DATA_DELETE', 'CFP_VehicleType', $id);
    redirectWithToast('ลบข้อมูลเรียบร้อยแล้ว');
}

/* ===========================
   action=create / update (ตัวแปรฟอร์มอื่นๆ)
   =========================== */
$name   = trim($_POST['TypeName'] ?? '');
$desc   = trim($_POST['Description'] ?? '');
$sort   = (int)($_POST['SortOrder'] ?? 99);
$active = isset($_POST['IsActive']) ? (int)$_POST['IsActive'] : 1;

/* ===== Validate ===== */
if ($name === '') {
    redirectWithToast('กรุณากรอกชื่อประเภทให้ครบถ้วน', 'error');
}

if ($action === 'create') {

    /* รหัสสร้างโดยระบบเสมอ ไม่รับค่าจาก Form */
    $code = generateTypeCode($conn, CODE_PREFIX);

    $sql = "INSERT INTO CFP_VehicleType
            (TypeCode, TypeName, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, 1, ?, GETDATE())";
    $r = sqlsrv_query($conn, $sql, array(
        $code, $name, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']
    ));

    /* กรณีชนกัน (race condition) ลองสร้างรหัสใหม่อีกครั้งเดียว */
    if ($r === false) {
        $code = generateTypeCode($conn, CODE_PREFIX);
        $r = sqlsrv_query($conn, $sql, array(
            $code, $name, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']
        ));
    }

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก กรุณาลองใหม่อีกครั้ง', 'error');
    }

    logAction($conn, 'DATA_CREATE', 'CFP_VehicleType', null, null, null, null,
        'เพิ่มประเภทยานพาหนะ: ' . $code . ' - ' . $name);
    redirectWithToast('เพิ่มประเภทยานพาหนะเรียบร้อยแล้ว (รหัส ' . $code . ')');
} elseif ($action === 'update') {

    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }

    /* รหัสไม่ให้แก้ไขหลังสร้างแล้ว — UPDATE เฉพาะฟิลด์อื่น */
    $sql = "UPDATE CFP_VehicleType
            SET TypeName=?, Description=?, SortOrder=?, IsActive=?,
                UpdatedBy=?, UpdatedDate=GETDATE()
            WHERE TypeID=?";
    $r = sqlsrv_query($conn, $sql, array(
        $name, ($desc !== '' ? $desc : null), $sort, $active,
        (int)$_SESSION['user_id'], $id
    ));

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error');
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_VehicleType', $id, null, null, null,
        'แก้ไขประเภทยานพาหนะ: ' . $name);
    redirectWithToast('บันทึกการแก้ไขเรียบร้อยแล้ว');

} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}

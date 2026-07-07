<?php
/* ==============================================
   master/flourwastetype_save.php
   รับ POST จาก flourwastetype.php
   actions: create | update | delete | toggle
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

define('CODE_PREFIX', 'FWT');

requireRole(array(4, 5));

$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: flourwastetype.php');
    exit;
}

/* ===== Helper: generate รหัสอัตโนมัติ พร้อม error handling ===== */
function generateTypeCode($conn, $prefix) {
    $sql = "
        SELECT MAX(CAST(SUBSTRING(TypeCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
        FROM CFP_FlourWasteType
        WHERE TypeCode LIKE ? + '-%'";
    
    $res = sqlsrv_query($conn, $sql, array($prefix, $prefix));
    
    // ✅ ถ้า query ล้มเหลว (เช่น ตารางยังไม่มี หรือ syntax error) ให้คืนค่ารหัสเริ่มต้น
    if ($res === false) {
        return $prefix . '-0001';
    }
    
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    $nextNum = ($row && $row['MaxNum'] !== null) ? ((int)$row['MaxNum'] + 1) : 1;
    return $prefix . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
}

verifyCsrf();

$action = $_POST['action'] ?? '';

/* ===========================
   action=toggle (เปิด/ปิดใช้งาน)
   =========================== */
if ($action === 'toggle') {

    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลประเภทการกำจัดเศษแป้ง', 'error'); }

    $sql = "SELECT IsActive, TypeName FROM CFP_FlourWasteType WHERE TypeID = ?";
    $res = sqlsrv_query($conn, $sql, array($id));

    // ✅ ตรวจสอบ query
    if ($res === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการดึงข้อมูล', 'error');
    }

    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบข้อมูลประเภทการกำจัดเศษแป้ง', 'error'); }

    $newStatus = $row['IsActive'] ? 0 : 1;

    $updateSql = "UPDATE CFP_FlourWasteType SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE TypeID=?";
    $updateRes = sqlsrv_query($conn, $updateSql, array($newStatus, (int)$_SESSION['user_id'], $id));

    if ($updateRes === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการอัปเดตสถานะ', 'error');
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_FlourWasteType', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['TypeName']);

    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อยแล้ว' : 'ปิดใช้งานเรียบร้อยแล้ว');
}

/* ===========================
   action=delete
   =========================== */
if ($action === 'delete') {

    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลประเภทการกำจัดเศษแป้ง', 'error'); }

    /* ✅ ถ้าไม่มีตาราง CFP_FlourWaste ให้ข้ามการตรวจสอบ */
    $hasUsage = false;
    $sql = "SELECT COUNT(*) AS Cnt FROM CFP_FlourWaste WHERE FlourWasteTypeID = ?";
    $res = sqlsrv_query($conn, $sql, array($id));

    if ($res !== false) {
        $chk = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
        $hasUsage = ($chk && $chk['Cnt'] > 0);
    }
    // ถ้า $res === false → ตารางไม่มี → ถือว่าไม่มีการใช้งาน

    if ($hasUsage) {
        /* มีการใช้งานอยู่ → Soft delete (ปิดใช้งาน) แทนการลบจริง */
        $updateSql = "UPDATE CFP_FlourWasteType SET IsActive=0, UpdatedBy=?, UpdatedDate=GETDATE() WHERE TypeID=?";
        $updateRes = sqlsrv_query($conn, $updateSql, array((int)$_SESSION['user_id'], $id));
        if ($updateRes === false) {
            redirectWithToast('เกิดข้อผิดพลาดในการปิดใช้งาน', 'error');
        }
        logAction($conn, 'DATA_UPDATE', 'CFP_FlourWasteType', $id, null, null, null, 'ปิดใช้งาน (มีการใช้งานอยู่)');
        redirectWithToast('มีข้อมูลเศษแป้งใช้ประเภทนี้อยู่ ระบบปิดใช้งานให้แทนการลบ');
    }

    $delSql = "DELETE FROM CFP_FlourWasteType WHERE TypeID=?";
    $delRes = sqlsrv_query($conn, $delSql, array($id));

    if ($delRes === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการลบข้อมูล', 'error');
    }

    logAction($conn, 'DATA_DELETE', 'CFP_FlourWasteType', $id);
    redirectWithToast('ลบข้อมูลเรียบร้อยแล้ว');
}

/* ===========================
   action=create / update
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

    $sql = "INSERT INTO CFP_FlourWasteType
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

    logAction($conn, 'DATA_CREATE', 'CFP_FlourWasteType', null, null, null, null,
        'เพิ่มประเภทการกำจัดเศษแป้ง: ' . $code . ' - ' . $name);
    redirectWithToast('เพิ่มประเภทการกำจัดเศษแป้งเรียบร้อยแล้ว (รหัส ' . $code . ')');

} elseif ($action === 'update') {

    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }

    /* รหัสไม่ให้แก้ไขหลังสร้างแล้ว — UPDATE เฉพาะฟิลด์อื่น */
    $sql = "UPDATE CFP_FlourWasteType
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

    logAction($conn, 'DATA_UPDATE', 'CFP_FlourWasteType', $id, null, null, null,
        'แก้ไขประเภทการกำจัดเศษแป้ง: ' . $name);
    redirectWithToast('บันทึกการแก้ไขเรียบร้อยแล้ว');

} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}
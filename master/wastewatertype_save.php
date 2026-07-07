<?php
/* ==============================================
   master/wastewatertype_save.php
   รับ POST จาก wastewatertype.php
   actions: create | update | delete | toggle
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

define('CODE_PREFIX', 'WWT');

requireRole(array(4, 5));

$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: wastewatertype.php');
    exit;
}

verifyCsrf();

$action = $_POST['action'] ?? '';

/* ===========================
   action=toggle (เปิด/ปิดใช้งาน)
   =========================== */
if ($action === 'toggle') {

    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลประเภทการปล่อยน้ำเสีย', 'error'); }

    $sql = "SELECT IsActive, TypeName FROM CFP_WastewaterType WHERE TypeID = ?";
    $res = sqlsrv_query($conn, $sql, array($id));

    // ✅ ตรวจสอบ error
    if ($res === false) {
        $errors = sqlsrv_errors();
        $msg = 'เกิดข้อผิดพลาดในการดึงข้อมูล: ' . print_r($errors, true);
        redirectWithToast($msg, 'error');
    }

    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบข้อมูลประเภทการปล่อยน้ำเสีย', 'error'); }

    $newStatus = $row['IsActive'] ? 0 : 1;

    $updateSql = "UPDATE CFP_WastewaterType SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE TypeID=?";
    $updateRes = sqlsrv_query($conn, $updateSql, array($newStatus, (int)$_SESSION['user_id'], $id));

    if ($updateRes === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการอัปเดตสถานะ', 'error');
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_WastewaterType', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['TypeName']);

    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อยแล้ว' : 'ปิดใช้งานเรียบร้อยแล้ว');
}

/* ===========================
   action=delete
   =========================== */
if ($action === 'delete') {

    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลประเภทการปล่อยน้ำเสีย', 'error'); }

    /* ✅ ถ้าไม่มีตาราง CFP_Wastewater ให้ข้ามการตรวจสอบ */
    $hasUsage = false;
    $sql = "SELECT COUNT(*) AS Cnt FROM CFP_Wastewater WHERE WastewaterTypeID = ?";
    $res = sqlsrv_query($conn, $sql, array($id));

    if ($res !== false) {
        $chk = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
        $hasUsage = ($chk && $chk['Cnt'] > 0);
    }
    // ถ้า $res === false → ตารางไม่มี → ถือว่าไม่มีการใช้งาน

    if ($hasUsage) {
        // มีการใช้งาน → ปิดใช้งานแทนการลบ
        $updateSql = "UPDATE CFP_WastewaterType SET IsActive=0, UpdatedBy=?, UpdatedDate=GETDATE() WHERE TypeID=?";
        $updateRes = sqlsrv_query($conn, $updateSql, array((int)$_SESSION['user_id'], $id));
        logAction($conn, 'DATA_UPDATE', 'CFP_WastewaterType', $id, null, null, null, 'ปิดใช้งาน (มีการใช้งานอยู่)');
        redirectWithToast('มีข้อมูลน้ำเสียใช้ประเภทนี้อยู่ ระบบปิดใช้งานให้แทนการลบ');
    }

    // ลบจริง
    $delSql = "DELETE FROM CFP_WastewaterType WHERE TypeID=?";
    $delRes = sqlsrv_query($conn, $delSql, array($id));

    if ($delRes === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการลบข้อมูล', 'error');
    }

    logAction($conn, 'DATA_DELETE', 'CFP_WastewaterType', $id);
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

/* ===========================
   action=create (Manual Code)
   =========================== */
if ($action === 'create') {

    $code = trim($_POST['TypeCode'] ?? '');
    if ($code === '') {
        redirectWithToast('กรุณากรอกรหัสประเภทการปล่อยน้ำเสีย', 'error');
    }

    // ตรวจสอบรหัสซ้ำ
    $checkSql = "SELECT COUNT(*) AS Cnt FROM CFP_WastewaterType WHERE TypeCode = ?";
    $checkRes = sqlsrv_query($conn, $checkSql, array($code));
    if ($checkRes === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการตรวจสอบรหัส', 'error');
    }
    $chkRow = sqlsrv_fetch_array($checkRes, SQLSRV_FETCH_ASSOC);
    if ($chkRow && $chkRow['Cnt'] > 0) {
        redirectWithToast('รหัสนี้มีอยู่แล้วในระบบ กรุณาใช้รหัสอื่น', 'error');
    }

    $sql = "INSERT INTO CFP_WastewaterType
            (TypeCode, TypeName, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, 1, ?, GETDATE())";
    $r = sqlsrv_query($conn, $sql, array(
        $code, $name, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']
    ));

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error');
    }

    logAction($conn, 'DATA_CREATE', 'CFP_WastewaterType', null, null, null, null,
        'เพิ่มประเภทการปล่อยน้ำเสีย: ' . $code . ' - ' . $name);
    redirectWithToast('เพิ่มประเภทการปล่อยน้ำเสียเรียบร้อยแล้ว (รหัส ' . $code . ')');

/* ===========================
   action=update
   =========================== */
} elseif ($action === 'update') {

    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }

    /* รหัสไม่ให้แก้ไขหลังสร้างแล้ว — UPDATE เฉพาะฟิลด์อื่น */
    $sql = "UPDATE CFP_WastewaterType
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

    logAction($conn, 'DATA_UPDATE', 'CFP_WastewaterType', $id, null, null, null,
        'แก้ไขประเภทการปล่อยน้ำเสีย: ' . $name);
    redirectWithToast('บันทึกการแก้ไขเรียบร้อยแล้ว');

} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}
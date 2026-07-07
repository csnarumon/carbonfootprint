<?php
/* ==============================================
   master/electrictype_save.php
   รับ POST จาก electrictype.php
   actions: create | update | delete | toggle
   หมายเหตุ: ตาราง CFP_ElectricSourceType ใช้ Primary Key ชื่อ SourceID
   แต่คอลัมน์ข้อมูลใช้ TypeCode/TypeName (ผสมกัน)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

define('CODE_PREFIX', 'ES');

requireRole(array(4, 5));

$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: electrictype.php');
    exit;
}

/* ===== Helper: generate รหัสอัตโนมัติ รูปแบบ ES-0001 ===== */
function generateSourceCode($conn, $prefix) {
    $res = sqlsrv_query($conn, "
        SELECT MAX(CAST(SUBSTRING(SourceCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
        FROM CFP_ElectricSourceType
        WHERE SourceCode LIKE ? + '-%'",
        array($prefix, $prefix));
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

    $id = (int)($_POST['SourceID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลแหล่งไฟฟ้า', 'error'); }

    $res = sqlsrv_query($conn, "SELECT IsActive, SourceName FROM CFP_ElectricSourceType WHERE SourceID=?", array($id));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบข้อมูลแหล่งไฟฟ้า', 'error'); }

    $newStatus = $row['IsActive'] ? 0 : 1;

    sqlsrv_query($conn, "UPDATE CFP_ElectricSourceType SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE SourceID=?",
        array($newStatus, (int)$_SESSION['user_id'], $id));

    logAction($conn, 'DATA_UPDATE', 'CFP_ElectricSourceType', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['SourceName']);

    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อยแล้ว' : 'ปิดใช้งานเรียบร้อยแล้ว');
}

/* ===========================
   action=delete
   =========================== */
if ($action === 'delete') {

    $id = (int)($_POST['SourceID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลแหล่งไฟฟ้า', 'error'); }

    /* ตรวจว่ามีการใช้งานอยู่ใน CFP_ElectricMeter หรือไม่ (เช็คผ่าน FK ElectricSourceID)
       หมายเหตุ: ใช้ @ กันไว้เผื่อคอลัมน์/ตารางยังไม่ตรงกับที่คาดไว้ ไม่ให้ทั้งหน้า error */
    $hasUsage = false;
    $res = @sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_ElectricMeter WHERE ElectricSourceID = ?", array($id));
    if ($res !== false) {
        $chk = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
        $hasUsage = ($chk && $chk['Cnt'] > 0);
    }

    if ($hasUsage) {
        /* มีการใช้งานอยู่ → Soft delete (ปิดใช้งาน) แทนการลบจริง */ 
        sqlsrv_query($conn, "UPDATE CFP_ElectricSourceType SET IsActive=0, UpdatedBy=?, UpdatedDate=GETDATE() WHERE SourceID=?",
            array((int)$_SESSION['user_id'], $id));
        logAction($conn, 'DATA_UPDATE', 'CFP_ElectricSourceType', $id, null, null, null, 'ปิดใช้งาน (มีการใช้งานอยู่)');
        redirectWithToast('มีมิเตอร์ไฟฟ้าใช้แหล่งไฟฟ้านี้อยู่ ระบบปิดใช้งานให้แทนการลบ');
    }

    $rDel = sqlsrv_query($conn, "DELETE FROM CFP_ElectricSourceType WHERE SourceID=?", array($id));
    if ($rDel === false) {
        $err = sqlsrv_errors();
        redirectWithToast('ไม่สามารถลบข้อมูลได้: ' . ($err[0]['message'] ?? 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'), 'error');
    }
    logAction($conn, 'DATA_DELETE', 'CFP_ElectricSourceType', $id);
    redirectWithToast('ลบข้อมูลเรียบร้อยแล้ว');
}

/* ===========================
   action=create / update
   =========================== */
$name   = trim($_POST['SourceName'] ?? '');
$grid   = trim($_POST['GridFactor'] ?? '');
$desc   = trim($_POST['Description'] ?? '');
$sort   = (int)($_POST['SortOrder'] ?? 99);
$active = isset($_POST['IsActive']) ? (int)$_POST['IsActive'] : 1;

/* ===== Validate ===== */
if ($name === '') {
    redirectWithToast('กรุณากรอกชื่อแหล่งไฟฟ้าให้ครบถ้วน', 'error');
}
$gridValue = ($grid !== '' && is_numeric($grid)) ? (float)$grid : null;

if ($action === 'create') {

    $code = trim($_POST['SourceCode'] ?? '');
    if ($code === '') {
        redirectWithToast('กรุณากรอกรหัสแหล่งไฟฟ้า', 'error');
    }

    // ตรวจสอบรหัสซ้ำ
    $checkSql = "SELECT COUNT(*) AS Cnt FROM CFP_ElectricSourceType WHERE SourceCode = ?";
    $checkRes = sqlsrv_query($conn, $checkSql, array($code));
    if ($checkRes === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการตรวจสอบรหัส', 'error');
    }
    $chkRow = sqlsrv_fetch_array($checkRes, SQLSRV_FETCH_ASSOC);
    if ($chkRow && $chkRow['Cnt'] > 0) {
        redirectWithToast('รหัสนี้มีอยู่แล้วในระบบ กรุณาใช้รหัสอื่น', 'error');
    }

    // ตรวจสอบชื่อซ้ำ (มีอยู่แล้วในโค้ดเดิม)
    $resDup = sqlsrv_query($conn, "SELECT SourceID FROM CFP_ElectricSourceType WHERE SourceName=?", array($name));
    if (sqlsrv_fetch_array($resDup, SQLSRV_FETCH_ASSOC)) {
        redirectWithToast('ชื่อแหล่งไฟฟ้านี้มีอยู่แล้วในระบบ', 'error');
    }

    $sql = "INSERT INTO CFP_ElectricSourceType
            (SourceCode, SourceName, GridFactor, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, ?, 1, ?, GETDATE())";
    $r = sqlsrv_query($conn, $sql, array(
        $code, $name, $gridValue, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']
    ));

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error');
    }

    logAction($conn, 'DATA_CREATE', 'CFP_ElectricSourceType', null, null, null, null,
        'เพิ่มแหล่งไฟฟ้า: ' . $code . ' - ' . $name);
    redirectWithToast('เพิ่มแหล่งไฟฟ้าเรียบร้อยแล้ว (รหัส ' . $code . ')');
} elseif ($action === 'update') {

    $id = (int)($_POST['SourceID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }

    /* เช็คชื่อซ้ำกับรายการอื่น (ไม่นับตัวเอง) */
    $resDup = sqlsrv_query($conn, "SELECT SourceID FROM CFP_ElectricSourceType WHERE SourceName=? AND SourceID<>?", array($name, $id));
    if (sqlsrv_fetch_array($resDup, SQLSRV_FETCH_ASSOC)) {
        redirectWithToast('ชื่อแหล่งไฟฟ้านี้มีอยู่แล้วในระบบ', 'error');
    }

    /* รหัสไม่ให้แก้ไขหลังสร้างแล้ว — UPDATE เฉพาะฟิลด์อื่น */
    $sql = "UPDATE CFP_ElectricSourceType
            SET SourceName=?, GridFactor=?, Description=?, SortOrder=?, IsActive=?,
                UpdatedBy=?, UpdatedDate=GETDATE()
            WHERE SourceID=?";
    $r = sqlsrv_query($conn, $sql, array(
        $name, $gridValue, ($desc !== '' ? $desc : null), $sort, $active,
        (int)$_SESSION['user_id'], $id
    ));

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error');
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_ElectricSourceType', $id, null, null, null,
        'แก้ไขแหล่งไฟฟ้า: ' . $name);
    redirectWithToast('บันทึกการแก้ไขเรียบร้อยแล้ว');

} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}

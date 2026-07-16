<?php
/* ==============================================
   master/wastedisposalmethod_save.php
   รับ POST จาก wastedisposalmethod.php
   actions: create | update | delete | toggle
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

define('CODE_PREFIX', 'WDM');

requireRole(array(4, 5));

$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: wastedisposalmethod.php');
    exit;
}

/* ===== Helper: generate รหัสอัตโนมัติ พร้อม error handling ===== */
function generateMethodCode($conn, $prefix) {
    $sql = "
        SELECT MAX(CAST(SUBSTRING(MethodCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
        FROM CFP_WasteDisposalMethod
        WHERE MethodCode LIKE ? + '-%'";
    
    $res = sqlsrv_query($conn, $sql, array($prefix, $prefix));
    
    // ✅ ถ้า query ล้มเหลว (ตารางยังไม่มี) ให้คืนค่ารหัสเริ่มต้น
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

    $id = (int)($_POST['MethodID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลวิธีกำจัดขยะ', 'error'); }

    $sql = "SELECT IsActive, MethodName FROM CFP_WasteDisposalMethod WHERE MethodID = ?";
    $res = sqlsrv_query($conn, $sql, array($id));

    // ✅ ตรวจสอบ error
    if ($res === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการดึงข้อมูล', 'error');
    }

    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบข้อมูลวิธีกำจัดขยะ', 'error'); }

    $newStatus = $row['IsActive'] ? 0 : 1;

    $updateSql = "UPDATE CFP_WasteDisposalMethod SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE MethodID=?";
    $updateRes = sqlsrv_query($conn, $updateSql, array($newStatus, (int)$_SESSION['user_id'], $id));

    if ($updateRes === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการอัปเดตสถานะ', 'error');
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_WasteDisposalMethod', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['MethodName']);

    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อยแล้ว' : 'ปิดใช้งานเรียบร้อยแล้ว');
}

/* ===========================
   action=delete
   =========================== */
if ($action === 'delete') {

    $id = (int)($_POST['MethodID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลวิธีกำจัดขยะ', 'error'); }

    /* ✅ ถ้าไม่มีตาราง CFP_Waste ให้ข้ามการตรวจสอบ */
    $usageCnt = 0;
    $sql = "SELECT COUNT(*) AS Cnt FROM CFP_Waste WHERE DisposalMethodID = ?";
    $res = sqlsrv_query($conn, $sql, array($id));

    if ($res !== false) {
        $chk = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
        $usageCnt = $chk ? (int)$chk['Cnt'] : 0;
    }
    // ถ้า $res === false → ตารางไม่มี → ถือว่าไม่มีการใช้งาน

    if ($usageCnt > 0) {
        /* มีการใช้งานอยู่ → บล็อกการลบ ให้ผู้ใช้ไปกดปิดใช้งาน (Toggle) เองแทน */
        redirectWithToast('ไม่สามารถลบได้ เนื่องจากมีข้อมูลขยะ ' . $usageCnt . ' รายการใช้วิธีนี้อยู่ — กรุณาปิดใช้งานแทน หรือย้ายไปใช้วิธีอื่นก่อน', 'error');
    }

    $delSql = "DELETE FROM CFP_WasteDisposalMethod WHERE MethodID=?";
    $delRes = sqlsrv_query($conn, $delSql, array($id));

    if ($delRes === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการลบข้อมูล', 'error');
    }

    logAction($conn, 'DATA_DELETE', 'CFP_WasteDisposalMethod', $id);
    redirectWithToast('ลบข้อมูลเรียบร้อยแล้ว');
}

/* ===========================
   action=create / update (ตัวแปรฟอร์มอื่นๆ)
   =========================== */
$name   = trim($_POST['MethodName'] ?? '');
$desc   = trim($_POST['Description'] ?? '');
$sort   = (int)($_POST['SortOrder'] ?? 99);
$active = isset($_POST['IsActive']) ? (int)$_POST['IsActive'] : 1;

/* ===== Validate ===== */
if ($name === '') {
    redirectWithToast('กรุณากรอกชื่อประเภทให้ครบถ้วน', 'error');
}

if ($action === 'create') {

    /* รหัสสร้างโดยระบบเสมอ ไม่รับค่าจาก Form */
    $code = generateMethodCode($conn, CODE_PREFIX);

    $sql = "INSERT INTO CFP_WasteDisposalMethod
            (MethodCode, MethodName, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, 1, ?, GETDATE())";
    $r = sqlsrv_query($conn, $sql, array(
        $code, $name, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']
    ));

    /* กรณีชนกัน (race condition) ลองสร้างรหัสใหม่อีกครั้งเดียว */
    if ($r === false) {
        $code = generateMethodCode($conn, CODE_PREFIX);
        $r = sqlsrv_query($conn, $sql, array(
            $code, $name, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']
        ));
    }

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error');
    }

    logAction($conn, 'DATA_CREATE', 'CFP_WasteDisposalMethod', null, null, null, null,
        'เพิ่มวิธีกำจัดขยะ: ' . $code . ' - ' . $name);
    redirectWithToast('เพิ่มวิธีกำจัดขยะเรียบร้อยแล้ว (รหัส ' . $code . ')');

} elseif ($action === 'update') {

    $id = (int)($_POST['MethodID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }

    /* รหัสไม่ให้แก้ไขหลังสร้างแล้ว — UPDATE เฉพาะฟิลด์อื่น */
    $sql = "UPDATE CFP_WasteDisposalMethod
            SET MethodName=?, Description=?, SortOrder=?, IsActive=?,
                UpdatedBy=?, UpdatedDate=GETDATE()
            WHERE MethodID=?";
    $r = sqlsrv_query($conn, $sql, array(
        $name, ($desc !== '' ? $desc : null), $sort, $active,
        (int)$_SESSION['user_id'], $id
    ));

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error');
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_WasteDisposalMethod', $id, null, null, null,
        'แก้ไขวิธีกำจัดขยะ: ' . $name);
    redirectWithToast('บันทึกการแก้ไขเรียบร้อยแล้ว');

} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}
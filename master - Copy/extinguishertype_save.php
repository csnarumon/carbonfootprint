<?php
/* ==============================================
   master/extinguishertype_save.php
   รับ POST จาก extinguishertype.php
   actions: create | update | delete | toggle
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

define('CODE_PREFIX', 'EX');

requireRole(array(4, 5));

$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: extinguishertype.php');
    exit;
}

/* ===== Helper: generate รหัสอัตโนมัติ รูปแบบ EX-0001 ===== */
function generateTypeCode($conn, $prefix) {
    $res = sqlsrv_query($conn, "
        SELECT MAX(CAST(SUBSTRING(TypeCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
        FROM CFP_ExtinguisherType
        WHERE TypeCode LIKE ? + '-%'",
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

    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลประเภทสารดับเพลิง', 'error'); }

    $sql = "SELECT IsActive, TypeName FROM CFP_ExtinguisherType WHERE TypeID = ?";
    $res = sqlsrv_query($conn, $sql, array($id));

    if ($res === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการดึงข้อมูล', 'error');
    }

    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบข้อมูลประเภทสารดับเพลิง', 'error'); }

    $newStatus = $row['IsActive'] ? 0 : 1;

    $updateSql = "UPDATE CFP_ExtinguisherType SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE TypeID=?";
    $updateRes = sqlsrv_query($conn, $updateSql, array($newStatus, (int)$_SESSION['user_id'], $id));

    if ($updateRes === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการอัปเดตสถานะ', 'error');
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_ExtinguisherType', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['TypeName']);

    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อยแล้ว' : 'ปิดใช้งานเรียบร้อยแล้ว');
}

/* ===========================
   action=delete
   =========================== */
if ($action === 'delete') {

    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลประเภทสารดับเพลิง', 'error'); }

    /* ✅ ถ้าไม่มีตาราง CFP_Extinguisher ให้ข้ามการตรวจสอบ */
    $usageCnt = 0;
    $sql = "SELECT COUNT(*) AS Cnt FROM CFP_Extinguisher WHERE ExtinguisherTypeID = ?";
    $res = sqlsrv_query($conn, $sql, array($id));

    if ($res !== false) {
        $chk = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
        $usageCnt = $chk ? (int)$chk['Cnt'] : 0;
    }
    // ถ้า $res === false → ตารางไม่มี → ถือว่าไม่มีการใช้งาน

    if ($usageCnt > 0) {
        /* มีการใช้งานอยู่ → บล็อกการลบ ให้ผู้ใช้ไปกดปิดใช้งาน (Toggle) เองแทน */
        redirectWithToast('ไม่สามารถลบได้ เนื่องจากมีถังดับเพลิง ' . $usageCnt . ' รายการใช้ประเภทนี้อยู่ — กรุณาปิดใช้งานแทน หรือย้ายไปใช้ประเภทอื่นก่อน', 'error');
    }

    $delSql = "DELETE FROM CFP_ExtinguisherType WHERE TypeID=?";
    $delRes = sqlsrv_query($conn, $delSql, array($id));

    if ($delRes === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการลบข้อมูล', 'error');
    }

    logAction($conn, 'DATA_DELETE', 'CFP_ExtinguisherType', $id);
    redirectWithToast('ลบข้อมูลเรียบร้อยแล้ว');
}

/* ===========================
   action=create / update
   =========================== */
$name   = trim($_POST['TypeName'] ?? '');
$gwp    = trim($_POST['GWP100'] ?? '');
$desc   = trim($_POST['Description'] ?? '');
$sort   = (int)($_POST['SortOrder'] ?? 99);
$active = isset($_POST['IsActive']) ? (int)$_POST['IsActive'] : 1;

/* ===== Validate ===== */
if ($name === '') {
    redirectWithToast('กรุณากรอกชื่อประเภทให้ครบถ้วน', 'error');
}
$gwpValue = ($gwp !== '' && is_numeric($gwp)) ? (float)$gwp : null;

/* ===========================
   action=create (Auto Code)
   =========================== */
if ($action === 'create') {

    /* รหัสสร้างโดยระบบเสมอ ไม่รับค่าจาก Form */
    $code = generateTypeCode($conn, CODE_PREFIX);

    $sql = "INSERT INTO CFP_ExtinguisherType
            (TypeCode, TypeName, GWP100, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, ?, 1, ?, GETDATE())";
    $r = sqlsrv_query($conn, $sql, array(
        $code, $name, $gwpValue, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']
    ));

    /* กรณีชนกัน (race condition) ลองสร้างรหัสใหม่อีกครั้งเดียว */
    if ($r === false) {
        $code = generateTypeCode($conn, CODE_PREFIX);
        $r = sqlsrv_query($conn, $sql, array(
            $code, $name, $gwpValue, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']
        ));
    }

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error');
    }

    logAction($conn, 'DATA_CREATE', 'CFP_ExtinguisherType', null, null, null, null,
        'เพิ่มประเภทสารดับเพลิง: ' . $code . ' - ' . $name);
    redirectWithToast('เพิ่มประเภทสารดับเพลิงเรียบร้อยแล้ว (รหัส ' . $code . ')');

/* ===========================
   action=update
   =========================== */
} elseif ($action === 'update') {

    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }

    /* รหัสไม่ให้แก้ไขหลังสร้างแล้ว — UPDATE เฉพาะฟิลด์อื่น */
    $sql = "UPDATE CFP_ExtinguisherType
            SET TypeName=?, GWP100=?, Description=?, SortOrder=?, IsActive=?,
                UpdatedBy=?, UpdatedDate=GETDATE()
            WHERE TypeID=?";
    $r = sqlsrv_query($conn, $sql, array(
        $name, $gwpValue, ($desc !== '' ? $desc : null), $sort, $active,
        (int)$_SESSION['user_id'], $id
    ));

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error');
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_ExtinguisherType', $id, null, null, null,
        'แก้ไขประเภทสารดับเพลิง: ' . $name);
    redirectWithToast('บันทึกการแก้ไขเรียบร้อยแล้ว');

} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}
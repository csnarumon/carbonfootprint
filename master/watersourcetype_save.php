<?php
/* ==============================================
   master/watersourcetype_save.php
   รับ POST จาก watersourcetype.php
   actions: create | update | delete | toggle
   หมายเหตุ: ตาราง CFP_WaterSourceType ใช้ SourceID/SourceCode/SourceName
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

define('CODE_PREFIX', 'WS');

requireRole(array(4, 5));

$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: watersourcetype.php');
    exit;
}

/* ===== Helper: generate รหัสอัตโนมัติ รูปแบบ WS-0001 ===== */
function generateSourceCode($conn, $prefix) {
    $res = sqlsrv_query($conn, "
        SELECT MAX(CAST(SUBSTRING(SourceCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
        FROM CFP_WaterSourceType
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
    if (!$id) { redirectWithToast('ไม่พบข้อมูลแหล่งน้ำ', 'error'); }

    $res = sqlsrv_query($conn, "SELECT IsActive, SourceName FROM CFP_WaterSourceType WHERE SourceID=?", array($id));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบข้อมูลแหล่งน้ำ', 'error'); }

    $newStatus = $row['IsActive'] ? 0 : 1;

    sqlsrv_query($conn, "UPDATE CFP_WaterSourceType SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE SourceID=?",
        array($newStatus, (int)$_SESSION['user_id'], $id));

    logAction($conn, 'DATA_UPDATE', 'CFP_WaterSourceType', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['SourceName']);

    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อยแล้ว' : 'ปิดใช้งานเรียบร้อยแล้ว');
}

/* ===========================
   action=delete
   =========================== */
if ($action === 'delete') {

    $id = (int)($_POST['SourceID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลแหล่งน้ำ', 'error'); }

    /* ตรวจว่ามีการใช้งานอยู่ใน CFP_WaterMeter หรือไม่ (เช็คผ่าน FK WaterSourceID) */
    $res = sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_WaterMeter WHERE WaterSourceID = ?", array($id));
    $chk = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);

    if ($chk && $chk['Cnt'] > 0) {
        /* มีการใช้งานอยู่ → บล็อกการลบ ให้ผู้ใช้ไปกดปิดใช้งาน (Toggle) เองแทน */
        redirectWithToast('ไม่สามารถลบได้ เนื่องจากมีมิเตอร์น้ำ ' . (int)$chk['Cnt'] . ' รายการใช้แหล่งน้ำนี้อยู่ — กรุณาปิดใช้งานแทน หรือย้ายไปใช้แหล่งอื่นก่อน', 'error');
    }

    $rDel = sqlsrv_query($conn, "DELETE FROM CFP_WaterSourceType WHERE SourceID=?", array($id));
    if ($rDel === false) {
        $err = sqlsrv_errors();
        redirectWithToast('ไม่สามารถลบข้อมูลได้: ' . ($err[0]['message'] ?? 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'), 'error');
    }
    logAction($conn, 'DATA_DELETE', 'CFP_WaterSourceType', $id);
    redirectWithToast('ลบข้อมูลเรียบร้อยแล้ว');
}

/* ===========================
   action=create / update
   =========================== */
$name   = trim($_POST['SourceName'] ?? '');
$desc   = trim($_POST['Description'] ?? '');
$sort   = (int)($_POST['SortOrder'] ?? 99);
$active = isset($_POST['IsActive']) ? (int)$_POST['IsActive'] : 1;

/* ===== Validate ===== */
if ($name === '') {
    redirectWithToast('กรุณากรอกชื่อแหล่งน้ำให้ครบถ้วน', 'error');
}

if ($action === 'create') {

    /* รหัสสร้างโดยระบบเสมอ ไม่รับค่าจาก Form */
    $code = generateSourceCode($conn, CODE_PREFIX);

    $sql = "INSERT INTO CFP_WaterSourceType
            (SourceCode, SourceName, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, 1, ?, GETDATE())";
    $r = sqlsrv_query($conn, $sql, array(
        $code, $name, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']
    ));

    /* กรณีชนกัน (race condition) ลองสร้างรหัสใหม่อีกครั้งเดียว */
    if ($r === false) {
        $code = generateSourceCode($conn, CODE_PREFIX);
        $r = sqlsrv_query($conn, $sql, array(
            $code, $name, ($desc !== '' ? $desc : null), $sort, (int)$_SESSION['user_id']
        ));
    }

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก กรุณาลองใหม่อีกครั้ง', 'error');
    }

    logAction($conn, 'DATA_CREATE', 'CFP_WaterSourceType', null, null, null, null,
        'เพิ่มแหล่งน้ำ: ' . $code . ' - ' . $name);
    redirectWithToast('เพิ่มแหล่งน้ำเรียบร้อยแล้ว (รหัส ' . $code . ')');

} elseif ($action === 'update') {

    $id = (int)($_POST['SourceID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }

    /* รหัสไม่ให้แก้ไขหลังสร้างแล้ว — UPDATE เฉพาะฟิลด์อื่น */
    $sql = "UPDATE CFP_WaterSourceType
            SET SourceName=?, Description=?, SortOrder=?, IsActive=?,
                UpdatedBy=?, UpdatedDate=GETDATE()
            WHERE SourceID=?";
    $r = sqlsrv_query($conn, $sql, array(
        $name, ($desc !== '' ? $desc : null), $sort, $active,
        (int)$_SESSION['user_id'], $id
    ));

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error');
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_WaterSourceType', $id, null, null, null,
        'แก้ไขแหล่งน้ำ: ' . $name);
    redirectWithToast('บันทึกการแก้ไขเรียบร้อยแล้ว');

} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}

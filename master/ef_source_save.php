<?php
/* ==============================================
   master/ef_source_save.php
   รับ POST จาก ef_source.php
   actions: create | update | toggle
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(4, 5));

$conn = getConnection();

/* ===== Helper: redirect พร้อม toast ===== */
function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: ef_source.php');
    exit;
}

verifyCsrf();

$action = $_POST['action'] ?? '';

/* ===========================
   action=toggle
   =========================== */
if ($action === 'toggle') {

    $id = (int)($_POST['SourceID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลแหล่งอ้างอิง', 'error'); }

    $res = sqlsrv_query($conn, "SELECT IsActive, SourceName FROM CFP_EFSource WHERE SourceID=?", array($id));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบข้อมูลแหล่งอ้างอิง', 'error'); }

    $newStatus = $row['IsActive'] ? 0 : 1;

    sqlsrv_query($conn,
        "UPDATE CFP_EFSource SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE SourceID=?",
        array($newStatus, (int)$_SESSION['user_id'], $id));

    logAction($conn, 'DATA_UPDATE', 'CFP_EFSource', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['SourceName']);

    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อยแล้ว' : 'ปิดใช้งานเรียบร้อยแล้ว');
}

/* ===========================
   action=create / update
   =========================== */
$name    = trim($_POST['SourceName'] ?? '');
$version = trim($_POST['SourceVersion'] ?? '');
$year    = (int)($_POST['YearApply'] ?? 0);
$org     = trim($_POST['Organization'] ?? '');
$remark  = trim($_POST['Remark'] ?? '');
$active  = isset($_POST['IsActive']) ? (int)$_POST['IsActive'] : 1;

if ($name === '') {
    redirectWithToast('กรุณากรอกชื่อแหล่งอ้างอิงให้ครบถ้วน', 'error');
}
if ($year < 2000 || $year > 2100) {
    redirectWithToast('กรุณากรอกปีที่ใช้ให้ถูกต้อง', 'error');
}

$versionVal = ($version !== '') ? $version : null;
$orgVal     = ($org !== '') ? $org : null;
$remarkVal  = ($remark !== '') ? $remark : null;

if ($action === 'create') {

    $code = trim($_POST['SourceCode'] ?? '');
    if ($code === '') {
        redirectWithToast('กรุณากรอกรหัสแหล่งอ้างอิง', 'error');
    }

    $checkRes = sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_EFSource WHERE SourceCode = ?", array($code));
    $chkRow   = sqlsrv_fetch_array($checkRes, SQLSRV_FETCH_ASSOC);
    if ($chkRow && $chkRow['Cnt'] > 0) {
        redirectWithToast('รหัสนี้มีอยู่แล้วในระบบ กรุณาใช้รหัสอื่น', 'error');
    }

    $sql = "INSERT INTO CFP_EFSource
            (SourceCode, SourceName, SourceVersion, YearApply, Organization, Remark, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?, GETDATE())";

    $r = sqlsrv_query($conn, $sql, array(
        $code, $name, $versionVal, $year, $orgVal, $remarkVal, (int)$_SESSION['user_id']
    ));

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก กรุณาลองใหม่อีกครั้ง', 'error');
    }

    logAction($conn, 'DATA_CREATE', 'CFP_EFSource', null, null, null, null,
        'เพิ่มแหล่งอ้างอิง: ' . $code . ' - ' . $name);
    redirectWithToast('เพิ่มแหล่งอ้างอิงเรียบร้อยแล้ว (รหัส ' . $code . ')');

} elseif ($action === 'update') {

    $id = (int)($_POST['SourceID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }

    /* รหัสไม่ให้แก้ไขหลังสร้างแล้ว — UPDATE เฉพาะฟิลด์อื่น */
    $sql = "UPDATE CFP_EFSource
            SET SourceName=?, SourceVersion=?, YearApply=?, Organization=?, Remark=?, IsActive=?,
                UpdatedBy=?, UpdatedDate=GETDATE()
            WHERE SourceID=?";

    $r = sqlsrv_query($conn, $sql, array(
        $name, $versionVal, $year, $orgVal, $remarkVal, $active,
        (int)$_SESSION['user_id'], $id
    ));

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error');
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_EFSource', $id, null, null, null,
        'แก้ไขแหล่งอ้างอิง: ' . $name);
    redirectWithToast('บันทึกการแก้ไขเรียบร้อยแล้ว');

} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}

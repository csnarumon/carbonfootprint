<?php
/* ==============================================
   master/unit_save.php
   รับ POST จาก unit.php
   actions: create | update | delete | toggle
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(4));

$conn = getConnection();

/* ===== Helper: redirect พร้อม toast ===== */
function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: unit.php');
    exit;
}

verifyCsrf();

$action = $_POST['action'] ?? '';

/* ===========================
   action=toggle
   =========================== */
if ($action === 'toggle') {

    $id = (int)($_POST['UnitID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลหน่วยวัด', 'error'); }

    $res = sqlsrv_query($conn, "SELECT IsActive, UnitName FROM CFP_Unit WHERE UnitID=?", array($id));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบข้อมูลหน่วยวัด', 'error'); }

    $newStatus = $row['IsActive'] ? 0 : 1;

    sqlsrv_query($conn,
        "UPDATE CFP_Unit SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE UnitID=?",
        array($newStatus, (int)$_SESSION['user_id'], $id));

    logAction($conn, 'DATA_UPDATE', 'CFP_Unit', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['UnitName']);

    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อยแล้ว' : 'ปิดใช้งานเรียบร้อยแล้ว');
}

/* ===========================
   action=delete
   =========================== */
if ($action === 'delete') {

    $id = (int)($_POST['UnitID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลหน่วยวัด', 'error'); }

    /* ตรวจว่ามีการใช้งานอยู่ใน CFP_ActivityItem / CFP_ActivityData / CFP_WasteAsset หรือไม่
       (ทั้ง 3 ตารางมี FK ไป CFP_Unit.UnitID) */
    $used = 0;

    $r1 = @sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_ActivityItem WHERE UnitID=? AND IsActive=1", array($id));
    if ($r1 !== false) {
        $c1 = sqlsrv_fetch_array($r1, SQLSRV_FETCH_ASSOC);
        $used += (int)($c1['Cnt'] ?? 0);
    }

    $r2 = @sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_ActivityData WHERE UnitID=?", array($id));
    if ($r2 !== false) {
        $c2 = sqlsrv_fetch_array($r2, SQLSRV_FETCH_ASSOC);
        $used += (int)($c2['Cnt'] ?? 0);
    }

    $r3 = @sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_WasteAsset WHERE UnitID=?", array($id));
    if ($r3 !== false) {
        $c3 = sqlsrv_fetch_array($r3, SQLSRV_FETCH_ASSOC);
        $used += (int)($c3['Cnt'] ?? 0);
    }

    if ($used > 0) {
        /* มีการใช้งาน → Soft delete แทน */
        sqlsrv_query($conn,
            "UPDATE CFP_Unit SET IsActive=0, UpdatedBy=?, UpdatedDate=GETDATE() WHERE UnitID=?",
            array((int)$_SESSION['user_id'], $id));
        logAction($conn, 'DATA_UPDATE', 'CFP_Unit', $id, null, null, null, 'ปิดใช้งาน (มีการใช้งานอยู่)');
        redirectWithToast('มีการใช้งานหน่วยวัดนี้อยู่ ระบบปิดใช้งานให้แทนการลบ');
    }

    $rDel = sqlsrv_query($conn, "DELETE FROM CFP_Unit WHERE UnitID=?", array($id));
    if ($rDel === false) {
        $err = sqlsrv_errors();
        redirectWithToast('ไม่สามารถลบหน่วยวัดได้: ' . ($err[0]['message'] ?? 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'), 'error');
    }

    logAction($conn, 'DATA_DELETE', 'CFP_Unit', $id);
    redirectWithToast('ลบข้อมูลเรียบร้อยแล้ว');
}

/* ===========================
   action=create / update
   =========================== */
$name   = trim($_POST['UnitName'] ?? '');
$typeID = (int)($_POST['UnitTypeID'] ?? 0);
$desc   = trim($_POST['Description'] ?? '');
$sort   = (int)($_POST['SortOrder'] ?? 99);
$active = isset($_POST['IsActive']) ? (int)$_POST['IsActive'] : 1;

/* Validate */
if ($name === '') {
    redirectWithToast('กรุณากรอกชื่อหน่วยวัดให้ครบถ้วน', 'error');
}

/* ตรวจสอบว่า UnitTypeID ที่ส่งมามีอยู่จริงและยังใช้งานอยู่ (ถ้ามีการเลือก) */
$typeIDVal = null;
if ($typeID > 0) {
    $resType = sqlsrv_query($conn,
        "SELECT UnitTypeID FROM CFP_UnitType WHERE UnitTypeID=? AND IsActive=1",
        array($typeID));
    if ($resType !== false && sqlsrv_fetch_array($resType, SQLSRV_FETCH_ASSOC)) {
        $typeIDVal = $typeID;
    } else {
        redirectWithToast('ประเภทหน่วยที่เลือกไม่ถูกต้องหรือถูกปิดใช้งาน', 'error');
    }
}

/* ค่า null สำหรับฟิลด์ optional */
$descVal = ($desc !== '') ? $desc : null;

if ($action === 'create') {

    /* รับรหัสที่ผู้ใช้กรอกมาเอง */
    $code = trim($_POST['UnitCode'] ?? '');
    if ($code === '') {
        redirectWithToast('กรุณากรอกรหัสหน่วยวัด', 'error');
    }

    /* ตรวจสอบรหัสซ้ำ */
    $checkRes = sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_Unit WHERE UnitCode = ?", array($code));
    $chkRow   = sqlsrv_fetch_array($checkRes, SQLSRV_FETCH_ASSOC);
    if ($chkRow && $chkRow['Cnt'] > 0) {
        redirectWithToast('รหัสนี้มีอยู่แล้วในระบบ กรุณาใช้รหัสอื่น', 'error');
    }

    $sql = "INSERT INTO CFP_Unit
            (UnitCode, UnitName, UnitTypeID, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, ?, 1, ?, GETDATE())";

    $r = sqlsrv_query($conn, $sql, array(
        $code, $name, $typeIDVal, $descVal, $sort, (int)$_SESSION['user_id']
    ));

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก กรุณาลองใหม่อีกครั้ง', 'error');
    }

    logAction($conn, 'DATA_CREATE', 'CFP_Unit', null, null, null, null,
        'เพิ่มหน่วยวัด: ' . $code . ' - ' . $name);
    redirectWithToast('เพิ่มหน่วยวัดเรียบร้อยแล้ว (รหัส ' . $code . ')');

} elseif ($action === 'update') {

    $id = (int)($_POST['UnitID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }

    /* รหัสไม่ให้แก้ไขหลังสร้างแล้ว — UPDATE เฉพาะฟิลด์อื่น */
    $sql = "UPDATE CFP_Unit
            SET UnitName=?, UnitTypeID=?, Description=?, SortOrder=?, IsActive=?,
                UpdatedBy=?, UpdatedDate=GETDATE()
            WHERE UnitID=?";

    $r = sqlsrv_query($conn, $sql, array(
        $name, $typeIDVal, $descVal, $sort, $active,
        (int)$_SESSION['user_id'], $id
    ));

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error');
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_Unit', $id, null, null, null,
        'แก้ไขหน่วยวัด: ' . $name);
    redirectWithToast('บันทึกการแก้ไขเรียบร้อยแล้ว');

} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}

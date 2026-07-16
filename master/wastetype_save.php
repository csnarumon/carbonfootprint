<?php
/* ==============================================
   master/wastetype_save.php
   รับ POST จาก wastetype.php
   actions: create | update | delete | toggle
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

define('CODE_PREFIX', 'WT');

requireRole(array(4, 5));

$conn = getConnection();

/* ===== Helper: redirect พร้อม toast ===== */
function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: wastetype.php'); 
    exit;
}

/* ===== Helper: generate รหัสอัตโนมัติ รูปแบบ VT-0001 ===== */
function generateTypeCode($conn, $prefix) {
    $res = sqlsrv_query($conn, "
        SELECT MAX(CAST(SUBSTRING(TypeCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
        FROM CFP_WasteType
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
    if (!$id) { redirectWithToast('ไม่พบข้อมูลประเภทขยะของเสีย', 'error'); }

    $res = sqlsrv_query($conn, "SELECT IsActive, TypeName FROM CFP_WasteType WHERE TypeID=?", array($id));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบข้อมูลประเภทขยะของเสีย', 'error'); }

    $newStatus = $row['IsActive'] ? 0 : 1;

    sqlsrv_query($conn, "UPDATE CFP_WasteType SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE TypeID=?",
        array($newStatus, (int)$_SESSION['user_id'], $id));

    logAction($conn, 'DATA_UPDATE', 'CFP_WasteType', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['TypeName']);

    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อยแล้ว' : 'ปิดใช้งานเรียบร้อยแล้ว');
}

/* ===========================
   action=delete
   =========================== */
if ($action === 'delete') {
    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลประเภทขยะของเสีย', 'error'); }

    /* เช็ค CFP_WasteAsset (มี FK บังคับจริง) และ CFP_Waste (ตารางเดิม/legacy) */
    $usageCnt = 0;
    $res = @sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_WasteAsset WHERE WasteTypeID = ?", array($id));
    if ($res !== false) {
        $chk = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
        $usageCnt += $chk ? (int)$chk['Cnt'] : 0;
    }
    $res2 = @sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_Waste WHERE WasteTypeID = ?", array($id));
    if ($res2 !== false) {
        $chk2 = sqlsrv_fetch_array($res2, SQLSRV_FETCH_ASSOC);
        $usageCnt += $chk2 ? (int)$chk2['Cnt'] : 0;
    }

    if ($usageCnt > 0) {
        /* มีการใช้งานอยู่ → บล็อกการลบ ให้ผู้ใช้ไปกดปิดใช้งาน (Toggle) เองแทน */
        redirectWithToast('ไม่สามารถลบได้ เนื่องจากมีข้อมูลขยะ ' . $usageCnt . ' รายการใช้ประเภทนี้อยู่ — กรุณาปิดใช้งานแทน หรือย้ายไปใช้ประเภทอื่นก่อน', 'error');
    }

    $rDel = sqlsrv_query($conn, "DELETE FROM CFP_WasteType WHERE TypeID=?", array($id));
    if ($rDel === false) {
        $err = sqlsrv_errors();
        redirectWithToast('ไม่สามารถลบข้อมูลได้: ' . ($err[0]['message'] ?? 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'), 'error');
    }
    logAction($conn, 'DATA_DELETE', 'CFP_WasteType', $id);
    redirectWithToast('ลบข้อมูลเรียบร้อยแล้ว');
}


/* ===========================
   action=create / update (ตัวแปรฟอร์มอื่นๆ)
   =========================== */
$name   = trim($_POST['TypeName'] ?? '');
$group  = trim($_POST['WasteGroup'] ?? '');
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

    $sql = "INSERT INTO CFP_WasteType
            (TypeCode, TypeName, WasteGroup, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, ?, 1, ?, GETDATE())";
    $r = sqlsrv_query($conn, $sql, array(
        $code, $name,
        ($group !== '' ? $group : null),
        ($desc !== '' ? $desc : null),
        $sort, (int)$_SESSION['user_id']
    ));

    /* กรณีชนกัน (race condition) ลองสร้างรหัสใหม่อีกครั้งเดียว */
    if ($r === false) {
        $code = generateTypeCode($conn, CODE_PREFIX);
        $r = sqlsrv_query($conn, $sql, array(
            $code, $name,
            ($group !== '' ? $group : null),
            ($desc !== '' ? $desc : null),
            $sort, (int)$_SESSION['user_id']
        ));
    }

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก กรุณาลองใหม่อีกครั้ง', 'error');
    }

    logAction($conn, 'DATA_CREATE', 'CFP_WasteType', null, null, null, null,
        'เพิ่มประเภทขยะของเสีย: ' . $code . ' - ' . $name);
    redirectWithToast('เพิ่มประเภทขยะของเสียเรียบร้อยแล้ว (รหัส ' . $code . ')');
} elseif ($action === 'update') {

    $id = (int)($_POST['TypeID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }

    /* รหัสไม่ให้แก้ไขหลังสร้างแล้ว — UPDATE เฉพาะฟิลด์อื่น */
    $sql = "UPDATE CFP_WasteType
            SET TypeName=?, WasteGroup=?, Description=?, SortOrder=?, IsActive=?,
                UpdatedBy=?, UpdatedDate=GETDATE()
            WHERE TypeID=?";
    $r = sqlsrv_query($conn, $sql, array(
        $name,
        ($group !== '' ? $group : null),
        ($desc !== '' ? $desc : null),
        $sort, $active,
        (int)$_SESSION['user_id'], $id
    ));

    if ($r === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error');
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_WasteType', $id, null, null, null,
        'แก้ไขประเภทขยะของเสีย: ' . $name);
    redirectWithToast('บันทึกการแก้ไขเรียบร้อยแล้ว');

} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}

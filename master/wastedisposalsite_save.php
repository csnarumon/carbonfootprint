<?php
/* ==============================================
   master/wastedisposalsite_save.php
   actions: create | update | delete | toggle
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

define('CODE_PREFIX', 'WDS');
requireRole(array(4, 5));
$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: wastedisposalsite.php');
    exit;
}

function generateSiteCode($conn, $prefix) {
    $res = sqlsrv_query($conn, "
        SELECT MAX(CAST(SUBSTRING(SiteCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
        FROM CFP_WasteDisposalSite WHERE SiteCode LIKE ? + '-%'",
        array($prefix, $prefix));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    $n = ($row && $row['MaxNum'] !== null) ? ((int)$row['MaxNum'] + 1) : 1;
    return $prefix . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

verifyCsrf();
$action = $_POST['action'] ?? '';

if ($action === 'toggle') {
    $id = (int)($_POST['SiteID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลสถานที่', 'error'); }
    $res = sqlsrv_query($conn, "SELECT IsActive, SiteName FROM CFP_WasteDisposalSite WHERE SiteID=?", array($id));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบข้อมูลสถานที่', 'error'); }
    $newStatus = $row['IsActive'] ? 0 : 1;
    sqlsrv_query($conn, "UPDATE CFP_WasteDisposalSite SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE SiteID=?",
        array($newStatus, (int)$_SESSION['user_id'], $id));
    logAction($conn, 'DATA_UPDATE', 'CFP_WasteDisposalSite', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['SiteName']);
    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อยแล้ว' : 'ปิดใช้งานเรียบร้อยแล้ว');
}

if ($action === 'delete') {
    $id = (int)($_POST['SiteID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลสถานที่', 'error'); }
    $hasUsage = false;

    /* เช็ค CFP_WasteAsset (มี FK บังคับจริง: DisposalSiteID)
       และ CFP_Waste (ตารางเดิม/legacy: คอลัมน์ชื่อ WasteDisposalSiteID) */
    $res = @sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_WasteAsset WHERE DisposalSiteID = ?", array($id));
    if ($res !== false) {
        $chk = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
        $hasUsage = $hasUsage || ($chk && $chk['Cnt'] > 0);
    }
    $res2 = @sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_Waste WHERE WasteDisposalSiteID = ?", array($id));
    if ($res2 !== false) {
        $chk2 = sqlsrv_fetch_array($res2, SQLSRV_FETCH_ASSOC);
        $hasUsage = $hasUsage || ($chk2 && $chk2['Cnt'] > 0);
    }

    if ($hasUsage) {
        sqlsrv_query($conn, "UPDATE CFP_WasteDisposalSite SET IsActive=0, UpdatedBy=?, UpdatedDate=GETDATE() WHERE SiteID=?",
            array((int)$_SESSION['user_id'], $id));
        logAction($conn, 'DATA_UPDATE', 'CFP_WasteDisposalSite', $id, null, null, null, 'ปิดใช้งาน (มีการใช้งานอยู่)');
        redirectWithToast('มีข้อมูลขยะใช้สถานที่นี้อยู่ ระบบปิดใช้งานให้แทนการลบ');
    }
    $rDel = sqlsrv_query($conn, "DELETE FROM CFP_WasteDisposalSite WHERE SiteID=?", array($id));
    if ($rDel === false) {
        $err = sqlsrv_errors();
        redirectWithToast('ไม่สามารถลบข้อมูลได้: ' . ($err[0]['message'] ?? 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'), 'error');
    }
    logAction($conn, 'DATA_DELETE', 'CFP_WasteDisposalSite', $id);
    redirectWithToast('ลบข้อมูลเรียบร้อยแล้ว');
}

$name     = trim($_POST['SiteName'] ?? '');
$address  = trim($_POST['Address'] ?? '');
$province = trim($_POST['Province'] ?? '');
$desc     = trim($_POST['Description'] ?? '');
$sort     = (int)($_POST['SortOrder'] ?? 99);
$active   = isset($_POST['IsActive']) ? (int)$_POST['IsActive'] : 1;

if ($name === '') { redirectWithToast('กรุณากรอกชื่อสถานที่ให้ครบถ้วน', 'error'); }

if ($action === 'create') {
    $code = generateSiteCode($conn, CODE_PREFIX);
    $sql = "INSERT INTO CFP_WasteDisposalSite (SiteCode, SiteName, Address, Province, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?, GETDATE())";
    $params = array($code, $name, ($address!==''?$address:null), ($province!==''?$province:null), ($desc!==''?$desc:null), $sort, (int)$_SESSION['user_id']);
    $r = sqlsrv_query($conn, $sql, $params);
    if ($r === false) {
        $code = generateSiteCode($conn, CODE_PREFIX);
        $r = sqlsrv_query($conn, $sql, array($code, $name, ($address!==''?$address:null), ($province!==''?$province:null), ($desc!==''?$desc:null), $sort, (int)$_SESSION['user_id']));
    }
    if ($r === false) { redirectWithToast('เกิดข้อผิดพลาดในการบันทึก กรุณาลองใหม่อีกครั้ง', 'error'); }
    logAction($conn, 'DATA_CREATE', 'CFP_WasteDisposalSite', null, null, null, null, 'เพิ่มสถานที่: '.$code.' - '.$name);
    redirectWithToast('เพิ่มสถานที่กำจัดขยะเรียบร้อยแล้ว (รหัส '.$code.')');

} elseif ($action === 'update') {
    $id = (int)($_POST['SiteID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }
    $sql = "UPDATE CFP_WasteDisposalSite SET SiteName=?, Address=?, Province=?, Description=?, SortOrder=?, IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE SiteID=?";
    $r = sqlsrv_query($conn, $sql, array($name, ($address!==''?$address:null), ($province!==''?$province:null), ($desc!==''?$desc:null), $sort, $active, (int)$_SESSION['user_id'], $id));
    if ($r === false) { redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error'); }
    logAction($conn, 'DATA_UPDATE', 'CFP_WasteDisposalSite', $id, null, null, null, 'แก้ไขสถานที่: '.$name);
    redirectWithToast('บันทึกการแก้ไขเรียบร้อยแล้ว');
} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}

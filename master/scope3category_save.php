<?php
/* ==============================================
   master/scope3category_save.php
   เปิด/ปิดใช้งาน Category Scope 3 — Admin (4), SustainAdmin (5)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(4, 5));
verifyCsrf();

$conn   = getConnection();
$userID = (int)$_SESSION['user_id'];

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: scope3category.php');
    exit;
}

$categoryNo = (int)($_POST['categoryNo'] ?? 0);
$catAction  = $_POST['catAction'] ?? '';

if ($categoryNo < 1 || $categoryNo > 15) { redirectWithToast('Category ไม่ถูกต้อง', 'error'); }
if (!in_array($catAction, array('enable', 'disable'))) { redirectWithToast('คำขอไม่ถูกต้อง', 'error'); }

if ($catAction === 'disable') {
    $resChk = sqlsrv_query($conn, "SELECT CategoryNo FROM CFP_Scope3CategoryDisabled WHERE CategoryNo = ?", array($categoryNo));
    $exists = $resChk ? sqlsrv_fetch_array($resChk, SQLSRV_FETCH_ASSOC) : null;
    if (!$exists) {
        $res = sqlsrv_query($conn,
            "INSERT INTO CFP_Scope3CategoryDisabled (CategoryNo, DisabledBy, DisabledDate) VALUES (?, ?, GETDATE())",
            array($categoryNo, $userID));
        if (!$res) { $e = sqlsrv_errors(); redirectWithToast('ปิดใช้งานไม่สำเร็จ: ' . ($e[0]['message'] ?? ''), 'error'); }
    }
    logAction($conn, 'DISABLE_CATEGORY', 'CFP_Scope3CategoryDisabled', $categoryNo,
        null, null, 'Scope3', 'ปิดใช้งาน Category ' . $categoryNo);
    redirectWithToast('ปิดใช้งาน Category ' . $categoryNo . ' เรียบร้อยแล้ว');

} else {
    $res = sqlsrv_query($conn, "DELETE FROM CFP_Scope3CategoryDisabled WHERE CategoryNo = ?", array($categoryNo));
    if (!$res) { $e = sqlsrv_errors(); redirectWithToast('เปิดใช้งานไม่สำเร็จ: ' . ($e[0]['message'] ?? ''), 'error'); }
    logAction($conn, 'ENABLE_CATEGORY', 'CFP_Scope3CategoryDisabled', $categoryNo,
        null, null, 'Scope3', 'เปิดใช้งาน Category ' . $categoryNo);
    redirectWithToast('เปิดใช้งาน Category ' . $categoryNo . ' เรียบร้อยแล้ว');
}

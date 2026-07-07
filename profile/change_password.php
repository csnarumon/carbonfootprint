<?php
/* ==============================================
   profile/change_password.php
   รับ JSON POST — เปลี่ยนรหัสผ่านตัวเอง
   ============================================== */
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/auth_check.php';
require_once '../config/db.php';

/* รับเฉพาะ POST */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array('success' => false, 'msg' => 'Method not allowed'));
    exit;
}

/* รับ JSON body */
$body = file_get_contents('php://input');
$data = json_decode($body, true);

/* CSRF */
$csrfToken = $data['csrf_token'] ?? '';
if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(array('success' => false, 'msg' => 'คำขอไม่ถูกต้อง (CSRF)'));
    exit;
}

$oldPass = $data['old_password'] ?? '';
$newPass = $data['new_password'] ?? '';

/* Validate */
if (empty($oldPass) || empty($newPass)) {
    echo json_encode(array('success' => false, 'msg' => 'กรุณากรอกรหัสผ่านให้ครบ'));
    exit;
}
if (mb_strlen($newPass) < 8) {
    echo json_encode(array('success' => false, 'msg' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร'));
    exit;
}
if ($oldPass === $newPass) {
    echo json_encode(array('success' => false, 'msg' => 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม'));
    exit;
}

$conn   = getConnection();
$userID = (int)$_SESSION['user_id'];

/* ดึง PasswordHash เดิม */
$res  = sqlsrv_query($conn, "SELECT PasswordHash FROM CFP_User WHERE UserID=? AND IsActive=1",
                     array($userID));
$user = $res ? sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC) : null;

if (!$user) {
    echo json_encode(array('success' => false, 'msg' => 'ไม่พบบัญชีผู้ใช้'));
    exit;
}

/* ตรวจรหัสผ่านเดิม */
if (!password_verify($oldPass, $user['PasswordHash'])) {
    echo json_encode(array('success' => false, 'msg' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง'));
    exit;
}

/* UPDATE */
$newHash = password_hash($newPass, PASSWORD_BCRYPT);
$upd     = sqlsrv_query($conn,
    "UPDATE CFP_User SET PasswordHash=? WHERE UserID=?",
    array($newHash, $userID)
);

if ($upd === false) {
    error_log('change_password error: ' . print_r(sqlsrv_errors(), true));
    echo json_encode(array('success' => false, 'msg' => 'เกิดข้อผิดพลาดในการบันทึก'));
    exit;
}

/* Audit Log */
logAction($conn, 'CHANGE_PASSWORD', 'CFP_User', $userID, null, null, null,
          'เปลี่ยนรหัสผ่านตัวเอง');

echo json_encode(array('success' => true, 'msg' => 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว'));

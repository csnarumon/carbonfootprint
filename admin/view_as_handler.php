<?php
/* ==============================================
   admin/view_as_handler.php
   API handler สำหรับ View-as (สวมสิทธิ์เป็น user คนอื่น — read-only)
   รับ POST JSON → ตอบกลับ JSON
   ============================================== */

session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once '../includes/auth_check.php';
require_once '../config/db.php';

/* เฉพาะ Admin เท่านั้น */
if ((int)($_SESSION['role_id'] ?? 0) !== 4) {
    echo json_encode(array('success' => false, 'msg' => 'ไม่มีสิทธิ์ดำเนินการ'));
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$action = isset($input['action']) ? trim($input['action']) : '';
$conn   = getConnection();
$ip     = $_SERVER['REMOTE_ADDR'] ?? '';
$adminID = (int)$_SESSION['user_id'];

/* ============================================================
   LIST USERS — สำหรับ dropdown เลือก user (ไม่รวมตัวเอง/Admin อื่น)
   ============================================================ */
if ($action === 'list_users') {
    $res = sqlsrv_query($conn,
        "SELECT UserID, FullName, Username, RoleID
         FROM CFP_User
         WHERE IsActive = 1 AND RoleID <> 4 AND UserID <> ?
         ORDER BY RoleID, FullName",
        array($adminID));
    $roleNames = array(1=>'Data Entry', 2=>'Reviewer', 3=>'Approver', 5=>'Sustainability Admin', 6=>'Viewer');
    $users = array();
    if ($res) {
        while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $users[] = array(
                'userID'   => (int)$r['UserID'],
                'fullName' => $r['FullName'],
                'username' => $r['Username'],
                'roleID'   => (int)$r['RoleID'],
                'roleName' => $roleNames[(int)$r['RoleID']] ?? '',
            );
        }
    }
    sqlsrv_close($conn);
    echo json_encode(array('success' => true, 'users' => $users), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   START VIEW-AS
   ============================================================ */
if ($action === 'start') {
    /* ห้ามซ้อนกับ Elevation — ทำได้ทีละอย่าง กันสับสนว่ากำลังสวมอะไรอยู่ */
    if (!empty($_SESSION['elevated_role'])) {
        echo json_encode(array('success' => false, 'msg' => 'กำลัง Elevate Role อยู่ กรุณาสิ้นสุดก่อน'));
        exit;
    }

    $targetID = (int)($input['target_user_id'] ?? 0);
    $reason   = isset($input['reason']) ? trim($input['reason']) : '';

    if ($targetID <= 0) {
        echo json_encode(array('success' => false, 'msg' => 'กรุณาเลือก user'));
        exit;
    }
    if ($targetID === $adminID) {
        echo json_encode(array('success' => false, 'msg' => 'ไม่สามารถสวมสิทธิ์เป็นตัวเองได้'));
        exit;
    }
    if ($reason === '' || mb_strlen($reason) < 3) {
        echo json_encode(array('success' => false, 'msg' => 'กรุณากรอกเหตุผลอย่างน้อย 3 ตัวอักษร'));
        exit;
    }
    if (mb_strlen($reason) > 300) {
        echo json_encode(array('success' => false, 'msg' => 'เหตุผลต้องไม่เกิน 300 ตัวอักษร'));
        exit;
    }

    /* ตรวจว่า user เป้าหมายมีอยู่จริงและ active, ไม่ใช่ Admin คนอื่น */
    $resU = sqlsrv_query($conn,
        "SELECT UserID, FullName, RoleID FROM CFP_User WHERE UserID = ? AND IsActive = 1",
        array($targetID));
    $u = $resU ? sqlsrv_fetch_array($resU, SQLSRV_FETCH_ASSOC) : null;
    if (!$u) {
        echo json_encode(array('success' => false, 'msg' => 'ไม่พบ user นี้ หรือถูกปิดใช้งานแล้ว'));
        exit;
    }
    if ((int)$u['RoleID'] === 4) {
        echo json_encode(array('success' => false, 'msg' => 'ไม่สามารถสวมสิทธิ์เป็น Admin คนอื่นได้'));
        exit;
    }

    /* ปิด session view-as เก่าที่ยัง active อยู่ก่อน (กันซ้อน) */
    sqlsrv_query($conn,
        "UPDATE CFP_ViewAsSession SET EndTime = GETDATE(), EndReason = ? WHERE AdminUserID = ? AND EndTime IS NULL",
        array('SYSTEM', $adminID));

    /* สร้าง session ใหม่ */
    $ok = sqlsrv_query($conn,
        "INSERT INTO CFP_ViewAsSession (AdminUserID, TargetUserID, Reason, IPAddress) VALUES (?, ?, ?, ?)",
        array($adminID, $targetID, $reason, $ip));
    if (!$ok) {
        echo json_encode(array('success' => false, 'msg' => 'เกิดข้อผิดพลาดในการบันทึก'));
        exit;
    }

    $resID = sqlsrv_query($conn, 'SELECT SCOPE_IDENTITY() AS NewID');
    $rowID = sqlsrv_fetch_array($resID, SQLSRV_FETCH_ASSOC);
    $sessionID = (int)($rowID['NewID'] ?? 0);

    /* Audit log */
    sqlsrv_query($conn,
        "INSERT INTO CFP_ActionLog (ActorUserID, ActorRole, ActionCode, TargetTable, TargetID, Remark, IPAddress)
         VALUES (?, 4, 'VIEWAS_START', 'CFP_User', ?, ?, ?)",
        array($adminID, $targetID, 'View-as: ' . $u['FullName'] . ' | เหตุผล: ' . $reason, $ip));

    /* เก็บใน Session — read-only เสมอ */
    $_SESSION['view_as_user_id']   = (int)$u['UserID'];
    $_SESSION['view_as_role_id']   = (int)$u['RoleID'];
    $_SESSION['view_as_name']      = $u['FullName'];
    $_SESSION['view_as_session_id'] = $sessionID;
    $_SESSION['view_as_reason']    = $reason;
    $_SESSION['view_as_start']     = date('H:i');

    sqlsrv_close($conn);
    echo json_encode(array(
        'success'   => true,
        'msg'       => 'เริ่มดูแทน ' . $u['FullName'] . ' แล้ว',
        'user_name' => $u['FullName'],
        'start_time' => $_SESSION['view_as_start'],
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   END VIEW-AS
   ============================================================ */
if ($action === 'end') {
    $sessionID = (int)($_SESSION['view_as_session_id'] ?? 0);
    $targetID  = (int)($_SESSION['view_as_user_id'] ?? 0);

    if ($sessionID > 0) {
        sqlsrv_query($conn,
            "UPDATE CFP_ViewAsSession SET EndTime = GETDATE(), EndReason = ? WHERE SessionID = ? AND AdminUserID = ?",
            array('MANUAL_END', $sessionID, $adminID));

        sqlsrv_query($conn,
            "INSERT INTO CFP_ActionLog (ActorUserID, ActorRole, ActionCode, TargetTable, TargetID, Remark, IPAddress)
             VALUES (?, 4, 'VIEWAS_END', 'CFP_User', ?, 'สิ้นสุด View-as', ?)",
            array($adminID, $targetID ?: null, $ip));
    }

    unset(
        $_SESSION['view_as_user_id'],
        $_SESSION['view_as_role_id'],
        $_SESSION['view_as_name'],
        $_SESSION['view_as_session_id'],
        $_SESSION['view_as_reason'],
        $_SESSION['view_as_start']
    );

    sqlsrv_close($conn);
    echo json_encode(array('success' => true, 'msg' => 'สิ้นสุด View-as แล้ว'));
    exit;
}

echo json_encode(array('success' => false, 'msg' => 'Action ไม่ถูกต้อง'));

<?php
/* ==============================================
   admin/elevation_handler.php
   API handler สำหรับ Role Elevation
   รับ POST JSON → ตอบกลับ JSON
   ============================================== */

session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once '../includes/auth_check.php';
require_once '../config/db.php';

/* เฉพาะ Admin เท่านั้น */
if ((int)$_SESSION['role_id'] !== 4) {
    echo json_encode(array('success' => false, 'msg' => 'ไม่มีสิทธิ์ดำเนินการ'));
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$action = isset($input['action']) ? trim($input['action']) : '';
$conn   = getConnection();
$ip     = $_SERVER['REMOTE_ADDR'] ?? '';
$userID = (int)$_SESSION['user_id'];

/* ============================================================
   START ELEVATION
   ============================================================ */
if ($action === 'start') {
    if (!empty($_SESSION['view_as_user_id'])) {
        echo json_encode(array('success' => false, 'msg' => 'กำลัง View-as user อยู่ กรุณาสิ้นสุดก่อน'));
        exit;
    }

    $elevRole = (int)($input['elevated_role'] ?? 0);
    $reason   = isset($input['reason']) ? trim($input['reason']) : '';

    /* Validate */
    if (!in_array($elevRole, array(1, 2, 3))) {
        echo json_encode(array('success' => false, 'msg' => 'Role ที่ elevate ได้มีแค่ Data Entry (1), Reviewer (2) และ Approver (3)'));
        exit;
    }
    if ($reason === '') {
        echo json_encode(array('success' => false, 'msg' => 'กรุณากรอกเหตุผลก่อน'));
        exit;
    }
    if (mb_strlen($reason) > 300) {
        echo json_encode(array('success' => false, 'msg' => 'เหตุผลต้องไม่เกิน 300 ตัวอักษร'));
        exit;
    }

    /* ปิด session เก่าที่ยัง active อยู่ก่อน */
    $sqlEnd = 'UPDATE CFP_ElevationSession
               SET EndTime = GETDATE(), EndReason = ?
               WHERE AdminUserID = ? AND EndTime IS NULL';
    sqlsrv_query($conn, $sqlEnd, array('SYSTEM', $userID));

    /* สร้าง session ใหม่ */
    $sqlIns = 'INSERT INTO CFP_ElevationSession
               (AdminUserID, ElevatedRole, Reason, IPAddress)
               VALUES (?, ?, ?, ?)';
    $ok = sqlsrv_query($conn, $sqlIns, array($userID, $elevRole, $reason, $ip));

    if (!$ok) {
        echo json_encode(array('success' => false, 'msg' => 'เกิดข้อผิดพลาดในการบันทึก'));
        exit;
    }

    /* ดึง SessionID ที่เพิ่งสร้าง */
    $resID = sqlsrv_query($conn, 'SELECT SCOPE_IDENTITY() AS NewID');
    $rowID = sqlsrv_fetch_array($resID, SQLSRV_FETCH_ASSOC);
    $sessionID = (int)($rowID['NewID'] ?? 0);

    /* บันทึก ActionLog */
    $sqlLog = "INSERT INTO CFP_ActionLog
               (ActorUserID, ActorRole, ElevatedRole, ActionCode, IPAddress)
               VALUES (?, 4, ?, 'ELEVATION_START', ?)";
    sqlsrv_query($conn, $sqlLog, array($userID, $elevRole, $ip));

    /* เก็บใน Session */
    $_SESSION['elevated_role']       = $elevRole;
    $_SESSION['elevation_session_id'] = $sessionID;
    $_SESSION['elevation_reason']    = $reason;
    $_SESSION['elevation_start']     = date('H:i');

    $roleNames = array(1 => 'Data Entry', 2 => 'Reviewer', 3 => 'Approver');

    sqlsrv_close($conn);
    echo json_encode(array(
        'success'      => true,
        'msg'          => 'เริ่ม Elevation เป็น ' . $roleNames[$elevRole] . ' แล้ว',
        'elevated_role' => $elevRole,
        'role_name'    => $roleNames[$elevRole],
        'start_time'   => $_SESSION['elevation_start'],
    ));
    exit;
}

/* ============================================================
   END ELEVATION
   ============================================================ */
if ($action === 'end') {
    $sessionID = (int)($_SESSION['elevation_session_id'] ?? 0);
    $elevRole  = (int)($_SESSION['elevated_role'] ?? 0);

    if ($sessionID > 0) {
        $sqlEnd = 'UPDATE CFP_ElevationSession
                   SET EndTime = GETDATE(), EndReason = ?
                   WHERE SessionID = ? AND AdminUserID = ?';
        sqlsrv_query($conn, $sqlEnd, array('MANUAL_END', $sessionID, $userID));

        /* บันทึก ActionLog */
        $sqlLog = "INSERT INTO CFP_ActionLog
                   (ActorUserID, ActorRole, ElevatedRole, ActionCode, IPAddress)
                   VALUES (?, 4, ?, 'ELEVATION_END', ?)";
        sqlsrv_query($conn, $sqlLog, array($userID, $elevRole ?: null, $ip));
    }

    /* ล้าง Session */
    unset(
        $_SESSION['elevated_role'],
        $_SESSION['elevation_session_id'],
        $_SESSION['elevation_reason'],
        $_SESSION['elevation_start']
    );

    sqlsrv_close($conn);
    echo json_encode(array('success' => true, 'msg' => 'สิ้นสุด Elevation แล้ว'));
    exit;
}

/* ============================================================
   GET STATUS
   ============================================================ */
if ($action === 'status') {
    $isElevated = !empty($_SESSION['elevated_role']);
    $roleNames  = array(1 => 'Data Entry', 2 => 'Reviewer', 3 => 'Approver');
    $elevRole   = (int)($_SESSION['elevated_role'] ?? 0);

    sqlsrv_close($conn);
    echo json_encode(array(
        'success'      => true,
        'is_elevated'  => $isElevated,
        'elevated_role' => $elevRole,
        'role_name'    => $isElevated ? ($roleNames[$elevRole] ?? '') : '',
        'start_time'   => $_SESSION['elevation_start'] ?? '',
        'reason'       => $_SESSION['elevation_reason'] ?? '',
    ));
    exit;
}

echo json_encode(array('success' => false, 'msg' => 'Action ไม่ถูกต้อง'));

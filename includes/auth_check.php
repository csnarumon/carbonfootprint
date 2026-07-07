<?php
/* ==============================================
   includes/auth_check.php
   ตรวจ Session + CSRF + Role Elevation aware
   include บรรทัดแรกของทุกไฟล์ (ยกเว้น login.php)
   ============================================== */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ยังไม่ได้ login → redirect ออกจาก iframe ด้วย */
if (empty($_SESSION['user_id'])) {
    $isXhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    if ($isXhr) {
        http_response_code(401);
        echo json_encode(array('error' => 'unauthenticated'));
        exit;
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
    echo '<script>window.top.location.href="/carbonfootprint/login.php";</script>';
    echo '</body></html>';
    exit;
}

/* สร้าง CSRF token ถ้ายังไม่มี */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ============================================================
   Role Helpers — Elevation Aware
   getEffectiveRole() คืนค่า Role จริงที่ใช้งานตอนนี้
   ถ้า Admin กำลัง Elevate จะคืน Role ที่ Elevate
   ============================================================ */

/**
 * คืน Role ที่มีผลจริง ณ ขณะนี้
 * - ปกติ = $_SESSION['role_id']
 * - Admin + Elevating = $_SESSION['elevated_role']
 */
function getEffectiveRole() {
    $baseRole = (int)($_SESSION['role_id'] ?? 0);
    if ($baseRole === 4 && !empty($_SESSION['elevated_role'])) {
        return (int)$_SESSION['elevated_role'];
    }
    return $baseRole;
}

/**
 * คืน Role จริงของผู้ใช้ (ไม่สนใจ Elevation)
 */
function getActualRole() {
    return (int)($_SESSION['role_id'] ?? 0);
}

/**
 * ตรวจว่ากำลัง Elevate อยู่ไหม
 */
function isElevating() {
    return (int)($_SESSION['role_id'] ?? 0) === 4
        && !empty($_SESSION['elevated_role']);
}

/**
 * บังคับ Role ตาม Effective Role
 * ถ้า Admin Elevate เป็น Reviewer จะผ่าน check ของ Reviewer
 */
function requireRole($allowedRoles) {
    $effectiveRole = getEffectiveRole();
    $actualRole    = getActualRole();

    /* Admin และ SustainAdmin เข้าได้ทุกที่เสมอ */
    if ($actualRole === 4 || $actualRole === 5) { return; }

    if (!in_array($effectiveRole, (array)$allowedRoles)) {
        echo '<script>window.top.location.href="/carbonfootprint/error_403.php";</script>';
        exit;
    }
}

/**
 * ตรวจ Role โดยใช้ Effective Role
 */
function hasRole($roleID) {
    return getEffectiveRole() === (int)$roleID;
}

function isAdmin() {
    return getActualRole() === 4;
}

function isSustainAdmin() {
    return getActualRole() === 5;
}

function isSuperAdmin() {
    return in_array(getActualRole(), array(4, 5));
}

function isApprover() {
    return getEffectiveRole() >= 3;
}

function isReviewer() {
    return getEffectiveRole() >= 2;
}

function isDataEntry() {
    return getEffectiveRole() === 1;
}

/* ============================================================
   Action Log Helper
   ใช้บันทึก Audit Trail ทุก Action สำคัญ
   ============================================================ */

/**
 * บันทึก CFP_ActionLog
 * @param resource $conn    sqlsrv connection
 * @param string   $action  ActionCode เช่น DATA_CREATE, SUBMIT, APPROVE
 * @param string   $table   TargetTable เช่น 'CFP_ActivityData'
 * @param int|null $targetID
 * @param int|null $siteID
 * @param string   $ym      YearMonth YYYYMM
 * @param string   $scope   Scope1|Scope2|Scope3
 * @param string   $remark
 * @param string   $old     JSON OldValue
 * @param string   $new     JSON NewValue
 */
function logAction($conn, $action, $table = null, $targetID = null,
                   $siteID = null, $ym = null, $scope = null,
                   $remark = null, $old = null, $new = null) {
    $actorID   = (int)$_SESSION['user_id'];
    $actorRole = getActualRole();
    $elevRole  = isElevating() ? (int)$_SESSION['elevated_role'] : null;
    $ip        = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (strlen($ua) > 300) { $ua = substr($ua, 0, 300); }

    $sql = 'INSERT INTO CFP_ActionLog
            (ActorUserID, ActorRole, ElevatedRole, ActionCode,
             TargetTable, TargetID, TargetSiteID, TargetYearMonth, TargetScope,
             OldValue, NewValue, Remark, IPAddress, UserAgent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

    sqlsrv_query($conn, $sql, array(
        $actorID, $actorRole, $elevRole, $action,
        $table, $targetID, $siteID, $ym, $scope,
        $old, $new, $remark, $ip, $ua
    ));
}

/* ============================================================
   CSRF
   ============================================================ */

function verifyCsrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            http_response_code(403);
            die('<div style="font-family:sans-serif;padding:2rem;color:#DC3545;">'
                . 'คำขอไม่ถูกต้อง (CSRF token mismatch)</div>');
        }
    }
}

function getRoleName($roleID) {
    $map = array(
        1 => 'Data Entry',
        2 => 'Reviewer',
        3 => 'Approver',
        4 => 'Admin',
        5 => 'Sustainability Admin',
        6 => 'Viewer',
    );
    return $map[(int)$roleID] ?? 'Unknown';
}
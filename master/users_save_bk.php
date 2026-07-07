<?php
/* ==============================================
   master/users_save.php
   รับ POST/GET จาก users.php
   actions: create | update | toggle
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(4, 5));

$conn       = getConnection();
$myRoleID   = getActualRole();
$myUserID   = (int)$_SESSION['user_id'];
$isSustain  = ($myRoleID === 5);
$isDevAdmin = ($myRoleID === 4);

/* Role ที่ SustainAdmin กำหนดได้ */
$allowedAssignRoles = array(1, 2, 3, 6);

/* ===== Helper: redirect พร้อม toast ===== */
function redirectWithToast($msg, $type = 'success', $tab = 'tabUsers') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: users.php?tab=' . $tab);
    exit;
}

/* ===== Helper: validate role permission ===== */
function canAssignRole($myRoleID, $targetRoleID, $allowedAssignRoles) {
    if ($myRoleID === 4) { return true; } /* DevAdmin ทุก Role */
    return in_array((int)$targetRoleID, $allowedAssignRoles);
}

/* ===== Helper: sanitize int ===== */
function intOrNull($val) {
    $v = (int)$val;
    return ($v > 0) ? $v : null;
}

/* ===========================
   GET: action=toggle
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'toggle') {
    /* CSRF via GET token */
    if (empty($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        redirectWithToast('คำขอไม่ถูกต้อง (CSRF)', 'error');
    }

    $targetID = intOrNull($_GET['user_id'] ?? 0);
    if (!$targetID) { redirectWithToast('ไม่พบข้อมูลผู้ใช้', 'error'); }

    /* ห้ามปิดตัวเอง */
    if ($targetID === $myUserID) { redirectWithToast('ไม่สามารถปิดบัญชีของตัวเองได้', 'error'); }

    /* ดึง user ปัจจุบัน */
    $res = sqlsrv_query($conn, "SELECT UserID, RoleID, IsActive FROM CFP_User WHERE UserID=?", array($targetID));
    $u   = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$u) { redirectWithToast('ไม่พบผู้ใช้งาน', 'error'); }

    /* SustainAdmin ห้ามแตะ Admin/SustainAdmin */
    if ($isSustain && in_array((int)$u['RoleID'], array(4, 5))) {
        redirectWithToast('ไม่มีสิทธิ์จัดการ Admin', 'error');
    }

    $newActive = $u['IsActive'] ? 0 : 1;
    $label     = $newActive ? 'เปิดใช้งาน' : 'ปิดใช้งาน';

    $oldVal = json_encode(array('IsActive' => (bool)$u['IsActive']));
    $newVal = json_encode(array('IsActive' => (bool)$newActive));

    $sql = "UPDATE CFP_User SET IsActive=? WHERE UserID=?";
    $res = sqlsrv_query($conn, $sql, array($newActive, $targetID));

    if ($res === false) {
        redirectWithToast('เกิดข้อผิดพลาดในการอัปเดต', 'error');
    }

    logAction($conn, 'USER_TOGGLE', 'CFP_User', $targetID, null, null, null,
              $label . ' บัญชี UserID=' . $targetID, $oldVal, $newVal);

    redirectWithToast($label . ' บัญชีผู้ใช้เรียบร้อยแล้ว');
}

/* ===========================
   POST: create | update
   =========================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php');
    exit;
}

/* CSRF */
verifyCsrf();

$action   = $_POST['action'] ?? '';
$targetID = intOrNull($_POST['user_id'] ?? 0);

/* ===== รับค่าจาก form ===== */
$username   = trim($_POST['username']    ?? '');
$fullname   = trim($_POST['fullname']    ?? '');
$email      = trim($_POST['email']       ?? '');
$password   = $_POST['password']          ?? '';
$roleID     = intOrNull($_POST['role_id']      ?? 0);
$companyID  = intOrNull($_POST['company_id']   ?? 0);
$divisionID = intOrNull($_POST['division_id']  ?? 0);
$deptID     = intOrNull($_POST['dept_id']      ?? 0);
$sectionID  = intOrNull($_POST['section_id']   ?? 0);
$positionID = intOrNull($_POST['position_id']  ?? 0);
$siteID     = intOrNull($_POST['site_id']      ?? 0);

/* ===== Validate ===== */
$errors = array();

if (empty($username)) { $errors[] = 'กรุณาระบุชื่อผู้ใช้'; }
if (mb_strlen($username) > 100) { $errors[] = 'ชื่อผู้ใช้ยาวเกินไป'; }
if (!preg_match('/^[A-Za-z0-9._@-]+$/', $username)) {
    $errors[] = 'ชื่อผู้ใช้ใช้ได้เฉพาะ A-Z, 0-9, . _ @ -';
}
if (empty($fullname)) { $errors[] = 'กรุณาระบุชื่อ-นามสกุล'; }
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
}
if (empty($roleID)) { $errors[] = 'กรุณาเลือก Role'; }

/* ตรวจสิทธิ์ Role ที่กำหนด */
if ($roleID && !canAssignRole($myRoleID, $roleID, $allowedAssignRoles)) {
    $errors[] = 'ไม่มีสิทธิ์กำหนด Role นี้';
}

/* ===== CREATE ===== */
if ($action === 'create') {

    if (empty($password)) { $errors[] = 'กรุณาระบุรหัสผ่าน'; }
    if (!empty($password) && mb_strlen($password) < 8) {
        $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    }

    /* ตรวจ username ซ้ำ */
    $chk = sqlsrv_query($conn, "SELECT UserID FROM CFP_User WHERE Username=?", array($username));
    if (sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC)) {
        $errors[] = 'ชื่อผู้ใช้นี้มีในระบบแล้ว';
    }

    if (!empty($errors)) {
        redirectWithToast(implode(' / ', $errors), 'error');
    }

    $passHash = password_hash($password, PASSWORD_BCRYPT);

    $sql = "INSERT INTO CFP_User
            (Username, PasswordHash, FullName, Email, RoleID,
             CompanyID, DivisionID, DeptID, SectionID, PositionID, SiteID,
             IsActive, CreatedDate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, GETDATE())";

    $params = array(
        $username, $passHash, $fullname,
        (!empty($email) ? $email : null),
        $roleID, $companyID, $divisionID, $deptID, $sectionID, $positionID, $siteID
    );

    $res = sqlsrv_query($conn, $sql, $params);

    if ($res === false) {
        $errDetail = print_r(sqlsrv_errors(), true);
        error_log('users_save CREATE error: ' . $errDetail);
        redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error');
    }

    /* ดึง UserID ที่เพิ่งสร้าง */
    $newIDRes = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS NewID");
    $newRow   = sqlsrv_fetch_array($newIDRes, SQLSRV_FETCH_ASSOC);
    $newID    = (int)($newRow['NewID'] ?? 0);

    $newVal = json_encode(array(
        'Username' => $username, 'FullName' => $fullname,
        'RoleID'   => $roleID,   'Email'    => $email
    ));
    logAction($conn, 'USER_CREATE', 'CFP_User', $newID, null, null, null,
              'สร้างผู้ใช้ใหม่: ' . $fullname, null, $newVal);

    redirectWithToast('เพิ่มผู้ใช้งาน "' . $fullname . '" เรียบร้อยแล้ว');
}

/* ===== UPDATE ===== */
if ($action === 'update') {

    if (!$targetID) { redirectWithToast('ไม่พบข้อมูลผู้ใช้', 'error'); }

    /* ดึงข้อมูลเดิม */
    $resOld = sqlsrv_query($conn, "SELECT * FROM CFP_User WHERE UserID=?", array($targetID));
    $uOld   = sqlsrv_fetch_array($resOld, SQLSRV_FETCH_ASSOC);
    if (!$uOld) { redirectWithToast('ไม่พบผู้ใช้งาน', 'error'); }

    /* SustainAdmin ห้ามแก้ Admin/SustainAdmin */
    if ($isSustain && in_array((int)$uOld['RoleID'], array(4, 5))) {
        redirectWithToast('ไม่มีสิทธิ์แก้ไข Admin', 'error');
    }

    /* ตรวจ username ซ้ำ (ยกเว้นตัวเอง) */
    $chk = sqlsrv_query($conn,
        "SELECT UserID FROM CFP_User WHERE Username=? AND UserID<>?",
        array($username, $targetID));
    if (sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC)) {
        redirectWithToast('ชื่อผู้ใช้นี้มีในระบบแล้ว', 'error');
    }

    if (!empty($password) && mb_strlen($password) < 8) {
        redirectWithToast('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร', 'error');
    }

    if (!empty($errors)) {
        redirectWithToast(implode(' / ', $errors), 'error');
    }

    /* สร้าง SET clause */
    $setClauses = array(
        "Username=?", "FullName=?", "Email=?", "RoleID=?",
        "CompanyID=?", "DivisionID=?", "DeptID=?",
        "SectionID=?", "PositionID=?", "SiteID=?"
    );
    $params = array(
        $username, $fullname,
        (!empty($email) ? $email : null),
        $roleID, $companyID, $divisionID, $deptID, $sectionID, $positionID, $siteID
    );

    /* เปลี่ยนรหัสผ่านถ้ากรอกมา */
    if (!empty($password)) {
        $setClauses[] = "PasswordHash=?";
        $params[]     = password_hash($password, PASSWORD_BCRYPT);
    }

    $params[] = $targetID;
    $sql = "UPDATE CFP_User SET " . implode(', ', $setClauses) . " WHERE UserID=?";
    $res = sqlsrv_query($conn, $sql, $params);

    if ($res === false) {
        error_log('users_save UPDATE error: ' . print_r(sqlsrv_errors(), true));
        redirectWithToast('เกิดข้อผิดพลาดในการอัปเดต', 'error');
    }

    $oldVal = json_encode(array(
        'Username' => $uOld['Username'], 'FullName' => $uOld['FullName'],
        'RoleID'   => $uOld['RoleID'],  'Email'    => $uOld['Email']
    ));
    $newVal = json_encode(array(
        'Username' => $username, 'FullName' => $fullname,
        'RoleID'   => $roleID,   'Email'    => $email
    ));
    logAction($conn, 'USER_UPDATE', 'CFP_User', $targetID, null, null, null,
              'แก้ไขผู้ใช้: ' . $fullname, $oldVal, $newVal);

    redirectWithToast('แก้ไขข้อมูลผู้ใช้งานเรียบร้อยแล้ว');
}

/* ถ้าไม่ตรง action ใด */
redirectWithToast('คำขอไม่ถูกต้อง', 'error');
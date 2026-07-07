<?php
/* ==============================================
   login.php — Design D: Reversed Split
   Form ซ้าย · Visual/Scope ขวา (Wave Gradient)
   Responsive: Desktop / Tablet / Mobile
   PHP 8.3 + MSSQL (sqlsrv)
   ============================================== */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php';

function getLandingPage($roleID) {
    $map = array(
        1 => 'data_entry/scope1.php',
        2 => 'workflow/review.php',
        3 => 'workflow/approve.php',
        4 => 'dashboard/index.php',
        5 => 'master/users.php', 
        6 => 'report/index.php',
    );
    return $map[(int)$roleID] ?? 'dashboard/index.php'; 
}

if (!empty($_SESSION['user_id'])) {
    $landingPage = '/carbonfootprint/' . getLandingPage($_SESSION['role_id']);
    echo '<script>window.top.location.href=' . json_encode($landingPage) . ';</script>';
    exit;
}

$errors         = array();
$username       = '';
$rememberedUser = '';

if (!empty($_COOKIE['cfp_remember_user'])) {
    $raw = $_COOKIE['cfp_remember_user'];
    if (preg_match('/^[\w.\-@]{1,100}$/', $raw)) {
        $rememberedUser = $raw;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username   = trim($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';
    $rememberMe = !empty($_POST['remember_me']);

    if ($username === '') { $errors[] = 'กรุณากรอกชื่อผู้ใช้'; }
    if ($password === '') { $errors[] = 'กรุณากรอกรหัสผ่าน'; }

    if (empty($errors)) {
        $conn = getConnection();
        $sql  = "SELECT TOP 1
                     u.UserID, u.Username, u.FullName, u.PasswordHash,
                     u.RoleID, u.SiteID, u.Department,
                     r.RoleCode, r.RoleName, r.RoleNameEn
                 FROM CFP_User u
                 JOIN CFP_Role r ON u.RoleID = r.RoleID
                 WHERE u.Username = ? AND u.IsActive = 1 AND r.IsActive = 1";
        $res  = sqlsrv_query($conn, $sql, array($username));
        //ถ้าการค้นหาสำเร็จ (มีตัวแปร $res อยู่จริง)" ให้ดึงข้อมูลมาแปลง... แต่ถ้าไม่สำเร็จ ให้เก็บค่าว่างเปล่า (null)
        $user = $res ? sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC) : null;

        if ($user && password_verify($password, $user['PasswordHash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']      = $user['UserID'];
            $_SESSION['username']     = $user['Username'];
            $_SESSION['fullname']     = $user['FullName'];
            $_SESSION['role_id']      = $user['RoleID'];
            $_SESSION['role_code']    = $user['RoleCode'];
            $_SESSION['role_name']    = $user['RoleName'];
            $_SESSION['role_name_en'] = $user['RoleNameEn'];
            $_SESSION['site_id']      = $user['SiteID'];
            $_SESSION['dept']         = $user['Department'];
            $_SESSION['csrf_token']   = bin2hex(random_bytes(32));

            $sqlPerm = "SELECT PermCode FROM CFP_Permission WHERE RoleID = ? AND IsAllow = 1";
            $resPerm = sqlsrv_query($conn, $sqlPerm, array($user['RoleID']));
            $perms   = array();
            if ($resPerm) {
                while ($p = sqlsrv_fetch_array($resPerm, SQLSRV_FETCH_ASSOC)) {
                    $perms[] = $p['PermCode'];
                }
            }
            $_SESSION['perms'] = $perms;

            $logSql = "INSERT INTO CFP_LoginLog (UserID, LoginTime, IPAddress) VALUES (?, GETDATE(), ?)";
            sqlsrv_query($conn, $logSql, array($user['UserID'], $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));

            $alSql = "INSERT INTO CFP_ActionLog (ActorUserID, ActorRole, ActionCode, IPAddress, UserAgent)
                      VALUES (?, ?, 'LOGIN_SUCCESS', ?, ?)";
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
            sqlsrv_query($conn, $alSql, array($user['UserID'], $user['RoleID'], $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $ua));

            if ($rememberMe) {
                setcookie('cfp_remember_user', $user['Username'], array(
                    'expires' => time() + 86400 * 30, 'path' => '/carbonfootprint/',
                    'httponly' => true, 'samesite' => 'Strict',
                ));
            } else {
                setcookie('cfp_remember_user', '', array(
                    'expires' => time() - 3600, 'path' => '/carbonfootprint/',
                    'httponly' => true, 'samesite' => 'Strict',
                ));
            }

            sqlsrv_close($conn);
            $landingPage = '/carbonfootprint/' . getLandingPage($user['RoleID']);
            echo '<script>window.top.location.href=' . json_encode($landingPage) . ';</script>';
            exit;

        } else {
            if ($user) {
                $alSql = "INSERT INTO CFP_ActionLog (ActorUserID, ActorRole, ActionCode, IPAddress)
                          VALUES (?, ?, 'LOGIN_FAIL', ?)";
                sqlsrv_query($conn, $alSql, array($user['UserID'], $user['RoleID'], $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
            }
            $errors[] = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
            if ($conn) { sqlsrv_close($conn); }
        }
    }
}

/* ===== ดึงข้อมูล Scope ===== */
$scopeData = array('scope1' => null, 'scope2' => null, 'scope3' => null);
try {
    $connScope = getConnection();
    if ($connScope) {
        $curYear  = (int)date('Y');
        $sqlScope = "SELECT a.Scope, SUM(a.CO2e) AS TotalCO2e
                     FROM CFP_ActivityData a
                     INNER JOIN CFP_MonthlyHeader h ON a.HeaderID = h.HeaderID
                     WHERE LEFT(h.YearMonth, 4) = ? AND a.IsActive = 1
                     GROUP BY a.Scope";
        $resScope = sqlsrv_query($connScope, $sqlScope, array((string)$curYear));
        if ($resScope) {
            while ($row = sqlsrv_fetch_array($resScope, SQLSRV_FETCH_ASSOC)) {
                $val = (float)$row['TotalCO2e'];
                if ($row['Scope'] === 'Scope1') { $scopeData['scope1'] = $val; }
                if ($row['Scope'] === 'Scope2') { $scopeData['scope2'] = $val; }
                if ($row['Scope'] === 'Scope3') { $scopeData['scope3'] = $val; }
            }
        }
        sqlsrv_close($connScope);
    }
} catch (Exception $e) { /* empty state */ }

$buddhistYear = (int)date('Y') + 543;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>เข้าสู่ระบบ — ระบบบริหารจัดการการปล่อยก๊าซเรือนกระจก</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/css/cfp-theme.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; }

/* ── PAGE BASE ── */
body {
    font-family: 'Prompt', sans-serif;
    background: #C8E8F0;
    min-height: 100vh;
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 16px;
}

/* ── WRAPPER ── */
.login-wrap {
    display: flex;
    width: 100%;
    max-width: 960px;
    min-height: 560px;
    border-radius: 22px;
    overflow: hidden;
    box-shadow: 0 24px 64px rgba(42,100,130,.18), 0 4px 16px rgba(42,100,130,.1);
    animation: fadeInUp 0.7s ease-out both;
}

/* ════════════════════════════════
   LEFT PANEL — White Form
════════════════════════════════ */
.lp-left {
    flex: 1;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 52px 56px;
}
.lf-wrap { width: 100%; max-width: 360px; }

/* Brand */
.lf-brand { display: flex; align-items: center; gap: 11px; margin-bottom: 28px; }
.lf-brand-icon {
    width: 42px; height: 42px; border-radius: 12px;
    background: linear-gradient(135deg, #2AABB8, #5CC8D8);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; color: #fff; flex-shrink: 0;
}
.lf-brand-name { font-size: 0.78rem; font-weight: 700; color: #1A3A48; line-height: 1.4; }
.lf-brand-name small { font-size: 0.62rem; font-weight: 400; color: #7AAAB8; display: block; margin-top: 1px; }

/* Heading */
.lf-title { font-size: 1.45rem; font-weight: 700; color: #1A4A52; letter-spacing: -.02em; margin-bottom: 4px; }
.lf-sub   { font-size: 0.78rem; color: #9AAAB8; margin-bottom: 26px; }

/* Error */
.lf-error {
    background: rgba(224,80,80,.07); border: 1px solid rgba(224,80,80,.25);
    border-radius: 10px; padding: 11px 14px; font-size: 0.82rem; color: #8B2020;
    margin-bottom: 18px; display: flex; align-items: flex-start; gap: 9px;
}

/* Fields */
.lf-field { margin-bottom: 18px; }
.lf-label { font-size: 0.78rem; font-weight: 600; color: #1A3A44; margin-bottom: 7px; display: block; }
.lf-label-req::after { content: ' *'; color: #E05050; }
.lf-iw { position: relative; }
.lf-input {
    width: 100%; height: 48px; border-radius: 12px;
    border: 1.5px solid #D0E4EC; background: #FAFEFF;
    padding: 0 44px;
    font-family: 'Prompt', sans-serif; font-size: 0.88rem; color: #1A3A44;
    outline: none; transition: border-color .18s, box-shadow .18s;
}
.lf-input:focus { border-color: #2AABB8; box-shadow: 0 0 0 3px rgba(42,171,184,.14); background: #fff; }
.lf-input::placeholder { color: #C0D4DC; }
.lf-input.is-invalid { border-color: #E05050; box-shadow: 0 0 0 3px rgba(224,80,80,.08); }
.lf-icon-l {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    color: #C0D4DC; font-size: 1.05rem; pointer-events: none; transition: color .15s;
}
.lf-iw:focus-within .lf-icon-l { color: #2AABB8; }
.lf-icon-r {
    position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
    color: #C0D4DC; font-size: 1.05rem; cursor: pointer; background: none; border: none; padding: 0; transition: color .15s;
}
.lf-icon-r:hover { color: #2AABB8; }

/* Options */
.lf-options { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; margin-top: -4px; }
.lf-check { display: flex; align-items: center; gap: 7px; font-size: 0.8rem; color: #6AAAB8; cursor: pointer; }
.lf-check input[type="checkbox"] { accent-color: #2AABB8; width: 15px; height: 15px; }
.lf-forgot { font-size: 0.8rem; color: #5AAAB8; text-decoration: none; display: flex; align-items: center; gap: 4px; }
.lf-forgot:hover { color: #2AABB8; }

/* Submit */
.lf-btn {
    width: 100%; height: 50px; border-radius: 12px; border: none;
    background: linear-gradient(90deg, #2AABB8, #5CC8D8);
    color: #fff; font-family: 'Prompt', sans-serif; font-size: 0.92rem; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
    box-shadow: 0 4px 18px rgba(42,171,184,.32);
    transition: opacity .18s, box-shadow .18s, transform .1s;
}
.lf-btn:hover { opacity: .92; box-shadow: 0 6px 22px rgba(42,171,184,.44); transform: translateY(-1px); }
.lf-btn:active { transform: translateY(0); }

/* Footer */
.lf-footer {
    display: flex; align-items: center; justify-content: space-between;
    margin-top: 22px; padding-top: 16px; border-top: 1px solid #EEF2F4; flex-wrap: wrap; gap: 6px;
}
.lf-version { font-size: 0.65rem; color: #C0CCD4; }

/* ════════════════════════════════
   RIGHT PANEL — Wave Gradient (เหมือนไฟล์แรก)
════════════════════════════════ */
.lp-right {
    width: 42%;
    flex-shrink: 0;
    /* Gradient เดียวกับ .login-left ในไฟล์แรก */
    background: linear-gradient(160deg,
        #C4E8D4 0%,
        #82D4E2 38%,
        #C0DFF0 72%,
        #DAE8F2 100%
    );
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    padding: 44px 36px;
    position: relative;
    overflow: hidden;
}

/* Wave strip 1 — โค้งกลาง */
.lp-right::before {
    content: '';
    position: absolute;
    top: 38%;
    left: -80px;
    right: -80px;
    height: 120px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.22);
    transform: rotate(-6deg);
    pointer-events: none;
    z-index: 0;
}

/* Wave strip 2 — โค้งบน */
.lp-right::after {
    content: '';
    position: absolute;
    top: -30px;
    left: -80px;
    right: -80px;
    height: 110px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.18);
    transform: rotate(4deg);
    pointer-events: none;
    z-index: 0;
}

/* ซ่อนของตกแต่งเดิมที่ไม่ใช้ */
.lp-bubble { display: none; }

/* ให้เนื้อหาทั้งหมดอยู่เหนือ wave */
.lp-icon-wrap,
.lp-sys-title,
.lp-sys-sub,
.lp-divider,
.scope-list,
.lp-iso {
    position: relative;
    z-index: 2;
}

/* Icon ต้นไม้ตรงกลาง */
.lp-icon-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
}
.lp-icon-circle {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: linear-gradient(135deg, #2AABB8, #5CC8D8);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: #fff;
    box-shadow: 0 8px 24px rgba(42,171,184,.3);
}

/* ข้อความกลาง */
.lp-sys-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1A4A52;
    text-align: center;
    line-height: 1.4;
    margin-bottom: 2px;
}
.lp-sys-sub {
    font-size: 0.7rem;
    color: #2A6A78;
    text-align: center;
    margin-bottom: 20px;
}
.lp-divider {
    height: 1px;
    background: rgba(42,171,184,.25);
    margin-bottom: 18px;
    width: 100%;
}

/* Scope Cards — โปร่งแสงพอเหมาะ */
.scope-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    width: 100%;
}
.scope-card {
    background: rgba(255, 255, 255, 0.5);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.8);
    border-radius: 14px;
    padding: 12px 15px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 2px 8px rgba(42,100,130,.1);
    opacity: 0;
    animation: fadeInUp 0.5s ease-out both;
}
.scope-card:nth-child(1) { animation-delay: 0.2s; }
.scope-card:nth-child(2) { animation-delay: 0.35s; }
.scope-card:nth-child(3) { animation-delay: 0.5s; }

.scope-icon {
    width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 0.95rem;
}
.scope-icon-s1 { background: rgba(239,83,80,.15);  color: #D04040; }
.scope-icon-s2 { background: rgba(255,193,7,.18);  color: #B8860B; }
.scope-icon-s3 { background: rgba(42,171,184,.18); color: #1A8A9A; }

.scope-info { flex: 1; min-width: 0; }
.scope-name {
    font-size: 0.78rem;
    font-weight: 600;
    color: #1A3A48;
    margin-bottom: 3px;
}
.scope-desc-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.scope-desc-txt {
    font-size: 0.68rem;
    color: #2A5A68;
}
.scope-val-wrap {
    display: flex;
    align-items: baseline;
    gap: 3px;
    flex-shrink: 0;
}
.scope-val  {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1A4A52;
    line-height: 1;
}
.scope-unit { font-size: 0.55rem; color: #2A6A78; }
.scope-badge-wait {
    font-size: 0.55rem; font-weight: 600;
    background: rgba(255,193,7,.25); color: #8A6500;
    border: 1px solid rgba(180,130,0,.2); border-radius: 8px; padding: 1px 6px;
}

/* ISO footer (ถ้าต้องการ) */
.lp-iso {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid rgba(42,171,184,.18);
    font-size: 0.6rem;
    color: #2A6A78;
    width: 100%;
}

/* ── LOADING OVERLAY ── */
#loginOverlay {
    display: none; position: fixed; inset: 0;
    background: rgba(26,58,72,.88); z-index: 9999;
    align-items: center; justify-content: center; flex-direction: column; gap: 14px;
}
.overlay-txt  { color: #fff; font-family: 'Prompt', sans-serif; font-size: 0.9rem; font-weight: 500; animation: cfpFadeInOut 1.2s ease infinite; }
.overlay-sub  { font-size: 0.68rem; color: rgba(255,255,255,.35); font-family: 'Prompt', sans-serif; }

/* ── ANIMATIONS ── */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(22px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes cfpFadeInOut { 0%,100% { opacity: .5; } 50% { opacity: 1; } }
@keyframes cfpSpinCircle { to { transform: rotate(360deg); } }

/* ════════════════════════════════
   RESPONSIVE
════════════════════════════════ */
@media (max-width: 1199px) {
    .login-wrap { max-width: 860px; }
    .lp-left  { padding: 44px 44px; }
    .lp-right { width: 44%; padding: 36px 28px; }
}

@media (max-width: 991px) {
    .login-wrap { max-width: 680px; min-height: auto; flex-direction: column; border-radius: 18px; }
    .lp-left  { padding: 40px 44px; }
    .lp-right { width: 100%; padding: 24px 44px 32px; }
    .lp-icon-wrap { display: flex; } /* แสดงไอคอน */
    .scope-list { flex-direction: row; gap: 10px; }
    .scope-card { flex-direction: column; align-items: flex-start; padding: 10px 12px; flex: 1; }
    .scope-desc-row { flex-direction: column; align-items: flex-start; gap: 3px; }
    .scope-icon { width: 28px; height: 28px; font-size: 0.8rem; }
    .scope-val { font-size: 0.9rem; }
}

@media (max-width: 767px) {
    body { padding: 0; align-items: stretch; background: #EEF6F8; }
    .login-wrap { border-radius: 0; box-shadow: none; flex-direction: column; min-height: 100vh; max-width: 100%; }
    .lp-right { display: none; } /* ซ่อน visual บนมือถือ */
    .lp-left  { flex: 1; padding: 40px 32px 52px; border-top: 4px solid #2AABB8; }
    .lf-wrap  { max-width: 100%; }
    .lf-title { font-size: 1.25rem; }
}

@media (max-width: 480px) {
    .lp-left  { padding: 36px 20px 48px; }
    .lf-input { height: 44px; font-size: 0.85rem; }
    .lf-btn   { height: 46px; }
    .lf-brand-name { font-size: 0.72rem; }
}
</style>
</head>
<body>

<div class="login-wrap">

    <!-- ══ LEFT: FORM ══ -->
    <div class="lp-left">
        <div class="lf-wrap">

            <!-- Brand -->
            <div class="lf-brand">
                <div class="lf-brand-icon"><i class="bi bi-tree-fill"></i></div>
                <div class="lf-brand-name">
                    ระบบบริหารจัดการการปล่อยก๊าซเรือนกระจก
                    <small>TRUBB Greenhouse Gas Management System</small>
                </div>
            </div>

            <!-- Heading -->
            <div class="lf-title">เข้าสู่ระบบ</div>
            <div class="lf-sub">กรุณาใช้บัญชีองค์กรในการเข้าสู่ระบบ</div>

            <!-- Error -->
            <?php if (!empty($errors)) { ?>
            <div class="lf-error">
                <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;margin-top:1px;"></i>
                <div><?php echo htmlspecialchars($errors[0]); ?></div>
            </div>
            <?php } ?>

            <!-- Form -->
            <form method="POST" action="" autocomplete="off"
                  id="loginForm" onsubmit="return showLoginLoading(event)">

                <!-- Username -->
                <div class="lf-field">
                    <label class="lf-label lf-label-req" for="username">ชื่อผู้ใช้</label>
                    <div class="lf-iw">
                        <input type="text" id="username" name="username"
                               class="lf-input <?php echo !empty($errors) ? 'is-invalid' : ''; ?>"
                               placeholder="กรอกชื่อผู้ใช้"
                               value="<?php echo htmlspecialchars($username !== '' ? $username : $rememberedUser); ?>"
                               autofocus autocomplete="username" required>
                        <i class="bi bi-person lf-icon-l"></i>
                    </div>
                </div>

                <!-- Password -->
                <div class="lf-field">
                    <label class="lf-label lf-label-req" for="password">รหัสผ่าน</label>
                    <div class="lf-iw">
                        <input type="password" id="password" name="password"
                               class="lf-input <?php echo !empty($errors) ? 'is-invalid' : ''; ?>"
                               placeholder="กรอกรหัสผ่าน"
                               autocomplete="current-password" required>
                        <i class="bi bi-lock lf-icon-l"></i>
                        <button type="button" class="lf-icon-r" id="togglePass" aria-label="แสดง/ซ่อนรหัสผ่าน">
                            <i class="bi bi-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Options -->
                <div class="lf-options">
                    <label class="lf-check">
                        <input type="checkbox" name="remember_me" value="1"
                               <?php echo ($rememberedUser !== '') ? 'checked' : ''; ?>>
                        จำชื่อผู้ใช้งาน
                    </label>
                    <a href="forgot_password.php" class="lf-forgot">
                        <i class="bi bi-key"></i>ลืมรหัสผ่าน?
                    </a>
                </div>

                <button type="submit" class="lf-btn">
                    <i class="bi bi-box-arrow-in-right"></i>เข้าสู่ระบบ
                </button>

            </form>

            <!-- Footer -->
            <div class="lf-footer">
                <div class="lf-version">Program Version 1.0</div>
            </div>

        </div>
    </div><!-- /lp-left -->

    <!-- ══ RIGHT: VISUAL (Wave Gradient) ══ -->
    <div class="lp-right">
        <!-- ไม่ต้องมี bubbles อีกแล้ว (ซ่อนด้วย CSS) -->

        <!-- Icon ต้นไม้ตรงกลาง -->
        <div class="lp-icon-wrap">
            <div class="lp-icon-circle"><i class="bi bi-tree-fill"></i></div>
        </div>

        <!-- ข้อความกลาง -->
        <div class="lp-sys-title">ระบบบริหารจัดการ<br>การปล่อยก๊าซเรือนกระจก</div>
        <div class="lp-sys-sub">TRUBB · ปีงบประมาณ <?php echo $buddhistYear; ?></div>

        <div class="lp-divider"></div>

        <!-- Scope Cards -->
        <div class="scope-list">

            <!-- Scope 1 -->
            <div class="scope-card">
                <div class="scope-icon scope-icon-s1"><i class="bi bi-fire"></i></div>
                <div class="scope-info">
                    <div class="scope-name">Scope 1</div>
                    <div class="scope-desc-row">
                        <span class="scope-desc-txt">Direct Emissions</span>
                        <?php if ($scopeData['scope1'] !== null) { ?>
                        <div class="scope-val-wrap">
                            <span class="scope-val"><?php echo number_format($scopeData['scope1'], 0); ?></span>
                            <span class="scope-unit">tCO2e</span>
                        </div>
                        <?php } else { ?>
                        <span class="scope-badge-wait">รอข้อมูล</span>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <!-- Scope 2 -->
            <div class="scope-card">
                <div class="scope-icon scope-icon-s2"><i class="bi bi-lightning-charge"></i></div>
                <div class="scope-info">
                    <div class="scope-name">Scope 2</div>
                    <div class="scope-desc-row">
                        <span class="scope-desc-txt">Energy Indirect</span>
                        <?php if ($scopeData['scope2'] !== null) { ?>
                        <div class="scope-val-wrap">
                            <span class="scope-val"><?php echo number_format($scopeData['scope2'], 0); ?></span>
                            <span class="scope-unit">tCO2e</span>
                        </div>
                        <?php } else { ?>
                        <span class="scope-badge-wait">รอข้อมูล</span>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <!-- Scope 3 -->
            <div class="scope-card">
                <div class="scope-icon scope-icon-s3"><i class="bi bi-globe-asia-australia"></i></div>
                <div class="scope-info">
                    <div class="scope-name">Scope 3</div>
                    <div class="scope-desc-row">
                        <span class="scope-desc-txt">Value Chain</span>
                        <?php if ($scopeData['scope3'] !== null) { ?>
                        <div class="scope-val-wrap">
                            <span class="scope-val"><?php echo number_format($scopeData['scope3'], 0); ?></span>
                            <span class="scope-unit">tCO2e</span>
                        </div>
                        <?php } else { ?>
                        <span class="scope-badge-wait">รอข้อมูล</span>
                        <?php } ?>
                    </div>
                </div>
            </div>

        </div><!-- /scope-list -->

        <!-- (ไม่ต้องมี ISO footer ถ้าไม่ต้องการ) -->
    </div><!-- /lp-right -->

</div><!-- /login-wrap -->

<!-- Loading Overlay -->
<div id="loginOverlay">
    <svg width="52" height="52" viewBox="0 0 52 52">
        <circle cx="26" cy="26" r="21" fill="none" stroke="rgba(255,255,255,.12)" stroke-width="3.5"/>
        <circle cx="26" cy="26" r="21" fill="none" stroke="#5CC8A0" stroke-width="3.5"
                stroke-linecap="round" stroke-dasharray="32 100"
                style="transform-origin:center;animation:cfpSpinCircle .85s linear infinite;"/>
    </svg>
    <div class="overlay-txt">กำลังเข้าสู่ระบบ...</div>
    <div class="overlay-sub">ระบบบริหารการปล่อยก๊าซเรือนกระจก</div>
</div>

<script>
document.getElementById('togglePass').addEventListener('click', function() {
    var inp  = document.getElementById('password');
    var icon = document.getElementById('eyeIcon');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'bi bi-eye';
    }
});

function showLoginLoading(e) {
    var u = document.getElementById('username').value.trim();
    var p = document.getElementById('password').value;
    if (!u || !p) { return true; }
    var overlay = document.getElementById('loginOverlay');
    overlay.style.display = 'flex';
    var btn = document.querySelector('.lf-btn');
    if (btn) { btn.disabled = true; btn.style.opacity = '.7'; }
    setTimeout(function() {
        overlay.style.display = 'none';
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
    }, 8000);
    return true;
}
</script>

</body>
</html>
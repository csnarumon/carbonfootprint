<?php
/* ==============================================
   forgot_password.php
   Forgot Password — OTP 6 หลักผ่าน Email
   Flow: กรอก Email → รับ OTP → ยืนยัน OTP → ตั้งรหัสใหม่
   ============================================== */

session_start();
date_default_timezone_set('Asia/Bangkok');
require_once 'config/db.php';      /* sqlsrv_connect() */
require_once 'config/config_mail.php';    /* cfp_send_mail($to, $subject, $body) */

$conn = getConnection();

/* ===== Cancel request — ต้องอยู่ก่อน output ทั้งหมด ===== */
if (isset($_GET['cancel'])) {
    unset(
        $_SESSION['fp_step'],
        $_SESSION['fp_user_id'],
        $_SESSION['fp_email'],
        $_SESSION['fp_name'],
        $_SESSION['fp_otp_valid']
    );
    header('Location: forgot_password.php');
    exit;
}

/* ป้องกัน user ที่ Login แล้วเข้าหน้านี้ */
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

/* ---------- Helper ---------- */
function generateOTP() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function sanitizeInput($val) {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

/* ---------- State จาก Session ----------
   step = 1: กรอก Email
   step = 2: กรอก OTP
   step = 3: ตั้งรหัสใหม่
   step = 4: สำเร็จ
*/
$step   = isset($_SESSION['fp_step']) ? (int)$_SESSION['fp_step'] : 1;
$errors = array();
$info   = '';

/* ============================================================
   POST Handler
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    /* ----- Step 1: ตรวจ Email แล้วส่ง OTP ----- */
    if ($action === 'send_otp') {
        $email = sanitizeInput($_POST['email'] ?? '');

        if ($email === '') {
            $errors[] = 'กรุณากรอกอีเมล';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
        } else {
            /* ค้นหา User จาก Email */
            $sql  = 'SELECT UserID, FullName, IsActive FROM CFP_User
                     WHERE Email = ? AND IsActive = 1';
            $stmt = sqlsrv_query($conn, $sql, array($email));
            $user = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;

            /* ไม่บอกว่าหาไม่เจอ — ป้องกัน Email Enumeration */
            if (!$user) {
                $info = 'หากอีเมลนี้มีในระบบ รหัส OTP จะถูกส่งไปให้ภายใน 1 นาที';
                $step = 1;
            } else {
                /* Rate limit: OTP ใหม่ได้ทุก 2 นาที */
                $sqlCheck = 'SELECT TOP 1 RequestTime FROM CFP_OTPRequest
                             WHERE UserID = ? AND IsUsed = 0
                             ORDER BY RequestTime DESC';
                $stmtCheck = sqlsrv_query($conn, $sqlCheck, array($user['UserID']));
                $lastOTP   = $stmtCheck ? sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC) : null;

                if ($lastOTP) {
                    $lastTime = $lastOTP['RequestTime'];
                    if ($lastTime instanceof DateTime) {
                        $lastTime = $lastTime->getTimestamp();
                    } else {
                        $lastTime = strtotime($lastOTP['RequestTime']);
                    }

                    $diffSec = time() - $lastTime;

                    if ($diffSec < 120) {
                        $remain = 120 - $diffSec;
                        if ($remain >= 60) {
                            $errors[] = 'กรุณารอ ' . ceil($remain / 60) . ' นาที ก่อนขอ OTP ใหม่';
                        } else {
                            $errors[] = 'กรุณารอ ' . $remain . ' วินาที ก่อนขอ OTP ใหม่';
                        }
                    }
                }

                if (empty($errors)) {
                    $otp     = generateOTP();
                    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
                    /* ลบ $expire ออก — ให้ MSSQL คำนวณเวลาเองแทน */
                    //$expire  = date('Y-m-d H:i:s', time() + 600); /* 10 นาที */
                    $ip      = $_SERVER['REMOTE_ADDR'] ?? '';

                   $sqlIns = 'INSERT INTO CFP_OTPRequest
           (UserID, OTPCode, OTPHash, ExpireTime, IPAddress)
           VALUES (?, ?, ?, DATEADD(MINUTE, 10, GETDATE()), ?)';
                    $params = array(
                        $user['UserID'],
                        $otp,
                        $otpHash,
                       // $expire,
                        $ip
                    );
                    $ok = sqlsrv_query($conn, $sqlIns, $params);

                    if ($ok) {
                        /* ส่งอีเมล */
$subject   = 'รหัส OTP สำหรับรีเซ็ตรหัสผ่าน — GHG Management System';
/* ใช้แทน $body ใน forgot_password.php
   Outlook 365 safe — ใช้ table layout ทั้งหมด
   ไม่ใช้ border-radius, background ใน style, flex, div layout */
 
$expireStr = date('H:i', time() + 600) . ' น.';
 
/* สร้าง OTP digits */
$otpCells = '';
for ($i = 0; $i < 6; $i++) {
    $otpCells .= '
        <td width="10" style="font-size:0;">&nbsp;</td>
        <td width="48" height="56" bgcolor="#FFFFFF"
            style="border:2px solid #1B3A4A;text-align:center;vertical-align:middle;
                   font-size:28px;font-weight:bold;color:#1B3A4A;
                   font-family:Courier New,Courier,monospace;">
            ' . htmlspecialchars($otp[$i]) . '
        </td>';
}
$otpCells .= '<td width="10" style="font-size:0;">&nbsp;</td>';
 
$subject = 'รหัส OTP สำหรับรีเซ็ตรหัสผ่าน — GHG Management System';
 
$body = '
<table width="600" cellpadding="0" cellspacing="0" border="0"
       align="center" bgcolor="#F0F4F8"
       style="font-family:Tahoma,Arial,sans-serif;">
  <tr>
    <td style="padding:24px 0;">
 
      <!-- Card wrapper -->
      <table width="520" cellpadding="0" cellspacing="0" border="0"
             align="center" bgcolor="#FFFFFF"
             style="border:1px solid #E0E0E0;">
 
        <!-- Header -->
        <tr>
          <td bgcolor="#1B3A4A" style="padding:20px 28px;">
            <table cellpadding="0" cellspacing="0" border="0" width="100%">
              <tr>
                <td style="color:#4CAF50;font-size:16px;font-weight:bold;
                           letter-spacing:1px;padding-bottom:4px;">
                  &#127807; GHG Management System
                </td>
              </tr>
              <tr>
                <td style="color:#FFFFFF;font-size:18px;font-weight:bold;">
                  รหัสยืนยันการรีเซ็ตรหัสผ่าน
                </td>
              </tr>
            </table>
          </td>
        </tr>
 
        <!-- Body -->
        <tr>
          <td style="padding:28px;">
 
            <!-- ทักทาย -->
            <table cellpadding="0" cellspacing="0" border="0" width="100%">
              <tr>
                <td style="font-size:14px;color:#37474F;line-height:1.8;
                           padding-bottom:20px;font-family:Tahoma,Arial,sans-serif;line-height:1.7;">
                  เรียน <b>คุณ' . htmlspecialchars($user['FullName']) . '</b><br>
                  ระบบได้รับคำขอรีเซ็ตรหัสผ่านของท่าน<br>
                  กรุณาใช้รหัส OTP ด้านล่างนี้เพื่อดำเนินการต่อ
                </td>
              </tr>
            </table>
 
            <!-- กล่อง OTP -->
            <table cellpadding="0" cellspacing="0" border="0" width="100%">
              <tr>
                <td bgcolor="#EEF4FF"
                    style="padding:24px 16px;text-align:center;
                           border:1px solid #BBDEFB;">
 
                  <!-- Label -->
                  <table cellpadding="0" cellspacing="0" border="0" width="100%">
                    <tr>
                      <td style="text-align:center;font-size:11px;color:#546E7A;
                                 letter-spacing:1px;padding-bottom:14px;font-family:Tahoma,Arial,sans-serif;line-height:1.7;">
                        รหัส OTP ของท่าน
                      </td>
                    </tr>
                  </table>
 
                  <!-- ตัวเลข OTP -->
                  <table cellpadding="0" cellspacing="0" border="0" align="center">
                    <tr>' . $otpCells . '</tr>
                  </table>
 
                  <!-- หมดอายุ -->
                  <table cellpadding="0" cellspacing="0" border="0" width="100%">
                    <tr>
                      <td style="text-align:center;font-size:12px;color:#78909C;
                                 padding-top:14px;font-family:Tahoma,Arial,sans-serif;line-height:1.7;">
                        รหัสนี้จะหมดอายุใน
                        <b style="color:#E53935;">10 นาที</b>
                        &nbsp;(เวลา ' . $expireStr . ')
                      </td>
                    </tr>
                  </table>
 
                </td>
              </tr>
            </table>
 
            <!-- เส้นแบ่ง -->
            <table cellpadding="0" cellspacing="0" border="0" width="100%">
              <tr style="vertical-align:top;">
                <td bgcolor="#F0F0F0" height="1"
                    style="font-size:0;line-height:0;padding:12px 0 12px;">
                  &nbsp;
                </td>
              </tr>
            </table>
<!-- คำแนะนำ -->
<table cellpadding="0" cellspacing="0" border="0" width="100%">
  <tr>
    <td width="26" valign="top" style="padding:4px 0 10px 0;">
      <table cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td width="20" height="20" bgcolor="#E8F5E9"
              style="text-align:center;vertical-align:middle;
                     font-size:11px;font-weight:bold;color:#2E7D32;
                     border:1px solid #A5D6A7;">
            &#10003;
          </td>
        </tr>
      </table>
    </td>
    <td valign="top" style="font-size:13px;color:#546E7A;
        padding:4px 0 10px 6px;font-family:Tahoma,Arial,sans-serif;line-height:1.5;">
      กรอกรหัส OTP 6 หลักนี้ในหน้าเว็บที่เปิดค้างไว้
    </td>
  </tr>
  <tr>
    <td width="26" valign="top" style="padding:4px 0 10px 0;">
      <table cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td width="20" height="20" bgcolor="#E3F2FD"
              style="text-align:center;vertical-align:middle;
                     font-size:11px;font-weight:bold;color:#1565C0;
                     border:1px solid #90CAF9;">
            i
          </td>
        </tr>
      </table>
    </td>
    <td valign="top" style="font-size:13px;color:#546E7A;
        padding:4px 0 10px 6px;font-family:Tahoma,Arial,sans-serif;line-height:1.5;">
      สามารถกรอกผิดได้ไม่เกิน <b>5 ครั้ง</b> ก่อนที่รหัสจะถูกยกเลิกอัตโนมัติ
    </td>
  </tr>

</table>
 
            <!-- คำเตือน OTP -->
            <table cellpadding="0" cellspacing="0" border="0" width="100%"
                   style="margin-top:16px;">
              <tr>
                <td width="3" bgcolor="#FFA000"
                    style="font-size:0;line-height:0;">&nbsp;</td>
                <td bgcolor="#FFF8E1"
                    style="padding:12px 14px;font-size:12px;
                           color:#5D4037;
                           border-top:1px solid #FFE082;
                           border-right:1px solid #FFE082;
                           border-bottom:1px solid #FFE082;">
                  <b>&#128274; อย่าบอกรหัส OTP นี้แก่ผู้อื่น</b>
                </td>
              </tr>
            </table>
 
          </td>
        </tr>
 
        <!-- Footer -->
        <tr>
          <td bgcolor="#F5F7FA"
              style="padding:16px 28px;border-top:1px solid #E0E0E0;
                     font-size:11px;color:#90A4AE;
                     text-align:center;">
            อีเมลนี้ถูกส่งอัตโนมัติ กรุณาอย่าตอบกลับ<br>
            หากมีปัญหา ติดต่อผู้ดูแลระบบ:
            <a href="mailto:it@thaitex.com"
               style="color:#1B3A4A;text-decoration:none;">
              it@thaitex.com
            </a>
          </td>
        </tr>
 
      </table>
      <!-- /Card wrapper -->
 
    </td>
  </tr>
</table>';
                        cfp_send_mail($email, $subject, $body);

                        /* เก็บ Session สำหรับ Step 2 */
                        $_SESSION['fp_step']    = 2;
                        $_SESSION['fp_user_id'] = $user['UserID'];
                        $_SESSION['fp_email']   = $email;
                        $_SESSION['fp_name']    = $user['FullName'];

                        header('Location: forgot_password.php');
                        exit;
                    } else {
                        $errors[] = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง';
                    }
                }
            }
        }
    }

    /* ----- Step 2: ยืนยัน OTP ----- */
    if ($action === 'verify_otp') {
        $otpInput = trim($_POST['otp'] ?? '');
        $userID   = (int)($_SESSION['fp_user_id'] ?? 0);

        if (!$userID) {
            header('Location: forgot_password.php');
            exit;
        }

        if (!preg_match('/^\d{6}$/', $otpInput)) {
            $errors[] = 'OTP ต้องเป็นตัวเลข 6 หลักเท่านั้น';
        } else {
            /* ดึง OTP ล่าสุดที่ยังไม่ใช้และไม่หมดอายุ */
            $sqlOTP  = 'SELECT TOP 1 OTPID, OTPHash, ExpireTime, AttemptCount
                        FROM CFP_OTPRequest
                        WHERE UserID = ? AND IsUsed = 0
                          AND ExpireTime > GETDATE()
                        ORDER BY RequestTime DESC';
            $stmtOTP = sqlsrv_query($conn, $sqlOTP, array($userID));
            $otpRow  = $stmtOTP ? sqlsrv_fetch_array($stmtOTP, SQLSRV_FETCH_ASSOC) : null;

            if (!$otpRow) {
                $errors[] = 'OTP หมดอายุแล้ว กรุณาขอ OTP ใหม่';
                $_SESSION['fp_step'] = 1;
                unset($_SESSION['fp_user_id'], $_SESSION['fp_email']);
            } elseif ($otpRow['AttemptCount'] >= 5) {
                /* ล็อค OTP ทันทีถ้ากรอกผิดเกิน 5 ครั้ง */
                $sqlUsed = 'UPDATE CFP_OTPRequest SET IsUsed = 1, UsedTime = GETDATE()
                            WHERE OTPID = ?';
                sqlsrv_query($conn, $sqlUsed, array($otpRow['OTPID']));
                $errors[] = 'กรอกรหัสผิดเกิน 5 ครั้ง กรุณาขอ OTP ใหม่';
                $_SESSION['fp_step'] = 1;
                unset($_SESSION['fp_user_id'], $_SESSION['fp_email']);
            } elseif (!password_verify($otpInput, $otpRow['OTPHash'])) {
                /* เพิ่ม AttemptCount */
                $sqlAtt = 'UPDATE CFP_OTPRequest SET AttemptCount = AttemptCount + 1
                           WHERE OTPID = ?';
                sqlsrv_query($conn, $sqlAtt, array($otpRow['OTPID']));
                $remain   = 5 - ($otpRow['AttemptCount'] + 1);
                $errors[] = 'รหัส OTP ไม่ถูกต้อง (เหลือ ' . $remain . ' ครั้ง)';
            } else {
                /* OTP ถูกต้อง — mark used */
                $sqlUsed = 'UPDATE CFP_OTPRequest SET IsUsed = 1, UsedTime = GETDATE()
                            WHERE OTPID = ?';
                sqlsrv_query($conn, $sqlUsed, array($otpRow['OTPID']));

                $_SESSION['fp_step']      = 3;
                $_SESSION['fp_otp_valid'] = true;

                header('Location: forgot_password.php');
                exit;
            }
        }
        $step = (int)($_SESSION['fp_step'] ?? 2);
    }

    /* ----- Step 3: ตั้งรหัสผ่านใหม่ ----- */
    if ($action === 'reset_password') {
        $userID   = (int)($_SESSION['fp_user_id'] ?? 0);
        $otpValid = !empty($_SESSION['fp_otp_valid']);

        if (!$userID || !$otpValid) {
            header('Location: forgot_password.php');
            exit;
        }

        $newPass  = $_POST['new_password']     ?? '';
        $confPass = $_POST['confirm_password'] ?? '';

        if (strlen($newPass) < 8) {
            $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
        }
        if (!preg_match('/[A-Z]/', $newPass)) {
            $errors[] = 'ต้องมีตัวพิมพ์ใหญ่อย่างน้อย 1 ตัว';
        }
        if (!preg_match('/[0-9]/', $newPass)) {
            $errors[] = 'ต้องมีตัวเลขอย่างน้อย 1 ตัว';
        }
        if ($newPass !== $confPass) {
            $errors[] = 'รหัสผ่านและการยืนยันไม่ตรงกัน';
        }

        if (empty($errors)) {
            $hash   = password_hash($newPass, PASSWORD_DEFAULT);
            $sqlUpd = 'UPDATE CFP_User SET PasswordHash = ? WHERE UserID = ?';
            $ok     = sqlsrv_query($conn, $sqlUpd, array($hash, $userID));

            if ($ok) {
                /* บันทึก ActionLog */
                $sqlLog = 'INSERT INTO CFP_ActionLog
                           (ActorUserID, ActorRole, ActionCode, TargetTable, TargetID, IPAddress)
                           VALUES (?, 0, \'PASSWORD_RESET\', \'CFP_User\', ?, ?)';
                sqlsrv_query($conn, $sqlLog, array(
                    $userID, $userID, $_SERVER['REMOTE_ADDR'] ?? ''
                ));

                /* ล้าง Session */
                unset(
                    $_SESSION['fp_step'],
                    $_SESSION['fp_user_id'],
                    $_SESSION['fp_email'],
                    $_SESSION['fp_name'],
                    $_SESSION['fp_otp_valid']
                );
                $_SESSION['fp_step'] = 4;

                header('Location: forgot_password.php');
                exit;
            } else {
                $errors[] = 'เกิดข้อผิดพลาดในการบันทึก กรุณาลองใหม่';
            }
        }
        $step = 3;
    }
}

/* อ่าน step ล่าสุดจาก Session */
$step      = isset($_SESSION['fp_step'])  ? (int)$_SESSION['fp_step']  : 1;
$fpEmail   = $_SESSION['fp_email']  ?? '';
$fpName    = $_SESSION['fp_name']   ?? '';

/* ============================================================
   HTML Output
   ============================================================ */
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ลืมรหัสผ่าน — GHG Management System</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {
    font-family: 'Prompt', sans-serif;
    background: #F0F4F8;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.fp-card {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #E0E0E0;
    padding: 40px 36px;
    width: 100%;
    max-width: 440px;
}
.fp-logo {
    color: #4CAF50;
    font-size: 1rem;
    font-weight: 600;
    letter-spacing: .5px;
    margin-bottom: 4px;
}
.fp-title {
    color: #1B3A4A;
    font-size: 1.3rem;
    font-weight: 600;
    margin-bottom: 6px;
}
.fp-subtitle {
    color: #78909C;
    font-size: 0.85rem;
    margin-bottom: 28px;
}
/* Step indicator */
.step-bar {
    display: flex;
    align-items: center;
    margin-bottom: 28px;
    gap: 0;
}
.step-dot {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    flex-shrink: 0;
    transition: all .3s;
}
.step-dot.done    { background: #4CAF50; color: #fff; }
.step-dot.current { background: #1B3A4A; color: #fff; }
.step-dot.pending { background: #E0E0E0; color: #9E9E9E; }
.step-line {
    flex: 1;
    height: 2px;
    background: #E0E0E0;
    transition: background .3s;
}
.step-line.done { background: #4CAF50; }
.step-label {
    font-size: 9px;
    color: #9E9E9E;
    text-align: center;
    margin-top: 4px;
}
.step-label.current { color: #1B3A4A; font-weight: 600; }
.step-label.done    { color: #4CAF50; }
/* OTP Input */
.otp-wrap {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin: 20px 0;
}
.otp-input {
    width: 48px;
    height: 56px;
    text-align: center;
    font-size: 1.4rem;
    font-weight: 600;
    font-family: 'Prompt', sans-serif;
    border: 2px solid #E0E0E0;
    border-radius: 10px;
    color: #1B3A4A;
    transition: border-color .2s;
    outline: none;
}
.otp-input:focus { border-color: #1B3A4A; }
.otp-input.filled { border-color: #4CAF50; background: #F1F8E9; }
/* Form controls */
.cfp-label { font-size: 0.82rem; font-weight: 500; color: #1B3A4A; margin-bottom: 5px; }
.cfp-input {
    font-family: 'Prompt', sans-serif;
    border: 1.5px solid #E0E0E0;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 0.88rem;
    color: #1B3A4A;
    width: 100%;
    transition: border-color .2s;
    outline: none;
}
.cfp-input:focus { border-color: #1B3A4A; }
.cfp-input.is-invalid { border-color: #DC3545; }
/* Password strength */
.pw-strength { height: 4px; border-radius: 2px; margin-top: 6px; transition: all .3s; }
.pw-hint { font-size: 0.75rem; color: #78909C; margin-top: 5px; }
/* Buttons */
.btn-cfp-primary {
    font-family: 'Prompt', sans-serif;
    background: #1B3A4A;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 11px;
    font-size: 0.9rem;
    font-weight: 500;
    width: 100%;
    cursor: pointer;
    transition: background .2s;
}
.btn-cfp-primary:hover { background: #0F2A38; }
.btn-cfp-primary:disabled { background: #B0BEC5; cursor: not-allowed; }
.btn-cfp-ghost {
    font-family: 'Prompt', sans-serif;
    background: transparent;
    color: #78909C;
    border: 1.5px solid #E0E0E0;
    border-radius: 8px;
    padding: 10px;
    font-size: 0.85rem;
    width: 100%;
    cursor: pointer;
    transition: all .2s;
    margin-top: 10px;
}
.btn-cfp-ghost:hover { border-color: #1B3A4A; color: #1B3A4A; }
/* Alert */
.cfp-alert-err {
    background: #FFEBEE;
    border-left: 3px solid #DC3545;
    color: #B71C1C;
    border-radius: 0 8px 8px 0;
    padding: 10px 14px;
    font-size: 0.82rem;
    margin-bottom: 18px;
}
.cfp-alert-info {
    background: #E3F2FD;
    border-left: 3px solid #1E88E5;
    color: #0D47A1;
    border-radius: 0 8px 8px 0;
    padding: 10px 14px;
    font-size: 0.82rem;
    margin-bottom: 18px;
}
/* Success */
.success-icon {
    width: 72px; height: 72px;
    background: #E8F5E9;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 20px;
    font-size: 2rem;
    color: #4CAF50;
}
.back-link {
    display: block;
    text-align: center;
    font-size: 0.82rem;
    color: #78909C;
    margin-top: 20px;
    text-decoration: none;
}
.back-link:hover { color: #1B3A4A; }
/* Resend timer */
.resend-wrap { text-align: center; font-size: 0.82rem; color: #78909C; margin-top: 14px; }
.resend-link { color: #1B3A4A; font-weight: 500; cursor: pointer; text-decoration: underline; }
</style>
</head>
<body>

<div class="fp-card">

    <div class="fp-logo"><i class="bi bi-leaf-fill"></i> CFP</div>
    <div class="fp-title">
        <?php
        if ($step === 1) { echo 'ลืมรหัสผ่าน'; }
        elseif ($step === 2) { echo 'ยืนยัน OTP'; }
        elseif ($step === 3) { echo 'ตั้งรหัสผ่านใหม่'; }
        else { echo 'เปลี่ยนรหัสผ่านสำเร็จ'; }
        ?>
    </div>
    <div class="fp-subtitle">GHG Management System</div>

    <?php if ($step <= 3) { ?>
    <!-- Step Indicator -->
    <div>
        <div class="step-bar">
            <!-- Step 1 -->
            <div class="step-dot <?php echo $step > 1 ? 'done' : ($step === 1 ? 'current' : 'pending'); ?>">
                <?php echo $step > 1 ? '<i class="bi bi-check"></i>' : '1'; ?>
            </div>
            <div class="step-line <?php echo $step > 1 ? 'done' : ''; ?>"></div>
            <!-- Step 2 -->
            <div class="step-dot <?php echo $step > 2 ? 'done' : ($step === 2 ? 'current' : 'pending'); ?>">
                <?php echo $step > 2 ? '<i class="bi bi-check"></i>' : '2'; ?>
            </div>
            <div class="step-line <?php echo $step > 2 ? 'done' : ''; ?>"></div>
            <!-- Step 3 -->
            <div class="step-dot <?php echo $step === 3 ? 'current' : 'pending'; ?>">3</div>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:4px;">
            <span class="step-label <?php echo $step === 1 ? 'current' : ($step > 1 ? 'done' : ''); ?>" style="flex:1;text-align:left;">กรอก Email</span>
            <span class="step-label <?php echo $step === 2 ? 'current' : ($step > 2 ? 'done' : ''); ?>" style="flex:1;text-align:center;">ยืนยัน OTP</span>
            <span class="step-label <?php echo $step === 3 ? 'current' : ''; ?>" style="flex:1;text-align:right;">รหัสใหม่</span>
        </div>
    </div>
    <?php } ?>

    <!-- Error / Info -->
    <?php if (!empty($errors)) { ?>
    <div class="cfp-alert-err mt-3">
        <i class="bi bi-exclamation-circle me-1"></i>
        <?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?>
    </div>
    <?php } ?>
    <?php if ($info !== '') { ?>
    <div class="cfp-alert-info mt-3">
        <i class="bi bi-info-circle me-1"></i>
        <?php echo htmlspecialchars($info); ?>
    </div>
    <?php } ?>

    <!-- ==============================
         STEP 1: กรอก Email
         ============================== -->
    <?php if ($step === 1) { ?>
    <form method="POST" action="forgot_password.php" novalidate>
        <input type="hidden" name="action" value="send_otp">
        <div class="mb-3 mt-3">
            <label class="cfp-label">อีเมลที่ลงทะเบียนไว้ในระบบ</label>
            <input type="email" name="email" class="cfp-input <?php echo !empty($errors) ? 'is-invalid' : ''; ?>"
                   placeholder="example@company.com"
                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                   autocomplete="email" autofocus required>
        </div>
        <button type="submit" class="btn-cfp-primary">
            <i class="bi bi-send me-1"></i> ส่งรหัส OTP ไปยังอีเมล
        </button>
    </form>
    <a href="login.php" class="back-link"><i class="bi bi-arrow-left me-1"></i>กลับหน้าเข้าสู่ระบบ</a>
    <?php } ?>

    <!-- ==============================
         STEP 2: กรอก OTP 6 หลัก
         ============================== -->
    <?php if ($step === 2) { ?>
    <p style="font-size:0.83rem;color:#546E7A;text-align:center;margin-top:16px;">
        ระบบส่ง OTP ไปที่<br>
        <strong style="color:#1B3A4A;"><?php echo htmlspecialchars($fpEmail); ?></strong><br>
        <span style="font-size:0.78rem;color:#90A4AE;">OTP มีอายุ 10 นาที · กรอกผิดได้ไม่เกิน 5 ครั้ง</span>
    </p>

    <form method="POST" action="forgot_password.php" id="formOTP" novalidate>
        <input type="hidden" name="action" value="verify_otp">
        <input type="hidden" name="otp" id="otpHidden">

        <div class="otp-wrap">
            <?php for ($i = 1; $i <= 6; $i++) { ?>
            <input type="text" inputmode="numeric" maxlength="1"
                   class="otp-input" id="otp<?php echo $i; ?>"
                   autocomplete="off">
            <?php } ?>
        </div>

        <button type="submit" class="btn-cfp-primary" id="btnVerify" disabled>
            <i class="bi bi-shield-check me-1"></i> ยืนยัน OTP
        </button>
    </form>

    <div class="resend-wrap">
        ไม่ได้รับรหัส?
        <span id="resendTimer">ขอใหม่ได้ใน <span id="timerCount">120</span> วินาที</span>
        <span id="resendLink" style="display:none;">
            <span class="resend-link" onclick="resendOTP()">ส่ง OTP ใหม่</span>
        </span>
    </div>

    <button class="btn-cfp-ghost" onclick="cancelReset()">
        <i class="bi bi-arrow-left me-1"></i> เริ่มใหม่
    </button>

    <form method="POST" action="forgot_password.php" id="formResend" style="display:none;">
        <input type="hidden" name="action" value="send_otp">
        <input type="hidden" name="email" value="<?php echo htmlspecialchars($fpEmail); ?>">
    </form>
    <form method="POST" action="forgot_password.php" id="formCancel" style="display:none;">
        <input type="hidden" name="action" value="cancel">
    </form>
    <?php } ?>

    <!-- ==============================
         STEP 3: ตั้งรหัสผ่านใหม่
         ============================== -->
    <?php if ($step === 3) { ?>
    <form method="POST" action="forgot_password.php" id="formReset" novalidate>
        <input type="hidden" name="action" value="reset_password">

        <div class="mb-3 mt-3">
            <label class="cfp-label">รหัสผ่านใหม่</label>
            <div style="position:relative;">
                <input type="password" name="new_password" id="newPass" class="cfp-input"
                       placeholder="อย่างน้อย 8 ตัว, มีพิมพ์ใหญ่+ตัวเลข"
                       autocomplete="new-password" autofocus required>
                <button type="button" onclick="togglePass('newPass','eyeNew')"
                        style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#78909C;cursor:pointer;padding:0;">
                    <i class="bi bi-eye" id="eyeNew"></i>
                </button>
            </div>
            <div class="pw-strength" id="pwStrengthBar" style="background:#E0E0E0;width:0%"></div>
            <div class="pw-hint" id="pwHint">กรอกรหัสผ่านเพื่อตรวจสอบความแข็งแรง</div>
        </div>

        <div class="mb-4">
            <label class="cfp-label">ยืนยันรหัสผ่านใหม่</label>
            <div style="position:relative;">
                <input type="password" name="confirm_password" id="confPass" class="cfp-input"
                       placeholder="กรอกรหัสผ่านอีกครั้ง"
                       autocomplete="new-password" required>
                <button type="button" onclick="togglePass('confPass','eyeConf')"
                        style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#78909C;cursor:pointer;padding:0;">
                    <i class="bi bi-eye" id="eyeConf"></i>
                </button>
            </div>
            <div class="pw-hint" id="confHint"></div>
        </div>

        <button type="submit" class="btn-cfp-primary" id="btnReset">
            <i class="bi bi-lock me-1"></i> บันทึกรหัสผ่านใหม่
        </button>
    </form>
    <?php } ?>

    <!-- ==============================
         STEP 4: สำเร็จ
         ============================== -->
    <?php if ($step === 4) { ?>
    <div style="text-align:center;padding:20px 0;">
        <div class="success-icon"><i class="bi bi-check-lg"></i></div>
        <div style="font-size:1rem;font-weight:600;color:#1B3A4A;margin-bottom:8px;">
            เปลี่ยนรหัสผ่านเรียบร้อยแล้ว
        </div>
        <div style="font-size:0.83rem;color:#78909C;margin-bottom:24px;">
            กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่ของท่าน
        </div>
        <a href="login.php" class="btn-cfp-primary" style="text-decoration:none;display:block;padding:11px;text-align:center;">
            <i class="bi bi-box-arrow-in-right me-1"></i> เข้าสู่ระบบ
        </a>
    </div>
    <?php
        /* ล้าง Step 4 ออกจาก Session */
        unset($_SESSION['fp_step']);
    } ?>

</div><!-- /.fp-card -->

<script>
/* ---- OTP Input Navigation ---- */
(function() {
    var inputs = document.querySelectorAll('.otp-input');
    if (!inputs.length) { return; }

    inputs.forEach(function(inp, idx) {
        inp.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 1);
            if (this.value) {
                this.classList.add('filled');
                if (idx < 5) { inputs[idx + 1].focus(); }
            } else {
                this.classList.remove('filled');
            }
            syncOTP();
        });

        inp.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !this.value && idx > 0) {
                inputs[idx - 1].focus();
                inputs[idx - 1].value = '';
                inputs[idx - 1].classList.remove('filled');
                syncOTP();
            }
            /* paste support */
            if (e.key === 'v' && (e.ctrlKey || e.metaKey)) { return; }
        });

        inp.addEventListener('paste', function(e) {
            e.preventDefault();
            var pasted = (e.clipboardData || window.clipboardData)
                          .getData('text').replace(/\D/g, '').slice(0, 6);
            pasted.split('').forEach(function(ch, i) {
                if (inputs[i]) {
                    inputs[i].value = ch;
                    inputs[i].classList.add('filled');
                }
            });
            if (inputs[pasted.length - 1]) { inputs[pasted.length - 1].focus(); }
            syncOTP();
        });
    });

    function syncOTP() {
        var val = '';
        inputs.forEach(function(inp) { val += inp.value; });
        var hidden = document.getElementById('otpHidden');
        var btn    = document.getElementById('btnVerify');
        if (hidden) { hidden.value = val; }
        if (btn)    { btn.disabled = val.length < 6; }
    }

    inputs[0] && inputs[0].focus();
})();

/* ---- Resend OTP Countdown ---- */
(function() {
    var countEl  = document.getElementById('timerCount');
    var timerEl  = document.getElementById('resendTimer');
    var linkEl   = document.getElementById('resendLink');
    if (!countEl) { return; }

    var sec = 120;
    var iv  = setInterval(function() {
        sec--;
        countEl.textContent = sec;
        if (sec <= 0) {
            clearInterval(iv);
            timerEl.style.display = 'none';
            linkEl.style.display  = 'inline';
        }
    }, 1000);
})();

function resendOTP() {
    document.getElementById('formResend').submit();
}

function cancelReset() {
    /* ล้าง Session ฝั่ง server ผ่าน GET param */
    window.location.href = 'forgot_password.php?cancel=1';
}

/* ---- Password Strength ---- */
(function() {
    var newPass  = document.getElementById('newPass');
    var confPass = document.getElementById('confPass');
    if (!newPass) { return; }

    newPass.addEventListener('input', function() {
        var v   = this.value;
        var lvl = 0;
        if (v.length >= 8)           { lvl++; }
        if (/[A-Z]/.test(v))         { lvl++; }
        if (/[0-9]/.test(v))         { lvl++; }
        if (/[^A-Za-z0-9]/.test(v))  { lvl++; }

        var bar  = document.getElementById('pwStrengthBar');
        var hint = document.getElementById('pwHint');
        var colors = ['#E0E0E0','#DC3545','#FFA726','#4CAF50','#1B3A4A'];
        var labels = ['','อ่อนมาก','พอใช้','ดี','แข็งแรงมาก'];
        bar.style.width     = (lvl * 25) + '%';
        bar.style.background = colors[lvl];
        hint.textContent    = lvl > 0 ? 'ความแข็งแรง: ' + labels[lvl] : '';
        checkMatch();
    });

    confPass && confPass.addEventListener('input', checkMatch);

    function checkMatch() {
        var hint = document.getElementById('confHint');
        var btn  = document.getElementById('btnReset');
        if (!hint || !confPass.value) { if(hint){hint.textContent='';} return; }
        if (newPass.value === confPass.value) {
            hint.textContent  = '✓ รหัสผ่านตรงกัน';
            hint.style.color  = '#4CAF50';
            if(btn) { btn.disabled = false; }
        } else {
            hint.textContent  = '✗ รหัสผ่านไม่ตรงกัน';
            hint.style.color  = '#DC3545';
            if(btn) { btn.disabled = true; }
        }
    }
})();

/* ---- Toggle Password Visibility ---- */
function togglePass(inputId, iconId) {
    var inp  = document.getElementById(inputId);
    var icon = document.getElementById(iconId);
    if (!inp) { return; }
    if (inp.type === 'password') {
        inp.type       = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        inp.type       = 'password';
        icon.className = 'bi bi-eye';
    }
}
</script>

</body>
</html>

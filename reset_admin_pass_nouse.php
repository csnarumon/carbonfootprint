<?php
/* ==============================================
   reset_admin_pass.php
   ใช้ครั้งเดียว → ลบทิ้งทันทีหลังรัน
   ============================================== */

require_once 'config/db.php';

$newPassword = 'Admin@1234';
$hash        = password_hash($newPassword, PASSWORD_DEFAULT);

$conn = getConnection();
$sql  = "UPDATE CFP_User SET PasswordHash = ? WHERE Username = 'admin'";
$res  = sqlsrv_query($conn, $sql, array($hash));

if ($res) {
    echo '<p style="color:green;font-family:monospace;">✓ เปลี่ยนรหัสผ่าน admin เป็น <strong>Admin@1234</strong> สำเร็จ</p>';
    echo '<p style="color:red;font-family:monospace;">⚠ ลบไฟล์นี้ทิ้งทันที!</p>';
} else {
    echo '<p style="color:red;font-family:monospace;">✗ ERROR: ';
    print_r(sqlsrv_errors());
    echo '</p>';
}

sqlsrv_close($conn);
?>
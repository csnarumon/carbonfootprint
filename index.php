<?php
/* ==============================================
   /carbonfootprint/index.php  (root entry point)
   redirect อัตโนมัติ:
     - ยังไม่ login  → login.php
     - login แล้ว   → หน้าตาม Role
   ============================================== */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getLandingPage($roleID) {
    $map = array(
        1 => 'data_entry/scope1.php', // Role 1: ไปยังหน้าคีย์ข้อมูล Scope 1
        2 => 'workflow/review.php',    // Role 2: ไปยังหน้าสำหรับผู้ตรวจสอบ
        3 => 'workflow/approve.php',   // Role 3: ไปยังหน้าสำหรับผู้อนุมัติ
        4 => 'dashboard/index.php',    // Role 4: ไปยังหน้าหลัก Dashboard
        5 => 'master/users.php',       // Role 5: ไปยังหน้าการจัดการบัญชีผู้ใช้งานระบบ
        6 => 'report/index.php',       // Role 6: ไปยังหน้าสรุปรายงาน 
    );
    return $map[(int)$roleID] ?? 'dashboard/index.php'; 
}

if (!empty($_SESSION['user_id'])) {
    header('Location: /carbonfootprint/' . getLandingPage($_SESSION['role_id']));
} else {
    header('Location: /carbonfootprint/login.php');
}
exit;

<?php
/* logout.php — ล้าง session + redirect ออกจาก iframe */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

session_unset();
/*
การเขียนเว็บทั่วไป เราไม่สามารถลบคุกกี้ในเครื่องผู้ใช้ตรงๆ ได้ 
วิธีเดียวที่จะลบคุกกี้ได้คือ การส่งคุกกี้ตัวเดิมไปอีกรอบ 
แต่กำหนดวันหมดอายุเป็นเวลาในอดีต (เช่น ย้อนหลังไป 42,000 วินาที หรือประมาณ 11 ชั่วโมง) 
เมื่อบราวเซอร์เห็นว่าคุกกี้นี้ "หมดอายุไปตั้งนานแล้ว" มันจะทำการ ลบคุกกี้ตัวนั้นออกจากเครื่องของผู้ใช้ทันที 
*/
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly'] 
    );
}
/* session_destroy() นั้นมีไว้ล้างข้อมูลในฝั่งเซิฟเวอร์เท่านั้น  
แต่ข้อมูลที่เก็บอยู่ในรูปของคุ้กกี้ในเบราว์เซอร์ของผู้ใช้งานจะยังคงอยู่ไม่ยอมหายไป 
แม้จะใช้งานไม่ได้แล้วก็ตาม จึงต้องเช็ค if (ini_get('session.use_cookies'))  */
session_destroy();
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body>
<script>
/* ถ้าอยู่ใน iframe ให้ redirect ทั้ง window หลัก */
if (window.self !== window.top) {
    window.top.location.href = '/carbonfootprint/login.php';
} else {
    window.location.href = '/carbonfootprint/login.php';
}
</script>
</body>
</html>
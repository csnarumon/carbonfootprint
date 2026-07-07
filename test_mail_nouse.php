<?php
require_once 'config/config_mail.php';

$ok = cfp_send_mail(
    'narumon.it@thaitex.com',
    'ทดสอบ OTP — CFP System',
    '<p>ทดสอบส่งอีเมล</p>
     <h2 style="letter-spacing:8px;color:#1B3A4A;text-align:center;">123456</h2>
     <p>ถ้าได้รับอีเมลนี้แปลว่า SMTP ใช้งานได้แล้ว</p>'
);

echo $ok ? '✅ ส่งอีเมลสำเร็จ' : '❌ ส่งไม่ได้ — เปิด php_error.log ดูสาเหตุ';
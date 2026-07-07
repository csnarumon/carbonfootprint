<?php
/* ==============================================
   setup_folders.php
   วาง file นี้ที่ root: C:\xampp\htdocs\carbonfootprint\
   เปิด browser: http://localhost:81/carbonfootprint/setup_folders.php
   รันครั้งเดียว แล้วลบทิ้ง
   ============================================== */

$base = __DIR__ . '/uploads/assets/';

$folders = array(
    'equipment',
    'vehicle',
    'refrigerant',
    'watermeter',
    'electricmeter',
    'vendor',
    'employee',
);

$results = array();
foreach ($folders as $f) {
    $path = $base . $f;
    if (is_dir($path)) {
        $results[] = array('path' => $path, 'status' => 'exists', 'ok' => true);
    } elseif (mkdir($path, 0755, true)) {
        $results[] = array('path' => $path, 'status' => 'created', 'ok' => true);
    } else {
        $results[] = array('path' => $path, 'status' => 'failed', 'ok' => false);
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Setup Folders</title>
<style>
  body { font-family: 'Segoe UI', sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; }
  h2   { color: #2AABB8; }
  .item { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 6px; margin-bottom: 6px; font-size: 0.9rem; }
  .ok  { background: #E8F5E9; }
  .err { background: #FFEBEE; }
  code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; font-size: 0.82rem; }
  .warn { background: #FFF9E6; border: 1px solid #F2A541; border-radius: 8px; padding: 12px 16px; margin-top: 20px; font-size: 0.85rem; }
</style>
</head>
<body>
<h2>📁 Setup Upload Folders</h2>
<?php foreach ($results as $r) { ?>
<div class="item <?php echo $r['ok'] ? 'ok' : 'err'; ?>">
  <?php echo $r['ok'] ? '✅' : '❌'; ?>
  <div>
    <code><?php echo htmlspecialchars($r['path']); ?></code><br>
    <span style="color:<?php echo $r['ok'] ? '#2E7D32' : '#C62828'; ?>;font-size:0.78rem;">
      <?php echo $r['status'] === 'exists' ? 'มีอยู่แล้ว' : ($r['status'] === 'created' ? 'สร้างสำเร็จ' : 'สร้างไม่ได้ — ตรวจสิทธิ์ folder'); ?>
    </span>
  </div>
</div>
<?php } ?>

<?php $hasError = count(array_filter($results, function($r){ return !$r['ok']; })) > 0; ?>
<?php if ($hasError) { ?>
<div class="warn">
  ⚠️ <strong>สร้าง folder ไม่ได้</strong> — ลองทำด้วยตนเอง:<br><br>
  เปิด File Explorer ไปที่<br>
  <code>C:\xampp\htdocs\carbonfootprint\uploads\assets\</code><br>
  แล้วสร้าง folder ชื่อ <code>equipment</code>, <code>vehicle</code>, <code>refrigerant</code> ฯลฯ ด้วยมือ
</div>
<?php } else { ?>
<div class="warn">
  🗑️ <strong>เสร็จแล้ว — ลบไฟล์นี้ออกได้เลย</strong><br>
  <code>C:\xampp\htdocs\carbonfootprint\setup_folders.php</code>
</div>
<?php } ?>
</body>
</html>

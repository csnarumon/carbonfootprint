<?php
/* ==============================================
   test_db.php — ทดสอบ DB Connection
   *** ลบไฟล์นี้ก่อน deploy production ***
   ============================================== */
require_once 'config/db.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>DB Test — Carbon Footprint</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { font-family:'Prompt',sans-serif; background:#F5F7FA; padding:2rem; }
    .test-card { background:#fff; border-radius:12px; border:1px solid #E0E0E0;
                 padding:1.5rem; max-width:620px; margin:0 auto; }
    .test-title { font-size:1.1rem; font-weight:600; color:#1B3A4A;
                  border-bottom:1px solid #E0E0E0; padding-bottom:0.75rem; margin-bottom:1rem;
                  display:flex; align-items:center; gap:8px; }
    .test-row { display:flex; align-items:flex-start; gap:10px;
                padding:0.6rem 0; border-bottom:1px solid #F5F7FA; font-size:0.88rem; }
    .test-row:last-child { border-bottom:none; }
    .test-label { color:#6C757D; min-width:160px; }
    .test-value { color:#1B3A4A; font-weight:500; }
    .ok  { color:#4CAF50; font-weight:600; }
    .err { color:#DC3545; font-weight:600; }
    .badge-mode { background:#E8F5E9; color:#2E7D32; font-size:0.75rem;
                  padding:2px 8px; border-radius:10px; font-weight:500; }
    .warn { background:#FFF8E1; border:1px solid #FFE082; border-radius:8px;
            padding:0.6rem 0.9rem; font-size:0.8rem; color:#795548; margin-top:1rem; }
  </style>
</head>
<body>

<div class="test-card">

  <div class="test-title">
    <i class="bi bi-tree-fill" style="color:#4CAF50;"></i>
    Carbon Footprint — DB Connection Test
  </div>

  <!-- 1. Extension -->
  <div class="test-row">
    <div class="test-label"><i class="bi bi-puzzle me-1"></i>sqlsrv extension</div>
    <div class="test-value">
      <?php if (extension_loaded('sqlsrv')): ?>
        <span class="ok"><i class="bi bi-check-circle-fill me-1"></i>Loaded</span>
        <small class="text-muted ms-2"><?php echo phpversion('sqlsrv'); ?></small>
      <?php else: ?>
        <span class="err"><i class="bi bi-x-circle-fill me-1"></i>ไม่พบ — ตรวจสอบ php.ini</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- 2. pdo_sqlsrv -->
  <div class="test-row">
    <div class="test-label"><i class="bi bi-puzzle me-1"></i>pdo_sqlsrv extension</div>
    <div class="test-value">
      <?php if (extension_loaded('pdo_sqlsrv')): ?>
        <span class="ok"><i class="bi bi-check-circle-fill me-1"></i>Loaded</span>
      <?php else: ?>
        <span class="err"><i class="bi bi-x-circle-fill me-1"></i>ไม่พบ</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- 3. Auth Mode -->
  <div class="test-row">
    <div class="test-label"><i class="bi bi-shield-lock me-1"></i>Auth Mode</div>
    <div class="test-value">
      <span class="badge-mode">
        <?php echo DB_AUTH_MODE === 'windows' ? 'Windows Authentication' : 'SQL Server Authentication'; ?>
      </span>
      <?php if (DB_AUTH_MODE === 'sql'): ?>
        <small class="text-muted ms-2">User: <?php echo htmlspecialchars(DB_USER); ?></small>
      <?php endif; ?>
    </div>
  </div>

  <!-- 4. Connect -->
  <div class="test-row">
    <div class="test-label"><i class="bi bi-hdd-network me-1"></i>เชื่อมต่อ MSSQL</div>
    <div class="test-value">
      <?php
      $connectionInfo = array(
          'Database'               => DB_NAME,
          'CharacterSet'           => 'UTF-8',
          'TrustServerCertificate' => true,
          'LoginTimeout'           => 10,
      );
      if (DB_AUTH_MODE === 'sql') {
          $connectionInfo['UID'] = DB_USER;
          $connectionInfo['PWD'] = DB_PASSWORD;
      }
      $conn = sqlsrv_connect(DB_SERVER, $connectionInfo);
      if ($conn):
      ?>
        <span class="ok"><i class="bi bi-check-circle-fill me-1"></i>เชื่อมต่อสำเร็จ</span>
      <?php else: ?>
        <span class="err"><i class="bi bi-x-circle-fill me-1"></i>เชื่อมต่อไม่ได้</span>
        <div class="mt-1" style="font-size:0.78rem;color:#DC3545;">
          <?php
            $errs = sqlsrv_errors();
            echo htmlspecialchars($errs[0]['message'] ?? 'Unknown error');
          ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($conn): ?>

  <!-- 5. Server Info -->
  <?php
    $res = sqlsrv_query($conn, "SELECT GETDATE() AS now, SYSTEM_USER AS usr, @@VERSION AS ver");
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
  ?>
  <div class="test-row">
    <div class="test-label"><i class="bi bi-calendar3 me-1"></i>Server Time</div>
    <div class="test-value">
      <?php echo ($row['now'] instanceof DateTime) ? $row['now']->format('d/m/Y H:i:s') : $row['now']; ?>
    </div>
  </div>

  <div class="test-row">
    <div class="test-label"><i class="bi bi-person-check me-1"></i>Login User</div>
    <div class="test-value"><?php echo htmlspecialchars($row['usr']); ?></div>
  </div>

  <div class="test-row">
    <div class="test-label"><i class="bi bi-database me-1"></i>Database</div>
    <div class="test-value"><?php echo htmlspecialchars(DB_NAME); ?></div>
  </div>

  <div class="test-row">
    <div class="test-label"><i class="bi bi-server me-1"></i>SQL Version</div>
    <div class="test-value" style="font-size:0.78rem;">
      <?php echo htmlspecialchars(substr($row['ver'], 0, 55)) . '...'; ?>
    </div>
  </div>

  <?php sqlsrv_close($conn); ?>
  <?php endif; ?>

  <!-- 6. PHP Info -->
  <div class="test-row">
    <div class="test-label"><i class="bi bi-code-slash me-1"></i>PHP Version</div>
    <div class="test-value"><?php echo PHP_VERSION; ?></div>
  </div>

  <div class="warn">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <strong>ลบไฟล์ test_db.php ก่อน deploy production</strong>
  </div>

</div>

</body>
</html>
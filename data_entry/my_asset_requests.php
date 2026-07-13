<?php
/* ==============================================
   data_entry/my_asset_requests.php
   Data Entry ดูคำขอเพิ่มทรัพย์สินของตัวเอง (ทุก Scope/Site) — read-only
   การปิดคำขอทำที่ master/asset_requests.php โดย Admin เท่านั้น
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(1, 4, 5));

$conn   = getConnection();
$userID = getEffectiveUserID();

$assetTypeMap = array(
    'Equipment'     => 'เครื่องจักร',
    'Vehicle'       => 'ยานพาหนะ',
    'Refrigerant'   => 'อุปกรณ์ทำความเย็น',
    'WaterMeter'    => 'มิเตอร์น้ำ',
    'ElectricMeter' => 'มิเตอร์ไฟฟ้า',
    'Vendor'        => 'ชาวสวน/ผู้ขนส่ง',
    'Waste'         => 'รายการของเสีย',
    'Employee'      => 'พนักงาน',
);

$resReq = sqlsrv_query($conn, "
    SELECT ar.RequestID, ar.ScopeNo, ar.AssetType, ar.RequestedName, ar.Remark, ar.Status,
           s.SiteName, ar.CreatedDate, clo.FullName AS ClosedByName, ar.ClosedDate
    FROM CFP_AssetRequest ar
    LEFT JOIN CFP_Site s ON s.SiteID = ar.SiteID
    LEFT JOIN CFP_User clo ON clo.UserID = ar.ClosedBy
    WHERE ar.RequestedBy = ?
    ORDER BY ar.Status ASC, ar.CreatedDate DESC
", array($userID));
$requests = array();
if ($resReq) { while ($r = sqlsrv_fetch_array($resReq, SQLSRV_FETCH_ASSOC)) { $requests[] = $r; } }

$openCount = count(array_filter($requests, function($r) { return (int)$r['Status'] === 0; }));

$pageTitle = 'คำขอเพิ่มทรัพย์สินของฉัน';
$pageIcon  = 'box-seam';
?>
<!DOCTYPE html><html lang="th"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>คำขอเพิ่มทรัพย์สินของฉัน — ระบบบริหารจัดการคาร์บอนองค์กร</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">
<style>
body { font-family:'Prompt',sans-serif; }
.req-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
.req-table th { background:var(--cfp-bg); color:var(--cfp-text-muted); font-weight:500; padding:8px 10px; border-bottom:1px solid var(--cfp-border); font-size:0.72rem; text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }
.req-table td { padding:9px 10px; border-bottom:1px solid var(--cfp-border); vertical-align:middle; }
.req-table tr:hover td { background:#F9FDFE; }
.sp { display:inline-flex; align-items:center; gap:3px; padding:2px 8px; border-radius:9px; font-size:0.72rem; font-weight:500; white-space:nowrap; }
.sp-open  { background:#FEF3C7; color:#92400E; border:1px solid #FCD34D; }
.sp-done  { background:#D1FAE5; color:#065F46; border:1px solid #6EE7B7; }
</style>
</head><body>
<?php include '../includes/sidebar.php'; ?>
<div class="cfp-main">
<?php include '../includes/topbar.php'; ?>
<div class="cfp-content">

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold" style="color:var(--cfp-primary);">
      <i class="bi bi-box-seam me-2"></i>คำขอเพิ่มทรัพย์สินของฉัน
    </h5>
    <div style="font-size:0.72rem;color:var(--cfp-text-muted);">
      Data Entry › คำขอเพิ่มทรัพย์สิน — ค้างอยู่ <?php echo $openCount; ?> รายการ
    </div>
  </div>
</div>

<div class="cfp-card" style="padding:0;">
  <div class="table-responsive">
  <table class="req-table">
    <thead>
      <tr>
        <th>สถานะ</th>
        <th>Scope</th>
        <th>ประเภท</th>
        <th>ชื่อ/รายละเอียดที่ขอ</th>
        <th>Site</th>
        <th>หมายเหตุ</th>
        <th>วันที่ขอ</th>
        <th>ดำเนินการเสร็จเมื่อ</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($requests)) { ?>
      <tr><td colspan="8" class="text-center py-4" style="color:var(--cfp-text-muted);">คุณยังไม่เคยส่งคำขอ</td></tr>
    <?php } else { foreach ($requests as $r) {
        $isOpen = (int)$r['Status'] === 0;
        $reqDate = $r['CreatedDate'] instanceof DateTime ? $r['CreatedDate']->format('d M Y H:i') : '';
        $closedDate = $r['ClosedDate'] instanceof DateTime ? $r['ClosedDate']->format('d M Y') : '';
    ?>
      <tr>
        <td><?php echo $isOpen
              ? '<span class="sp sp-open"><i class="bi bi-hourglass-split"></i>รอดำเนินการ</span>'
              : '<span class="sp sp-done"><i class="bi bi-check2-circle"></i>เสร็จแล้ว</span>'; ?>
        </td>
        <td>Scope <?php echo (int)$r['ScopeNo']; ?></td>
        <td><?php echo htmlspecialchars($assetTypeMap[$r['AssetType']] ?? $r['AssetType']); ?></td>
        <td><?php echo htmlspecialchars($r['RequestedName']); ?></td>
        <td><?php echo htmlspecialchars($r['SiteName'] ?? ''); ?></td>
        <td style="max-width:200px;"><?php echo htmlspecialchars($r['Remark'] ?? ''); ?></td>
        <td style="font-size:0.75rem;color:var(--cfp-text-muted);"><?php echo $reqDate; ?></td>
        <td style="font-size:0.75rem;color:var(--cfp-text-muted);">
          <?php if (!$isOpen) {
              echo htmlspecialchars($closedDate);
              if (!empty($r['ClosedByName'])) { echo ' โดย ' . htmlspecialchars($r['ClosedByName']); }
          } else { echo '—'; } ?>
        </td>
      </tr>
    <?php } } ?>
    </tbody>
  </table>
  </div>
</div>

</div><!-- /cfp-content -->
</div><!-- /cfp-main -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>

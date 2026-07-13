<?php
/* ==============================================
   master/asset_requests.php
   คำขอเพิ่มทรัพย์สินใหม่จาก Data Entry — Admin (4) และ Sustainability Admin (5)
   Admin ไปสร้างทะเบียนจริงเองที่หน้า master ที่เกี่ยวข้อง แล้วกลับมา "ปิดคำขอ" ที่นี่
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4, 5));

$conn     = getConnection();
$myUserID = (int)$_SESSION['user_id'];

/* ===== ปิดคำขอ (POST) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close') {
    verifyCsrf();
    $reqID = (int)($_POST['requestID'] ?? 0);
    if ($reqID > 0) {
        sqlsrv_query($conn,
            "UPDATE CFP_AssetRequest SET Status=1, ClosedBy=?, ClosedDate=GETDATE() WHERE RequestID=? AND Status=0",
            array($myUserID, $reqID));
        logAction($conn, 'ASSET_REQUEST_CLOSE', 'CFP_AssetRequest', $reqID, null, null, null, 'ปิดคำขอเพิ่มทรัพย์สิน');
        $_SESSION['toast_msg']  = 'ปิดคำขอเรียบร้อยแล้ว';
        $_SESSION['toast_type'] = 'success';
    }
    header('Location: asset_requests.php');
    exit;
}

$toastMsg = ''; $toastType = 'success';
if (!empty($_SESSION['toast_msg'])) {
    $toastMsg  = $_SESSION['toast_msg'];
    $toastType = $_SESSION['toast_type'] ?? 'success';
    unset($_SESSION['toast_msg'], $_SESSION['toast_type']);
}

/* ลิงก์ไปหน้า master ที่เกี่ยวข้อง ตาม AssetType */
$assetTypeMap = array(
    'Equipment'     => array('label' => 'เครื่องจักร',                 'url' => 'equipment.php'),
    'Vehicle'       => array('label' => 'ยานพาหนะ',                   'url' => 'vehicle.php'),
    'Refrigerant'   => array('label' => 'อุปกรณ์ทำความเย็น',           'url' => 'refrigerant.php'),
    'WaterMeter'    => array('label' => 'มิเตอร์น้ำ',                   'url' => 'watermeter.php'),
    'ElectricMeter' => array('label' => 'มิเตอร์ไฟฟ้า',                 'url' => 'electricmeter.php'),
    'Vendor'        => array('label' => 'ทะเบียนชาวสวน',               'url' => 'vendor.php'),
    'Waste'         => array('label' => 'รายการขยะ/ของเสีย',           'url' => 'waste.php'),
    'Employee'      => array('label' => 'ทะเบียนพนักงาน',              'url' => 'employee.php'),
);

/* ===== ดึงคำขอทั้งหมด (ค้าง + เสร็จแล้ว) ===== */
$resReq = sqlsrv_query($conn, "
    SELECT ar.RequestID, ar.ScopeNo, ar.AssetType, ar.RequestedName, ar.Remark, ar.Details, ar.Status,
           ar.SiteID, s.SiteName, req.FullName AS RequesterName, ar.CreatedDate,
           clo.FullName AS ClosedByName, ar.ClosedDate
    FROM CFP_AssetRequest ar
    LEFT JOIN CFP_Site s ON s.SiteID = ar.SiteID
    LEFT JOIN CFP_User req ON req.UserID = ar.RequestedBy
    LEFT JOIN CFP_User clo ON clo.UserID = ar.ClosedBy
    ORDER BY ar.Status ASC, ar.CreatedDate DESC
");
$requests = array();
if ($resReq) { while ($r = sqlsrv_fetch_array($resReq, SQLSRV_FETCH_ASSOC)) { $requests[] = $r; } }

/**
 * สร้าง URL พร้อม pre-fill ข้อมูลกลับเข้าฟอร์ม master — generic ทุกประเภททรัพย์สิน
 * key 'primary' ในคำขอ → query param 'name' เสมอ ส่วน key อื่นๆ ส่งผ่านตรงๆ (lowercase)
 * แต่ละหน้า master เป็นคนอ่าน query param ที่ตัวเองต้องการเอง (ไม่รู้จักก็แค่เมิน)
 */
function cfpBuildPrefillUrl($assetType, $baseUrl, $siteID, $detailsJson) {
    if (empty($detailsJson)) { return $baseUrl; }
    $d = json_decode($detailsJson, true);
    if (!is_array($d)) { return $baseUrl; }
    $params = array('prefill' => '1');
    if ($siteID) { $params['site'] = $siteID; }
    foreach ($d as $k => $v) {
        if ($v === '' || $v === null) { continue; }
        $paramKey = ($k === 'primary') ? 'name' : strtolower($k);
        $params[$paramKey] = $v;
    }
    return $baseUrl . '?' . http_build_query($params);
}

$openCount = count(array_filter($requests, function($r) { return (int)$r['Status'] === 0; }));

$pageTitle = 'คำขอเพิ่มทรัพย์สินใหม่';
$pageIcon  = 'box-seam';
?>
<!DOCTYPE html><html lang="th"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>คำขอเพิ่มทรัพย์สินใหม่ — ระบบบริหารจัดการคาร์บอนองค์กร</title>
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
.btn-close-req { padding:4px 12px; border-radius:6px; font-size:0.78rem; background:var(--cfp-primary); color:#fff; border:none; font-family:'Prompt',sans-serif; cursor:pointer; }
.btn-close-req:hover { background:var(--cfp-primary-dark); }
</style>
</head><body>
<?php include '../includes/sidebar.php'; ?>
<div class="cfp-main">
<?php include '../includes/topbar.php'; ?>
<div class="cfp-content">

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold" style="color:var(--cfp-primary);">
      <i class="bi bi-box-seam me-2"></i>คำขอเพิ่มทรัพย์สินใหม่
    </h5>
    <div style="font-size:0.72rem;color:var(--cfp-text-muted);">
      Master › คำขอเพิ่มทรัพย์สิน — ค้างอยู่ <?php echo $openCount; ?> รายการ
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
        <th>ผู้ขอ</th>
        <th>วันที่ขอ</th>
        <th style="width:160px;">การจัดการ</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($requests)) { ?>
      <tr><td colspan="9" class="text-center py-4" style="color:var(--cfp-text-muted);">ยังไม่มีคำขอ</td></tr>
    <?php } else { foreach ($requests as $r) {
        $atInfo = $assetTypeMap[$r['AssetType']] ?? array('label' => $r['AssetType'], 'url' => '#');
        $isOpen = (int)$r['Status'] === 0;
        $reqDate = $r['CreatedDate'] instanceof DateTime ? $r['CreatedDate']->format('d M Y H:i') : '';
    ?>
      <tr>
        <td><?php echo $isOpen
              ? '<span class="sp sp-open"><i class="bi bi-hourglass-split"></i>รอดำเนินการ</span>'
              : '<span class="sp sp-done"><i class="bi bi-check2-circle"></i>เสร็จแล้ว</span>'; ?>
        </td>
        <td>Scope <?php echo (int)$r['ScopeNo']; ?></td>
        <td><?php echo htmlspecialchars($atInfo['label']); ?></td>
        <td><?php echo htmlspecialchars($r['RequestedName']); ?></td>
        <td><?php echo htmlspecialchars($r['SiteName'] ?? ''); ?></td>
        <td style="max-width:200px;"><?php echo htmlspecialchars($r['Remark'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars($r['RequesterName'] ?? ''); ?></td>
        <td style="font-size:0.75rem;color:var(--cfp-text-muted);"><?php echo $reqDate; ?></td>
        <td>
          <?php if ($isOpen) { $prefillUrl = cfpBuildPrefillUrl($r['AssetType'], $atInfo['url'], (int)$r['SiteID'], $r['Details']); ?>
          <a href="<?php echo htmlspecialchars($prefillUrl); ?>" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-size:0.75rem;">
            <i class="bi bi-box-arrow-up-right"></i> ไปสร้างทะเบียน
          </a>
          <form method="POST" style="display:inline;" onsubmit="return confirm('ยืนยันว่าสร้างทะเบียนเสร็จแล้ว?');">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="close">
            <input type="hidden" name="requestID" value="<?php echo (int)$r['RequestID']; ?>">
            <button type="submit" class="btn-close-req"><i class="bi bi-check2"></i> เสร็จแล้ว</button>
          </form>
          <?php } else { ?>
          <span style="font-size:0.72rem;color:var(--cfp-text-muted);">
            โดย <?php echo htmlspecialchars($r['ClosedByName'] ?? ''); ?>
            <?php if ($r['ClosedDate'] instanceof DateTime) { echo ' · ' . $r['ClosedDate']->format('d M Y'); } ?>
          </span>
          <?php } ?>
        </td>
      </tr>
    <?php } } ?>
    </tbody>
  </table>
  </div>
</div>

</div><!-- /cfp-content -->
</div><!-- /cfp-main -->

<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999;">
  <div id="toastOK" class="toast align-items-center text-bg-success border-0" style="font-family:'Prompt',sans-serif;">
    <div class="d-flex"><div class="toast-body" id="toastOKMsg"></div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var tm = <?php echo json_encode($toastMsg, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
    if (tm) {
        document.getElementById('toastOKMsg').textContent = tm;
        new bootstrap.Toast(document.getElementById('toastOK'), {delay:3000}).show();
    }
});
</script>
</body></html>

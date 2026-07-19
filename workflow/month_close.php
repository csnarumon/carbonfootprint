<?php
/* =============================================================
   workflow/month_close.php
   ปิดเดือน (Month Close) — Role 3 (Approver) + Role 5 (SustainAdmin)
   - ตรวจสอบว่าทุก Site × Scope Approved ครบก่อนปิด
   - ปิดเดือน = UPDATE CFP_MonthlyHeader Status=3, ClosedBy, ClosedDate
   ============================================================= */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(3, 5)); /* Approver + SustainAdmin */

$conn   = getConnection();
$userID = (int)$_SESSION['user_id'];

/* ===== Toast ===== */
$toastMsg  = '';
$toastType = 'success';
if (!empty($_SESSION['toast'])) {
    $toastMsg  = $_SESSION['toast']['msg'];
    $toastType = $_SESSION['toast']['type'];
    unset($_SESSION['toast']);
}

/* ===== Filter เดือน/ปี ===== */
$filterYM    = $_GET['ym'] ?? date('Ym');
$filterYear  = (int)substr($filterYM, 0, 4);
$filterMonth = (int)substr($filterYM, 4, 2);

/* Prev / Next YM */
$ymDate  = DateTime::createFromFormat('Ym', $filterYM);
$prevYM  = (clone $ymDate)->modify('-1 month')->format('Ym');
$nextYM  = (clone $ymDate)->modify('+1 month')->format('Ym');
$ymLabel = $ymDate->format('F Y'); /* เช่น June 2026 */
/* แปลงเป็นภาษาไทย */
$thMonths = array(
    '01'=>'มกราคม','02'=>'กุมภาพันธ์','03'=>'มีนาคม','04'=>'เมษายน',
    '05'=>'พฤษภาคม','06'=>'มิถุนายน','07'=>'กรกฎาคม','08'=>'สิงหาคม',
    '09'=>'กันยายน','10'=>'ตุลาคม','11'=>'พฤศจิกายน','12'=>'ธันวาคม',
);
$ymLabelTH = ($thMonths[str_pad($filterMonth,2,'0',STR_PAD_LEFT)] ?? '') . ' ' . ($filterYear + 543);

/* ===== ดึง Sites ทั้งหมด ===== */
$resSite = sqlsrv_query($conn, "SELECT SiteID, SiteName, SiteCode FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
$sites   = array();
while ($r = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) { $sites[] = $r; }

/* ===== ดึง MonthlyHeader ทุก Site × Scope ของเดือนนี้ ===== */
$resHdr = sqlsrv_query($conn,
    "SELECT h.HeaderID, h.SiteID, h.Scope, h.Status, h.ClosedBy, h.ClosedDate,
            s.SiteName, s.SiteCode,
            ua.FullName AS ApproverName, h.ApprovedDate
     FROM CFP_MonthlyHeader h
     LEFT JOIN CFP_Site s ON s.SiteID = h.SiteID
     LEFT JOIN CFP_User ua ON ua.UserID = h.ApprovedBy
     WHERE h.YearMonth = ?
     ORDER BY s.SiteName, h.Scope",
    array($filterYM));

$headers = array();
while ($r = sqlsrv_fetch_array($resHdr, SQLSRV_FETCH_ASSOC)) { $headers[] = $r; }

/* ===== สร้าง matrix: SiteID → Scope → row ===== */
$matrix = array(); /* [SiteID][ScopeNo] = row */
foreach ($headers as $h) {
    $sno = (int)str_replace('Scope', '', $h['Scope']);
    $matrix[(int)$h['SiteID']][$sno] = $h;
}

/* ===== KPI ===== */
$totalExpected = count($sites) * 3; /* Site × Scope1,2,3 */
$cntApproved   = 0;
$cntSubmitted  = 0;
$cntDraft      = 0;
$cntNone       = 0;
$cntClosed     = 0;

foreach ($sites as $s) {
    $sid = (int)$s['SiteID'];
    for ($sno = 1; $sno <= 3; $sno++) {
        if (isset($matrix[$sid][$sno])) {
            $st = (int)$matrix[$sid][$sno]['Status'];
            if ($st === 3) { $cntClosed++; }
            elseif ($st === 2) { $cntApproved++; }
            elseif ($st === 1) { $cntSubmitted++; }
            else               { $cntDraft++; }
        } else {
            $cntNone++;
        }
    }
}

$cntNotReady = $totalExpected - $cntApproved - $cntClosed;
$canClose    = ($cntNotReady === 0 && $cntClosed < $totalExpected);
$allClosed   = ($cntClosed === $totalExpected);
$pctApproved = $totalExpected > 0 ? round(($cntApproved + $cntClosed) / $totalExpected * 100) : 0;

/* ===== Scope tab status ===== */
/* เช็คว่าแต่ละ Scope ครบทุก Site หรือยัง */
$scopeStatus = array(); /* 1/2/3 → 'ok'|'warn' */
for ($sno = 1; $sno <= 3; $sno++) {
    $allOk = true;
    foreach ($sites as $s) {
        $sid = (int)$s['SiteID'];
        $st  = isset($matrix[$sid][$sno]) ? (int)$matrix[$sid][$sno]['Status'] : -1;
        if ($st !== 2 && $st !== 3) { $allOk = false; break; }
    }
    $scopeStatus[$sno] = $allOk ? 'ok' : 'warn';
}

/* ===== Filter Scope tab ===== */
$filterScope = (int)($_GET['scope'] ?? 0); /* 0 = ทั้งหมด */

$pageTitle = 'ปิดเดือน';
$pageIcon  = 'calendar-lock';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ปิดเดือน — ระบบบริหารจัดการคาร์บอนองค์กร</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">
<style>
body { font-family:'Prompt',sans-serif; }
.font-prompt { font-family:'Prompt',sans-serif !important; }

/* Steps */
.mc-steps { display:flex; align-items:flex-start; overflow-x:auto; padding-bottom:4px; }
.mc-step  { display:flex; flex-direction:column; align-items:center; flex:1; min-width:64px; }
.mc-dot   { width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center;
             font-size:12px; font-weight:600; flex-shrink:0; z-index:1; }
.mc-dot.done   { background:var(--cfp-primary); color:#fff; }
.mc-dot.active { background:#fff; border:2px solid var(--cfp-primary); color:var(--cfp-primary);
                 box-shadow:0 0 0 3px rgba(42,171,184,.15); }
.mc-dot.todo   { background:var(--cfp-bg); border:1px solid var(--cfp-border); color:var(--cfp-text-muted); }
.mc-dot-lbl    { font-size:10px; margin-top:5px; text-align:center; line-height:1.4;
                 color:var(--cfp-text-muted); max-width:64px; }
.mc-dot-lbl.active { color:var(--cfp-primary); font-weight:600; }
.mc-line       { flex:1; height:2px; margin-top:14px; }
.mc-line.done  { background:var(--cfp-primary); }
.mc-line.todo  { background:var(--cfp-border); }

/* KPI card */
.mc-kpi-card { background:#fff; border:1px solid var(--cfp-border); border-radius:12px;
               padding:16px 18px; margin-bottom:14px; }
.mc-big-num  { font-size:36px; font-weight:600; line-height:1; color:var(--cfp-text); }
.mc-big-denom{ font-size:18px; font-weight:400; color:var(--cfp-text-muted); }
.mc-big-pct  { font-size:12px; font-weight:600; padding:2px 10px; border-radius:20px;
               background:#FEF3C7; color:#92400E; margin-left:4px; }
.mc-big-pct.full { background:#D1FAE5; color:#065F46; }

/* Stacked bar */
.mc-stacked  { height:10px; border-radius:99px; overflow:hidden; display:flex; gap:2px; margin:12px 0 8px; }
.mc-seg      { height:10px; border-radius:99px; }
.mc-seg.ok   { background:#4CAF50; }
.mc-seg.wait { background:#F59E0B; }
.mc-seg.pend { background:#FCA5A5; }
.mc-seg.none { background:#D1D5DB; }

/* Legend */
.mc-legend      { display:flex; gap:14px; flex-wrap:wrap; }
.mc-legend-item { display:flex; align-items:center; gap:5px; font-size:11px; color:var(--cfp-text-mid); }
.mc-legend-dot  { width:8px; height:8px; border-radius:50%; flex-shrink:0; }

/* Scope tabs */
.mc-scope-tabs { display:flex; gap:6px; margin-bottom:10px; flex-wrap:wrap; }
.mc-scope-tab  { padding:4px 14px; border-radius:20px; font-size:12px; font-weight:500;
                 border:1px solid var(--cfp-border); cursor:pointer; background:none;
                 color:var(--cfp-text-muted); font-family:'Prompt',sans-serif; }
.mc-scope-tab.active { background:var(--cfp-bg); color:var(--cfp-primary); border-color:var(--cfp-primary); }
.mc-scope-tab.ok     { border-color:#A8D8DF; color:#2E7D32; }
.mc-scope-tab.warn   { border-color:#FCD34D; color:#B45309; }

/* Table */
.mc-ico-ok   { color:#2E7D32; font-size:15px; }
.mc-ico-wait { color:#F59E0B; font-size:15px; }
.mc-ico-pend { color:#FCA5A5; font-size:15px; }
.mc-ico-none { color:#D1D5DB; font-size:15px; }
.mc-ico-closed { color:var(--cfp-primary); font-size:15px; }
.mc-tag      { display:inline-block; padding:1px 8px; border-radius:20px; font-size:10px; font-weight:600; }
.mc-tag.ok   { background:#E8F5E9; color:#2E7D32; }
.mc-tag.wait { background:#FEF3C7; color:#92400E; }
.mc-tag.pend { background:#FEE2E2; color:#B91C1C; }
.mc-tag.closed { background:#E8F5E9; color:#2E7D32; }
.mc-tag.none { background:var(--cfp-bg); color:var(--cfp-text-muted); border:1px solid var(--cfp-border); }

/* Alert */
.mc-alert-warn { background:#FFF8E1; border:1px solid #FCD34D; border-radius:8px;
                 padding:10px 14px; font-size:12px; color:#B45309;
                 display:flex; align-items:center; gap:8px; margin-bottom:12px; flex-wrap:wrap; }
.mc-alert-ok   { background:#F0FFF4; border:1px solid #6EE7B7; border-radius:8px;
                 padding:10px 14px; font-size:12px; color:#065F46;
                 display:flex; align-items:center; gap:8px; margin-bottom:12px; }
.mc-alert-closed { background:#EEF6F8; border:1px solid var(--cfp-primary); border-radius:8px;
                   padding:10px 14px; font-size:12px; color:var(--cfp-primary);
                   display:flex; align-items:center; gap:8px; margin-bottom:12px; }

/* Footer */
.mc-footer { display:flex; align-items:center; justify-content:space-between;
             flex-wrap:wrap; gap:10px; padding-top:12px; border-top:1px solid var(--cfp-border); }

/* Go link */
.mc-go-link { font-size:11px; color:var(--cfp-primary); text-decoration:none;
              display:flex; align-items:center; gap:2px; white-space:nowrap; }
.mc-go-link:hover { text-decoration:underline; }
</style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="cfp-main">
<?php include '../includes/topbar.php'; ?>
<div class="cfp-content">

  <!-- Page Header -->
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
      <h5 class="mb-0" style="font-weight:600;color:var(--cfp-primary);">
        <i class="bi bi-calendar-lock me-2" style="color:var(--cfp-green);"></i>ปิดเดือน
      </h5>
      <div style="font-size:0.78rem;color:var(--cfp-text-muted);margin-top:2px;">
        Workflow › ปิดเดือน
      </div>
    </div>
  </div>

  <div class="cfp-card">

    <!-- Header: เลือกเดือน -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
      <div>
        <div style="font-size:0.95rem;font-weight:600;color:var(--cfp-text);">
          <?php echo htmlspecialchars($ymLabelTH); ?>
        </div>
        <div style="font-size:0.75rem;color:var(--cfp-text-muted);">Approver / Sustainability Admin เท่านั้น</div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <a href="?ym=<?php echo $prevYM; ?>" class="btn btn-outline-secondary btn-sm py-1 px-2">
          <i class="bi bi-chevron-left"></i>
        </a>
        <span style="font-size:13px;font-weight:500;min-width:130px;text-align:center;color:var(--cfp-text);">
          <?php echo htmlspecialchars($ymLabelTH); ?>
        </span>
        <a href="?ym=<?php echo $nextYM; ?>" class="btn btn-outline-secondary btn-sm py-1 px-2">
          <i class="bi bi-chevron-right"></i>
        </a>
      </div>
    </div>

    <!-- Steps -->
    <div class="mc-steps mb-3">
      <div class="mc-step">
        <div class="mc-dot done"><i class="bi bi-check-lg"></i></div>
        <div class="mc-dot-lbl">กรอกข้อมูล</div>
      </div>
      <div class="mc-line done"></div>
      <div class="mc-step">
        <div class="mc-dot done"><i class="bi bi-check-lg"></i></div>
        <div class="mc-dot-lbl">ตรวจสอบ</div>
      </div>
      <div class="mc-line done"></div>
      <div class="mc-step">
        <div class="mc-dot <?php echo $canClose||$allClosed ? 'done' : 'active'; ?>">
          <?php if ($canClose || $allClosed) { ?><i class="bi bi-check-lg"></i><?php } else { ?>3<?php } ?>
        </div>
        <div class="mc-dot-lbl <?php echo (!$canClose && !$allClosed) ? 'active' : ''; ?>">
          อนุมัติ<?php if (!$canClose && !$allClosed && $cntNotReady > 0) { ?><br>(ค้าง <?php echo $cntNotReady; ?>)<?php } ?>
        </div>
      </div>
      <div class="mc-line <?php echo $allClosed ? 'done' : 'todo'; ?>"></div>
      <div class="mc-step">
        <div class="mc-dot <?php echo $allClosed ? 'done' : 'todo'; ?>">
          <?php echo $allClosed ? '<i class="bi bi-check-lg"></i>' : '4'; ?>
        </div>
        <div class="mc-dot-lbl">ปิดเดือน</div>
      </div>
    </div>

    <!-- KPI Card -->
    <div class="mc-kpi-card">
      <!-- Big number -->
      <div class="d-flex align-items-baseline gap-2 flex-wrap mb-2">
        <span class="mc-big-num"><?php echo $cntApproved + $cntClosed; ?></span>
        <span class="mc-big-denom">/ <?php echo $totalExpected; ?></span>
        <span class="mc-big-pct <?php echo $pctApproved === 100 ? 'full' : ''; ?>">
          <?php echo $pctApproved; ?>%
        </span>
        <span style="font-size:12px;color:var(--cfp-text-muted);">รายการ Approved แล้ว</span>
      </div>

      <!-- Stacked bar -->
      <?php
      $w_ok   = $totalExpected > 0 ? round(($cntApproved + $cntClosed) / $totalExpected * 100) : 0;
      $w_wait = $totalExpected > 0 ? round($cntSubmitted / $totalExpected * 100) : 0;
      $w_none = max(0, 100 - $w_ok - $w_wait);
      ?>
      <div class="mc-stacked">
        <?php if ($w_ok > 0)   { ?><div class="mc-seg ok"   style="width:<?php echo $w_ok; ?>%"></div><?php } ?>
        <?php if ($w_wait > 0) { ?><div class="mc-seg wait" style="width:<?php echo $w_wait; ?>%"></div><?php } ?>
        <?php if ($w_none > 0) { ?><div class="mc-seg none" style="width:<?php echo $w_none; ?>%"></div><?php } ?>
      </div>

      <!-- Legend -->
      <div class="mc-legend">
        <div class="mc-legend-item">
          <div class="mc-legend-dot" style="background:#4CAF50;"></div>
          <span>Approved <?php echo $cntApproved + $cntClosed; ?></span>
        </div>
        <?php if ($cntSubmitted > 0) { ?>
        <div class="mc-legend-item">
          <div class="mc-legend-dot" style="background:#F59E0B;"></div>
          <span>รออนุมัติ <?php echo $cntSubmitted; ?></span>
        </div>
        <?php } ?>
        <?php if ($cntDraft + $cntNone > 0) { ?>
        <div class="mc-legend-item">
          <div class="mc-legend-dot" style="background:#D1D5DB;"></div>
          <span>ยังไม่ส่ง <?php echo $cntDraft + $cntNone; ?></span>
        </div>
        <?php } ?>
        <div class="mc-legend-item" style="margin-left:auto;">
          <span style="color:var(--cfp-text-muted);">
            <?php echo count($sites); ?> Site × 3 Scope = <?php echo $totalExpected; ?> รายการ
          </span>
        </div>
      </div>
    </div>

    <!-- Alert -->
    <?php if ($allClosed) { ?>
    <div class="mc-alert-closed">
      <i class="bi bi-lock-fill"></i>
      เดือนนี้ปิดแล้ว — ข้อมูลถูกล็อคทั้งหมด
    </div>
    <?php } elseif ($canClose) { ?>
    <div class="mc-alert-ok">
      <i class="bi bi-check-circle-fill"></i>
      Approved ครบทุกรายการแล้ว — พร้อมปิดเดือน
    </div>
    <?php } else { ?>
    <div class="mc-alert-warn">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span>ยังค้าง <?php echo $cntNotReady; ?> รายการ — ต้อง Approved ครบก่อนจึงจะปิดเดือนได้</span>
    </div>
    <?php } ?>

    <!-- Scope filter tabs -->
    <div class="mc-scope-tabs">
      <button class="mc-scope-tab <?php echo $filterScope===0?'active':''; ?>"
              onclick="filterScope(0)">ทั้งหมด</button>
      <?php for ($sno = 1; $sno <= 3; $sno++) {
        $st = $scopeStatus[$sno] ?? 'warn';
        $icon = $st === 'ok' ? ' ✓' : ' ⚠';
        $cls = $filterScope===$sno ? 'active' : $st;
      ?>
      <button class="mc-scope-tab <?php echo $cls; ?>"
              onclick="filterScope(<?php echo $sno; ?>)">
        Scope <?php echo $sno; ?><?php echo $icon; ?>
      </button>
      <?php } ?>
    </div>

    <!-- Checklist Table -->
    <div class="table-responsive">
      <table class="table table-bordered table-hover align-middle mb-0"
             style="font-size:0.82rem;width:100%;">
        <thead>
          <tr>
            <th>Site</th>
            <th class="text-center" style="width:90px;">Scope 1</th>
            <th class="text-center" style="width:90px;">Scope 2</th>
            <th class="text-center" style="width:90px;">Scope 3</th>
            <th class="text-center" style="width:90px;">สถานะ</th>
            <th style="width:50px;"></th>
          </tr>
        </thead>
        <tbody id="tblBody">
          <?php foreach ($sites as $s) {
            $sid = (int)$s['SiteID'];
            $allOk = true;
            $pendCount = 0;
            $rowScopes = array();
            for ($sno = 1; $sno <= 3; $sno++) {
              if (isset($matrix[$sid][$sno])) {
                $st = (int)$matrix[$sid][$sno]['Status'];
                $rowScopes[$sno] = $st;
                if ($st !== 2 && $st !== 3) { $allOk = false; $pendCount++; }
              } else {
                $rowScopes[$sno] = -1;
                $allOk = false; $pendCount++;
              }
            }

            /* row data attr สำหรับ filter */
            $pendScopes = array();
            for ($sno = 1; $sno <= 3; $sno++) {
              if (($rowScopes[$sno] ?? -1) !== 2 && ($rowScopes[$sno] ?? -1) !== 3) {
                $pendScopes[] = $sno;
              }
            }
            $dataScopes = implode(',', $pendScopes); /* scopes ที่ยังค้าง */
          ?>
          <tr data-site="<?php echo $sid; ?>"
              data-pend-scopes="<?php echo $dataScopes; ?>">
            <td>
              <div style="font-weight:500;"><?php echo htmlspecialchars($s['SiteName']); ?></div>
              <div style="font-size:0.72rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($s['SiteCode']); ?></div>
            </td>
            <?php for ($sno = 1; $sno <= 3; $sno++) {
              $st = $rowScopes[$sno] ?? -1;
              if ($st === 2 || $st === 3) {
                $ico = '<i class="bi bi-check-circle-fill mc-ico-ok"></i>';
              } elseif ($st === 1) {
                $ico = '<i class="bi bi-clock mc-ico-wait"></i>';
              } elseif ($st === 0) {
                $ico = '<i class="bi bi-pencil mc-ico-pend"></i>';
              } else {
                $ico = '<i class="bi bi-dash mc-ico-none"></i>';
              }
            ?>
            <td class="text-center"><?php echo $ico; ?></td>
            <?php } ?>
            <td class="text-center">
              <?php if ($allOk) { ?>
              <span class="mc-tag ok">ครบ</span>
              <?php } else { ?>
              <span class="mc-tag <?php echo $pendCount === 3 ? 'none' : 'wait'; ?>">
                ค้าง <?php echo $pendCount; ?>
              </span>
              <?php } ?>
            </td>
            <td>
              <?php if (!$allOk && !$allClosed) { ?>
              <a class="mc-go-link"
                 href="/carbonfootprint/workflow/review.php?site=<?php echo $sid; ?>&ym=<?php echo $filterYM; ?>"
                 title="ไปหน้า Review">
                ดู <i class="bi bi-arrow-right" style="font-size:11px;"></i>
              </a>
              <?php } ?>
            </td>
          </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>

    <!-- Footer -->
    <div class="mc-footer mt-3">
      <?php if ($allClosed) { ?>
      <span style="font-size:12px;color:var(--cfp-primary);">
        <i class="bi bi-lock-fill me-1"></i>เดือนนี้ถูกปิดแล้ว
      </span>
      <?php } elseif (!$canClose) { ?>
      <span style="font-size:12px;color:var(--cfp-text-muted);">
        ต้อง Approved ครบ <?php echo $totalExpected; ?>/<?php echo $totalExpected; ?> รายการก่อนปิดเดือน
      </span>
      <?php } else { ?>
      <span style="font-size:12px;color:#065F46;">
        <i class="bi bi-check-circle-fill me-1"></i>พร้อมปิดเดือน
      </span>
      <?php } ?>

      <?php if (!$allClosed) { ?>
      <button class="btn-cfp-add <?php echo !$canClose ? 'disabled' : ''; ?>"
              <?php echo !$canClose ? 'disabled' : ''; ?>
              onclick="confirmClose()">
        <i class="bi bi-lock"></i>
        ปิดเดือน <?php echo htmlspecialchars($ymLabelTH); ?>
      </button>
      <?php } ?>
    </div>

  </div><!-- end cfp-card -->
</div><!-- end cfp-content -->
</div><!-- end cfp-main -->

<!-- Hidden form -->
<form id="formClose" method="POST" action="month_close_handler.php" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="ym" value="<?php echo htmlspecialchars($filterYM); ?>">
  <input type="hidden" name="action" value="close_month">
</form>

<!-- Toast -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:9999;">
  <div id="toastSuccess" class="toast align-items-center text-white border-0"
       style="background:var(--cfp-primary);" role="alert">
    <div class="d-flex">
      <div class="toast-body font-prompt" id="toastMsg"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
  <div id="toastError" class="toast align-items-center text-white border-0 bg-danger" role="alert">
    <div class="d-flex">
      <div class="toast-body font-prompt" id="toastErrMsg"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function filterScope(sno) {
    var rows = document.querySelectorAll('#tblBody tr');
    rows.forEach(function(row) {
        if (sno === 0) {
            row.style.display = '';
            return;
        }
        /* แสดงเฉพาะ row ที่ scope นั้น "ค้าง" */
        var pendScopes = row.getAttribute('data-pend-scopes');
        var arr = pendScopes ? pendScopes.split(',').map(Number) : [];
        row.style.display = arr.includes(sno) ? '' : 'none';
    });
    /* update tab active */
    document.querySelectorAll('.mc-scope-tab').forEach(function(btn, idx) {
        btn.classList.remove('active');
        if (idx === sno) { btn.classList.add('active'); }
    });
}

function confirmClose() {
    Swal.fire({
        title: 'ยืนยันการปิดเดือน?',
        html: '<div style="font-family:\'Prompt\',sans-serif;font-size:0.88rem;">'
            + 'ปิดเดือน <b><?php echo addslashes($ymLabelTH); ?></b><br>'
            + 'ข้อมูลทั้งหมดจะถูก <b>ล็อค</b> และแก้ไขไม่ได้อีก<br>'
            + '<span style="color:#B45309;">ไม่สามารถยกเลิกได้</span>'
            + '</div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#2AABB8',
        cancelButtonColor: '#6C757D',
        confirmButtonText: '<i class="bi bi-lock me-1"></i>ปิดเดือน',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true,
        customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('formClose').submit();
        }
    });
}

function showToast(msg, isError) {
    var id  = isError ? 'toastError' : 'toastSuccess';
    var mid = isError ? 'toastErrMsg' : 'toastMsg';
    document.getElementById(mid).textContent = msg;
    new bootstrap.Toast(document.getElementById(id), { delay: 3000 }).show();
}

<?php if ($toastMsg) { ?>
showToast('<?php echo htmlspecialchars(addslashes($toastMsg)); ?>',
          <?php echo $toastType === 'error' ? 'true' : 'false'; ?>);
<?php } ?>
</script>
</body>
</html>

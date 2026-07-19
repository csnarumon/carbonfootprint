<?php
/* workflow/approved.php — อนุมัติแล้ว (Hybrid B: เหมือน review แต่เน้น approved) */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(2, 3, 4, 5));
$conn   = getConnection();
$userID = getEffectiveUserID();
$roleID = getEffectiveRole();

$toastMsg = ''; $toastType = 'success';
if (!empty($_SESSION['toast_msg'])) {
    $toastMsg  = $_SESSION['toast_msg'];
    $toastType = $_SESSION['toast_type'] ?? 'success';
    unset($_SESSION['toast_msg'], $_SESSION['toast_type']);
}

$filterYM    = $_GET['ym']     ?? date('Ym');
$filterScope = $_GET['scope']  ?? '';
$filterSite  = $_GET['site']   ?? '';
$filterStatus= $_GET['status'] ?? '';

$filterYear  = (int)substr($filterYM, 0, 4);
$filterMonth = (int)substr($filterYM, 4, 2);

$resSite = sqlsrv_query($conn, "SELECT SiteID,SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
$sites   = array(); while ($r = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) { $sites[] = $r; }

/* ===== ดึง MonthlyHeader ที่ Approved(2) หรือ Draft ที่เคย Reject กลับ ===== */
/* Status: 0=Draft, 1=Submitted, 2=Approved */
$sqlMain = "
    SELECT h.HeaderID, h.Scope, h.YearMonth, h.SiteID, h.Status,
           h.SubmittedBy, h.SubmittedDate, h.ApprovedBy, h.ApprovedDate,
           h.ReviewedBy, h.ReviewedDate, h.Remark,
           s.SiteName,
           us.FullName AS SubmitterName,
           ua.FullName AS ApproverName,
           ur.FullName AS ReviewerName,
           COUNT(a.ActivityID) AS ItemCount,
           SUM(a.CO2e) AS TotalCO2e
    FROM CFP_MonthlyHeader h
    LEFT JOIN CFP_Site s   ON s.SiteID  = h.SiteID
    LEFT JOIN CFP_User us  ON us.UserID = h.SubmittedBy
    LEFT JOIN CFP_User ua  ON ua.UserID = h.ApprovedBy
    LEFT JOIN CFP_User ur  ON ur.UserID = h.ReviewedBy
    LEFT JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
    WHERE h.Status IN (0, 2)
    AND YEAR(COALESCE(h.ApprovedDate, h.SubmittedDate, h.CreatedDate)) = ?
    AND MONTH(COALESCE(h.ApprovedDate, h.SubmittedDate, h.CreatedDate)) = ?
";
$paramsMain = array($filterYear, $filterMonth);
if ($filterScope !== '') {
    $sqlMain .= " AND h.Scope = ?";
    $paramsMain[] = 'Scope' . (int)$filterScope;
}
if ($filterSite !== '') {
    $sqlMain .= " AND h.SiteID = ?";
    $paramsMain[] = (int)$filterSite;
}
if ($filterStatus !== '') {
    /* filterStatus: Approved=2, Rejected(Draft)=0 */
    $sqlMain .= " AND h.Status = ?";
    $paramsMain[] = ($filterStatus === 'Approved') ? 2 : 0;
}
$sqlMain .= " GROUP BY h.HeaderID, h.Scope, h.YearMonth, h.SiteID, h.Status,
              h.SubmittedBy, h.SubmittedDate, h.ApprovedBy, h.ApprovedDate,
              h.ReviewedBy, h.ReviewedDate, h.Remark, h.CreatedDate,
              s.SiteName, us.FullName, ua.FullName, ur.FullName
              ORDER BY COALESCE(h.ApprovedDate, h.SubmittedDate, h.CreatedDate) DESC";

$resMain = sqlsrv_query($conn, $sqlMain, $paramsMain);
$rows = array(); if ($resMain) { while ($r = sqlsrv_fetch_array($resMain, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; } }

/* ===== KPI ===== */
$cntApproved = count(array_filter($rows, function($r){ return (int)$r['Status'] === 2; }));
$cntRejected = count(array_filter($rows, function($r){ return (int)$r['Status'] === 0 && !empty($r['Remark']); }));
$cntTotal    = count($rows);

/* นับ Header ที่รออนุมัติ (Status=1) เดือนเดียวกัน */
$sqlPend = "SELECT COUNT(*) AS C FROM CFP_MonthlyHeader
            WHERE Status=1
            AND YEAR(COALESCE(SubmittedDate,CreatedDate))=?
            AND MONTH(COALESCE(SubmittedDate,CreatedDate))=?";
$resPend = sqlsrv_query($conn, $sqlPend, array($filterYear, $filterMonth));
$cntPending = 0;
if ($resPend) { $rP = sqlsrv_fetch_array($resPend, SQLSRV_FETCH_ASSOC); $cntPending = $rP ? (int)$rP['C'] : 0; }

/* ===== Timeline log ===== */
$sqlLog = "
    SELECT TOP 15
           l.ActionCode, l.TargetID, l.Remark, l.CreatedDate, l.ActorRole,
           u.FullName AS ActorName,
           r.RoleName,
           mh.Scope, mh.YearMonth AS HeaderYM,
           s.SiteName
    FROM CFP_ActionLog l
    LEFT JOIN CFP_User u          ON u.UserID   = l.ActorUserID
    LEFT JOIN CFP_Role r          ON r.RoleID   = l.ActorRole
    LEFT JOIN CFP_MonthlyHeader mh ON mh.HeaderID = l.TargetID
                                   AND l.TargetTable = 'CFP_MonthlyHeader'
    LEFT JOIN CFP_Site s           ON s.SiteID   = mh.SiteID
    WHERE l.ActionCode IN ('APPROVE','REJECT','UNLOCK')
    ORDER BY l.CreatedDate DESC
";
$resLog = sqlsrv_query($conn, $sqlLog);
$logRows = array(); if ($resLog) { while ($r = sqlsrv_fetch_array($resLog, SQLSRV_FETCH_ASSOC)) { $logRows[] = $r; } }

function scopeBar($scope) {
    $c = array(1 => '#43A047', 2 => '#7C3AED', 3 => '#F59E0B');
    return $c[$scope] ?? '#2AABB8';
}
function scopeBadge($scope) {
    $labels = array(1 => 'Scope 1', 2 => 'Scope 2', 3 => 'Scope 3');
    $styles = array(
        1 => 'background:#E4F8EE;color:#1A6A50;border:1px solid #A0DCC8;',
        2 => 'background:#EDE9FE;color:#4C1D95;border:1px solid #C4B5FD;',
        3 => 'background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;',
    );
    $s = $scope ?? 0;
    return '<span style="' . ($styles[$s] ?? '') . 'padding:1px 6px;border-radius:4px;font-size:0.72rem;">' . ($labels[$s] ?? 'Scope '.$s) . '</span>';
}
function logDot($action) { return $action === 'APPROVE' ? '#43A047' : '#E05050'; }
function logIcon($action) {
    $map = array('APPROVE' => 'check2-circle', 'REJECT' => 'x-circle');
    return $map[$action] ?? 'circle';
}
function logBgColor($action) {
    $map = array(
        'APPROVE' => array('bg'=>'#D1FAE5','border'=>'#6EE7B7','icon'=>'#059669'),
        'REJECT'  => array('bg'=>'#FEE2E2','border'=>'#FECACA','icon'=>'#DC2626'),
    );
    return $map[$action] ?? array('bg'=>'#F1F5F5','border'=>'#D0E8EE','icon'=>'#7AAAB8');
}
function logScopeColor($scope) {
    $map = array(1=>'#43A047', 2=>'#7C3AED', 3=>'#F59E0B'); return $map[$scope] ?? '#2AABB8';
}
function logScopeLabel($scope) {
    $map = array(1=>'Scope 1', 2=>'Scope 2', 3=>'Scope 3'); return $map[$scope] ?? '';
}

$pageTitle = 'อนุมัติแล้ว';
$pageIcon  = 'check2-all';
?>
<!DOCTYPE html><html lang="th"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>อนุมัติแล้ว — CFP</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">
<style>
body { font-family: 'Prompt', sans-serif; }
.font-prompt { font-family: 'Prompt', sans-serif !important; }
.kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
.kpi-card { background: #fff; border: 1px solid var(--cfp-border); border-radius: 10px; padding: 12px 16px; text-align: center; }
.kpi-num { font-size: 1.8rem; font-weight: 700; line-height: 1; }
.kpi-lbl { font-size: 0.72rem; color: var(--cfp-text-muted); margin-top: 4px; }
.scope-bar { width: 4px; border-radius: 3px; flex-shrink: 0; align-self: stretch; min-height: 36px; }
.section-hd { font-size: 0.72rem; font-weight: 600; color: var(--cfp-text-muted); letter-spacing: .8px; text-transform: uppercase; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
.section-hd .badge-count { padding: 1px 7px; border-radius: 9px; font-size: 0.7rem; font-weight: 600; }
.apv-row { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--cfp-border); }
.apv-row:last-child { border-bottom: none; }
.apv-info { flex: 1; min-width: 0; }
.apv-name { font-size: 0.85rem; font-weight: 500; color: var(--cfp-text); }
.apv-meta { display: flex; gap: 6px; align-items: center; margin-top: 3px; flex-wrap: wrap; }
.apv-meta span { font-size: 0.72rem; color: var(--cfp-text-muted); }
.bottom-grid { display: grid; grid-template-columns: minmax(0,1.5fr) minmax(0,1fr); gap: 14px; }
.tl-item { display: flex; gap: 10px; padding: 0 0 14px 0; }
.tl-item:last-child { padding-bottom: 0; }
.tl-col { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; width: 34px; }
.tl-icon-wrap { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; border: 1.5px solid; flex-shrink: 0; }
.tl-line { width: 1.5px; background: var(--cfp-border); flex: 1; margin: 3px 0; min-height: 10px; }
.tl-card { flex: 1; min-width: 0; background: var(--cfp-bg); border: 1px solid var(--cfp-border); border-radius: 8px; padding: 8px 12px; }
.tl-card-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px; gap: 6px; flex-wrap: wrap; }
.tl-action-lbl { font-size: 0.8rem; font-weight: 600; }
.tl-time-badge { font-size: 0.68rem; color: var(--cfp-text-muted); background: var(--cfp-card); border: 1px solid var(--cfp-border); padding: 1px 7px; border-radius: 9px; white-space: nowrap; display: flex; align-items: center; gap: 3px; }
.tl-itemname { font-size: 0.8rem; font-weight: 500; color: var(--cfp-text); margin-bottom: 5px; display: flex; align-items: center; gap: 5px; }
.tl-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 6px; }
.tl-chip { display: inline-flex; align-items: center; gap: 3px; font-size: 0.68rem; padding: 2px 8px; border-radius: 9px; border: 1px solid; white-space: nowrap; }
.tl-actor { display: flex; align-items: center; gap: 6px; padding-top: 6px; border-top: 1px solid var(--cfp-border); }
.tl-avatar { width: 20px; height: 20px; border-radius: 50%; background: var(--cfp-primary); color: #fff; font-size: 0.6rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.tl-actor-name { font-size: 0.72rem; color: var(--cfp-text-mid); font-weight: 500; }
.tl-actor-role { font-size: 0.68rem; color: var(--cfp-text-muted); }
.pill-approved { background: #E4F8F0; color: #1A6A50; border: 1px solid #A0DCC8; padding: 2px 8px; border-radius: 9px; font-size: 0.72rem; font-weight: 500; white-space: nowrap; }
.pill-rejected  { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; padding: 2px 8px; border-radius: 9px; font-size: 0.72rem; font-weight: 500; white-space: nowrap; }
.reject-reason { font-size: 0.72rem; color: var(--cfp-danger); margin-top: 2px; }
</style>
</head><body>
<?php include '../includes/sidebar.php'; ?>
<div class="cfp-main">
<?php include '../includes/topbar.php'; ?>
<div class="cfp-content">

<!-- Header + Filter -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold" style="color:var(--cfp-primary);">
      <i class="bi bi-check2-all me-2"></i>อนุมัติแล้ว
    </h5>
    <div style="font-size:0.75rem;color:var(--cfp-text-muted);">Workflow › อนุมัติแล้ว</div>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <a href="review.php?ym=<?php echo $filterYM; ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-clock me-1"></i>รออนุมัติ <?php if ($cntPending > 0) echo '('.$cntPending.')'; ?>
    </a>
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
      <select name="ym" class="form-select form-select-sm" style="width:130px;" onchange="this.form.submit()">
        <?php
        for ($m = 0; $m < 12; $m++) {
            $ts  = mktime(0,0,0, date('m') - $m, 1, date('Y'));
            $val = date('Ym', $ts);
            $lbl = date('M Y', $ts);
            $sel = ($val === $filterYM) ? 'selected' : '';
            echo '<option value="'.$val.'" '.$sel.'>'.$lbl.'</option>';
        }
        ?>
      </select>
      <select name="scope" class="form-select form-select-sm" style="width:120px;" onchange="this.form.submit()">
        <option value="">ทุก Scope</option>
        <option value="1" <?php echo $filterScope==='1'?'selected':''; ?>>Scope 1</option>
        <option value="2" <?php echo $filterScope==='2'?'selected':''; ?>>Scope 2</option>
        <option value="3" <?php echo $filterScope==='3'?'selected':''; ?>>Scope 3</option>
      </select>
      <select name="status" class="form-select form-select-sm" style="width:130px;" onchange="this.form.submit()">
        <option value="">อนุมัติ + ปฏิเสธ</option>
        <option value="Approved" <?php echo $filterStatus==='Approved'?'selected':''; ?>>อนุมัติเท่านั้น</option>
        <option value="Rejected" <?php echo $filterStatus==='Rejected'?'selected':''; ?>>ส่งกลับเท่านั้น</option>
      </select>
    </form>
  </div>
</div>

<!-- KPI -->
<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-num" style="color:var(--cfp-text-mid);"><?php echo $cntTotal; ?></div>
    <div class="kpi-lbl">รวมทั้งหมด</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-num" style="color:var(--cfp-success);"><?php echo $cntApproved; ?></div>
    <div class="kpi-lbl"><i class="bi bi-check-circle me-1" style="color:var(--cfp-success);"></i>อนุมัติ</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-num" style="color:var(--cfp-danger);"><?php echo $cntRejected; ?></div>
    <div class="kpi-lbl"><i class="bi bi-x-circle me-1" style="color:var(--cfp-danger);"></i>ปฏิเสธ</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-num" style="color:#F59E0B;"><?php echo $cntPending; ?></div>
    <div class="kpi-lbl"><i class="bi bi-clock me-1" style="color:#F59E0B;"></i>รออนุมัติ</div>
  </div>
</div>

<!-- Main list + Timeline -->
<div class="bottom-grid">

  <!-- Approved / Rejected list -->
  <div class="cfp-card" style="margin-bottom:0;">
    <div class="section-hd">
      <i class="bi bi-check2-all" style="color:var(--cfp-success);font-size:0.85rem;"></i>
      ประวัติการอนุมัติ
      <span class="badge-count" style="background:#D1FAE5;color:#065F46;"><?php echo $cntTotal; ?></span>
    </div>
    <?php if (count($rows) === 0) { ?>
    <div class="text-center py-4" style="color:var(--cfp-text-muted);">
      <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
      ไม่มีข้อมูลในช่วงเวลานี้
    </div>
    <?php } else { ?>
    <div>
    <?php foreach ($rows as $r) {
        $apvDate = $r['ApprovedDate'] instanceof DateTime ? $r['ApprovedDate']->format('d M Y') : '';
        $subDate = $r['SubmittedDate'] instanceof DateTime ? $r['SubmittedDate']->format('d M Y') : '';
    ?>
    <?php
      $scopeNo = (int)str_replace('Scope','',$r['Scope']);
      $sColors = array(1=>'#43A047',2=>'#7C3AED',3=>'#F59E0B');
      $sColor  = $sColors[$scopeNo] ?? '#2AABB8';
      $sNames  = array('Scope1'=>'Scope 1','Scope2'=>'Scope 2','Scope3'=>'Scope 3');
      $sName   = $sNames[$r['Scope']] ?? $r['Scope'];
      $apvDate = $r['ApprovedDate'] instanceof DateTime ? $r['ApprovedDate']->format('d M Y') : '';
      $revDate = $r['ReviewedDate'] instanceof DateTime ? $r['ReviewedDate']->format('d M Y') : '';
      $isApproved = ((int)$r['Status'] === 2);
      $isRejected = ((int)$r['Status'] === 0 && !empty($r['Remark']));
    ?>
    <div class="apv-row">
      <div class="scope-bar" style="background:<?php echo $sColor; ?>;"></div>
      <div class="apv-info">
        <div class="apv-name">
          <?php echo $sName; ?> — <?php echo htmlspecialchars($r['SiteName'] ?? ''); ?>
          <span style="font-size:0.72rem;color:var(--cfp-text-muted);font-weight:400;margin-left:4px;"><?php echo $r['YearMonth']; ?></span>
        </div>
        <div class="apv-meta">
          <span style="background:<?php echo $sColor; ?>18;border:1px solid <?php echo $sColor; ?>44;color:<?php echo $sColor; ?>;padding:1px 7px;border-radius:9px;font-size:0.72rem;"><?php echo $sName; ?></span>
          <span><i class="bi bi-list-ul me-1"></i><?php echo (int)($r['ItemCount'] ?? 0); ?> รายการ</span>
          <?php if ($r['TotalCO2e'] !== null) { ?>
          <span style="color:#059669;font-weight:500;"><?php echo number_format((float)$r['TotalCO2e']/1000,4); ?> tCO₂e</span>
          <?php } ?>
          <?php if ($isApproved && !empty($r['ApproverName'])) { ?>
          <span><i class="bi bi-person-check me-1"></i><?php echo htmlspecialchars($r['ApproverName']); ?></span>
          <?php } ?>
          <?php if ($apvDate) { ?><span><i class="bi bi-calendar3 me-1"></i><?php echo $apvDate; ?></span><?php } ?>
        </div>
        <?php if ($isRejected && !empty($r['Remark'])) { ?>
        <div class="reject-reason"><i class="bi bi-exclamation-circle me-1"></i><?php echo htmlspecialchars($r['Remark']); ?></div>
        <?php } ?>
      </div>
      <?php if ($isApproved) { ?>
        <span class="pill-approved"><i class="bi bi-check2 me-1"></i>อนุมัติ</span>
      <?php } elseif ($isRejected) { ?>
        <span class="pill-rejected"><i class="bi bi-x me-1"></i>ส่งกลับ</span>
      <?php } else { ?>
        <span class="pill-pending"><i class="bi bi-pencil me-1"></i>Draft</span>
      <?php } ?>
    </div>
    <?php } ?>
    </div>
    <?php } ?>
  </div>

  <!-- Timeline -->
  <div class="cfp-card" style="margin-bottom:0;">
    <div class="section-hd">
      <i class="bi bi-clock-history" style="font-size:0.85rem;"></i>
      กิจกรรมล่าสุด
    </div>
    <?php if (count($logRows) === 0) { ?>
    <div style="text-align:center;padding:24px 0;color:var(--cfp-text-muted);font-size:0.82rem;">
      <i class="bi bi-clock-history" style="font-size:1.8rem;display:block;margin-bottom:6px;opacity:.4;"></i>
      ยังไม่มีกิจกรรม
    </div>
    <?php } else { ?>
    <?php foreach ($logRows as $i => $r) {
        $isLast     = ($i === count($logRows) - 1);
        $createdDate = $r['CreatedDate'] instanceof DateTime ? $r['CreatedDate']->format('d M Y H:i') : '';
        $colors     = logBgColor($r['ActionCode']);
        $initials   = '';
        if (!empty($r['ActorName'])) {
            $pts = explode(' ', trim($r['ActorName']));
            $initials = mb_substr($pts[0], 0, 1, 'UTF-8');
            if (count($pts) >= 2) { $initials .= mb_substr($pts[1], 0, 1, 'UTF-8'); }
        }
        $avatarColors = array('#2AABB8','#7C3AED','#059669','#D97706','#DC2626','#0284C7');
        $avatarBg = $avatarColors[crc32($r['ActorName'] ?? '') % count($avatarColors)];
        if ($avatarBg < 0) { $avatarBg = $avatarColors[abs(crc32($r['ActorName'] ?? '')) % count($avatarColors)]; }
    ?>
    <div class="tl-item">
      <div class="tl-col">
        <div class="tl-icon-wrap"
             style="background:<?php echo $colors['bg']; ?>;border-color:<?php echo $colors['border']; ?>;color:<?php echo $colors['icon']; ?>;">
          <i class="bi bi-<?php echo logIcon($r['ActionCode']); ?>"></i>
        </div>
        <?php if (!$isLast) { ?><div class="tl-line"></div><?php } ?>
      </div>
      <div class="tl-card">
        <div class="tl-card-top">
          <span class="tl-action-lbl" style="color:<?php echo $colors['icon']; ?>;">
            <?php echo $r['ActionCode'] === 'APPROVE' ? 'อนุมัติ' : 'ปฏิเสธ'; ?>
          </span>
          <span class="tl-time-badge">
            <i class="bi bi-clock" style="font-size:0.65rem;"></i><?php echo $createdDate; ?>
          </span>
        </div>
        <?php if (!empty($r['Remark'])) { ?>
        <div class="tl-itemname">
          <i class="bi bi-file-text" style="font-size:0.75rem;color:var(--cfp-text-muted);"></i>
          <?php echo htmlspecialchars(mb_substr($r['Remark'], 0, 80)); ?>
        </div>
        <?php } ?>
        <div class="tl-chips">
          <?php if (!empty($r['Scope'])) { ?>
          <span class="tl-chip"
                style="background:<?php echo (array(1=>'#43A047',2=>'#7C3AED',3=>'#F59E0B')[(int)str_replace('Scope','',$r['Scope']??'0')] ?? '#2AABB8'); ?>18;
                       border-color:<?php echo (array(1=>'#43A047',2=>'#7C3AED',3=>'#F59E0B')[(int)str_replace('Scope','',$r['Scope']??'0')] ?? '#2AABB8'); ?>55;
                       color:<?php echo (array(1=>'#43A047',2=>'#7C3AED',3=>'#F59E0B')[(int)str_replace('Scope','',$r['Scope']??'0')] ?? '#2AABB8'); ?>;">
            <?php echo htmlspecialchars($r['Scope'] ?? ''); ?>
          </span>
          <?php } ?>
          <?php if (!empty($r['SiteName'])) { ?>
          <span class="tl-chip" style="background:var(--cfp-bg);border-color:var(--cfp-border);color:var(--cfp-text-mid);">
            <i class="bi bi-building" style="font-size:0.65rem;"></i>
            <?php echo htmlspecialchars($r['SiteName']); ?>
          </span>
          <?php } ?>
          
          <?php if ($r['ActionCode'] === 'REJECT' && !empty($r['Remark'])) { ?>
          <span class="tl-chip" style="background:#FEE2E2;border-color:#FECACA;color:#991B1B;">
            <i class="bi bi-exclamation-circle" style="font-size:0.65rem;"></i>
            <?php echo htmlspecialchars(mb_substr($r['Remark'], 0, 40)); ?>
          </span>
          <?php } ?>
        </div>
        <?php if (!empty($r['ActorName'])) { ?>
        <div class="tl-actor">
          <div class="tl-avatar" style="background:<?php echo $avatarColors[abs(crc32($r['ActorName'])) % count($avatarColors)]; ?>;">
            <?php echo htmlspecialchars($initials); ?>
          </div>
          <span class="tl-actor-name"><?php echo htmlspecialchars($r['ActorName']); ?></span>
          <?php if (!empty($r['RoleName'])) { ?>
          <span class="tl-actor-role">· <?php echo htmlspecialchars($r['RoleName']); ?></span>
          <?php } ?>
        </div>
        <?php } ?>
      </div>
    </div>
    <?php } ?>
    <?php } ?>
  </div>

</div>

</div><!-- /.cfp-content -->
</div><!-- /.cfp-main -->

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999">
  <div id="toastSuccess" class="toast align-items-center text-bg-success border-0" style="font-family:'Prompt',sans-serif;">
    <div class="d-flex"><div class="toast-body" id="toastMsg"></div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var toastMsg  = <?php echo json_encode($toastMsg,  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var toastType = <?php echo json_encode($toastType === 'error'); ?>;
    if (toastMsg) {
        document.getElementById('toastMsg').textContent = toastMsg;
        new bootstrap.Toast(document.getElementById('toastSuccess'), { delay: 3000 }).show();
    }
});
</script>
</body></html>

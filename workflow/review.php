<?php
/* workflow/review.php — รออนุมัติ (Hybrid B: KPI + List บน + Approved/Timeline ล่าง) */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(2, 3, 4, 5));
$conn   = getConnection();
$userID = getEffectiveUserID();
$roleID = getEffectiveRole();
$isSuperAdmin = isSuperAdmin() && !isViewingAs();

$toastMsg = ''; $toastType = 'success';
if (!empty($_SESSION['toast_msg'])) {
    $toastMsg  = $_SESSION['toast_msg'];
    $toastType = $_SESSION['toast_type'] ?? 'success';
    unset($_SESSION['toast_msg'], $_SESSION['toast_type']);
}

/* ===== กรองเดือน/ปี ===== */
$filterYM   = $_GET['ym']    ?? date('Ym');
$filterScope= $_GET['scope'] ?? '';
$filterSite = $_GET['site']  ?? '';

$filterYear  = (int)substr($filterYM, 0, 4);
$filterMonth = (int)substr($filterYM, 4, 2);

/* ===== dropdown options ===== */
$resSite = sqlsrv_query($conn, "SELECT SiteID,SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
$sites   = array(); while ($r = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) { $sites[] = $r; }

/* ===== กรอง Site ตามสิทธิ์ Reviewer/Approver (Role 2, 3) ===== */
/* Role 4/5 เห็นทุก Site — Role 2 (Reviewer) และ Role 3 (Approver/ผจก.โรงงาน)
   เห็น/อนุมัติได้เฉพาะ Site ที่ได้รับมอบหมายเท่านั้น
   ถ้าเป็น Admin/SustAdmin (Role จริง 4/5) ที่ Elevate ลงมาเป็น 2/3 ชั่วคราว ให้เห็นทุก Site เหมือนปกติ
   เพราะ UserID จริงของ Admin ไม่เคยมีแถวใน CFP_UserScopeAccess (ไม่จำเป็นต้องมีตอนใช้งานปกติ) */
$allowedSiteIDs = null; /* null = ทุก Site */
if (!isSuperAdmin() && ($roleID === 2 || $roleID === 3)) {
    $resRA = sqlsrv_query($conn,
        "SELECT DISTINCT SiteID FROM CFP_UserScopeAccess
         WHERE UserID = ? AND IsActive = 1 AND SiteID IS NOT NULL",
        array($userID));
    if ($resRA) {
        $allowedSiteIDs = array();
        while ($rA = sqlsrv_fetch_array($resRA, SQLSRV_FETCH_ASSOC)) {
            $allowedSiteIDs[] = (int)$rA['SiteID'];
        }
        /* ถ้าไม่มี row เลย = ไม่มีสิทธิ์ Site ไหน */
        if (empty($allowedSiteIDs)) { $allowedSiteIDs = array(0); /* บังคับไม่เจออะไร */ }
    }
    /* กรอง Site dropdown ให้แสดงเฉพาะที่มีสิทธิ์ */
    $sites = array_filter($sites, function($s) use ($allowedSiteIDs) {
        return in_array((int)$s['SiteID'], $allowedSiteIDs);
    });
    $sites = array_values($sites);
}

/* ===== ดึงข้อมูลรออนุมัติ (Status = Submitted) ===== */
$sqlPending = "
    SELECT h.HeaderID, h.Scope, h.YearMonth, h.SiteID, h.Status,
           h.SubmittedBy, h.SubmittedDate,
           h.ReviewedBy, ur.FullName AS ReviewerName,
           s.SiteName, us.FullName AS SubmitterName,
           COUNT(a.ActivityID) AS ItemCount,
           SUM(a.CO2e) AS TotalCO2e
    FROM CFP_MonthlyHeader h
    LEFT JOIN CFP_Site s ON s.SiteID = h.SiteID
    LEFT JOIN CFP_User us ON us.UserID = h.SubmittedBy
    LEFT JOIN CFP_User ur ON ur.UserID = h.ReviewedBy
    LEFT JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
    WHERE h.Status = 1
    AND YEAR(h.SubmittedDate) = ? AND MONTH(h.SubmittedDate) = ?
";
$paramsPending = array($filterYear, $filterMonth);
/* กรอง Site ตามสิทธิ์ Role 2 */
if ($allowedSiteIDs !== null && !empty($allowedSiteIDs)) {
    $ph = implode(',', array_fill(0, count($allowedSiteIDs), '?'));
    $sqlPending .= " AND h.SiteID IN ($ph)";
    $paramsPending = array_merge($paramsPending, $allowedSiteIDs);
}
if ($filterScope !== '') { $sqlPending .= " AND h.Scope = ?"; $paramsPending[] = 'Scope'.(int)$filterScope; }
if ($filterSite  !== '') { $sqlPending .= " AND h.SiteID = ?";  $paramsPending[] = (int)$filterSite; }
$sqlPending .= " GROUP BY h.HeaderID, h.Scope, h.YearMonth, h.SiteID, h.Status, h.SubmittedBy, h.SubmittedDate, h.ReviewedBy, ur.FullName, s.SiteName, us.FullName ORDER BY h.SubmittedDate DESC";

$resPending = @sqlsrv_query($conn, $sqlPending, $paramsPending);
$pendingRows = array();
if ($resPending) { while ($r = sqlsrv_fetch_array($resPending, SQLSRV_FETCH_ASSOC)) { $pendingRows[] = $r; } }

/* ===== ดึงข้อมูลอนุมัติแล้ว (เดือนเดียวกัน) =====
   หมายเหตุ: query เดิมอ้างคอลัมน์ DataID/Status/SubmittedDate/ApprovedDate/ApprovedBy บน CFP_ActivityData
   ซึ่งไม่มีอยู่จริง (คอลัมน์เหล่านี้อยู่ที่ CFP_MonthlyHeader) ทำให้ query error ทุกครั้งแต่ถูก @ กลืน error ไว้
   เลยไม่เคยแสดงรายการอนุมัติแล้วเลยสักครั้ง — แก้ให้ join ผ่าน Header ให้ถูกต้อง */
$sqlApproved = "
    SELECT a.ActivityID AS DataID, i.ItemName, i.ScopeNo, h.SiteID, s.SiteName,
           h.YearMonth, a.Quantity, u.UnitName,
           h.ApprovedDate, ap.FullName AS ApproverName
    FROM CFP_MonthlyHeader h
    JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
    JOIN CFP_ActivityItem i ON i.ItemID = a.ItemID
    LEFT JOIN CFP_Site s ON s.SiteID = h.SiteID
    LEFT JOIN CFP_Unit u ON u.UnitID = i.UnitID
    LEFT JOIN CFP_User ap ON ap.UserID = h.ApprovedBy
    WHERE h.Status = 2
    AND YEAR(h.ApprovedDate) = ? AND MONTH(h.ApprovedDate) = ?
    ORDER BY h.ApprovedDate DESC
";
$resApproved = sqlsrv_query($conn, $sqlApproved, array($filterYear, $filterMonth));
$approvedRows = array();
if ($resApproved) {
    while ($r = sqlsrv_fetch_array($resApproved, SQLSRV_FETCH_ASSOC)) {
        $r['Status'] = 'Approved';
        $approvedRows[] = $r;
    }
}

/* Reject ไม่เก็บสถานะถาวร (ส่งกลับ Header เป็น Draft ทันที) — ดึงประวัติ "ปฏิเสธ" จาก CFP_ActionLog แทน */
$sqlRejected = "
    SELECT l.TargetID AS HeaderID, l.TargetScope, l.TargetSiteID AS SiteID, s.SiteName,
           l.ActionTime AS ApprovedDate, u.FullName AS ApproverName
    FROM CFP_ActionLog l
    LEFT JOIN CFP_Site s ON s.SiteID = l.TargetSiteID
    LEFT JOIN CFP_User u ON u.UserID = l.ActorUserID
    WHERE l.ActionCode = 'REJECT' AND l.TargetTable = 'CFP_MonthlyHeader'
    AND YEAR(l.ActionTime) = ? AND MONTH(l.ActionTime) = ?
    ORDER BY l.ActionTime DESC
";
$resRejected = sqlsrv_query($conn, $sqlRejected, array($filterYear, $filterMonth));
if ($resRejected) {
    while ($r = sqlsrv_fetch_array($resRejected, SQLSRV_FETCH_ASSOC)) {
        $approvedRows[] = array(
            'ItemName'     => 'ส่งกลับให้แก้ไข (Header #' . $r['HeaderID'] . ')',
            'ScopeNo'      => (int)substr($r['TargetScope'] ?? '', -1),
            'SiteName'     => $r['SiteName'],
            'ApprovedDate' => $r['ApprovedDate'],
            'ApproverName' => $r['ApproverName'],
            'Status'       => 'Rejected',
        );
    }
}
/* เรียงรวม Approved+Rejected ตามเวลาล่าสุด */
usort($approvedRows, function($a, $b) {
    $ta = ($a['ApprovedDate'] instanceof DateTime) ? $a['ApprovedDate']->getTimestamp() : 0;
    $tb = ($b['ApprovedDate'] instanceof DateTime) ? $b['ApprovedDate']->getTimestamp() : 0;
    return $tb <=> $ta;
});

/* ===== Timeline (audit log ล่าสุด) ===== */
$sqlLog = "
    SELECT TOP 15
           l.ActionCode, l.TargetTable, l.TargetID, l.Remark,
           l.CreatedDate, l.ActorRole,
           u.FullName AS ActorName,
           r.RoleName,
           ad.Quantity,
           ai.ItemName, ai.ScopeNo,
           s.SiteName,
           un.UnitName
    FROM CFP_ActionLog l
    LEFT JOIN CFP_User u         ON u.UserID   = l.ActorUserID
    LEFT JOIN CFP_Role r         ON r.RoleID   = l.ActorRole
    LEFT JOIN CFP_ActivityData ad ON ad.ActivityID = l.TargetID
                                  AND l.TargetTable = 'CFP_ActivityData'
    LEFT JOIN CFP_ActivityItem ai ON ai.ItemID = ad.ItemID
    LEFT JOIN CFP_Site s          ON s.SiteID  = ad.SiteID
    LEFT JOIN CFP_Unit un         ON un.UnitID = ai.UnitID
    WHERE l.ActionCode IN ('SUBMIT','APPROVE','REJECT','DATA_CREATE','DATA_UPDATE')
    ORDER BY l.CreatedDate DESC
";
$resLog = sqlsrv_query($conn, $sqlLog);
$logRows = array();
if ($resLog) { while ($r = sqlsrv_fetch_array($resLog, SQLSRV_FETCH_ASSOC)) { $logRows[] = $r; } }

/* ===== KPI ===== */
$cntPending  = count($pendingRows);
$cntApproved = count(array_filter($approvedRows, function($r){ return $r['Status'] === 'Approved'; }));
$cntRejected = count(array_filter($approvedRows, function($r){ return $r['Status'] === 'Rejected'; }));

/* ===== สรุปยอดรวม (เดือนนี้ทั้งหมด) ===== */
/* เดิมอ้าง CFP_ActivityData.Status/SubmittedDate ที่ไม่มีอยู่จริง (คอลัมน์นี้อยู่ที่ CFP_MonthlyHeader)
   ทำให้ query error เงียบๆ ทุกครั้ง คงค่า 0 ตลอด — แก้ให้ join ผ่าน Header ให้ถูกต้อง */
$sqlTotal = "SELECT COUNT(*) AS C FROM CFP_MonthlyHeader WHERE Status IN (1,2,3) AND YEAR(SubmittedDate)=? AND MONTH(SubmittedDate)=?";
$resTotal = sqlsrv_query($conn, $sqlTotal, array($filterYear, $filterMonth));
$cntTotal = 0;
if ($resTotal) { $rT = sqlsrv_fetch_array($resTotal, SQLSRV_FETCH_ASSOC); $cntTotal = $rT ? (int)$rT['C'] : 0; }

/* ===== Site count ===== */
$sqlSiteC = "SELECT COUNT(DISTINCT SiteID) AS C FROM CFP_MonthlyHeader WHERE Status IN (1,2,3) AND YEAR(SubmittedDate)=? AND MONTH(SubmittedDate)=?";
$resSiteC = sqlsrv_query($conn, $sqlSiteC, array($filterYear, $filterMonth));
$cntSites = 0;
if ($resSiteC) { $rS = sqlsrv_fetch_array($resSiteC, SQLSRV_FETCH_ASSOC); $cntSites = $rS ? (int)$rS['C'] : 0; }

/* ===== helper: scope color bar ===== */
function scopeBar($scope) {
    $colors = array(1 => '#43A047', 2 => '#7C3AED', 3 => '#F59E0B');
    return $colors[$scope] ?? '#2AABB8';
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
function logDot($action) {
    $map = array('SUBMIT' => '#F59E0B', 'APPROVE' => '#43A047', 'REJECT' => '#E05050',
                 'DATA_CREATE' => '#2AABB8', 'DATA_UPDATE' => '#7C3AED');
    return $map[$action] ?? '#B0CED8';
}
function logLabel($action) {
    $map = array('SUBMIT' => 'ส่งรออนุมัติ', 'APPROVE' => 'อนุมัติ', 'REJECT' => 'ปฏิเสธ',
                 'DATA_CREATE' => 'บันทึกข้อมูล', 'DATA_UPDATE' => 'แก้ไขข้อมูล');
    return $map[$action] ?? $action;
}
function logIcon($action) {
    $map = array(
        'SUBMIT'      => 'send',
        'APPROVE'     => 'check2-circle',
        'REJECT'      => 'x-circle',
        'DATA_CREATE' => 'plus-circle',
        'DATA_UPDATE' => 'pencil',
    );
    return $map[$action] ?? 'circle';
}
function logBgColor($action) {
    $map = array(
        'SUBMIT'      => array('bg'=>'#FEF3C7','border'=>'#FCD34D','icon'=>'#D97706'),
        'APPROVE'     => array('bg'=>'#D1FAE5','border'=>'#6EE7B7','icon'=>'#059669'),
        'REJECT'      => array('bg'=>'#FEE2E2','border'=>'#FECACA','icon'=>'#DC2626'),
        'DATA_CREATE' => array('bg'=>'#DBEAFE','border'=>'#BFDBFE','icon'=>'#2563EB'),
        'DATA_UPDATE' => array('bg'=>'#EDE9FE','border'=>'#DDD6FE','icon'=>'#7C3AED'),
    );
    return $map[$action] ?? array('bg'=>'#F1F5F5','border'=>'#D0E8EE','icon'=>'#7AAAB8');
}
function logScopeLabel($scope) {
    $map = array(1=>'Scope 1', 2=>'Scope 2', 3=>'Scope 3');
    return $map[$scope] ?? '';
}
function logScopeColor($scope) {
    $map = array(1=>'#43A047', 2=>'#7C3AED', 3=>'#F59E0B');
    return $map[$scope] ?? '#2AABB8';
}
function logRoleShort($role) {
    $map = array(1=>'DataEntry', 2=>'Reviewer', 3=>'Approver', 4=>'Admin', 5=>'SustAdmin');
    return $map[$role] ?? '';
}

$canApprove      = in_array($roleID, array(3, 4, 5)) && !isViewingAs();  /* View-as = read-only เสมอ */
$canReject       = in_array($roleID, array(2, 3, 4, 5)) && !isViewingAs();
$canMarkReviewed = ($roleID === 2) && !isViewingAs();
$pageTitle  = 'รออนุมัติ';
$pageIcon   = 'clipboard-check';
?>
<!DOCTYPE html><html lang="th"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>รออนุมัติ — CFP</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">
<style>
body { font-family: 'Prompt', sans-serif; }
.font-prompt { font-family: 'Prompt', sans-serif !important; }

/* KPI Cards */
.kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
.kpi-card { background: #fff; border: 1px solid var(--cfp-border); border-radius: 10px; padding: 12px 16px; text-align: center; }
.kpi-num { font-size: 1.8rem; font-weight: 700; line-height: 1; }
.kpi-lbl { font-size: 0.72rem; color: var(--cfp-text-muted); margin-top: 4px; }

/* Scope bar accent */
.scope-bar { width: 4px; border-radius: 3px; flex-shrink: 0; align-self: stretch; min-height: 36px; }

/* Section headers */
.section-hd { font-size: 0.72rem; font-weight: 600; color: var(--cfp-text-muted); letter-spacing: .8px; text-transform: uppercase; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
.section-hd .badge-count { padding: 1px 7px; border-radius: 9px; font-size: 0.7rem; font-weight: 600; }

/* Pending list row */
.pending-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--cfp-border); }
.pending-row:last-child { border-bottom: none; }
.pending-info { flex: 1; min-width: 0; }
.pending-name { font-size: 0.85rem; font-weight: 500; color: var(--cfp-text); }
.pending-meta { display: flex; gap: 6px; align-items: center; margin-top: 3px; flex-wrap: wrap; }
.pending-meta span { font-size: 0.72rem; color: var(--cfp-text-muted); }
.pending-actions { display: flex; gap: 5px; flex-shrink: 0; }

/* Action btns */
.btn-approve { padding: 4px 12px; border-radius: 6px; font-size: 0.78rem; background: var(--cfp-primary); color: #fff; border: none; cursor: pointer; font-family: 'Prompt', sans-serif; white-space: nowrap; }
.btn-approve:hover { background: var(--cfp-primary-dark); }
.btn-reject  { padding: 4px 10px; border-radius: 6px; font-size: 0.78rem; background: #FEE2E2; color: #B91C1C; border: 1px solid #FECACA; cursor: pointer; font-family: 'Prompt', sans-serif; }
.btn-reject:hover { background: #FCA5A5; }
.btn-view    { padding: 4px 10px; border-radius: 6px; font-size: 0.78rem; background: var(--cfp-bg); color: var(--cfp-text-mid); border: 1px solid var(--cfp-border); cursor: pointer; font-family: 'Prompt', sans-serif; }
.btn-view:hover { background: var(--cfp-hover); color: var(--cfp-primary); }

/* Bottom split */
.bottom-grid { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 14px; margin-top: 14px; }

/* Approved list */
.apv-row { display: flex; align-items: center; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid var(--cfp-border); }
.apv-row:last-child { border-bottom: none; }
.apv-name { font-size: 0.82rem; font-weight: 500; color: var(--cfp-text); }
.apv-sub  { font-size: 0.72rem; color: var(--cfp-text-muted); }

/* Timeline */
/* Timeline cards */
.tl-item { display: flex; gap: 10px; padding: 0 0 14px 0; position: relative; }
.tl-item:last-child { padding-bottom: 0; }
.tl-col { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; width: 32px; }
.tl-icon-wrap {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; border: 1.5px solid;
    font-size: 0.85rem;
}
.tl-line { width: 1.5px; background: var(--cfp-border); flex: 1; margin: 4px 0; min-height: 10px; }
.tl-card {
    flex: 1; min-width: 0;
    background: var(--cfp-bg); border: 1px solid var(--cfp-border);
    border-radius: 8px; padding: 8px 12px;
}
.tl-card-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; flex-wrap: wrap; gap: 4px; }
.tl-action-label { font-size: 0.8rem; font-weight: 600; color: var(--cfp-text); }
.tl-time-badge {
    font-size: 0.68rem; color: var(--cfp-text-muted);
    background: var(--cfp-card); border: 1px solid var(--cfp-border);
    padding: 1px 7px; border-radius: 9px; white-space: nowrap;
}
.tl-item-name { font-size: 0.8rem; color: var(--cfp-text); margin-bottom: 4px; font-weight: 500; }
.tl-meta-row { display: flex; flex-wrap: wrap; gap: 5px; align-items: center; }
.tl-chip {
    display: inline-flex; align-items: center; gap: 3px;
    font-size: 0.68rem; padding: 1px 7px; border-radius: 9px;
    border: 1px solid; white-space: nowrap;
}
.tl-actor-row { display: flex; align-items: center; gap: 5px; margin-top: 5px; padding-top: 5px; border-top: 1px solid var(--cfp-border); }
.tl-actor-avatar {
    width: 18px; height: 18px; border-radius: 50%;
    background: var(--cfp-primary); color: #fff;
    font-size: 0.6rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.tl-actor-name { font-size: 0.72rem; color: var(--cfp-text-mid); font-weight: 500; }
.tl-actor-role { font-size: 0.68rem; color: var(--cfp-text-muted); }

/* Status pill */
.pill-approved { background: #E4F8F0; color: #1A6A50; border: 1px solid #A0DCC8; padding: 2px 8px; border-radius: 9px; font-size: 0.72rem; font-weight: 500; white-space: nowrap; }
.pill-rejected { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; padding: 2px 8px; border-radius: 9px; font-size: 0.72rem; font-weight: 500; white-space: nowrap; }
.pill-pending  { background: #FEF3C7; color: #92400E; border: 1px solid #FCD34D; padding: 2px 8px; border-radius: 9px; font-size: 0.72rem; font-weight: 500; white-space: nowrap; }

/* modal reason */
</style>
</head><body>
<?php include '../includes/sidebar.php'; ?>
<div class="cfp-main">
<?php include '../includes/topbar.php'; ?>
<div class="cfp-content">

<!-- Page header + filter bar -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold" style="color:var(--cfp-primary);">
      <i class="bi bi-clipboard-check me-2"></i>ตรวจสอบ / อนุมัติ
    </h5>
    <div style="font-size:0.75rem;color:var(--cfp-text-muted);">Workflow › รออนุมัติ</div>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
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
      <select name="site" class="form-select form-select-sm" style="width:140px;" onchange="this.form.submit()">
        <option value="">ทุก Site</option>
        <?php foreach ($sites as $s) { ?>
        <option value="<?php echo $s['SiteID']; ?>" <?php echo $filterSite==(string)$s['SiteID']?'selected':''; ?>>
          <?php echo htmlspecialchars($s['SiteName']); ?>
        </option>
        <?php } ?>
      </select>
    </form>
  </div>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-num" style="color:#F59E0B;"><?php echo $cntPending; ?></div>
    <div class="kpi-lbl"><i class="bi bi-clock me-1" style="color:#F59E0B;"></i>รออนุมัติ</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-num" style="color:var(--cfp-success);"><?php echo $cntApproved; ?></div>
    <div class="kpi-lbl"><i class="bi bi-check-circle me-1" style="color:var(--cfp-success);"></i>อนุมัติแล้ว</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-num" style="color:var(--cfp-danger);"><?php echo $cntRejected; ?></div>
    <div class="kpi-lbl"><i class="bi bi-x-circle me-1" style="color:var(--cfp-danger);"></i>ปฏิเสธ</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-num" style="color:var(--cfp-text-mid);"><?php echo $cntSites; ?></div>
    <div class="kpi-lbl"><i class="bi bi-building me-1"></i>Site ทั้งหมด</div>
  </div>
</div>

<!-- Pending + Timeline (ข้าง ๆ กัน) -->
<div class="bottom-grid">

  <!-- Pending Section -->
  <div class="cfp-card" style="margin-bottom:0;">
  <div class="section-hd">
    <i class="bi bi-clock" style="color:#F59E0B;font-size:0.85rem;"></i>
    รออนุมัติ
    <span class="badge-count" style="background:#FEF3C7;color:#92400E;"><?php echo $cntPending; ?></span>
    <?php if ($cntPending === 0) { echo '<span style="font-weight:400;color:var(--cfp-text-muted);text-transform:none;letter-spacing:0;">ไม่มีรายการ</span>'; } ?>
  </div>

  <?php if (count($pendingRows) === 0) { ?>
  <div class="text-center py-4" style="color:var(--cfp-text-muted);">
    <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
    ไม่มีรายการรออนุมัติในช่วงเวลานี้
  </div>
  <?php } else { ?>
  <div>
    <?php foreach ($pendingRows as $r) {
      $submittedDate = $r['SubmittedDate'] instanceof DateTime
          ? $r['SubmittedDate']->format('d M Y H:i')
          : (is_string($r['SubmittedDate']) ? $r['SubmittedDate'] : '—');
      /* query คืนค่าระดับ Header (สรุปยอด) ไม่มี ItemName/ScopeNo/Quantity/UnitName
         จึงสร้าง label จาก Site + Scope + เดือน แทน */
      $scopeIntPending = (int)str_replace('Scope', '', $r['Scope'] ?? '');
      $ymLabelPending  = !empty($r['YearMonth'])
          ? substr($r['YearMonth'], 4, 2) . '/' . substr($r['YearMonth'], 0, 4)
          : '—';
      $hLabel = ($r['SiteName'] ?? '—') . ' — Scope ' . $scopeIntPending . ' (' . $ymLabelPending . ')';
    ?>
    <div class="pending-row">
      <div class="scope-bar" style="background:<?php echo scopeBar($scopeIntPending); ?>;"></div>
      <div class="pending-info">
        <div class="pending-name"><?php echo htmlspecialchars($hLabel); ?></div>
        <div class="pending-meta">
          <?php echo scopeBadge($scopeIntPending); ?>
          <?php if (!empty($r['SiteName'])) { ?>
          <span><i class="bi bi-building me-1"></i><?php echo htmlspecialchars($r['SiteName']); ?></span>
          <?php } ?>
          <span><i class="bi bi-list-check me-1"></i><?php echo (int)($r['ItemCount'] ?? 0); ?> รายการ</span>
          <?php if ($r['TotalCO2e'] !== null) { ?>
          <span><i class="bi bi-cloud me-1"></i><?php echo number_format((float)$r['TotalCO2e'], 2); ?> kgCO2e</span>
          <?php } ?>
          <?php if (!empty($r['SubmitterName'])) { ?>
          <span><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($r['SubmitterName']); ?></span>
          <?php } ?>
          <span><i class="bi bi-calendar3 me-1"></i><?php echo $submittedDate; ?></span>
          <?php if (!empty($r['ReviewedBy'])) { ?>
          <span style="color:#2E7D32;"><i class="bi bi-eye-check me-1"></i>ตรวจแล้วโดย <?php echo htmlspecialchars($r['ReviewerName']); ?></span>
          <?php } ?>
        </div>
      </div>
      <div class="pending-actions">
        <?php if ($canApprove) { ?>
        <button class="btn-cfp-tonal btn-cfp-tonal-success" onclick="confirmApprove(<?php echo (int)$r['HeaderID']; ?>, '<?php echo htmlspecialchars(addslashes($hLabel)); ?>')">
          <i class="bi bi-check2 me-1"></i>อนุมัติ
        </button>
        <?php } ?>
        <?php if ($canReject) { ?>
        <button class="btn-cfp-tonal btn-cfp-tonal-danger" onclick="openRejectModal(<?php echo (int)$r['HeaderID']; ?>, '<?php echo htmlspecialchars(addslashes($hLabel)); ?>')">
          <i class="bi bi-x me-1"></i>ปฏิเสธ
        </button>
        <?php } ?>
        <?php if ($canMarkReviewed && empty($r['ReviewedBy'])) { ?>
        <button class="btn-cfp-tonal btn-cfp-tonal-info" onclick="submitAction('mark_reviewed', <?php echo (int)$r['HeaderID']; ?>, '')">
          <i class="bi bi-eye-check me-1"></i>ตรวจแล้ว
        </button>
        <?php } ?>
        <button class="btn-cfp-tonal btn-cfp-tonal-info" onclick="openDetailModal(<?php echo (int)$r['HeaderID']; ?>)">
          <i class="bi bi-eye me-1"></i>ดู
        </button>
        <?php if ($isSuperAdmin) { ?>
        <button class="btn-cfp-tonal btn-cfp-tonal-navy" onclick="adminUnlock(<?php echo (int)$r['HeaderID']; ?>, '<?php echo addslashes($hLabel); ?>')">
          <i class="bi bi-unlock me-1"></i>Unlock
        </button>
        <?php } ?>
      </div>
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
        $createdDate = $r['CreatedDate'] instanceof DateTime
            ? $r['CreatedDate']->format('d M Y H:i')
            : (is_string($r['CreatedDate']) ? $r['CreatedDate'] : '');
        $colors     = logBgColor($r['ActionCode']);
        $initials   = '';
        if (!empty($r['ActorName'])) {
            $parts    = explode(' ', trim($r['ActorName']));
            $initials = mb_substr($parts[0], 0, 1, 'UTF-8');
            if (count($parts) >= 2) { $initials .= mb_substr($parts[1], 0, 1, 'UTF-8'); }
        }
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
          <span class="tl-action-label" style="color:<?php echo $colors['icon']; ?>;">
            <?php echo htmlspecialchars(logLabel($r['ActionCode'])); ?>
          </span>
          <span class="tl-time-badge">
            <i class="bi bi-clock me-1"></i><?php echo $createdDate; ?>
          </span>
        </div>
        <?php if (!empty($r['ItemName'])) { ?>
        <div class="tl-item-name">
          <i class="bi bi-file-text me-1" style="color:var(--cfp-text-muted);font-size:0.75rem;"></i>
          <?php echo htmlspecialchars($r['ItemName']); ?>
        </div>
        <?php } elseif (!empty($r['Remark'])) { ?>
        <div class="tl-item-name" style="font-weight:400;">
          <?php echo htmlspecialchars(mb_substr($r['Remark'], 0, 80)); ?>
        </div>
        <?php } ?>
        <div class="tl-meta-row">
          <?php if (!empty($r['ScopeNo'])) { ?>
          <span class="tl-chip"
                style="background:<?php echo logScopeColor($r['ScopeNo']); ?>18;
                       border-color:<?php echo logScopeColor($r['ScopeNo']); ?>44;
                       color:<?php echo logScopeColor($r['ScopeNo']); ?>;">
            <?php echo logScopeLabel($r['ScopeNo']); ?>
          </span>
          <?php } ?>
          <?php if (!empty($r['SiteName'])) { ?>
          <span class="tl-chip" style="background:#EEF6F8;border-color:var(--cfp-border);color:var(--cfp-text-mid);">
            <i class="bi bi-building" style="font-size:0.65rem;"></i>
            <?php echo htmlspecialchars($r['SiteName']); ?>
          </span>
          <?php } ?>
          <?php if ($r['Quantity'] !== null && !empty($r['UnitName'])) { ?>
          <span class="tl-chip" style="background:#F0FDF4;border-color:#BBF7D0;color:#15803D;">
            <i class="bi bi-bar-chart-line" style="font-size:0.65rem;"></i>
            <?php echo number_format((float)$r['Quantity'], 2); ?> <?php echo htmlspecialchars($r['UnitName']); ?>
          </span>
          <?php } ?>
        </div>
        <?php if (!empty($r['ActorName'])) { ?>
        <div class="tl-actor-row">
          <div class="tl-actor-avatar"><?php echo htmlspecialchars($initials); ?></div>
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

<!-- Modal: Reject Reason -->
<div class="modal fade" id="modalReject" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="font-family:'Prompt',sans-serif;">
      <div class="modal-header">
        <h6 class="modal-title"><i class="bi bi-x-circle me-2"></i>ปฏิเสธรายการ</h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2" style="font-size:0.85rem;color:var(--cfp-text-muted);">รายการ: <strong id="rejectItemName"></strong></p>
        <label class="form-label form-required">เหตุผลที่ปฏิเสธ</label>
        <textarea id="rejectReason" class="form-control" rows="3" maxlength="500" placeholder="กรุณาระบุเหตุผล..."></textarea>
      </div>
      <div class="modal-footer" style="background:#F9FAFB;">
        <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
        <button class="btn btn-sm btn-reject" style="padding:5px 16px;" id="btnConfirmReject">
          <i class="bi bi-x-circle me-1"></i>ยืนยันปฏิเสธ
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: View Detail -->
<div class="modal fade" id="modalDetail" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content" style="font-family:'Prompt',sans-serif;">
      <div class="modal-header">
        <h6 class="modal-title"><i class="bi bi-eye me-2"></i>รายละเอียดข้อมูล</h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="detailBody">
        <div class="text-center py-4"><div class="spinner-border text-primary" style="width:2rem;height:2rem;"></div></div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: File Preview (ซ้อนอยู่เหนือ modalDetail) -->
<div class="modal fade" id="modalFilePreview" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content" style="font-family:'Prompt',sans-serif;">
      <div class="modal-header">
        <h6 class="modal-title text-truncate"><i class="bi bi-paperclip me-2"></i><span id="filePreviewName">ไฟล์แนบ</span></h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center" id="filePreviewBody" style="min-height:200px;background:#F6F9FA;padding:16px;">
      </div>
      <div class="modal-footer">
        <a id="filePreviewDownload" href="#" target="_blank" rel="noopener" class="btn btn-sm" style="background:var(--cfp-primary,#2AABB8);color:#fff;">
          <i class="bi bi-box-arrow-up-right me-1"></i>เปิดแท็บใหม่ / ดาวน์โหลด
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999">
  <div id="toastSuccess" class="toast align-items-center text-bg-success border-0" style="font-family:'Prompt',sans-serif;">
    <div class="d-flex"><div class="toast-body" id="toastMsg"></div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
  <div id="toastError" class="toast align-items-center text-bg-danger border-0" style="font-family:'Prompt',sans-serif;">
    <div class="d-flex"><div class="toast-body" id="toastErrMsg"></div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
var currentRejectID = 0;
var csrfToken = <?php echo json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var canApprove = <?php echo $canApprove ? 'true' : 'false'; ?>;
var canReject = <?php echo $canReject ? 'true' : 'false'; ?>;
var isSuperAdmin = <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;

function confirmApprove(headerID, label) {
    if (!canApprove) { return; }
    Swal.fire({
        title: 'ยืนยันการอนุมัติ?',
        html: '<b>' + label + '</b>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-check2 me-1"></i>อนุมัติ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#2AABB8',
        customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (!result.isConfirmed) { return; }
        submitAction('approve', headerID, '');
    });
}

function openRejectModal(headerID, label) {
    if (!canReject) { return; }
    currentRejectID = headerID;
    document.getElementById('rejectItemName').textContent = label;
    document.getElementById('rejectReason').value = '';
    new bootstrap.Modal(document.getElementById('modalReject')).show();
}

document.getElementById('btnConfirmReject').addEventListener('click', function() {
    var reason = document.getElementById('rejectReason').value.trim();
    if (!reason) {
        Swal.fire({ icon: 'warning', title: 'กรุณาระบุเหตุผล', confirmButtonText: 'ตกลง', customClass: { popup: 'font-prompt' } });
        return;
    }
    bootstrap.Modal.getInstance(document.getElementById('modalReject')).hide();
    submitAction('reject', currentRejectID, reason);
});

function submitAction(action, dataID, reason) {
    Swal.fire({ title: 'กำลังดำเนินการ...', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });
    fetch('approve_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: action, headerID: dataID, reason: reason, csrf_token: csrfToken })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            var titleMap = { approve: 'อนุมัติเรียบร้อย!', reject: 'ปฏิเสธเรียบร้อย!', mark_reviewed: 'บันทึกตรวจแล้ว!', unlock: 'Unlock เรียบร้อย!' };
            Swal.fire({ icon: 'success', title: titleMap[action] || res.msg,
                timer: 2000, timerProgressBar: true, showConfirmButton: false, customClass: { popup: 'font-prompt' } })
            .then(function() { location.reload(); });
        } else {
            Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: res.msg, confirmButtonText: 'ตกลง', customClass: { popup: 'font-prompt' } });
        }
    })
    .catch(function() {
        Swal.fire({ icon: 'error', title: 'เชื่อมต่อ server ไม่ได้', confirmButtonText: 'ตกลง', customClass: { popup: 'font-prompt' } });
    });
}

function openDetailModal(dataID) {
    document.getElementById('detailBody').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" style="width:2rem;height:2rem;"></div></div>';
    new bootstrap.Modal(document.getElementById('modalDetail')).show();
    fetch('approve_handler.php?action=detail&headerID=' + dataID)
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success && res.html) {
            document.getElementById('detailBody').innerHTML = res.html;
        } else {
            document.getElementById('detailBody').innerHTML = '<p class="text-danger">ไม่สามารถโหลดข้อมูลได้</p>';
        }
    })
    .catch(function() {
        document.getElementById('detailBody').innerHTML = '<p class="text-danger">เชื่อมต่อ server ไม่ได้</p>';
    });
}

/* เปิดไฟล์แนบเป็น modal ซ้อนเหนือ modalDetail แทนการเปิดแท็บใหม่ — รองรับรูปภาพ (แสดงตรง) และ PDF (embed) */
function openFilePreview(url, fileName) {
    document.getElementById('filePreviewName').textContent = fileName;
    document.getElementById('filePreviewDownload').href = url;
    var body = document.getElementById('filePreviewBody');
    var ext = (fileName.split('.').pop() || '').toLowerCase();
    if (['jpg', 'jpeg', 'png'].indexOf(ext) !== -1) {
        body.innerHTML = '<img src="' + url + '" style="max-width:100%;max-height:80vh;border-radius:8px;">';
    } else if (ext === 'pdf') {
        body.innerHTML = '<embed src="' + url + '" type="application/pdf" style="width:100%;height:80vh;border:none;border-radius:8px;">';
    } else {
        body.innerHTML = '<p style="color:var(--cfp-text-muted,#6B7280);">ไม่รองรับการแสดงตัวอย่างไฟล์ประเภทนี้ กด "เปิดแท็บใหม่" เพื่อดูไฟล์</p>';
    }
    var m = new bootstrap.Modal(document.getElementById('modalFilePreview'));
    m.show();
}

/* Bootstrap ไม่รองรับ modal ซ้อนกันโดย native — ปรับ z-index ของ modal ที่เปิดทีหลังให้ลอยอยู่บนสุดเสมอ
   กัน backdrop ของ modalFilePreview ไปบังอยู่ใต้ modalDetail */
document.addEventListener('show.bs.modal', function(e) {
    var openModalsCount = document.querySelectorAll('.modal.show').length;
    if (openModalsCount > 0) {
        setTimeout(function() {
            var backdrops = document.querySelectorAll('.modal-backdrop:not(.stacked)');
            var topBackdrop = backdrops[backdrops.length - 1];
            if (topBackdrop) {
                topBackdrop.classList.add('stacked');
                topBackdrop.style.zIndex = 1060 + (10 * openModalsCount);
            }
            e.target.style.zIndex = 1070 + (10 * openModalsCount);
        }, 0);
    }
});

document.addEventListener('DOMContentLoaded', function() {
    var toastMsg  = <?php echo json_encode($toastMsg,  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var toastType = <?php echo json_encode($toastType === 'error'); ?>;
    if (toastMsg) { showToast(toastMsg, toastType); }
});

function showToast(msg, isErr) {
    var id  = isErr ? 'toastError'  : 'toastSuccess';
    var mid = isErr ? 'toastErrMsg' : 'toastMsg';
    document.getElementById(mid).textContent = msg;
    new bootstrap.Toast(document.getElementById(id), { delay: 3000 }).show();
}
</script>
</body></html>
<?php
/* ==============================================
   dashboard/index.php — CFP Wave Theme
   ระบบบริหารจัดการคาร์บอนองค์กร
   PHP 8.3 + MSSQL (sqlsrv)
   ============================================== */

require_once '../includes/auth_check.php';
require_once '../config/db.php';

$pageTitle = 'Dashboard';
$pageIcon  = 'grid-1x2';

/* Filter */
$filterYear  = (int)($_GET['year']  ?? date('Y'));
$filterMonth = (int)($_GET['month'] ?? date('n'));
$yearMonth   = sprintf('%04d%02d', $filterYear, $filterMonth);
$filterSite  = (int)($_GET['site_id'] ?? 0);

$conn = getConnection();

/* Sites */
$sites    = array();
$resSites = sqlsrv_query($conn, "SELECT SiteID, SiteName FROM CFP_Site WHERE IsActive = 1 ORDER BY SiteName");
while ($row = sqlsrv_fetch_array($resSites, SQLSRV_FETCH_ASSOC)) {
    $sites[] = $row;
}

/* Scope where clause */
$scopeWhere = $filterSite > 0 ? " AND a.SiteID = $filterSite" : '';
if ((int)$_SESSION['role_id'] === 1 && (int)$_SESSION['site_id'] > 0) {
    $scopeWhere = " AND a.SiteID = " . (int)$_SESSION['site_id'];
}

/* CO2e by Scope */
$sqlCO2e = "SELECT a.Scope, SUM(a.CO2e) AS TotalCO2e, COUNT(a.ActivityID) AS RecordCount
            FROM CFP_ActivityData a
            INNER JOIN CFP_MonthlyHeader h ON a.HeaderID = h.HeaderID
            WHERE h.YearMonth = ? AND a.IsActive = 1 $scopeWhere
            GROUP BY a.Scope";
$resCO2e = sqlsrv_query($conn, $sqlCO2e, array($yearMonth));
$scope1CO2e = 0; $scope2CO2e = 0; $scope3CO2e = 0;
$scope1Cnt  = 0; $scope2Cnt  = 0; $scope3Cnt  = 0;
while ($row = sqlsrv_fetch_array($resCO2e, SQLSRV_FETCH_ASSOC)) {
    if ($row['Scope'] === 'Scope1') { $scope1CO2e = (float)$row['TotalCO2e']; $scope1Cnt = (int)$row['RecordCount']; }
    if ($row['Scope'] === 'Scope2') { $scope2CO2e = (float)$row['TotalCO2e']; $scope2Cnt = (int)$row['RecordCount']; }
    if ($row['Scope'] === 'Scope3') { $scope3CO2e = (float)$row['TotalCO2e']; $scope3Cnt = (int)$row['RecordCount']; }
}
$totalCO2e = $scope1CO2e + $scope2CO2e + $scope3CO2e;
$scope1Pct = $totalCO2e > 0 ? round($scope1CO2e / $totalCO2e * 100, 1) : 0;
$scope2Pct = $totalCO2e > 0 ? round($scope2CO2e / $totalCO2e * 100, 1) : 0;
$scope3Pct = $totalCO2e > 0 ? round($scope3CO2e / $totalCO2e * 100, 1) : 0;

/* Workflow Status */
$resStatus = sqlsrv_query($conn, "SELECT Status, COUNT(*) AS Cnt FROM CFP_MonthlyHeader WHERE YearMonth = ? GROUP BY Status", array($yearMonth));
$statusCount = array(1=>0,2=>0,3=>0,4=>0,5=>0);
while ($row = sqlsrv_fetch_array($resStatus, SQLSRV_FETCH_ASSOC)) {
    $statusCount[(int)$row['Status']] = (int)$row['Cnt'];
}

/* Recent 10 */
$sqlRecent = "SELECT TOP 10 a.ActivityID, a.Scope, a.Category, a.ActivityName,
                    a.Quantity, a.CO2e, h.Status, s.SiteName, a.CreatedDate
              FROM CFP_ActivityData a
              INNER JOIN CFP_MonthlyHeader h ON a.HeaderID = h.HeaderID
              LEFT  JOIN CFP_Site s ON a.SiteID = s.SiteID
              WHERE h.YearMonth = ? AND a.IsActive = 1 $scopeWhere
              ORDER BY a.CreatedDate DESC";
$resRecent  = sqlsrv_query($conn, $sqlRecent, array($yearMonth));
$recentRows = array();
while ($row = sqlsrv_fetch_array($resRecent, SQLSRV_FETCH_ASSOC)) {
    $recentRows[] = $row;
}

sqlsrv_close($conn);

/* Helpers */
function getStatusBadge($statusID) {
    $map = array(
        1 => array('Draft',     'badge-draft'),
        2 => array('Submitted', 'badge-submitted'),
        3 => array('Reviewed',  'badge-reviewed'),
        4 => array('Approved',  'badge-approved'),
        5 => array('Closed',    'badge-closed'),
    );
    $s = $map[(int)$statusID] ?? array('Unknown','bg-secondary');
    return '<span class="badge ' . $s[1] . '">' . $s[0] . '</span>';
}
function getScopeBadge($scope) {
    $map = array(
        'Scope1' => array('Scope 1','badge-scope1'),
        'Scope2' => array('Scope 2','badge-scope2'),
        'Scope3' => array('Scope 3','badge-scope3'),
    );
    $s = $map[$scope] ?? array($scope,'bg-secondary');
    return '<span class="badge ' . $s[1] . '">' . $s[0] . '</span>';
}

$thMonthFull = array(
    1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',
    5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',
    9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'
);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — ระบบบริหารจัดการคาร์บอนองค์กร</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">
    <style>
    /* ── Wave Topbar overrides ────────────────── */
    body { font-family: 'Prompt', sans-serif; background: #EEF6F8; }

    /* Topbar: gradient wave แทน solid */
    .cfp-topbar {
    /* พื้นหลังสีขาวโปร่งใส (Opacity) ผสมเอฟเฟกต์กระจกฝ้าแฝงโทน Navy */
    background: rgba(255, 255, 255, 0.45) !important;
    backdrop-filter: blur(10px) !important;
    -webkit-backdrop-filter: blur(10px) !important;
    
    /* เส้นขอบล่างบางๆ เพิ่มมิติแบบกระจก */
    border-bottom: 1px solid rgba(255, 255, 255, 0.4) !important;
    box-shadow: 0 4px 20px rgba(15, 32, 39, 0.08) !important;
    
    position: relative;
    overflow: hidden;
}

/* ปรับสีข้อความ Title และ Icon บน Topbar ให้เป็นโทน Navy เข้ม เพื่อให้เด่นและอ่านง่ายบนพื้นหลังกระจก */
.cfp-topbar .page-title {
    color: #0F2027 !important; /* สีกรมท่าเข้ม */
    font-weight: 600 !important;
}

.cfp-topbar .page-title .bi {
    color: #1F4068 !important; /* สีไอคอนโทน Navy แมตช์กับธีม */
}

/* หากมีข้อความแสดงชื่อผู้ใช้ หรือข้อมูลฝั่งขวาของ Topbar ให้ปรับเป็นโทน Navy ด้วย */
.cfp-topbar .user-profile-text, 
.cfp-topbar .text-muted {
    color: #2C5364 !important;
}


    /* breadcrumb bar */
    .dash-breadcrumb {
        background: #fff;
        border-bottom: 1px solid #D8EEF2;
        padding: 6px 1.5rem;
        font-size: 0.76rem;
        color: #7AAAB8;
        display: flex; align-items: center; gap: 6px;
        box-shadow: 0 1px 4px rgba(42,100,130,.05);
    }
    .dash-breadcrumb strong { color: #1A3A44; }
    .dash-breadcrumb .bi { font-size: 0.78rem; }

    /* Page background */
    .cfp-content { background: #EEF6F8; }

    /* ── Scope KPI Cards — Wave style ─────────────── */
    .scope-kpi-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
        margin-bottom: 1.25rem;
    }
    .scope-kpi {
        background: #fff;
        border-radius: 14px;
        padding: 16px 16px;
        border: 1px solid #D8EEF2;
        box-shadow: 0 2px 10px rgba(42,100,130,.07);
        display: flex;
        align-items: flex-start;
        gap: 13px;
        position: relative;
        overflow: hidden;
        transition: box-shadow .18s;
    }
    .scope-kpi:hover {
        box-shadow: 0 4px 18px rgba(42,100,130,.12);
    }
    /* top line */
    .scope-kpi::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 3px; border-radius: 14px 14px 0 0;
    }
    .scope-kpi.s1::before { background: linear-gradient(90deg,#A8D8C0,#5CC8D8); }
    .scope-kpi.s2::before { background: linear-gradient(90deg,#5CC8D8,#7ACCD8); }
    .scope-kpi.s3::before { background: linear-gradient(90deg,#7AC8D8,#A8D4E8); }
    .scope-kpi.stot::before { background: linear-gradient(90deg,#A8D8C0,#2AABB8,#A8D4E8); }

    /* icon วงกลมเหมือนรูป ref */
    .scope-kpi-icon {
        width: 42px; height: 42px;
        border-radius: 50%;
        flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.05rem; color: #fff;
        margin-top: 2px;
    }
    .scope-kpi.s1 .scope-kpi-icon { background: linear-gradient(135deg,#5CC8A0,#2AABB8); }
    .scope-kpi.s2 .scope-kpi-icon { background: linear-gradient(135deg,#2AABB8,#5CC8D8); }
    .scope-kpi.s3 .scope-kpi-icon { background: linear-gradient(135deg,#7AC8D8,#A8D4E8); color: #1A5060; }
    .scope-kpi.stot .scope-kpi-icon { background: linear-gradient(135deg,#1A6878,#2AABB8); }

    .scope-kpi-body { flex: 1; min-width: 0; }
    .scope-kpi-label {
        font-size: 0.72rem; font-weight: 600;
        color: #1A3A44; margin-bottom: 1px;
    }
    .scope-kpi-desc {
        font-size: 0.62rem; color: #90B8C4; margin-bottom: 7px;
    }
    .scope-kpi-value {
        font-size: 1.55rem; font-weight: 700;
        color: #1A3A44; line-height: 1; letter-spacing: -.02em;
    }
    .scope-kpi-unit {
        font-size: 0.62rem; color: #90B8C4;
        font-weight: 400; margin-left: 3px;
    }
    .scope-kpi-sub {
        font-size: 0.62rem; color: #90B8C4;
        margin-top: 5px;
    }
    .scope-kpi-bar {
        height: 3px; background: #E4F4F8;
        border-radius: 3px; overflow: hidden; margin-top: 8px;
    }
    .scope-kpi-bar-fill {
        height: 100%; border-radius: 3px; transition: width .4s ease;
    }
    .scope-kpi.s1 .scope-kpi-bar-fill { background: linear-gradient(90deg,#A8D8C0,#5CC8D8); }
    .scope-kpi.s2 .scope-kpi-bar-fill { background: linear-gradient(90deg,#5CC8D8,#7ACCD8); }
    .scope-kpi.s3 .scope-kpi-bar-fill { background: linear-gradient(90deg,#7AC8D8,#A8D4E8); }
    .scope-kpi.stot .scope-kpi-bar-fill { background: linear-gradient(90deg,#A8D8C0,#2AABB8,#A8D4E8); }

    /* Empty state inside KPI card */
    .scope-kpi-empty-val {
        font-size: 1.3rem; font-weight: 700;
        color: rgba(26,58,72,.25); line-height: 1;
    }
    .scope-kpi-empty-badge {
        display: inline-block;
        font-size: 0.6rem; font-weight: 600;
        padding: 2px 8px; border-radius: 8px;
        background: #FFF8E8; color: #8A6A00;
        border: 1px solid #FFE082; margin-top: 4px;
    }

    /* ── Total summary card ────────────── */
    .scope-kpi.stot .scope-kpi-value { font-size: 1.7rem; }

    /* ── Donut Chart Card ─────────────── */
    .donut-card {
        background: #fff; border-radius: 14px;
        padding: 20px; border: 1px solid #D8EEF2;
        box-shadow: 0 2px 10px rgba(42,100,130,.07);
        height: 100%;
    }

    /* ── Workflow Status Card ─────────── */
    .wf-status-item {
        display: flex; align-items: center;
        justify-content: space-between;
        padding: 8px 0; border-bottom: 1px solid #F0F8FA;
        font-size: 0.82rem;
    }
    .wf-status-item:last-child { border-bottom: none; }

    /* ── Scope bar in donut legend ─────── */
    .scope-legend-row {
        display: flex; align-items: center;
        justify-content: space-between;
        margin-bottom: 8px;
    }
    .scope-legend-dot {
        width: 9px; height: 9px; border-radius: 50%;
        display: inline-block; margin-right: 6px; flex-shrink: 0;
    }

    /* ── Responsive ─────────────────────── */
    @media (max-width: 991px) {
        .scope-kpi-grid { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 575px) {
        .scope-kpi-grid { grid-template-columns: 1fr; }
    }
    </style>
</head>
<body>
<div class="d-flex">

    <?php include '../includes/sidebar.php'; ?>

    <div class="cfp-main w-100">
        <?php include '../includes/topbar.php'; ?>

        <!-- Breadcrumb subbar -->
        <div class="dash-breadcrumb">
            <i class="bi bi-house-fill" style="color:#C0DDE8;"></i>
            <span style="color:#C8E0E8;">/</span>
            <strong>Dashboard</strong>
            <span style="color:#C8E0E8;margin-left:auto;font-size:0.7rem;">
                <i class="bi bi-clock me-1"></i>
                ข้อมูล <?php echo $thMonthFull[$filterMonth] . ' ' . ($filterYear + 543); ?>
            </span>
        </div>

        <div class="cfp-content">

            <!-- ===== FILTER BAR ===== -->
            <div class="cfp-card mb-4">
                <form method="GET" action="" class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label mb-1" style="font-size:0.8rem;">ปี</label>
                        <select name="year" class="form-select form-select-sm" style="width:110px;">
                            <?php for ($y = (int)date('Y'); $y >= (int)date('Y')-3; $y--) { ?>
                            <option value="<?php echo $y; ?>" <?php echo ($filterYear===$y)?'selected':''; ?>>
                                <?php echo $y + 543; ?>
                            </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <label class="form-label mb-1" style="font-size:0.8rem;">เดือน</label>
                        <select name="month" class="form-select form-select-sm" style="width:145px;">
                            <?php foreach ($thMonthFull as $m => $name) { ?>
                            <option value="<?php echo $m; ?>" <?php echo ($filterMonth===$m)?'selected':''; ?>>
                                <?php echo $name; ?>
                            </option>
                            <?php } ?>
                        </select>
                    </div>
                    <?php if ((int)$_SESSION['role_id'] !== 1) { ?>
                    <div class="col-auto">
                        <label class="form-label mb-1" style="font-size:0.8rem;">หน่วยงาน</label>
                        <select name="site_id" class="form-select form-select-sm" style="width:170px;">
                            <option value="0">— ทั้งหมด —</option>
                            <?php foreach ($sites as $site) { ?>
                            <option value="<?php echo $site['SiteID']; ?>"
                                    <?php echo ($filterSite===(int)$site['SiteID'])?'selected':''; ?>>
                                <?php echo htmlspecialchars($site['SiteName']); ?>
                            </option>
                            <?php } ?>
                        </select>
                    </div>
                    <?php } ?>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm"
                                style="background:#2AABB8;color:#fff;font-family:'Prompt',sans-serif;border:none;">
                            <i class="bi bi-funnel me-1"></i>กรอง
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary btn-sm btn-sm w-25 ms-1">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </form>
            </div>

            <!-- ===== SCOPE KPI CARDS ===== -->
            <div class="scope-kpi-grid">

                <!-- Scope 1 -->
                <div class="scope-kpi s1">
                    <div class="scope-kpi-icon"><i class="bi bi-fire"></i></div>
                    <div class="scope-kpi-body">
                        <div class="scope-kpi-label">Scope 1 — Direct</div>
                        <div class="scope-kpi-desc">การเผาไหม้โดยตรง</div>
                        <?php if ($scope1CO2e > 0) { ?>
                        <div>
                            <span class="scope-kpi-value"><?php echo number_format($scope1CO2e, 2); ?></span>
                            <span class="scope-kpi-unit">tCO2e</span>
                        </div>
                        <div class="scope-kpi-sub"><?php echo $scope1Cnt; ?> รายการ · <?php echo $scope1Pct; ?>% ของรวม</div>
                        <div class="scope-kpi-bar">
                            <div class="scope-kpi-bar-fill" style="width:<?php echo $scope1Pct; ?>%"></div>
                        </div>
                        <?php } else { ?>
                        <div class="scope-kpi-empty-val">—</div>
                        <div class="scope-kpi-empty-badge">รอข้อมูล</div>
                        <?php } ?>
                    </div>
                </div>

                <!-- Scope 2 -->
                <div class="scope-kpi s2">
                    <div class="scope-kpi-icon"><i class="bi bi-lightning-charge"></i></div>
                    <div class="scope-kpi-body">
                        <div class="scope-kpi-label">Scope 2 — Energy</div>
                        <div class="scope-kpi-desc">พลังงานทางอ้อม</div>
                        <?php if ($scope2CO2e > 0) { ?>
                        <div>
                            <span class="scope-kpi-value"><?php echo number_format($scope2CO2e, 2); ?></span>
                            <span class="scope-kpi-unit">tCO2e</span>
                        </div>
                        <div class="scope-kpi-sub"><?php echo $scope2Cnt; ?> รายการ · <?php echo $scope2Pct; ?>% ของรวม</div>
                        <div class="scope-kpi-bar">
                            <div class="scope-kpi-bar-fill" style="width:<?php echo $scope2Pct; ?>%"></div>
                        </div>
                        <?php } else { ?>
                        <div class="scope-kpi-empty-val">—</div>
                        <div class="scope-kpi-empty-badge">รอข้อมูล</div>
                        <?php } ?>
                    </div>
                </div>

                <!-- Scope 3 -->
                <div class="scope-kpi s3">
                    <div class="scope-kpi-icon"><i class="bi bi-globe-asia-australia"></i></div>
                    <div class="scope-kpi-body">
                        <div class="scope-kpi-label">Scope 3 — Indirect</div>
                        <div class="scope-kpi-desc">Other Indirect (15 Cat.)</div>
                        <?php if ($scope3CO2e > 0) { ?>
                        <div>
                            <span class="scope-kpi-value"><?php echo number_format($scope3CO2e, 2); ?></span>
                            <span class="scope-kpi-unit">tCO2e</span>
                        </div>
                        <div class="scope-kpi-sub"><?php echo $scope3Cnt; ?> รายการ · <?php echo $scope3Pct; ?>% ของรวม</div>
                        <div class="scope-kpi-bar">
                            <div class="scope-kpi-bar-fill" style="width:<?php echo $scope3Pct; ?>%"></div>
                        </div>
                        <?php } else { ?>
                        <div class="scope-kpi-empty-val">—</div>
                        <div class="scope-kpi-empty-badge">รอข้อมูล</div>
                        <?php } ?>
                    </div>
                </div>

            </div><!-- /scope-kpi-grid -->

            <!-- ===== ROW 2: Total + Donut + Workflow ===== -->
            <div class="row g-3 mb-4">

                <!-- Total CO2e -->
                <div class="col-md-3">
                    <div class="scope-kpi stot h-100">
                        <div class="scope-kpi-icon"><i class="bi bi-cloud-haze2"></i></div>
                        <div class="scope-kpi-body">
                            <div class="scope-kpi-label">รวมทุก Scope</div>
                            <div class="scope-kpi-desc">GHG ทั้งหมด ปี <?php echo $filterYear+543; ?></div>
                            <?php if ($totalCO2e > 0) { ?>
                            <div>
                                <span class="scope-kpi-value"><?php echo number_format($totalCO2e, 2); ?></span>
                                <span class="scope-kpi-unit">tCO2e</span>
                            </div>
                            <div class="scope-kpi-sub"><?php echo $scope1Cnt+$scope2Cnt+$scope3Cnt; ?> รายการทั้งหมด</div>
                            <div class="scope-kpi-bar">
                                <div class="scope-kpi-bar-fill" style="width:100%"></div>
                            </div>
                            <?php } else { ?>
                            <div class="scope-kpi-empty-val">—</div>
                            <div class="scope-kpi-empty-badge">ยังไม่มีข้อมูล</div>
                            <?php } ?>
                        </div>
                    </div>
                </div>

                <!-- Donut Chart -->
                <div class="col-md-5">
                    <div class="donut-card">
                        <div class="cfp-card-header">
                            <span><i class="bi bi-pie-chart-fill me-2" style="color:#2AABB8;"></i>สัดส่วน CO2e แยก Scope</span>
                        </div>
                        <?php if ($totalCO2e > 0) { ?>
                        <div class="d-flex align-items-center gap-4 flex-wrap mt-2">
                            <!-- SVG Donut -->
                            <?php
                            $c  = 2 * M_PI * 15.9;
                            $s1L = $c * $scope1Pct / 100;
                            $s2L = $c * $scope2Pct / 100;
                            $s3L = $c * $scope3Pct / 100;
                            $s1O = 0;
                            $s2O = -$s1L;
                            $s3O = -$s1L - $s2L;
                            ?>
                            <div style="position:relative;width:110px;height:110px;flex-shrink:0;">
                                <svg viewBox="0 0 36 36" style="width:110px;height:110px;transform:rotate(-90deg);">
                                    <circle cx="18" cy="18" r="15.9" fill="none" stroke="#E4F4F8" stroke-width="3.8"/>
                                    <circle cx="18" cy="18" r="15.9" fill="none"
                                            stroke="#5CC8A0" stroke-width="3.8"
                                            stroke-dasharray="<?php echo round($s1L,2); ?> <?php echo round($c,2); ?>"
                                            stroke-dashoffset="<?php echo round($s1O,2); ?>"/>
                                    <circle cx="18" cy="18" r="15.9" fill="none"
                                            stroke="#2AABB8" stroke-width="3.8"
                                            stroke-dasharray="<?php echo round($s2L,2); ?> <?php echo round($c,2); ?>"
                                            stroke-dashoffset="<?php echo round($s2O,2); ?>"/>
                                    <circle cx="18" cy="18" r="15.9" fill="none"
                                            stroke="#A8D4E8" stroke-width="3.8"
                                            stroke-dasharray="<?php echo round($s3L,2); ?> <?php echo round($c,2); ?>"
                                            stroke-dashoffset="<?php echo round($s3O,2); ?>"/>
                                </svg>
                                <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;line-height:1.25;">
                                    <div style="font-size:0.62rem;color:#90B8C4;">รวม</div>
                                    <div style="font-size:0.82rem;font-weight:700;color:#1A3A44;"><?php echo number_format($totalCO2e, 1); ?></div>
                                    <div style="font-size:0.56rem;color:#90B8C4;">tCO2e</div>
                                </div>
                            </div>
                            <!-- Legend -->
                            <div style="flex:1;min-width:130px;">
                                <?php
                                $scopeRows = array(
                                    array('Scope 1', '#5CC8A0', $scope1CO2e, $scope1Pct),
                                    array('Scope 2', '#2AABB8', $scope2CO2e, $scope2Pct),
                                    array('Scope 3', '#A8D4E8', $scope3CO2e, $scope3Pct),
                                );
                                foreach ($scopeRows as $sr) { ?>
                                <div class="scope-legend-row mb-2">
                                    <span style="font-size:0.78rem;display:flex;align-items:center;">
                                        <span class="scope-legend-dot" style="background:<?php echo $sr[1]; ?>;"></span>
                                        <?php echo $sr[0]; ?>
                                    </span>
                                    <span style="font-size:0.76rem;font-weight:600;color:#1A3A44;">
                                        <?php echo number_format($sr[2], 2); ?>
                                        <span style="color:#90B8C4;font-weight:400;">(<?php echo $sr[3]; ?>%)</span>
                                    </span>
                                </div>
                                <div class="progress mb-2" style="height:4px;border-radius:4px;background:#E4F4F8;">
                                    <div class="progress-bar" style="background:<?php echo $sr[1]; ?>;width:<?php echo $sr[3]; ?>%;border-radius:4px;"></div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                        <?php } else { ?>
                        <div class="text-center py-4" style="color:#90B8C4;">
                            <i class="bi bi-pie-chart" style="font-size:2.5rem;opacity:.3;"></i>
                            <div class="mt-2" style="font-size:0.85rem;">ยังไม่มีข้อมูลในเดือนที่เลือก</div>
                            <?php if (in_array((int)$_SESSION['role_id'], array(1,4))) { ?>
                            <a href="/carbonfootprint/data_entry/scope1.php"
                               class="btn btn-sm mt-2"
                               style="background:#2AABB8;color:#fff;font-family:'Prompt',sans-serif;border:none;">
                                <i class="bi bi-plus-circle me-1"></i>เริ่มบันทึกข้อมูล
                            </a>
                            <?php } ?>
                        </div>
                        <?php } ?>
                    </div>
                </div>

                <!-- Workflow Status -->
                <div class="col-md-4">
                    <div class="cfp-card h-100">
                        <div class="cfp-card-header">
                            <span><i class="bi bi-check2-circle me-2" style="color:#2AABB8;"></i>สถานะ Workflow</span>
                        </div>
                        <?php
                        $wfLabels = array(
                            1 => array('Draft',     'badge-draft'),
                            2 => array('Submitted', 'badge-submitted'),
                            3 => array('Reviewed',  'badge-reviewed'),
                            4 => array('Approved',  'badge-approved'),
                            5 => array('Closed',    'badge-closed'),
                        );
                        $totalWF = array_sum($statusCount);
                        if ($totalWF > 0) {
                            foreach ($wfLabels as $sid => $wf) {
                                if ($statusCount[$sid] > 0) { ?>
                        <div class="wf-status-item">
                            <span><i class="bi bi-circle-fill me-2" style="font-size:.45rem;color:#C0DDE8;"></i>
                                  <?php echo $wf[0]; ?></span>
                            <span class="badge <?php echo $wf[1]; ?>"><?php echo $statusCount[$sid]; ?> รายการ</span>
                        </div>
                        <?php }
                            }
                        } else { ?>
                        <div class="text-center py-3" style="color:#90B8C4;">
                            <i class="bi bi-check2-all" style="font-size:1.8rem;opacity:.3;"></i>
                            <div class="mt-1" style="font-size:0.82rem;">ไม่มีรายการในเดือนนี้</div>
                        </div>
                        <?php } ?>
                    </div>
                </div>

            </div><!-- /row 2 -->

            <!-- ===== RECENT ACTIVITY TABLE ===== -->
            <div class="cfp-card">
                <div class="cfp-card-header">
                    <span><i class="bi bi-clock-history me-2"></i>รายการข้อมูลล่าสุด</span>
                    <?php if (in_array((int)$_SESSION['role_id'], array(1,4))) { ?>
                    <a href="/carbonfootprint/data_entry/scope1.php"
                       class="btn btn-sm"
                       style="background:#2AABB8;color:#fff;font-family:'Prompt',sans-serif;border:none;">
                        <i class="bi bi-plus-circle me-1"></i>บันทึกข้อมูลใหม่
                    </a>
                    <?php } ?>
                </div>
                <?php if (!empty($recentRows)) { ?>
                <div class="table-responsive">
                    <table id="tblRecent" class="table table-bordered table-hover align-middle mb-0" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:80px;">Scope</th>
                                <th>หมวดกิจกรรม</th>
                                <th>ชื่อกิจกรรม</th>
                                <th>หน่วยงาน</th>
                                <th class="text-end">CO2e (tCO2e)</th>
                                <th class="text-center" style="width:100px;">สถานะ</th>
                                <th class="text-center" style="width:90px;">วันที่</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRows as $row) { ?>
                            <tr>
                                <td class="text-center"><?php echo getScopeBadge($row['Scope']); ?></td>
                                <td style="font-size:0.82rem;"><?php echo htmlspecialchars($row['Category'] ?? '—'); ?></td>
                                <td style="font-size:0.82rem;"><?php echo htmlspecialchars($row['ActivityName'] ?? '—'); ?></td>
                                <td style="font-size:0.82rem;"><?php echo htmlspecialchars($row['SiteName'] ?? '—'); ?></td>
                                <td class="text-end fw-500" style="color:#1A3A44;"><?php echo number_format((float)$row['CO2e'], 4); ?></td>
                                <td class="text-center"><?php echo getStatusBadge($row['Status']); ?></td>
                                <td class="text-center" style="font-size:0.78rem;color:#90B8C4;">
                                    <?php
                                    $d = $row['CreatedDate'];
                                    echo ($d instanceof DateTime) ? $d->format('d/m/Y') : '—';
                                    ?>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php } else { ?>
                <div class="text-center py-4" style="color:#90B8C4;">
                    <i class="bi bi-table" style="font-size:2rem;opacity:.3;"></i>
                    <div class="mt-2" style="font-size:0.85rem;">ไม่มีรายการข้อมูลในเดือนที่เลือก</div>
                </div>
                <?php } ?>
            </div>

        </div><!-- /cfp-content -->
    </div><!-- /cfp-main -->
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    <?php if (!empty($recentRows)) { ?>
    $('#tblRecent').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order: [[6, 'desc']],
        pageLength: 10,
        dom: '<"row align-items-center mb-2"<"col-md-6"l><"col-md-6 text-end"f>>rtip',
        columnDefs: [{ orderable: false, targets: [5] }]
    });
    <?php } ?>
});
</script>

</body>
</html>
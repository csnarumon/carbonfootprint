<?php
/* ==============================================
   report/summary.php — รายงานสรุปรายเดือน
   GHG Management System
   สรุป CO2e แยก Scope 1/2/3 (Scope 2 แยก Location/Market-based)
   พร้อมเทียบปีก่อนหน้า (YoY) — นับเฉพาะ Header Status=2 (Approved)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(1, 2, 3, 4, 5, 6));
$conn = getConnection();

/* ── Filter: เดือน/ปี, Site ── */
$filterMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$filterYear  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$filterSite  = isset($_GET['site'])  ? (int)$_GET['site']  : 0; /* 0 = ทุก Site */
if ($filterMonth < 1 || $filterMonth > 12) { $filterMonth = (int)date('n'); }

$ymCur  = sprintf('%04d%02d', $filterYear, $filterMonth);
$ymPrev = sprintf('%04d%02d', $filterYear - 1, $filterMonth);

/* ── รายชื่อ Site (สำหรับ dropdown filter) ── */
$sites = array();
$resSite = sqlsrv_query($conn, "SELECT SiteID, SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
if ($resSite) {
    while ($r = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) { $sites[] = $r; }
}

/* ── ดึงผลรวม CO2e (kgCO2e) แยก Scope + CategoryNo (สำหรับ Scope2 Location/Market) ──
   นับเฉพาะ Header Status=2 (Approved) ── */
function fetchCO2Sum($conn, $ym, $siteID) {
    $sql = "SELECT h.Scope, a.CategoryNo, SUM(a.CO2e) AS CO2eSum
            FROM CFP_MonthlyHeader h
            JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
            WHERE h.Status = 2 AND h.YearMonth = ?";
    $params = array($ym);
    if ($siteID > 0) { $sql .= " AND h.SiteID = ?"; $params[] = $siteID; }
    $sql .= " GROUP BY h.Scope, a.CategoryNo";

    $out = array('Scope1' => 0.0, 'Scope2_Loc' => 0.0, 'Scope2_Mkt' => 0.0, 'Scope3' => 0.0);
    $res = sqlsrv_query($conn, $sql, $params);
    if ($res) {
        while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $v = (float)$r['CO2eSum'];
            if ($r['Scope'] === 'Scope1') {
                $out['Scope1'] += $v;
            } elseif ($r['Scope'] === 'Scope2') {
                if ((int)$r['CategoryNo'] === 2) { $out['Scope2_Mkt'] += $v; }
                else                              { $out['Scope2_Loc'] += $v; } /* CategoryNo=1 หรือ NULL ถือเป็น Location-based (Grid Mix) */
            } elseif ($r['Scope'] === 'Scope3') {
                $out['Scope3'] += $v;
            }
        }
    }
    return $out;
}

$cur  = fetchCO2Sum($conn, $ymCur, $filterSite);
$prev = fetchCO2Sum($conn, $ymPrev, $filterSite);

/* kgCO2e -> tCO2e */
foreach ($cur as $k => $v)  { $cur[$k]  = $v / 1000; }
foreach ($prev as $k => $v) { $prev[$k] = $v / 1000; }

$cur['Scope2']  = $cur['Scope2_Loc'] + $cur['Scope2_Mkt'];
$prev['Scope2'] = $prev['Scope2_Loc'] + $prev['Scope2_Mkt'];
$cur['Total']   = $cur['Scope1'] + $cur['Scope2'] + $cur['Scope3'];
$prev['Total']  = $prev['Scope1'] + $prev['Scope2'] + $prev['Scope3'];

function yoyPct($curV, $prevV) {
    if ($prevV <= 0) { return null; } /* ไม่มีฐานเทียบ */
    return (($curV - $prevV) / $prevV) * 100;
}

/* ── กราฟแท่ง 6 เดือนล่าสุด (นับถอยจากเดือนที่เลือก) ── */
$trendLabels = array();
$trendS1 = array(); $trendS2 = array(); $trendS3 = array();
$thMonths = array('ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.');
for ($i = 5; $i >= 0; $i--) {
    $ts = mktime(0, 0, 0, $filterMonth - $i, 1, $filterYear);
    $ym = date('Ym', $ts);
    $mo = (int)date('n', $ts);
    $sum = fetchCO2Sum($conn, $ym, $filterSite);
    $trendLabels[] = $thMonths[$mo - 1];
    $trendS1[] = round($sum['Scope1'] / 1000, 2);
    $trendS2[] = round(($sum['Scope2_Loc'] + $sum['Scope2_Mkt']) / 1000, 2);
    $trendS3[] = round($sum['Scope3'] / 1000, 2);
}

/* ── Breakdown ราย Site (เฉพาะตอนเลือก "ทุก Site") ── */
$siteBreakdown = array();
if ($filterSite === 0) {
    $sql = "SELECT h.SiteID, s.SiteName, h.Scope, a.CategoryNo, SUM(a.CO2e) AS CO2eSum
            FROM CFP_MonthlyHeader h
            JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
            JOIN CFP_Site s ON s.SiteID = h.SiteID
            WHERE h.Status = 2 AND h.YearMonth = ?
            GROUP BY h.SiteID, s.SiteName, h.Scope, a.CategoryNo
            ORDER BY s.SiteName";
    $res = sqlsrv_query($conn, $sql, array($ymCur));
    if ($res) {
        while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $sid = (int)$r['SiteID'];
            if (!isset($siteBreakdown[$sid])) {
                $siteBreakdown[$sid] = array('name' => $r['SiteName'], 'Scope1' => 0.0, 'Scope2' => 0.0, 'Scope3' => 0.0);
            }
            $v = (float)$r['CO2eSum'] / 1000;
            if ($r['Scope'] === 'Scope1') { $siteBreakdown[$sid]['Scope1'] += $v; }
            elseif ($r['Scope'] === 'Scope2') { $siteBreakdown[$sid]['Scope2'] += $v; }
            elseif ($r['Scope'] === 'Scope3') { $siteBreakdown[$sid]['Scope3'] += $v; }
        }
    }
    foreach ($siteBreakdown as $sid => &$row) {
        $row['Total'] = $row['Scope1'] + $row['Scope2'] + $row['Scope3'];
    }
    unset($row);
}

/* ── page meta ── */
$pageTitle = 'รายงานสรุปรายเดือน';
$pageIcon  = 'file-earmark-bar-graph';
$monthNames = array(1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',
                     7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม');
$thYearCur  = $filterYear + 543;
$thYearPrev = $filterYear - 1 + 543;
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>รายงานสรุปรายเดือน — GHG Management System</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">
  <style>
    .cfp-main { margin-left: var(--cfp-sidebar-w); min-height: 100vh; }
    @media (max-width: 991px) { .cfp-main { margin-left: 0; } }
    .rpt-content { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
    @media (max-width: 575px) { .rpt-content { padding: 12px; } }

    /* Filter bar */
    .rpt-filter {
      background: #fff; border: 1px solid var(--cfp-border); border-radius: 12px;
      padding: 12px 16px; display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap;
    }
    .rpt-filter .f-group { display: flex; flex-direction: column; gap: 4px; }
    .rpt-filter label { font-size: 0.72rem; color: var(--cfp-text-muted); font-weight: 500; }
    .rpt-filter select { font-family: 'Prompt', sans-serif; font-size: 0.85rem; border: 1px solid var(--cfp-border);
      border-radius: 8px; padding: 7px 12px; min-width: 140px; }
    .rpt-filter .f-spacer { flex: 1; }
    .yoy-toggle { display: flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 500; color: var(--cfp-text); }
    .btn-export {
      font-family: 'Prompt', sans-serif; font-size: 0.84rem; font-weight: 500; color: #fff;
      background: linear-gradient(135deg, #54C05C, #3D8B40); border: none; border-radius: 10px;
      padding: 9px 20px; display: inline-flex; align-items: center; gap: 8px;
      box-shadow: 0 4px 14px -4px rgba(76,175,80,.5); transition: transform .15s;
    }
    .btn-export:hover { transform: translateY(-1px); color:#fff; }

    /* KPI */
    .cfp-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
    @media (max-width: 991px) { .cfp-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 575px)  { .cfp-kpi-grid { grid-template-columns: 1fr; } }
    .kpi-card { background: #fff; border: 1px solid var(--cfp-border); border-radius: 14px; padding: 14px 16px; position: relative; overflow: hidden; }
    .kpi-card-label { font-size: 0.72rem; font-weight: 500; color: var(--cfp-text-muted); margin-bottom: 4px; }
    .kpi-card-value { font-size: 1.6rem; font-weight: 700; color: var(--cfp-text); line-height: 1.1; }
    .kpi-card-unit { font-size: 0.68rem; color: var(--cfp-text-muted); font-weight: 400; margin-top: 1px; }
    .kpi-card-trend { font-size: 0.72rem; margin-top: 6px; display: flex; align-items: center; gap: 3px; font-weight: 500; }
    .trend-down { color: #2DB887; } .trend-up { color: #E05050; } .trend-ok { color: var(--cfp-primary); }
    .kpi-icon { position: absolute; top: 12px; right: 14px; width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    .kpi-icon-s1  { background: #E4F7F9; color: #2AABB8; }
    .kpi-icon-s2  { background: #F3EEFF; color: #7C3AED; }
    .kpi-icon-s3  { background: #FFF3E0; color: #F59E0B; }
    .kpi-icon-tot { background: #E8F5E9; color: #43A047; }

    /* Chart cards */
    .dash-card { background: #fff; border: 1px solid var(--cfp-border); border-radius: 14px; padding: 16px; }
    .dash-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
    .dash-card-title { font-size: 0.85rem; font-weight: 600; color: var(--cfp-text); display: flex; align-items: center; gap: 7px; }
    .dash-card-title i { color: var(--cfp-primary); }
    .dash-card-badge { font-size: 0.7rem; background: var(--cfp-hover); color: var(--cfp-primary); padding: 2px 8px; border-radius: 10px; border: 1px solid var(--cfp-border); }
    .chart-legend { display: flex; gap: 12px; margin-top: 8px; flex-wrap: wrap; }
    .legend-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 4px; }
    .legend-item { font-size: 0.72rem; color: var(--cfp-text-mid); display: flex; align-items: center; }

    /* Table */
    .rpt-table-wrap { overflow-x: auto; }
    table.rpt-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 560px; }
    table.rpt-table thead th {
      background: var(--cfp-hover); padding: 10px 12px; text-align: right; font-weight: 600;
      color: var(--cfp-text-muted); font-size: 0.7rem; letter-spacing: 0.02em; text-transform: uppercase;
      border-bottom: 1px solid var(--cfp-border);
    }
    table.rpt-table thead th:first-child { text-align: left; }
    table.rpt-table tbody td { padding: 11px 12px; text-align: right; border-bottom: 1px solid var(--cfp-border); }
    table.rpt-table tbody td:first-child { text-align: left; }
    table.rpt-table tbody tr:last-child td { border-bottom: none; }
    table.rpt-table tbody tr.main-row { background: #FAFCFD; }
    table.rpt-table tbody tr.main-row td:first-child { font-weight: 600; }
    table.rpt-table tbody tr.subrow td:first-child { padding-left: 32px; color: var(--cfp-text-muted); font-size: 0.81rem; font-weight: 400; }
    table.rpt-table tbody tr.total td { font-weight: 700; background: #E4F7F9; border-top: 2px solid var(--cfp-primary); }
    table.rpt-table tbody tr.total td:first-child { color: var(--cfp-primary); }
    .row-dot { display: inline-block; width: 8px; height: 8px; border-radius: 2px; margin-right: 8px; vertical-align: 1px; }
    .chg { font-size: 0.78rem; font-weight: 600; }
    .chg.up { color: #E05050; } .chg.down { color: #2DB887; } .chg.flat { color: var(--cfp-text-muted); }
    .tag { font-size: 0.66rem; padding: 2px 9px; border-radius: 20px; font-weight: 600; margin-left: 8px; }
    .tag.loc { background: #F3EEFF; color: #7C3AED; } .tag.mkt { background: #FFF3E0; color: #B5750E; }
  </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<div class="cfp-main">

  <?php include '../includes/topbar.php'; ?>

  <div class="rpt-content">

    <!-- Filter bar -->
    <form class="rpt-filter" method="get" id="filterForm">
      <div class="f-group">
        <label>เดือน</label>
        <select name="month" onchange="document.getElementById('filterForm').submit()">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?php echo $m; ?>" <?php echo $m === $filterMonth ? 'selected' : ''; ?>><?php echo $monthNames[$m]; ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="f-group">
        <label>ปี</label>
        <select name="year" onchange="document.getElementById('filterForm').submit()">
          <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 4; $y--): ?>
            <option value="<?php echo $y; ?>" <?php echo $y === $filterYear ? 'selected' : ''; ?>><?php echo $y + 543; ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="f-group">
        <label>Site</label>
        <select name="site" onchange="document.getElementById('filterForm').submit()">
          <option value="0">ทุก Site</option>
          <?php foreach ($sites as $s): ?>
            <option value="<?php echo (int)$s['SiteID']; ?>" <?php echo $filterSite === (int)$s['SiteID'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($s['SiteName']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="f-spacer"></div>
      <div class="yoy-toggle"><i class="bi bi-calendar2-range"></i> เทียบกับ <?php echo $monthNames[$filterMonth]; ?> <?php echo $thYearPrev; ?></div>
      <a class="btn-export"
         href="summary_export.php?month=<?php echo $filterMonth; ?>&year=<?php echo $filterYear; ?>&site=<?php echo $filterSite; ?>">
        <i class="bi bi-file-earmark-excel"></i> Export Excel
      </a>
    </form>

    <!-- KPI Cards -->
    <div class="cfp-kpi-grid">
      <div class="kpi-card">
        <div class="kpi-icon kpi-icon-s1"><i class="bi bi-fire"></i></div>
        <div class="kpi-card-label">Scope 1</div>
        <div class="kpi-card-value"><?php echo number_format($cur['Scope1'], 1); ?></div>
        <div class="kpi-card-unit">tCO<sub>2</sub>e</div>
        <?php $t = yoyPct($cur['Scope1'], $prev['Scope1']); ?>
        <div class="kpi-card-trend <?php echo $t === null ? 'trend-ok' : ($t < 0 ? 'trend-down' : 'trend-up'); ?>">
          <i class="bi bi-<?php echo $t === null ? 'dash-lg' : ($t < 0 ? 'arrow-down-short' : 'arrow-up-short'); ?>"></i>
          <?php echo $t === null ? 'ไม่มีข้อมูลปีก่อนเทียบ' : number_format(abs($t), 1) . '% จากปีก่อน'; ?>
        </div>
      </div>

      <div class="kpi-card">
        <div class="kpi-icon kpi-icon-s2"><i class="bi bi-lightning-charge-fill"></i></div>
        <div class="kpi-card-label">Scope 2</div>
        <div class="kpi-card-value"><?php echo number_format($cur['Scope2'], 1); ?></div>
        <div class="kpi-card-unit">tCO<sub>2</sub>e</div>
        <?php $t = yoyPct($cur['Scope2'], $prev['Scope2']); ?>
        <div class="kpi-card-trend <?php echo $t === null ? 'trend-ok' : ($t < 0 ? 'trend-down' : 'trend-up'); ?>">
          <i class="bi bi-<?php echo $t === null ? 'dash-lg' : ($t < 0 ? 'arrow-down-short' : 'arrow-up-short'); ?>"></i>
          <?php echo $t === null ? 'ไม่มีข้อมูลปีก่อนเทียบ' : number_format(abs($t), 1) . '% จากปีก่อน'; ?>
        </div>
      </div>

      <div class="kpi-card">
        <div class="kpi-icon kpi-icon-s3"><i class="bi bi-globe-asia-australia"></i></div>
        <div class="kpi-card-label">Scope 3</div>
        <div class="kpi-card-value"><?php echo number_format($cur['Scope3'], 1); ?></div>
        <div class="kpi-card-unit">tCO<sub>2</sub>e</div>
        <?php $t = yoyPct($cur['Scope3'], $prev['Scope3']); ?>
        <div class="kpi-card-trend <?php echo $t === null ? 'trend-ok' : ($t < 0 ? 'trend-down' : 'trend-up'); ?>">
          <i class="bi bi-<?php echo $t === null ? 'dash-lg' : ($t < 0 ? 'arrow-down-short' : 'arrow-up-short'); ?>"></i>
          <?php echo $t === null ? 'ไม่มีข้อมูลปีก่อนเทียบ' : number_format(abs($t), 1) . '% จากปีก่อน'; ?>
        </div>
      </div>

      <div class="kpi-card">
        <div class="kpi-icon kpi-icon-tot"><i class="bi bi-graph-up-arrow"></i></div>
        <div class="kpi-card-label">รวมทั้งหมด</div>
        <div class="kpi-card-value"><?php echo number_format($cur['Total'], 1); ?></div>
        <div class="kpi-card-unit">tCO<sub>2</sub>e</div>
        <?php $t = yoyPct($cur['Total'], $prev['Total']); ?>
        <div class="kpi-card-trend <?php echo $t === null ? 'trend-ok' : ($t < 0 ? 'trend-down' : 'trend-up'); ?>">
          <i class="bi bi-<?php echo $t === null ? 'dash-lg' : ($t < 0 ? 'arrow-down-short' : 'arrow-up-short'); ?>"></i>
          <?php echo $t === null ? 'ไม่มีข้อมูลปีก่อนเทียบ' : number_format(abs($t), 1) . '% จากปีก่อน'; ?>
        </div>
      </div>
    </div>

    <!-- Trend chart (บริบทประกอบตาราง — ดู Dashboard สำหรับสัดส่วน/ภาพรวมทั้งปี) -->
    <div class="dash-card">
      <div class="dash-card-head">
        <div class="dash-card-title"><i class="bi bi-bar-chart-fill"></i> แนวโน้ม 6 เดือนล่าสุด</div>
        <span class="dash-card-badge">tCO₂e</span>
      </div>
      <canvas id="chartTrend" height="70"></canvas>
      <div class="chart-legend">
        <div class="legend-item"><span class="legend-dot" style="background:#2AABB8;"></span>Scope 1</div>
        <div class="legend-item"><span class="legend-dot" style="background:#7C3AED;"></span>Scope 2</div>
        <div class="legend-item"><span class="legend-dot" style="background:#F59E0B;"></span>Scope 3</div>
      </div>
    </div>

    <!-- Summary table -->
    <div class="dash-card">
      <div class="dash-card-head">
        <div class="dash-card-title"><i class="bi bi-table"></i> ตารางสรุปเทียบปี</div>
        <span class="dash-card-badge"><?php echo $monthNames[$filterMonth] . ' ' . $thYearCur . ' เทียบ ' . $monthNames[$filterMonth] . ' ' . $thYearPrev; ?></span>
      </div>
      <div class="rpt-table-wrap">
        <table class="rpt-table">
          <thead>
            <tr>
              <th>รายการ</th>
              <th><?php echo $monthNames[$filterMonth] . ' ' . $thYearCur; ?></th>
              <th><?php echo $monthNames[$filterMonth] . ' ' . $thYearPrev; ?></th>
              <th>เปลี่ยนแปลง</th>
            </tr>
          </thead>
          <tbody>
            <?php
            function renderChg($t) {
                if ($t === null) { return '<span class="chg flat">—</span>'; }
                $cls = $t < 0 ? 'down' : 'up';
                $arrow = $t < 0 ? '▼' : '▲';
                return '<span class="chg ' . $cls . '">' . $arrow . ' ' . number_format(abs($t), 1) . '%</span>';
            }
            ?>
            <tr class="main-row">
              <td><span class="row-dot" style="background:#2AABB8;"></span>Scope 1 — Direct Emissions</td>
              <td><?php echo number_format($cur['Scope1'], 1); ?></td>
              <td><?php echo number_format($prev['Scope1'], 1); ?></td>
              <td><?php echo renderChg(yoyPct($cur['Scope1'], $prev['Scope1'])); ?></td>
            </tr>
            <tr class="main-row">
              <td><span class="row-dot" style="background:#7C3AED;"></span>Scope 2 — Energy Indirect</td>
              <td><?php echo number_format($cur['Scope2'], 1); ?></td>
              <td><?php echo number_format($prev['Scope2'], 1); ?></td>
              <td><?php echo renderChg(yoyPct($cur['Scope2'], $prev['Scope2'])); ?></td>
            </tr>
            <tr class="subrow">
              <td>Location-based <span class="tag loc">Grid Mix</span></td>
              <td><?php echo number_format($cur['Scope2_Loc'], 1); ?></td>
              <td><?php echo number_format($prev['Scope2_Loc'], 1); ?></td>
              <td><?php echo renderChg(yoyPct($cur['Scope2_Loc'], $prev['Scope2_Loc'])); ?></td>
            </tr>
            <tr class="subrow">
              <td>Market-based <span class="tag mkt">Solar PV / REC</span></td>
              <td><?php echo number_format($cur['Scope2_Mkt'], 1); ?></td>
              <td><?php echo number_format($prev['Scope2_Mkt'], 1); ?></td>
              <td><?php echo renderChg(yoyPct($cur['Scope2_Mkt'], $prev['Scope2_Mkt'])); ?></td>
            </tr>
            <tr class="main-row">
              <td><span class="row-dot" style="background:#F59E0B;"></span>Scope 3 — Other Indirect</td>
              <td><?php echo number_format($cur['Scope3'], 1); ?></td>
              <td><?php echo number_format($prev['Scope3'], 1); ?></td>
              <td><?php echo renderChg(yoyPct($cur['Scope3'], $prev['Scope3'])); ?></td>
            </tr>
            <tr class="total">
              <td>รวมทั้งหมด</td>
              <td><?php echo number_format($cur['Total'], 1); ?></td>
              <td><?php echo number_format($prev['Total'], 1); ?></td>
              <td><?php echo renderChg(yoyPct($cur['Total'], $prev['Total'])); ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($filterSite === 0): ?>
    <!-- Site breakdown table -->
    <div class="dash-card">
      <div class="dash-card-head">
        <div class="dash-card-title"><i class="bi bi-geo-alt-fill"></i> แยกราย Site</div>
        <span class="dash-card-badge"><?php echo $monthNames[$filterMonth] . ' ' . $thYearCur; ?></span>
      </div>
      <div class="rpt-table-wrap">
        <table class="rpt-table">
          <thead>
            <tr><th>Site</th><th>Scope 1</th><th>Scope 2</th><th>Scope 3</th><th>รวม</th></tr>
          </thead>
          <tbody>
            <?php if (empty($siteBreakdown)): ?>
              <tr><td colspan="5" style="text-align:center;color:var(--cfp-text-muted);padding:20px;">ไม่มีข้อมูล Approved ในเดือนนี้</td></tr>
            <?php else: foreach ($siteBreakdown as $row): ?>
              <tr>
                <td><?php echo htmlspecialchars($row['name']); ?></td>
                <td><?php echo number_format($row['Scope1'], 1); ?></td>
                <td><?php echo number_format($row['Scope2'], 1); ?></td>
                <td><?php echo number_format($row['Scope3'], 1); ?></td>
                <td><b><?php echo number_format($row['Total'], 1); ?></b></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /.rpt-content -->
</div><!-- /.cfp-main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Prompt', sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#7AAAB8';

var trendLabels = <?php echo json_encode($trendLabels, JSON_UNESCAPED_UNICODE); ?>;
var trendS1 = <?php echo json_encode($trendS1); ?>;
var trendS2 = <?php echo json_encode($trendS2); ?>;
var trendS3 = <?php echo json_encode($trendS3); ?>;

new Chart(document.getElementById('chartTrend').getContext('2d'), {
    type: 'bar',
    data: {
        labels: trendLabels,
        datasets: [
            { label: 'Scope 1', data: trendS1, backgroundColor: '#2AABB8', borderRadius: 4, barPercentage: 0.75 },
            { label: 'Scope 2', data: trendS2, backgroundColor: '#7C3AED', borderRadius: 4, barPercentage: 0.75 },
            { label: 'Scope 3', data: trendS3, backgroundColor: '#F59E0B', borderRadius: 4, barPercentage: 0.75 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: function(ctx) { return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString() + ' tCO₂e'; } } }
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { family: "'Prompt', sans-serif", size: 10 } } },
            y: { grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { family: "'Prompt', sans-serif", size: 10 } } }
        }
    }
});
</script>
</body>
</html>

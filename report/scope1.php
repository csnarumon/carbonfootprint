<?php
/* ==============================================
   report/scope1.php — รายงาน Scope 1 (Direct Emissions)
   GHG Management System
   แยกตาม Category: Stationary / Mobile (On-road, Off-road) / Fugitive / Process
   นับเฉพาะ Header Status=2 (Approved) ── */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(1, 2, 3, 4, 5, 6));
$conn = getConnection();

$filterMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$filterYear  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$filterSite  = isset($_GET['site'])  ? (int)$_GET['site']  : 0;
if ($filterMonth < 1 || $filterMonth > 12) { $filterMonth = (int)date('n'); }

$ymCur  = sprintf('%04d%02d', $filterYear, $filterMonth);
$ymPrev = sprintf('%04d%02d', $filterYear - 1, $filterMonth);

$sites = array();
$resSite = sqlsrv_query($conn, "SELECT SiteID, SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
if ($resSite) { while ($r = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) { $sites[] = $r; } }

$catLabels = array(
    1 => array('label' => 'Stationary Combustion',        'icon' => 'bi-fire',        'color' => '#D97706'),
    2 => array('label' => 'Mobile Combustion — On-road',   'icon' => 'bi-truck',       'color' => '#0284C7'),
    3 => array('label' => 'Mobile Combustion — Off-road',  'icon' => 'bi-cone-striped','color' => '#7C3AED'),
    4 => array('label' => 'Fugitive Emissions',            'icon' => 'bi-thermometer', 'color' => '#2AABB8'),
    5 => array('label' => 'Process Emissions',              'icon' => 'bi-gear',        'color' => '#059669'),
);

/* CategoryNo จริงในระบบมีแค่ 4 (Stationary=1, Mobile=2, Fugitive=3, Process=4)
   ต้องแยก Mobile เป็น On-road/Off-road เพิ่มด้วย ai.MobileRoadType (join ผ่าน ItemID) */
function fetchScope1Cat($conn, $ym, $siteID) {
    $sql = "SELECT a.CategoryNo, ai.MobileRoadType, SUM(a.CO2e) AS CO2eSum
            FROM CFP_MonthlyHeader h
            JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
            LEFT JOIN CFP_ActivityItem ai ON ai.ItemID = a.ItemID
            WHERE h.Status = 2 AND h.Scope = 'Scope1' AND h.YearMonth = ?";
    $params = array($ym);
    if ($siteID > 0) { $sql .= " AND h.SiteID = ?"; $params[] = $siteID; }
    $sql .= " GROUP BY a.CategoryNo, ai.MobileRoadType";

    $out = array(1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0);
    $res = sqlsrv_query($conn, $sql, $params);
    if ($res) {
        while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $v = (float)$r['CO2eSum'];
            $cat = (int)$r['CategoryNo'];
            if ($cat === 1) { $out[1] += $v; }
            elseif ($cat === 2) {
                if ($r['MobileRoadType'] === 'OffRoad') { $out[3] += $v; } else { $out[2] += $v; } /* NULL/OnRoad -> On-road */
            }
            elseif ($cat === 3) { $out[4] += $v; }
            elseif ($cat === 4) { $out[5] += $v; }
        }
    }
    return $out;
}

$cur  = fetchScope1Cat($conn, $ymCur, $filterSite);
$prev = fetchScope1Cat($conn, $ymPrev, $filterSite);
foreach ($cur as $k => $v)  { $cur[$k]  = $v / 1000; }
foreach ($prev as $k => $v) { $prev[$k] = $v / 1000; }
$curTotal  = array_sum($cur);
$prevTotal = array_sum($prev);

function yoyPct($curV, $prevV) {
    if ($prevV <= 0) { return null; }
    return (($curV - $prevV) / $prevV) * 100;
}
function renderChg($t) {
    if ($t === null) { return '<span class="chg flat">—</span>'; }
    $cls = $t < 0 ? 'down' : 'up';
    $arrow = $t < 0 ? '▼' : '▲';
    return '<span class="chg ' . $cls . '">' . $arrow . ' ' . number_format(abs($t), 1) . '%</span>';
}

/* ── กราฟแท่ง 6 เดือนล่าสุด (รวม Scope1 ทั้งหมด, ไม่แยก Category) ── */
$trendLabels = array(); $trendData = array();
$thMonths = array('ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.');
for ($i = 5; $i >= 0; $i--) {
    $ts = mktime(0, 0, 0, $filterMonth - $i, 1, $filterYear);
    $ym = date('Ym', $ts);
    $mo = (int)date('n', $ts);
    $sum = fetchScope1Cat($conn, $ym, $filterSite);
    $trendLabels[] = $thMonths[$mo - 1];
    $trendData[] = round(array_sum($sum) / 1000, 2);
}

/* ── แยกราย Site ── */
$siteBreakdown = array();
if ($filterSite === 0) {
    $sql = "SELECT h.SiteID, s.SiteName, a.CategoryNo, ai.MobileRoadType, SUM(a.CO2e) AS CO2eSum
            FROM CFP_MonthlyHeader h
            JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
            JOIN CFP_Site s ON s.SiteID = h.SiteID
            LEFT JOIN CFP_ActivityItem ai ON ai.ItemID = a.ItemID
            WHERE h.Status = 2 AND h.Scope = 'Scope1' AND h.YearMonth = ?
            GROUP BY h.SiteID, s.SiteName, a.CategoryNo, ai.MobileRoadType
            ORDER BY s.SiteName";
    $res = sqlsrv_query($conn, $sql, array($ymCur));
    if ($res) {
        while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $sid = (int)$r['SiteID'];
            if (!isset($siteBreakdown[$sid])) {
                $siteBreakdown[$sid] = array('name' => $r['SiteName'], 'Stationary' => 0.0, 'OnRoad' => 0.0, 'OffRoad' => 0.0, 'Fugitive' => 0.0, 'Process' => 0.0);
            }
            $v = (float)$r['CO2eSum'] / 1000;
            $cat = (int)$r['CategoryNo'];
            if ($cat === 1) { $siteBreakdown[$sid]['Stationary'] += $v; }
            elseif ($cat === 2) {
                if ($r['MobileRoadType'] === 'OffRoad') { $siteBreakdown[$sid]['OffRoad'] += $v; } else { $siteBreakdown[$sid]['OnRoad'] += $v; }
            }
            elseif ($cat === 3) { $siteBreakdown[$sid]['Fugitive'] += $v; }
            elseif ($cat === 4) { $siteBreakdown[$sid]['Process'] += $v; }
        }
    }
    foreach ($siteBreakdown as $sid => &$row) {
        $row['Total'] = $row['Stationary'] + $row['OnRoad'] + $row['OffRoad'] + $row['Fugitive'] + $row['Process'];
    }
    unset($row);
}

$pageTitle = 'รายงาน Scope 1';
$pageIcon  = 'fire';
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
  <title>รายงาน Scope 1 — GHG Management System</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">
  <style>
    .cfp-main { margin-left: var(--cfp-sidebar-w); min-height: 100vh; }
    @media (max-width: 991px) { .cfp-main { margin-left: 0; } }
    .rpt-content { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
    @media (max-width: 575px) { .rpt-content { padding: 12px; } }

    .rpt-filter { background: #fff; border: 1px solid var(--cfp-border); border-radius: 12px; padding: 12px 16px; display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
    .rpt-filter .f-group { display: flex; flex-direction: column; gap: 4px; }
    .rpt-filter label { font-size: 0.72rem; color: var(--cfp-text-muted); font-weight: 500; }
    .rpt-filter select { font-family: 'Prompt', sans-serif; font-size: 0.85rem; border: 1px solid var(--cfp-border); border-radius: 8px; padding: 7px 12px; min-width: 140px; }
    .rpt-filter .f-spacer { flex: 1; }
    .yoy-toggle { display: flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 500; color: var(--cfp-text); }
    .btn-export { font-family: 'Prompt', sans-serif; font-size: 0.84rem; font-weight: 500; color: #fff; background: linear-gradient(135deg, #54C05C, #3D8B40); border: none; border-radius: 10px; padding: 9px 20px; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 14px -4px rgba(76,175,80,.5); transition: transform .15s; }
    .btn-export:hover { transform: translateY(-1px); color:#fff; }

    .cfp-kpi-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; }
    @media (max-width: 1100px) { .cfp-kpi-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 650px)  { .cfp-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
    .kpi-card { background: #fff; border: 1px solid var(--cfp-border); border-radius: 14px; padding: 14px 16px; position: relative; overflow: hidden; }
    .kpi-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--ac,#2AABB8); }
    .kpi-card-label { font-size: 0.72rem; font-weight: 500; color: var(--cfp-text-muted); margin-bottom: 4px; display:flex; align-items:center; gap:5px; }
    .kpi-card-value { font-size: 1.4rem; font-weight: 700; color: var(--cfp-text); line-height: 1.1; }
    .kpi-card-unit { font-size: 0.66rem; color: var(--cfp-text-muted); font-weight: 400; margin-top: 1px; }
    .kpi-card-trend { font-size: 0.72rem; margin-top: 6px; display: flex; align-items: center; gap: 3px; font-weight: 500; }
    .trend-down { color: #2DB887; } .trend-up { color: #E05050; } .trend-ok { color: var(--cfp-primary); }

    .dash-card { background: #fff; border: 1px solid var(--cfp-border); border-radius: 14px; padding: 16px; }
    .dash-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap:wrap; gap:8px; }
    .dash-card-title { font-size: 0.85rem; font-weight: 600; color: var(--cfp-text); display: flex; align-items: center; gap: 7px; }
    .dash-card-title i { color: var(--cfp-primary); }
    .dash-card-badge { font-size: 0.7rem; background: var(--cfp-hover); color: var(--cfp-primary); padding: 2px 8px; border-radius: 10px; border: 1px solid var(--cfp-border); }

    .rpt-table-wrap { overflow-x: auto; }
    table.rpt-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 560px; }
    table.rpt-table thead th { background: var(--cfp-hover); padding: 10px 12px; text-align: right; font-weight: 600; color: var(--cfp-text-muted); font-size: 0.7rem; letter-spacing: 0.02em; text-transform: uppercase; border-bottom: 1px solid var(--cfp-border); }
    table.rpt-table thead th:first-child { text-align: left; }
    table.rpt-table tbody td { padding: 11px 12px; text-align: right; border-bottom: 1px solid var(--cfp-border); }
    table.rpt-table tbody td:first-child { text-align: left; display:flex; align-items:center; gap:9px; }
    table.rpt-table tbody tr:last-child td { border-bottom: none; }
    table.rpt-table tbody tr.total td { font-weight: 700; background: #E4F7F9; border-top: 2px solid var(--cfp-primary); }
    table.rpt-table tbody tr.total td:first-child { color: var(--cfp-primary); }
    table.rpt-table tbody tr.empty-cat td { color: var(--cfp-text-muted); font-style: italic; }
    .cat-ic { width: 26px; height: 26px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: .8rem; flex-shrink:0; }
    .chg { font-size: 0.78rem; font-weight: 600; }
    .chg.up { color: #E05050; } .chg.down { color: #2DB887; } .chg.flat { color: var(--cfp-text-muted); }
    .pill-na { font-size:.66rem; padding:2px 8px; border-radius:20px; font-weight:600; background:#F1F5F6; color:#8FA2A8; }
  </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<div class="cfp-main">
  <?php include '../includes/topbar.php'; ?>

  <div class="rpt-content">

    <form class="rpt-filter" method="get" id="filterForm">
      <div class="f-group"><label>เดือน</label>
        <select name="month" onchange="document.getElementById('filterForm').submit()">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?php echo $m; ?>" <?php echo $m === $filterMonth ? 'selected' : ''; ?>><?php echo $monthNames[$m]; ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="f-group"><label>ปี</label>
        <select name="year" onchange="document.getElementById('filterForm').submit()">
          <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 4; $y--): ?>
            <option value="<?php echo $y; ?>" <?php echo $y === $filterYear ? 'selected' : ''; ?>><?php echo $y + 543; ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="f-group"><label>Site</label>
        <select name="site" onchange="document.getElementById('filterForm').submit()">
          <option value="0">ทุก Site</option>
          <?php foreach ($sites as $s): ?>
            <option value="<?php echo (int)$s['SiteID']; ?>" <?php echo $filterSite === (int)$s['SiteID'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['SiteName']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="f-spacer"></div>
      <div class="yoy-toggle"><i class="bi bi-calendar2-range"></i> เทียบกับ <?php echo $monthNames[$filterMonth]; ?> <?php echo $thYearPrev; ?></div>
      <a class="btn-export" href="scope1_export.php?month=<?php echo $filterMonth; ?>&year=<?php echo $filterYear; ?>&site=<?php echo $filterSite; ?>">
        <i class="bi bi-file-earmark-excel"></i> Export Excel
      </a>
    </form>

    <div class="cfp-kpi-grid">
      <?php foreach ($catLabels as $catNo => $c): $t = yoyPct($cur[$catNo], $prev[$catNo]); ?>
        <div class="kpi-card" style="--ac:<?php echo $c['color']; ?>;">
          <div class="kpi-card-label"><i class="bi <?php echo $c['icon']; ?>"></i> <?php echo $c['label']; ?></div>
          <div class="kpi-card-value"><?php echo number_format($cur[$catNo], 1); ?></div>
          <div class="kpi-card-unit">tCO<sub>2</sub>e</div>
          <div class="kpi-card-trend <?php echo $t === null ? 'trend-ok' : ($t < 0 ? 'trend-down' : 'trend-up'); ?>">
            <i class="bi bi-<?php echo $t === null ? 'dash-lg' : ($t < 0 ? 'arrow-down-short' : 'arrow-up-short'); ?>"></i>
            <?php echo $t === null ? 'ไม่มีข้อมูลปีก่อน' : number_format(abs($t), 1) . '%'; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="dash-card">
      <div class="dash-card-head">
        <div class="dash-card-title"><i class="bi bi-bar-chart-fill"></i> แนวโน้ม 6 เดือนล่าสุด — Scope 1 รวม</div>
        <span class="dash-card-badge">tCO₂e</span>
      </div>
      <canvas id="chartTrend" height="70"></canvas>
    </div>

    <div class="dash-card">
      <div class="dash-card-head">
        <div class="dash-card-title"><i class="bi bi-table"></i> สรุปตาม Category</div>
        <span class="dash-card-badge"><?php echo $monthNames[$filterMonth] . ' ' . $thYearCur . ' เทียบ ' . $monthNames[$filterMonth] . ' ' . $thYearPrev; ?></span>
      </div>
      <div class="rpt-table-wrap">
        <table class="rpt-table">
          <thead><tr><th>Category</th><th><?php echo $monthNames[$filterMonth] . ' ' . $thYearCur; ?></th><th><?php echo $monthNames[$filterMonth] . ' ' . $thYearPrev; ?></th><th>เปลี่ยนแปลง</th></tr></thead>
          <tbody>
            <?php foreach ($catLabels as $catNo => $c):
              $isEmpty = $cur[$catNo] == 0 && $prev[$catNo] == 0; ?>
              <tr class="<?php echo $isEmpty ? 'empty-cat' : ''; ?>">
                <td><span class="cat-ic" style="background:<?php echo $c['color']; ?>1A;color:<?php echo $c['color']; ?>;"><i class="bi <?php echo $c['icon']; ?>"></i></span><?php echo $c['label']; ?></td>
                <td><?php echo number_format($cur[$catNo], 1); ?></td>
                <td><?php echo number_format($prev[$catNo], 1); ?></td>
                <td><?php echo $isEmpty ? '<span class="pill-na">ไม่มีข้อมูล</span>' : renderChg(yoyPct($cur[$catNo], $prev[$catNo])); ?></td>
              </tr>
            <?php endforeach; ?>
            <tr class="total">
              <td>รวม Scope 1</td>
              <td><?php echo number_format($curTotal, 1); ?></td>
              <td><?php echo number_format($prevTotal, 1); ?></td>
              <td><?php echo renderChg(yoyPct($curTotal, $prevTotal)); ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($filterSite === 0): ?>
    <div class="dash-card">
      <div class="dash-card-head">
        <div class="dash-card-title"><i class="bi bi-geo-alt-fill"></i> แยกราย Site</div>
        <span class="dash-card-badge"><?php echo $monthNames[$filterMonth] . ' ' . $thYearCur; ?></span>
      </div>
      <div class="rpt-table-wrap">
        <table class="rpt-table">
          <thead><tr><th>Site</th><th>Stationary</th><th>Mobile On-road</th><th>Mobile Off-road</th><th>Fugitive</th><th>Process</th><th>รวม</th></tr></thead>
          <tbody>
            <?php if (empty($siteBreakdown)): ?>
              <tr><td colspan="7" style="text-align:center;color:var(--cfp-text-muted);padding:20px;">ไม่มีข้อมูล Approved ในเดือนนี้</td></tr>
            <?php else: foreach ($siteBreakdown as $row): ?>
              <tr>
                <td><?php echo htmlspecialchars($row['name']); ?></td>
                <td><?php echo number_format($row['Stationary'], 1); ?></td>
                <td><?php echo number_format($row['OnRoad'], 1); ?></td>
                <td><?php echo number_format($row['OffRoad'], 1); ?></td>
                <td><?php echo number_format($row['Fugitive'], 1); ?></td>
                <td><?php echo number_format($row['Process'], 1); ?></td>
                <td><b><?php echo number_format($row['Total'], 1); ?></b></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Prompt', sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#7AAAB8';

var trendLabels = <?php echo json_encode($trendLabels, JSON_UNESCAPED_UNICODE); ?>;
var trendData = <?php echo json_encode($trendData); ?>;

new Chart(document.getElementById('chartTrend').getContext('2d'), {
    type: 'bar',
    data: { labels: trendLabels, datasets: [{ label: 'Scope 1', data: trendData, backgroundColor: '#2AABB8', borderRadius: 4, barPercentage: 0.5 }] },
    options: {
        responsive: true, maintainAspectRatio: true,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return ' ' + ctx.parsed.y.toLocaleString() + ' tCO₂e'; } } } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { family: "'Prompt', sans-serif", size: 10 } } },
            y: { grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { family: "'Prompt', sans-serif", size: 10 } } }
        }
    }
});
</script>
</body>
</html>

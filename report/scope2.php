<?php
/* ==============================================
   report/scope2.php — รายงาน Scope 2 (Energy Indirect)
   GHG Management System
   Dual Reporting: Location-based (Grid Mix) vs Market-based (Solar PV/REC)
   ตามมาตรฐาน GHG Protocol Scope 2 Guidance — นับเฉพาะ Header Status=2 (Approved)
   ============================================== */
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

function fetchScope2($conn, $ym, $siteID) {
    $sql = "SELECT a.CategoryNo, SUM(a.CO2e) AS CO2eSum
            FROM CFP_MonthlyHeader h
            JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
            WHERE h.Status = 2 AND h.Scope = 'Scope2' AND h.YearMonth = ?";
    $params = array($ym);
    if ($siteID > 0) { $sql .= " AND h.SiteID = ?"; $params[] = $siteID; }
    $sql .= " GROUP BY a.CategoryNo";

    $out = array('Loc' => 0.0, 'Mkt' => 0.0);
    $res = sqlsrv_query($conn, $sql, $params);
    if ($res) {
        while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $v = (float)$r['CO2eSum'];
            if ((int)$r['CategoryNo'] === 2) { $out['Mkt'] += $v; } else { $out['Loc'] += $v; }
        }
    }
    return $out;
}

$cur  = fetchScope2($conn, $ymCur, $filterSite);
$prev = fetchScope2($conn, $ymPrev, $filterSite);
foreach ($cur as $k => $v)  { $cur[$k]  = $v / 1000; }
foreach ($prev as $k => $v) { $prev[$k] = $v / 1000; }
$curTotal  = $cur['Loc'] + $cur['Mkt'];
$prevTotal = $prev['Loc'] + $prev['Mkt'];

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

/* ── กราฟแท่ง 6 เดือนล่าสุด (Location vs Market) ── */
$trendLabels = array(); $trendLoc = array(); $trendMkt = array();
$thMonths = array('ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.');
for ($i = 5; $i >= 0; $i--) {
    $ts = mktime(0, 0, 0, $filterMonth - $i, 1, $filterYear);
    $ym = date('Ym', $ts);
    $mo = (int)date('n', $ts);
    $sum = fetchScope2($conn, $ym, $filterSite);
    $trendLabels[] = $thMonths[$mo - 1];
    $trendLoc[] = round($sum['Loc'] / 1000, 2);
    $trendMkt[] = round($sum['Mkt'] / 1000, 2);
}

/* ── แยกราย Site ── */
$siteBreakdown = array();
if ($filterSite === 0) {
    $sql = "SELECT h.SiteID, s.SiteName, a.CategoryNo, SUM(a.CO2e) AS CO2eSum
            FROM CFP_MonthlyHeader h
            JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
            JOIN CFP_Site s ON s.SiteID = h.SiteID
            WHERE h.Status = 2 AND h.Scope = 'Scope2' AND h.YearMonth = ?
            GROUP BY h.SiteID, s.SiteName, a.CategoryNo
            ORDER BY s.SiteName";
    $res = sqlsrv_query($conn, $sql, array($ymCur));
    if ($res) {
        while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $sid = (int)$r['SiteID'];
            if (!isset($siteBreakdown[$sid])) { $siteBreakdown[$sid] = array('name' => $r['SiteName'], 'Loc' => 0.0, 'Mkt' => 0.0); }
            $v = (float)$r['CO2eSum'] / 1000;
            if ((int)$r['CategoryNo'] === 2) { $siteBreakdown[$sid]['Mkt'] += $v; } else { $siteBreakdown[$sid]['Loc'] += $v; }
        }
    }
    foreach ($siteBreakdown as $sid => &$row) { $row['Total'] = $row['Loc'] + $row['Mkt']; }
    unset($row);
}

$pageTitle = 'รายงาน Scope 2';
$pageIcon  = 'lightning-charge';
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
  <title>รายงาน Scope 2 — GHG Management System</title>
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

    .dash-card { background: #fff; border: 1px solid var(--cfp-border); border-radius: 14px; padding: 0; overflow:hidden; }
    .dash-card.pad { padding: 16px; }
    .dash-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap:wrap; gap:8px; padding: 16px 18px 0; }
    .dash-card-head.bordered { padding: 14px 18px; margin-bottom:0; border-bottom:1px solid var(--cfp-border); }
    .dash-card-title { font-size: 0.85rem; font-weight: 600; color: var(--cfp-text); display: flex; align-items: center; gap: 7px; }
    .dash-card-title i { color: var(--cfp-primary); }
    .dash-card-badge { font-size: 0.7rem; background: var(--cfp-hover); color: var(--cfp-primary); padding: 2px 8px; border-radius: 10px; border: 1px solid var(--cfp-border); }

    /* Dual reporting */
    .dual-grid { display: grid; grid-template-columns: 1fr 1fr; }
    @media (max-width: 800px) { .dual-grid { grid-template-columns: 1fr; } }
    .dual-col { padding: 18px 22px; }
    .dual-col + .dual-col { border-left: 1px solid var(--cfp-border); }
    @media (max-width: 800px) { .dual-col + .dual-col { border-left:none; border-top:1px solid var(--cfp-border); } }
    .dual-hd { display:flex; align-items:center; gap:8px; font-size:.88rem; font-weight:600; margin-bottom:3px; }
    .dual-sub { font-size:.74rem; color:var(--cfp-text-muted); margin-bottom:14px; }
    .dual-big { font-size:1.9rem; font-weight:700; }
    .dual-unit { font-size:.74rem; color:var(--cfp-text-muted); font-weight:400; }
    .kpi-card-trend { font-size: 0.76rem; margin-top: 8px; display: flex; align-items: center; gap: 3px; font-weight: 500; }
    .trend-down { color: #2DB887; } .trend-up { color: #E05050; } .trend-ok { color: var(--cfp-primary); }

    .rpt-table-wrap { overflow-x: auto; }
    table.rpt-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 480px; }
    table.rpt-table thead th { background: var(--cfp-hover); padding: 10px 12px; text-align: right; font-weight: 600; color: var(--cfp-text-muted); font-size: 0.7rem; letter-spacing: 0.02em; text-transform: uppercase; border-bottom: 1px solid var(--cfp-border); }
    table.rpt-table thead th:first-child { text-align: left; }
    table.rpt-table tbody td { padding: 11px 12px; text-align: right; border-bottom: 1px solid var(--cfp-border); }
    table.rpt-table tbody td:first-child { text-align: left; }
    table.rpt-table tbody tr:last-child td { border-bottom: none; }
    table.rpt-table tbody tr.total td { font-weight: 700; background: #E4F7F9; border-top: 2px solid var(--cfp-primary); }
    table.rpt-table tbody tr.total td:first-child { color: var(--cfp-primary); }
    .chg { font-size: 0.78rem; font-weight: 600; }
    .chg.up { color: #E05050; } .chg.down { color: #2DB887; } .chg.flat { color: var(--cfp-text-muted); }
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
      <a class="btn-export" href="scope2_export.php?month=<?php echo $filterMonth; ?>&year=<?php echo $filterYear; ?>&site=<?php echo $filterSite; ?>">
        <i class="bi bi-file-earmark-excel"></i> Export Excel
      </a>
    </form>

    <div class="dash-card">
      <div class="dash-card-head bordered">
        <div class="dash-card-title"><i class="bi bi-lightning-charge-fill"></i> Dual Reporting — Location-based vs Market-based</div>
        <span class="dash-card-badge">GHG Protocol Scope 2 Guidance</span>
      </div>
      <div class="dual-grid">
        <div class="dual-col">
          <div class="dual-hd"><span style="width:10px;height:10px;border-radius:3px;background:#7C3AED;display:inline-block;"></span>Location-based</div>
          <div class="dual-sub">ไฟฟ้า Grid Mix (MEA/PEA) — ใช้ EF เฉลี่ยของโครงข่ายไฟฟ้าประเทศ</div>
          <div class="dual-big"><?php echo number_format($cur['Loc'], 1); ?><span class="dual-unit"> tCO₂e</span></div>
          <?php $t = yoyPct($cur['Loc'], $prev['Loc']); ?>
          <div class="kpi-card-trend <?php echo $t === null ? 'trend-ok' : ($t < 0 ? 'trend-down' : 'trend-up'); ?>">
            <i class="bi bi-<?php echo $t === null ? 'dash-lg' : ($t < 0 ? 'arrow-down-short' : 'arrow-up-short'); ?>"></i>
            <?php echo $t === null ? 'ไม่มีข้อมูลปีก่อนเทียบ' : number_format(abs($t), 1) . '% เทียบ ' . $monthNames[$filterMonth] . ' ' . $thYearPrev; ?>
          </div>
        </div>
        <div class="dual-col">
          <div class="dual-hd"><span style="width:10px;height:10px;border-radius:3px;background:#F59E0B;display:inline-block;"></span>Market-based</div>
          <div class="dual-sub">Solar PV / REC — ใช้ EF ตามสัญญาซื้อขายไฟฟ้าจริง</div>
          <div class="dual-big"><?php echo number_format($cur['Mkt'], 1); ?><span class="dual-unit"> tCO₂e</span></div>
          <?php $t = yoyPct($cur['Mkt'], $prev['Mkt']); ?>
          <div class="kpi-card-trend <?php echo $t === null ? 'trend-ok' : ($t < 0 ? 'trend-down' : 'trend-up'); ?>">
            <i class="bi bi-<?php echo $t === null ? 'dash-lg' : ($t < 0 ? 'arrow-down-short' : 'arrow-up-short'); ?>"></i>
            <?php echo $t === null ? 'ไม่มีข้อมูลปีก่อนเทียบ' : number_format(abs($t), 1) . '% เทียบ ' . $monthNames[$filterMonth] . ' ' . $thYearPrev; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="dash-card pad">
      <div class="dash-card-head" style="padding:0;">
        <div class="dash-card-title"><i class="bi bi-bar-chart-fill"></i> แนวโน้ม 6 เดือนล่าสุด</div>
        <span class="dash-card-badge">tCO₂e</span>
      </div>
      <canvas id="chartTrend" height="70"></canvas>
      <div style="display:flex;gap:14px;margin-top:8px;font-size:.72rem;color:var(--cfp-text-mid);">
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#7C3AED;margin-right:4px;"></span>Location-based</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#F59E0B;margin-right:4px;"></span>Market-based</span>
      </div>
    </div>

    <div class="dash-card pad">
      <div class="dash-card-head" style="padding:0;">
        <div class="dash-card-title"><i class="bi bi-table"></i> ตารางสรุปเทียบปี</div>
        <span class="dash-card-badge"><?php echo $monthNames[$filterMonth] . ' ' . $thYearCur . ' เทียบ ' . $monthNames[$filterMonth] . ' ' . $thYearPrev; ?></span>
      </div>
      <div class="rpt-table-wrap">
        <table class="rpt-table">
          <thead><tr><th>รายการ</th><th><?php echo $monthNames[$filterMonth] . ' ' . $thYearCur; ?></th><th><?php echo $monthNames[$filterMonth] . ' ' . $thYearPrev; ?></th><th>เปลี่ยนแปลง</th></tr></thead>
          <tbody>
            <tr><td>Location-based (Grid Mix)</td><td><?php echo number_format($cur['Loc'], 1); ?></td><td><?php echo number_format($prev['Loc'], 1); ?></td><td><?php echo renderChg(yoyPct($cur['Loc'], $prev['Loc'])); ?></td></tr>
            <tr><td>Market-based (Solar PV / REC)</td><td><?php echo number_format($cur['Mkt'], 1); ?></td><td><?php echo number_format($prev['Mkt'], 1); ?></td><td><?php echo renderChg(yoyPct($cur['Mkt'], $prev['Mkt'])); ?></td></tr>
            <tr class="total"><td>รวม Scope 2</td><td><?php echo number_format($curTotal, 1); ?></td><td><?php echo number_format($prevTotal, 1); ?></td><td><?php echo renderChg(yoyPct($curTotal, $prevTotal)); ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($filterSite === 0): ?>
    <div class="dash-card pad">
      <div class="dash-card-head" style="padding:0;">
        <div class="dash-card-title"><i class="bi bi-geo-alt-fill"></i> แยกราย Site</div>
        <span class="dash-card-badge"><?php echo $monthNames[$filterMonth] . ' ' . $thYearCur; ?></span>
      </div>
      <div class="rpt-table-wrap">
        <table class="rpt-table">
          <thead><tr><th>Site</th><th>Location-based</th><th>Market-based</th><th>รวม Scope 2</th></tr></thead>
          <tbody>
            <?php if (empty($siteBreakdown)): ?>
              <tr><td colspan="4" style="text-align:center;color:var(--cfp-text-muted);padding:20px;">ไม่มีข้อมูล Approved ในเดือนนี้</td></tr>
            <?php else: foreach ($siteBreakdown as $row): ?>
              <tr>
                <td><?php echo htmlspecialchars($row['name']); ?></td>
                <td><?php echo number_format($row['Loc'], 1); ?></td>
                <td><?php echo number_format($row['Mkt'], 1); ?></td>
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
var trendLoc = <?php echo json_encode($trendLoc); ?>;
var trendMkt = <?php echo json_encode($trendMkt); ?>;

new Chart(document.getElementById('chartTrend').getContext('2d'), {
    type: 'bar',
    data: {
        labels: trendLabels,
        datasets: [
            { label: 'Location-based', data: trendLoc, backgroundColor: '#7C3AED', borderRadius: 4, barPercentage: 0.75 },
            { label: 'Market-based', data: trendMkt, backgroundColor: '#F59E0B', borderRadius: 4, barPercentage: 0.75 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: true,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString() + ' tCO₂e'; } } } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { family: "'Prompt', sans-serif", size: 10 } } },
            y: { grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { family: "'Prompt', sans-serif", size: 10 } } }
        }
    }
});
</script>
</body>
</html>

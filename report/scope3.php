<?php
/* ==============================================
   report/scope3.php — รายงาน Scope 3 (Other Indirect)
   GHG Management System
   แยกตาม 15 Categories (GHG Protocol Corporate Value Chain Standard)
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

/* 15 Categories ตาม GHG Protocol — ใช้ label/icon/color ชุดเดียวกับ data_entry/scope3.php */
$catLabels = array(
    1  => array('label' => 'Purchased Goods & Services',  'icon' => 'bi-box-seam',            'color' => '#D97706'),
    2  => array('label' => 'Capital Goods',                'icon' => 'bi-building',            'color' => '#7C3AED'),
    3  => array('label' => 'Fuel & Energy Related',        'icon' => 'bi-lightning-charge',    'color' => '#2AABB8'),
    4  => array('label' => 'Upstream Transportation',      'icon' => 'bi-truck',                'color' => '#059669'),
    5  => array('label' => 'Waste Generated',               'icon' => 'bi-trash3',               'color' => '#E05050'),
    6  => array('label' => 'Business Travel',               'icon' => 'bi-airplane',             'color' => '#0284C7'),
    7  => array('label' => 'Employee Commuting',            'icon' => 'bi-person-walking',       'color' => '#7C3AED'),
    8  => array('label' => 'Upstream Leased Assets',        'icon' => 'bi-building-up',          'color' => '#888888'),
    9  => array('label' => 'Downstream Transportation',     'icon' => 'bi-truck-front',          'color' => '#059669'),
    10 => array('label' => 'Processing of Sold Products',   'icon' => 'bi-gear-wide-connected',  'color' => '#D97706'),
    11 => array('label' => 'Use of Sold Products',          'icon' => 'bi-bag-check',            'color' => '#2AABB8'),
    12 => array('label' => 'End-of-Life Treatment',         'icon' => 'bi-recycle',              'color' => '#888888'),
    13 => array('label' => 'Downstream Leased Assets',      'icon' => 'bi-building-down',        'color' => '#8B5CF6'),
    14 => array('label' => 'Franchises',                    'icon' => 'bi-shop',                 'color' => '#888888'),
    15 => array('label' => 'Investments',                   'icon' => 'bi-graph-up-arrow',       'color' => '#888888'),
);

function fetchScope3Cat($conn, $ym, $siteID) {
    $sql = "SELECT a.CategoryNo, SUM(a.CO2e) AS CO2eSum
            FROM CFP_MonthlyHeader h
            JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
            WHERE h.Status = 2 AND h.Scope = 'Scope3' AND h.YearMonth = ?";
    $params = array($ym);
    if ($siteID > 0) { $sql .= " AND h.SiteID = ?"; $params[] = $siteID; }
    $sql .= " GROUP BY a.CategoryNo";

    $out = array();
    for ($i = 1; $i <= 15; $i++) { $out[$i] = 0.0; }
    $res = sqlsrv_query($conn, $sql, $params);
    if ($res) {
        while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $cat = (int)$r['CategoryNo'];
            if (isset($out[$cat])) { $out[$cat] += (float)$r['CO2eSum']; }
        }
    }
    return $out;
}

$cur  = fetchScope3Cat($conn, $ymCur, $filterSite);
$prev = fetchScope3Cat($conn, $ymPrev, $filterSite);
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

/* ── กราฟแท่ง 6 เดือนล่าสุด (รวม Scope3 ทั้งหมด) ── */
$trendLabels = array(); $trendData = array();
$thMonths = array('ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.');
for ($i = 5; $i >= 0; $i--) {
    $ts = mktime(0, 0, 0, $filterMonth - $i, 1, $filterYear);
    $ym = date('Ym', $ts);
    $mo = (int)date('n', $ts);
    $sum = fetchScope3Cat($conn, $ym, $filterSite);
    $trendLabels[] = $thMonths[$mo - 1];
    $trendData[] = round(array_sum($sum) / 1000, 2);
}

/* ── แยกราย Site (เฉพาะ Category ที่มีข้อมูล เพื่อไม่ให้ตารางกว้างเกินไป) ── */
$activeCats = array();
foreach ($catLabels as $catNo => $c) { if ($cur[$catNo] > 0 || $prev[$catNo] > 0) { $activeCats[] = $catNo; } }

$siteBreakdown = array();
if ($filterSite === 0) {
    $sql = "SELECT h.SiteID, s.SiteName, a.CategoryNo, SUM(a.CO2e) AS CO2eSum
            FROM CFP_MonthlyHeader h
            JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
            JOIN CFP_Site s ON s.SiteID = h.SiteID
            WHERE h.Status = 2 AND h.Scope = 'Scope3' AND h.YearMonth = ?
            GROUP BY h.SiteID, s.SiteName, a.CategoryNo
            ORDER BY s.SiteName";
    $res = sqlsrv_query($conn, $sql, array($ymCur));
    if ($res) {
        while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $sid = (int)$r['SiteID'];
            if (!isset($siteBreakdown[$sid])) {
                $siteBreakdown[$sid] = array('name' => $r['SiteName'], 'cats' => array(), 'Total' => 0.0);
                foreach ($activeCats as $ac) { $siteBreakdown[$sid]['cats'][$ac] = 0.0; }
            }
            $cat = (int)$r['CategoryNo'];
            $v = (float)$r['CO2eSum'] / 1000;
            if (isset($siteBreakdown[$sid]['cats'][$cat])) { $siteBreakdown[$sid]['cats'][$cat] += $v; }
            $siteBreakdown[$sid]['Total'] += $v;
        }
    }
}

$pageTitle = 'รายงาน Scope 3';
$pageIcon  = 'globe-asia-australia';
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
  <title>รายงาน Scope 3 — GHG Management System</title>
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

    .cfp-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
    @media (max-width: 991px) { .cfp-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
    .kpi-card { background: #fff; border: 1px solid var(--cfp-border); border-radius: 14px; padding: 14px 16px; }
    .kpi-card-label { font-size: 0.72rem; font-weight: 500; color: var(--cfp-text-muted); margin-bottom: 4px; }
    .kpi-card-value { font-size: 1.5rem; font-weight: 700; color: var(--cfp-text); line-height: 1.1; }
    .kpi-card-unit { font-size: 0.66rem; color: var(--cfp-text-muted); font-weight: 400; }

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
    table.rpt-table tbody tr.total td { font-weight: 700; background: #FFF3E0; border-top: 2px solid #F59E0B; }
    table.rpt-table tbody tr.total td:first-child { color: #B5750E; }
    table.rpt-table tbody tr.empty-cat td { color: var(--cfp-text-muted); font-style: italic; }
    table.rpt-table tbody tr.na-summary td { text-align:center; color:var(--cfp-text-muted); font-style:italic; padding:10px; display:table-cell; }
    .cat-ic { width: 26px; height: 26px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: .8rem; flex-shrink:0; }
    .chg { font-size: 0.78rem; font-weight: 600; }
    .chg.up { color: #E05050; } .chg.down { color: #2DB887; } .chg.flat { color: var(--cfp-text-muted); }
    .pill-na { font-size:.66rem; padding:2px 8px; border-radius:20px; font-weight:600; background:#F1F5F6; color:#8FA2A8; }
    .note { font-size: 0.74rem; color: var(--cfp-text-muted); padding: 10px 4px 0; display: flex; align-items: center; gap: 6px; }
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
      <a class="btn-export" href="scope3_export.php?month=<?php echo $filterMonth; ?>&year=<?php echo $filterYear; ?>&site=<?php echo $filterSite; ?>">
        <i class="bi bi-file-earmark-excel"></i> Export Excel
      </a>
    </form>

    <div class="cfp-kpi-grid">
      <div class="kpi-card">
        <div class="kpi-card-label">รวม Scope 3</div>
        <div class="kpi-card-value"><?php echo number_format($curTotal, 1); ?></div>
        <div class="kpi-card-unit">tCO<sub>2</sub>e</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-card-label">Category ที่มีข้อมูล</div>
        <div class="kpi-card-value"><?php echo count($activeCats); ?> / 15</div>
        <div class="kpi-card-unit">Categories</div>
      </div>
      <?php $t = yoyPct($curTotal, $prevTotal); ?>
      <div class="kpi-card">
        <div class="kpi-card-label">เปลี่ยนแปลงจากปีก่อน</div>
        <div class="kpi-card-value"><?php echo $t === null ? '—' : number_format(abs($t), 1) . '%'; ?></div>
        <div class="kpi-card-unit"><?php echo $t === null ? 'ไม่มีข้อมูลเทียบ' : ($t < 0 ? 'ลดลง' : 'เพิ่มขึ้น'); ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-card-label">เดือน/ปีที่ดู</div>
        <div class="kpi-card-value" style="font-size:1.1rem;"><?php echo $monthNames[$filterMonth]; ?></div>
        <div class="kpi-card-unit"><?php echo $thYearCur; ?></div>
      </div>
    </div>

    <div class="dash-card">
      <div class="dash-card-head">
        <div class="dash-card-title"><i class="bi bi-bar-chart-fill"></i> แนวโน้ม 6 เดือนล่าสุด — Scope 3 รวม</div>
        <span class="dash-card-badge">tCO₂e</span>
      </div>
      <canvas id="chartTrend" height="70"></canvas>
    </div>

    <div class="dash-card">
      <div class="dash-card-head">
        <div class="dash-card-title"><i class="bi bi-table"></i> สรุปตาม 15 Categories (GHG Protocol)</div>
        <span class="dash-card-badge"><?php echo $monthNames[$filterMonth] . ' ' . $thYearCur . ' เทียบ ' . $monthNames[$filterMonth] . ' ' . $thYearPrev; ?></span>
      </div>
      <div class="rpt-table-wrap">
        <table class="rpt-table">
          <thead><tr><th>Category</th><th><?php echo $monthNames[$filterMonth] . ' ' . $thYearCur; ?></th><th><?php echo $monthNames[$filterMonth] . ' ' . $thYearPrev; ?></th><th>เปลี่ยนแปลง</th></tr></thead>
          <tbody>
            <?php
            $emptyCatNos = array();
            foreach ($catLabels as $catNo => $c):
                $isEmpty = $cur[$catNo] == 0 && $prev[$catNo] == 0;
                if ($isEmpty) { $emptyCatNos[] = "3.$catNo"; continue; } /* ยุบรวมไว้ท้ายตาราง ไม่แสดงทีละบรรทัด */
            ?>
              <tr>
                <td><span class="cat-ic" style="background:<?php echo $c['color']; ?>1A;color:<?php echo $c['color']; ?>;"><i class="bi <?php echo $c['icon']; ?>"></i></span>3.<?php echo $catNo . ' ' . $c['label']; ?></td>
                <td><?php echo number_format($cur[$catNo], 1); ?></td>
                <td><?php echo number_format($prev[$catNo], 1); ?></td>
                <td><?php echo renderChg(yoyPct($cur[$catNo], $prev[$catNo])); ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!empty($emptyCatNos)): ?>
              <tr class="empty-cat"><td colspan="4" class="na-summary">
                <?php echo implode(', ', $emptyCatNos); ?> ยังไม่มีข้อมูล (<?php echo count($emptyCatNos); ?> categories)
              </td></tr>
            <?php endif; ?>
            <tr class="total">
              <td>รวม Scope 3</td>
              <td><?php echo number_format($curTotal, 1); ?></td>
              <td><?php echo number_format($prevTotal, 1); ?></td>
              <td><?php echo renderChg(yoyPct($curTotal, $prevTotal)); ?></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="note"><i class="bi bi-info-circle"></i> แสดงเฉพาะ Category ที่มีรายการใช้งานจริง — Category ที่ไม่มีข้อมูลยุบรวมไว้บรรทัดเดียวเพื่อความกระชับ</div>
    </div>

    <?php if ($filterSite === 0 && !empty($activeCats)): ?>
    <div class="dash-card">
      <div class="dash-card-head">
        <div class="dash-card-title"><i class="bi bi-geo-alt-fill"></i> แยกราย Site (เฉพาะ Category ที่มีข้อมูล)</div>
        <span class="dash-card-badge"><?php echo $monthNames[$filterMonth] . ' ' . $thYearCur; ?></span>
      </div>
      <div class="rpt-table-wrap">
        <table class="rpt-table">
          <thead>
            <tr>
              <th>Site</th>
              <?php foreach ($activeCats as $ac): ?><th>3.<?php echo $ac; ?></th><?php endforeach; ?>
              <th>รวม</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($siteBreakdown)): ?>
              <tr><td colspan="<?php echo count($activeCats) + 2; ?>" style="text-align:center;color:var(--cfp-text-muted);padding:20px;">ไม่มีข้อมูล Approved ในเดือนนี้</td></tr>
            <?php else: foreach ($siteBreakdown as $row): ?>
              <tr>
                <td><?php echo htmlspecialchars($row['name']); ?></td>
                <?php foreach ($activeCats as $ac): ?><td><?php echo number_format($row['cats'][$ac] ?? 0, 1); ?></td><?php endforeach; ?>
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
    data: { labels: trendLabels, datasets: [{ label: 'Scope 3', data: trendData, backgroundColor: '#F59E0B', borderRadius: 4, barPercentage: 0.5 }] },
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

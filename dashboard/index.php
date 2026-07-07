<?php
/* ==============================================
   dashboard/index.php — Dashboard หลัก
   ระบบบริหารจัดการคาร์บอนองค์กร
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(1, 2, 3, 4, 5, 6));
$conn = getConnection();

$roleID      = (int)$_SESSION['role_id'];
$thYear      = (int)date('Y') + 543;
$currentYear = (int)date('Y');

/* ── ดึงข้อมูล KPI รวมจากข้อมูลจริง
   นับเฉพาะ HeaderStatus=2 (Approved) เท่านั้น ตาม Architecture Note:
   "ข้อมูลที่ยัง Draft หรือ Rejected จะไม่ถูกนับในรายงาน Final" ── */
$kpiScope1 = 0.0; $kpiScope2 = 0.0; $kpiScope3 = 0.0;
$kpiTotal  = 0.0;
$trendS1 = 0.0; $trendS2 = 0.0; $trendS3 = 0.0;

/* ── รายเดือนปีปัจจุบัน แยกตาม Scope (ใช้ทั้ง KPI card และกราฟแท่ง) ── */
$monthlyCO2 = array(); /* [Scope][MonthNo] => kgCO2e */
$resKpi = sqlsrv_query($conn,
    "SELECT h.Scope, SUBSTRING(h.YearMonth,5,2) AS Mo, SUM(a.CO2e) AS CO2eSum
     FROM CFP_MonthlyHeader h
     JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
     WHERE h.Status = 2 AND LEFT(h.YearMonth,4) = ?
     GROUP BY h.Scope, SUBSTRING(h.YearMonth,5,2)",
    array((string)$currentYear));
if ($resKpi) {
    while ($r = sqlsrv_fetch_array($resKpi, SQLSRV_FETCH_ASSOC)) {
        $mo = (int)$r['Mo'];
        $monthlyCO2[$r['Scope']][$mo] = (float)$r['CO2eSum'];
    }
} else {
    /* sqlsrv_query คืนค่า false เมื่อ query ผิดพลาด — log ไว้เพื่อ debug */
    error_log('Dashboard KPI query failed: ' . print_r(sqlsrv_errors(), true));
}

/* รวมทั้งปีต่อ Scope — CO2e เก็บเป็น kgCO2e ในตาราง ต้องหาร 1000 เป็น tCO2e ตอนแสดงผล */
$kpiScope1 = !empty($monthlyCO2['Scope1']) ? array_sum($monthlyCO2['Scope1']) / 1000 : 0.0;
$kpiScope2 = !empty($monthlyCO2['Scope2']) ? array_sum($monthlyCO2['Scope2']) / 1000 : 0.0;
$kpiScope3 = !empty($monthlyCO2['Scope3']) ? array_sum($monthlyCO2['Scope3']) / 1000 : 0.0;
$kpiTotal  = $kpiScope1 + $kpiScope2 + $kpiScope3;

/* ── ข้อมูลปีก่อนหน้า สำหรับคำนวณ % เทียบปีก่อน (YoY) ── */
$prevYearTotal = array('Scope1' => 0.0, 'Scope2' => 0.0, 'Scope3' => 0.0);
$resPrev = sqlsrv_query($conn,
    "SELECT h.Scope, SUM(a.CO2e) AS CO2eSum
     FROM CFP_MonthlyHeader h
     JOIN CFP_ActivityData a ON a.HeaderID = h.HeaderID AND a.IsActive = 1
     WHERE h.Status = 2 AND LEFT(h.YearMonth,4) = ?
     GROUP BY h.Scope",
    array((string)($currentYear - 1)));
if ($resPrev) {
    while ($r = sqlsrv_fetch_array($resPrev, SQLSRV_FETCH_ASSOC)) {
        if (isset($prevYearTotal[$r['Scope']])) {
            $prevYearTotal[$r['Scope']] = (float)$r['CO2eSum'] / 1000;
        }
    }
}
/* trend% = (ปีนี้ - ปีก่อน) / ปีก่อน * 100 — ถ้าปีก่อนไม่มีข้อมูล (0) ให้เป็น 0 (ยังไม่มีฐานเทียบ) */
$trendS1 = $prevYearTotal['Scope1'] > 0 ? (($kpiScope1 - $prevYearTotal['Scope1']) / $prevYearTotal['Scope1']) * 100 : 0.0;
$trendS2 = $prevYearTotal['Scope2'] > 0 ? (($kpiScope2 - $prevYearTotal['Scope2']) / $prevYearTotal['Scope2']) * 100 : 0.0;
$trendS3 = $prevYearTotal['Scope3'] > 0 ? (($kpiScope3 - $prevYearTotal['Scope3']) / $prevYearTotal['Scope3']) * 100 : 0.0;
$prevTotalAll = array_sum($prevYearTotal);
$trendTotal   = $prevTotalAll > 0 ? (($kpiTotal - $prevTotalAll) / $prevTotalAll) * 100 : 0.0;

/* ── chart data รายเดือนจากข้อมูลจริง (tCO2e) ── */
$months  = array('ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.',
                 'ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.');
$chartS1 = array(); $chartS2 = array(); $chartS3 = array();
for ($m = 1; $m <= 12; $m++) {
    $chartS1[] = round(($monthlyCO2['Scope1'][$m] ?? 0) / 1000, 2);
    $chartS2[] = round(($monthlyCO2['Scope2'][$m] ?? 0) / 1000, 2);
    $chartS3[] = round(($monthlyCO2['Scope3'][$m] ?? 0) / 1000, 2);
}

/* ── ข้อมูลล่าสุดที่รอดำเนินการ (Status=1 = Submitted รออนุมัติ) ── */
$pendingCount = 0;
$resPending = sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_MonthlyHeader WHERE Status = 1");
if ($resPending) {
    $rP = sqlsrv_fetch_array($resPending, SQLSRV_FETCH_ASSOC);
    $pendingCount = $rP ? (int)$rP['Cnt'] : 0;
}

/* ── helper: แปลงค่า trend% เป็น class/icon/ข้อความสำหรับแสดงผล (ลดลง = ดี = สีเขียว) ── */
function trendDisplay($pct) {
    if ($pct == 0.0) {
        return array('class' => 'trend-ok', 'icon' => 'dash-lg', 'text' => 'ไม่มีข้อมูลปีก่อนเทียบ');
    }
    $isDown = ($pct < 0);
    return array(
        'class' => $isDown ? 'trend-down' : 'trend-up',
        'icon'  => $isDown ? 'arrow-down-short' : 'arrow-up-short',
        'text'  => number_format(abs($pct), 1) . '% จากปีก่อน',
    );
}

/* ── page meta ── */
$pageTitle = 'Dashboard';
$pageIcon  = 'grid-1x2-fill';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard — ระบบบริหารจัดการคาร์บอนองค์กร</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">
  <style>
    /* ── Dashboard-specific styles ── */
    .cfp-main { margin-left: var(--cfp-sidebar-w); min-height: 100vh; }
    @media (max-width: 991px) { .cfp-main { margin-left: 0; } }

    /* Overview bar */
    .dash-overview {
      background: #fff;
      border: 1px solid var(--cfp-border);
      border-radius: 12px;
      padding: 10px 16px;
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: 8px;
    }
    .dash-overview-title {
      font-size: 0.88rem; font-weight: 600; color: var(--cfp-text);
    }
    .dash-overview-sub { font-size: 0.72rem; color: var(--cfp-text-muted); }

    /* Tab pills */
    .dash-tabs { display: flex; gap: 6px; }
    .dash-tab {
      font-family: 'Prompt', sans-serif;
      font-size: 0.75rem; padding: 4px 12px;
      border-radius: 20px; border: 1px solid var(--cfp-border);
      color: var(--cfp-text-mid); cursor: pointer;
      background: #fff; transition: all .15s;
    }
    .dash-tab.active {
      background: var(--cfp-primary); color: #fff; border-color: var(--cfp-primary);
    }
    .dash-tab:hover:not(.active) { background: var(--cfp-hover); }

    /* KPI Cards */
    .cfp-kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;
    }
    @media (max-width: 991px) { .cfp-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 575px)  { .cfp-kpi-grid { grid-template-columns: 1fr; } }

    .kpi-card {
      background: #fff;
      border: 1px solid var(--cfp-border);
      border-radius: 14px;
      padding: 14px 16px;
      position: relative; overflow: hidden;
    }
    .kpi-card-label {
      font-size: 0.72rem; font-weight: 500;
      color: var(--cfp-text-muted); margin-bottom: 4px;
    }
    .kpi-card-value {
      font-size: 1.6rem; font-weight: 700;
      color: var(--cfp-text); line-height: 1.1;
    }
    .kpi-card-unit {
      font-size: 0.68rem; color: var(--cfp-text-muted);
      font-weight: 400; margin-top: 1px;
    }
    .kpi-card-trend {
      font-size: 0.72rem; margin-top: 6px;
      display: flex; align-items: center; gap: 3px; font-weight: 500;
    }
    .trend-down { color: #2DB887; }
    .trend-up   { color: #E05050; }
    .trend-ok   { color: var(--cfp-primary); }

    .kpi-icon {
      position: absolute; top: 12px; right: 14px;
      width: 36px; height: 36px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.1rem;
    }
    .kpi-icon-s1  { background: #E4F7F9; color: #2AABB8; }
    .kpi-icon-s2  { background: #FFF3E0; color: #F59E0B; }
    .kpi-icon-s3  { background: #F3EEFF; color: #8B5CF6; }
    .kpi-icon-tot { background: #E8F5E9; color: #43A047; }

    /* Chart cards */
    .chart-grid {
      display: grid;
      grid-template-columns: 1.65fr 1fr;
      gap: 12px;
    }
    @media (max-width: 767px) { .chart-grid { grid-template-columns: 1fr; } }

    .dash-card {
      background: #fff;
      border: 1px solid var(--cfp-border);
      border-radius: 14px;
      padding: 16px;
    }
    .dash-card-head {
      display: flex; justify-content: space-between; align-items: center;
      margin-bottom: 12px;
    }
    .dash-card-title {
      font-size: 0.85rem; font-weight: 600; color: var(--cfp-text);
      display: flex; align-items: center; gap: 7px;
    }
    .dash-card-title i { color: var(--cfp-primary); }
    .dash-card-badge {
      font-size: 0.7rem; background: var(--cfp-hover);
      color: var(--cfp-primary); padding: 2px 8px;
      border-radius: 10px; border: 1px solid var(--cfp-border);
    }

    /* Chart legend */
    .chart-legend { display: flex; gap: 12px; margin-top: 8px; flex-wrap: wrap; }
    .legend-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 4px; }
    .legend-item { font-size: 0.72rem; color: var(--cfp-text-mid); display: flex; align-items: center; }

    /* Donut chart area */
    .donut-wrap { display: flex; flex-direction: column; align-items: center; }
    .donut-legend-table { width: 100%; margin-top: 10px; }
    .donut-legend-row {
      display: flex; justify-content: space-between; align-items: center;
      padding: 5px 0; font-size: 0.78rem; color: var(--cfp-text-mid);
      border-bottom: 1px solid var(--cfp-border);
    }
    .donut-legend-row:last-child { border-bottom: none; }
    .donut-legend-pct { font-weight: 600; color: var(--cfp-text); }

    /* Content padding */
    .dash-content { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
    @media (max-width: 575px) { .dash-content { padding: 12px; } }
  </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<div class="cfp-main">

  <?php include '../includes/topbar.php'; ?>

  <div class="dash-content">

    <!-- Overview bar -->
    <div class="dash-overview">
      <div>
        <div class="dash-overview-title">
          ภาพรวมองค์กร &nbsp;
          <span style="font-size:0.72rem;color:var(--cfp-text-muted);font-weight:400;">
            วันนี้ <?php echo date('H:i'); ?> น.
          </span>
        </div>
        <div class="dash-overview-sub">ข้อมูลตามปีงบประมาณ <?php echo $thYear; ?></div>
      </div>
      <div class="dash-tabs">
        <button class="dash-tab active" onclick="setTab(this,'h1')">H1</button>
        <button class="dash-tab" onclick="setTab(this,'all')">ทั้งปี <?php echo $thYear; ?></button>
      </div>
    </div>

    <!-- KPI Cards -->
    <div class="cfp-kpi-grid">

      <!-- Scope 1 -->
      <div class="kpi-card">
        <div class="kpi-icon kpi-icon-s1"><i class="bi bi-fire"></i></div>
        <div class="kpi-card-label">Scope 1</div>
        <div class="kpi-card-value"><?php echo number_format($kpiScope1); ?></div>
        <div class="kpi-card-unit">tCO<sub>2</sub>e</div>
        <div class="kpi-card-trend <?php echo trendDisplay($trendS1)['class']; ?>">
          <i class="bi bi-<?php echo trendDisplay($trendS1)['icon']; ?>"></i>
          <?php echo trendDisplay($trendS1)['text']; ?>
        </div>
      </div>

      <!-- Scope 2 -->
      <div class="kpi-card">
        <div class="kpi-icon kpi-icon-s2"><i class="bi bi-lightning-charge-fill"></i></div>
        <div class="kpi-card-label">Scope 2</div>
        <div class="kpi-card-value"><?php echo number_format($kpiScope2); ?></div>
        <div class="kpi-card-unit">tCO<sub>2</sub>e</div>
        <div class="kpi-card-trend <?php echo trendDisplay($trendS2)['class']; ?>">
          <i class="bi bi-<?php echo trendDisplay($trendS2)['icon']; ?>"></i>
          <?php echo trendDisplay($trendS2)['text']; ?>
        </div>
      </div>

      <!-- Scope 3 -->
      <div class="kpi-card">
        <div class="kpi-icon kpi-icon-s3"><i class="bi bi-globe-asia-australia"></i></div>
        <div class="kpi-card-label">Scope 3</div>
        <div class="kpi-card-value"><?php echo number_format($kpiScope3); ?></div>
        <div class="kpi-card-unit">tCO<sub>2</sub>e</div>
        <div class="kpi-card-trend <?php echo trendDisplay($trendS3)['class']; ?>">
          <i class="bi bi-<?php echo trendDisplay($trendS3)['icon']; ?>"></i>
          <?php echo trendDisplay($trendS3)['text']; ?>
        </div>
      </div>

      <!-- รวม -->
      <div class="kpi-card">
        <div class="kpi-icon kpi-icon-tot"><i class="bi bi-graph-up-arrow"></i></div>
        <div class="kpi-card-label">รวมทั้งหมด</div>
        <div class="kpi-card-value"><?php echo number_format($kpiTotal); ?></div>
        <div class="kpi-card-unit">tCO<sub>2</sub>e</div>
        <div class="kpi-card-trend <?php echo trendDisplay($trendTotal)['class']; ?>">
          <i class="bi bi-<?php echo trendDisplay($trendTotal)['icon']; ?>"></i>
          <?php echo trendDisplay($trendTotal)['text']; ?>
        </div>
      </div>

    </div>

    <!-- Charts row -->
    <div class="chart-grid">

      <!-- Bar chart -->
      <div class="dash-card">
        <div class="dash-card-head">
          <div class="dash-card-title">
            <i class="bi bi-bar-chart-fill"></i> GHG รายเดือน
          </div>
          <span class="dash-card-badge"><?php echo $thYear; ?></span>
        </div>
        <canvas id="chartGHG" height="140"></canvas>
        <div class="chart-legend">
          <div class="legend-item"><span class="legend-dot" style="background:#2AABB8;"></span>Scope 1</div>
          <div class="legend-item"><span class="legend-dot" style="background:#5CC8D8;"></span>Scope 2</div>
          <div class="legend-item"><span class="legend-dot" style="background:#A8D8C0;"></span>Scope 3</div>
        </div>
      </div>

      <!-- Donut chart -->
      <div class="dash-card">
        <div class="dash-card-head">
          <div class="dash-card-title">
            <i class="bi bi-pie-chart-fill"></i> สัดส่วน
          </div>
        </div>
        <div class="donut-wrap">
          <canvas id="chartDonut" width="160" height="160"></canvas>
          <div class="donut-legend-table">
            <div class="donut-legend-row">
              <span><span class="legend-dot" style="background:#2AABB8;"></span>Scope 1</span>
              <span class="donut-legend-pct">
                <?php echo $kpiTotal > 0 ? round($kpiScope1 / $kpiTotal * 100) : 0; ?>%
              </span>
            </div>
            <div class="donut-legend-row">
              <span><span class="legend-dot" style="background:#5CC8D8;"></span>Scope 2</span>
              <span class="donut-legend-pct">
                <?php echo $kpiTotal > 0 ? round($kpiScope2 / $kpiTotal * 100) : 0; ?>%
              </span>
            </div>
            <div class="donut-legend-row">
              <span><span class="legend-dot" style="background:#A8D8C0;"></span>Scope 3</span>
              <span class="donut-legend-pct">
                <?php echo $kpiTotal > 0 ? round($kpiScope3 / $kpiTotal * 100) : 0; ?>%
              </span>
            </div>
          </div>
        </div>
      </div>

    </div>

  </div><!-- /.dash-content -->
</div><!-- /.cfp-main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
/* ── Chart.js global font ── */
Chart.defaults.font.family = "'Prompt', sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#7AAAB8';

/* ── Data จาก PHP ── */
var months  = <?php echo json_encode($months); ?>;
var dataS1  = <?php echo json_encode($chartS1); ?>;
var dataS2  = <?php echo json_encode($chartS2); ?>;
var dataS3  = <?php echo json_encode($chartS3); ?>;

/* ── Bar chart รายเดือน ── */
var ctxBar = document.getElementById('chartGHG').getContext('2d');
new Chart(ctxBar, {
    type: 'bar',
    data: {
        labels: months,
        datasets: [
            {
                label: 'Scope 1',
                data: dataS1,
                backgroundColor: '#2AABB8',
                borderRadius: 4,
                barPercentage: 0.75
            },
            {
                label: 'Scope 2',
                data: dataS2,
                backgroundColor: '#5CC8D8',
                borderRadius: 4,
                barPercentage: 0.75
            },
            {
                label: 'Scope 3',
                data: dataS3,
                backgroundColor: '#A8D8C0',
                borderRadius: 4,
                barPercentage: 0.75
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(ctx) {
                        return ' ' + ctx.dataset.label + ': ' +
                               ctx.parsed.y.toLocaleString() + ' tCO₂e';
                    }
                }
            }
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { family: "'Prompt', sans-serif", size: 10 } } },
            y: { grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { family: "'Prompt', sans-serif", size: 10 } } }
        }
    }
});

/* ── Donut chart สัดส่วน ── */
var ctxDonut = document.getElementById('chartDonut').getContext('2d');
new Chart(ctxDonut, {
    type: 'doughnut',
    data: {
        labels: ['Scope 1', 'Scope 2', 'Scope 3'],
        datasets: [{
            data: [<?php echo $kpiScope1; ?>, <?php echo $kpiScope2; ?>, <?php echo $kpiScope3; ?>],
            backgroundColor: ['#2AABB8', '#5CC8D8', '#A8D8C0'],
            borderWidth: 0,
            hoverOffset: 6
        }]
    },
    options: {
        responsive: false,
        cutout: '68%',
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(ctx) {
                        var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                        var pct   = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                        return ' ' + ctx.label + ': ' + ctx.parsed.toLocaleString() + ' tCO₂e (' + pct + '%)';
                    }
                }
            }
        }
    }
});

/* ── Tab switch ── */
function setTab(el, tab) {
    document.querySelectorAll('.dash-tab').forEach(function(btn) {
        btn.classList.remove('active');
    });
    el.classList.add('active');
    /* TODO: reload chart data ตาม tab ที่เลือก */
}
</script>
</body>
</html>
<?php
/* ==============================================
   master/ef_link.php
   Bulk-link / Bulk-unlink CFP_ActivityItem.EFID ↔ CFP_EFValue (many-to-one)
   Admin (4), SustainAdmin (5)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(4, 5));
$conn = getConnection();

/* ===== Toast ===== */
$toastMsg  = '';
$toastType = '';
if (!empty($_SESSION['toast'])) {
    $toastMsg  = $_SESSION['toast']['msg'];
    $toastType = $_SESSION['toast']['type'];
    unset($_SESSION['toast']);
}

/* ===== ดึง EFValue ที่ยังไม่มี Item ผูกอยู่เลยสักตัว (many-to-one: เช็คจาก CFP_ActivityItem.EFID) ===== */
$resEF = sqlsrv_query($conn, "
    SELECT e.EFID, e.EFCode, e.EFName, e.Scope, e.Category, e.GasType,
           e.EFValue, e.Unit, e.YearApply
    FROM CFP_EFValue e
    WHERE e.IsActive = 1
      AND NOT EXISTS (SELECT 1 FROM CFP_ActivityItem ai WHERE ai.EFID = e.EFID)
    ORDER BY e.Scope, CASE e.Category
        WHEN 'Stationary' THEN 1
        WHEN 'Mobile-OffRoad' THEN 2
        WHEN 'Mobile-OnRoad' THEN 3
        WHEN 'Fugitive' THEN 4
        WHEN 'Process' THEN 5
        WHEN 'Electricity' THEN 6
        ELSE 99 END, e.EFName
");
$efRows      = array();
while ($r = sqlsrv_fetch_array($resEF, SQLSRV_FETCH_ASSOC)) { $efRows[] = $r; }
$unlinkCount = count($efRows);

/* ===== ดึง EFValue ที่ผูกแล้ว — 1 แถวต่อ 1 คู่ (EF, Item) เพราะตอนนี้ EF เดียวผูกได้หลาย Item (many-to-one) ===== */
$resLinked = sqlsrv_query($conn, "
    SELECT e.EFID, e.EFCode, e.EFName, e.Scope, e.Category, e.GasType,
           e.EFValue, e.Unit, e.YearApply,
           a.ItemID, a.ItemCode, a.ItemName
    FROM CFP_ActivityItem a
    JOIN CFP_EFValue e ON e.EFID = a.EFID AND e.IsActive = 1
    ORDER BY e.Scope, CASE e.Category
        WHEN 'Stationary' THEN 1
        WHEN 'Mobile-OffRoad' THEN 2
        WHEN 'Mobile-OnRoad' THEN 3
        WHEN 'Fugitive' THEN 4
        WHEN 'Process' THEN 5
        WHEN 'Electricity' THEN 6
        ELSE 99 END, e.EFName, a.ItemName
");
$linkedRowsRaw = array();
while ($r = sqlsrv_fetch_array($resLinked, SQLSRV_FETCH_ASSOC)) { $linkedRowsRaw[] = $r; }
$linkedCount   = count($linkedRowsRaw);

/* ===== จัดกลุ่มตาม Scope + Category (Stationary / Mobile-OnRoad / Mobile-OffRoad / Electricity ฯลฯ)
   query เรียงมาตาม Scope,Category,EFName อยู่แล้ว แค่ทำ header คั่นตอน render ===== */
/* ชื่อกลุ่มอ้างอิงตามหัวข้อจริงใน Emission Factor_2569.pdf */
$catLabelMap = array(
    'Stationary'     => 'Stationary Source',
    'Mobile-OnRoad'  => 'Mobile Source — On-road vehicles',
    'Mobile-OffRoad' => 'Mobile Source — Off-road vehicles/mobile equipment',
    'Fugitive'       => 'Fugitive Emissions',
    'Process'        => 'Process Emissions',
    'Electricity'    => 'Electricity, grid mix',
);
$linkedRows = $linkedRowsRaw;

/* จัดกลุ่มแถวที่ผูกแล้วตาม EFID — 1 EF อาจผูกกับหลาย Item ให้แสดงเป็น 1 แถวต่อ EF (chip รวม Item ทั้งหมด) */
$linkedGroups = array();
$linkedGroupOrder = array();
foreach ($linkedRows as $r) {
    $efid = (int)$r['EFID'];
    if (!isset($linkedGroups[$efid])) {
        $linkedGroups[$efid] = array('ef' => $r, 'items' => array());
        $linkedGroupOrder[] = $efid;
    }
    $linkedGroups[$efid]['items'][] = array('ItemID' => (int)$r['ItemID'], 'ItemName' => $r['ItemName']);
}

/* นับจำนวนรายการต่อกลุ่ม (Scope|Category) ไว้โชว์ในหัวข้อกลุ่ม */
function cfpEfGroupCounts($rows) {
    $counts = array();
    foreach ($rows as $r) {
        $key = ($r['Scope'] ?? '') . '|' . ($r['Category'] ?? '');
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    return $counts;
}
$groupCountsUnlinked = cfpEfGroupCounts($efRows);
$groupCountsLinked   = cfpEfGroupCounts($linkedRows);

/* ไอคอนต่อหมวด สำหรับหัวข้อกลุ่มแบบใหม่ */
$catIconMap = array(
    'Stationary'     => 'bi-fire',
    'Mobile-OnRoad'  => 'bi-truck',
    'Mobile-OffRoad' => 'bi-truck-flatbed',
    'Fugitive'       => 'bi-droplet-half',
    'Process'        => 'bi-gear-wide-connected',
    'Electricity'    => 'bi-lightning-charge-fill',
);

/* ===== ดึง ActivityItem ทั้งหมด จัดกลุ่มตาม ScopeNo ===== */
$resItem = sqlsrv_query($conn, "
    SELECT ItemID, ItemCode, ItemName, ScopeNo, Scope1Type, MobileRoadType, CategoryNo
    FROM CFP_ActivityItem
    WHERE IsActive = 1
    ORDER BY ScopeNo, Scope1Type, CategoryNo, ItemName
");
$itemsByScopeNo = array(1 => array(), 2 => array(), 3 => array());
$allItems       = array();
while ($r = sqlsrv_fetch_array($resItem, SQLSRV_FETCH_ASSOC)) {
    $sno = (int)$r['ScopeNo'];
    $itemsByScopeNo[$sno][] = $r;
    $allItems[] = $r;
}

$scopeToNo = array('Scope1' => 1, 'Scope2' => 2, 'Scope3' => 3);
$gasColors = array('CO2'=>'#3B82F6','CH4'=>'#EC4899','N2O'=>'#84CC16','HFCs'=>'#B45309','CO2e'=>'#16A34A');

/* ตัดคำซ้ำกับหัวข้อกลุ่ม (Stationary/On-road/Off-road) ออกจากชื่อ EF ตอนแสดงผล
   เพราะกลุ่มด้านบนบอกอยู่แล้ว ไม่ต้องพูดซ้ำในชื่อรายการ */
function cfpEfDisplayName($name) {
    $name = preg_replace('/\s*\((Off-road|On-road|Stationary)\)\s*/i', ' ', $name);
    return trim(preg_replace('/\s+/', ' ', $name));
}

$jsItems = array();
foreach ($allItems as $it) {
    $jsItems[] = array(
        'id'      => (int)$it['ItemID'],
        'code'    => $it['ItemCode'],
        'name'    => $it['ItemName'],
        'scopeNo' => (int)$it['ScopeNo'],
        'type'    => $it['Scope1Type'] ?? '',
        'roadType'=> $it['MobileRoadType'] ?? '',
        'catNo'   => (int)($it['CategoryNo'] ?? 0),
    );
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>จัดการการผูก EF กับรา��การ Activity — GHG Management System</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets/css/cfp-theme.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body { font-family:'Prompt',sans-serif; }
    .font-prompt { font-family:'Prompt',sans-serif !important; }
    .ef-table th { background:var(--cfp-bg); color:var(--cfp-text-muted); font-size:0.72rem; font-weight:500; padding:7px 10px; border-bottom:1px solid var(--cfp-border); text-transform:uppercase; letter-spacing:.3px; white-space:nowrap; }
    .ef-table td { padding:8px 10px; border-bottom:1px solid var(--cfp-border); font-size:0.82rem; vertical-align:middle; }
    .ef-table tr:last-child td { border-bottom:none; }
    .ef-table tr.linked td { background:#F0FDF4; }
    .ef-table tr.linked td:first-child { border-left:3px solid #4CAF50; }
    .ef-table tr.unlink-sel td { background:#FEF2F2; }
    .item-sel { font-size:0.8rem; border:1px solid var(--cfp-border); border-radius:6px; padding:4px 8px; font-family:'Prompt',sans-serif; color:var(--cfp-text); background:#fff; width:100%; }
    .item-sel.matched { border-color:#4CAF50; background:#F0FDF4; }

    /* ===== Inline Row Expand Picker (Option C) — เลือก Activity Item ผูกกับ EF ===== */
    .ef-picker { min-width:220px; }
    .chipbox { display:flex; flex-wrap:wrap; gap:5px; align-items:center; min-height:0; margin-bottom:5px; }
    .chipbox:empty { display:none; }
    .item-chip {
      display:inline-flex; align-items:center; gap:5px;
      background:color-mix(in srgb, var(--chip-color, var(--cfp-primary)) 14%, transparent);
      border:1px solid color-mix(in srgb, var(--chip-color, var(--cfp-primary)) 55%, transparent);
      color:color-mix(in srgb, var(--chip-color, var(--cfp-primary)) 75%, black);
      font-size:0.72rem; font-weight:500; padding:3px 4px 3px 9px; border-radius:14px; white-space:nowrap;
    }
    .item-chip button {
      border:none; background:rgba(0,0,0,.08); color:inherit; width:15px; height:15px; border-radius:50%;
      cursor:pointer; font-size:0.62rem; line-height:1; display:flex; align-items:center; justify-content:center; padding:0; flex-shrink:0;
    }
    .item-chip button:hover { background:rgba(0,0,0,.18); }
    .item-chip-select { cursor:pointer; padding-left:6px; }
    .item-chip-select input { accent-color:#E05050; width:13px; height:13px; cursor:pointer; flex-shrink:0; }
    .linked-unlink-bar {
      position:sticky; bottom:0; z-index:5; display:none;
      align-items:center; gap:12px; padding:10px 16px;
      background:#FEF2F2; border-top:1px solid #FCA5A5;
    }
    .linked-unlink-bar.show { display:flex; }
    .btn-linked-unlink-clear { font-size:0.74rem; color:#6B7280; cursor:pointer; text-decoration:underline; }
    .btn-add-item {
      border:1px dashed var(--cfp-primary); color:var(--cfp-primary-dark,#1A8898); background:transparent;
      font-size:0.74rem; padding:3px 12px; border-radius:14px; cursor:pointer; font-family:'Prompt',sans-serif;
    }
    .btn-add-item:hover { background:rgba(42,171,184,.08); }
    .btn-add-item.open { background:var(--cfp-primary); color:#fff; border-style:solid; }

    /* ===== Docked Tray (Option D) — แผงเดียวใช้ร่วมกันทุกแถว ลอยติดขอบล่างจอเสมอ ===== */
    .ef-tray {
      position:fixed; left:0; right:0; bottom:0; z-index:1040;
      background:#fff; border-top:2px solid var(--cfp-primary);
      box-shadow:0 -10px 28px rgba(0,0,0,.14);
      max-height:min(60vh, 460px); display:flex; flex-direction:column;
    }
    .ef-tray-head { display:flex; justify-content:space-between; align-items:center; padding:12px 20px; border-bottom:1px solid var(--cfp-border); background:var(--cfp-bg); flex-shrink:0; }
    .ef-tray-head b { font-size:0.9rem; color:var(--cfp-text); }
    .ef-tray-head small { display:block; font-size:0.74rem; color:var(--cfp-text-muted); margin-top:1px; }
    .ef-tray-close { cursor:pointer; color:var(--cfp-text-muted); font-size:1rem; padding:4px 8px; }
    .ef-tray-close:hover { color:var(--cfp-text); }
    .ef-tray-body { padding:14px 20px; display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start; overflow-y:auto; }
    .panel-search-full {
      width:100%; border:1px solid var(--cfp-border); border-radius:7px; padding:7px 10px;
      font-size:0.8rem; font-family:'Prompt',sans-serif; color:var(--cfp-text); background:var(--cfp-bg); margin-bottom:10px;
    }
    .c-col-title { font-size:0.66rem; font-weight:700; color:var(--cfp-text-muted); text-transform:uppercase; letter-spacing:.03em; margin-bottom:6px; }
    .btn-clear-chips { font-size:0.68rem; font-weight:500; color:#B91C1C; cursor:pointer; text-transform:none; letter-spacing:normal; }
    .btn-clear-chips:hover { text-decoration:underline; }
    .panel-list { max-height:280px; overflow-y:auto; background:#fff; border:1px solid var(--cfp-border); border-radius:8px; }
    .panel-group {
      position:sticky; top:0; z-index:2;
      display:flex; align-items:center; gap:6px;
      font-size:0.68rem; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.03em;
      padding:6px 10px; background:var(--grp-color, var(--cfp-primary));
    }
    .panel-group .bi { font-size:0.78rem; }
    .panel-item { display:flex; align-items:center; gap:8px; padding:6px 10px; font-size:0.8rem; cursor:pointer; }
    .panel-item:hover { background:var(--cfp-bg); }
    .panel-item input { accent-color:var(--cfp-primary); width:14px; height:14px; flex-shrink:0; }
    .panel-empty { padding:14px 10px; color:var(--cfp-text-muted); font-size:0.76rem; text-align:center; }
    .chipbox-expand { display:flex; flex-wrap:wrap; gap:6px; align-content:flex-start; min-height:60px; max-height:280px; overflow-y:auto; }
    .scope-tab { padding:7px 16px; font-size:0.8rem; border-radius:20px; cursor:pointer; border:1px solid var(--cfp-border); background:#fff; color:var(--cfp-text-muted); }
    .scope-tab.active { background:var(--cfp-primary); color:#fff; border-color:var(--cfp-primary); font-weight:600; }
    .badge-unlink { background:#FEF2F2; color:#B91C1C; font-size:0.7rem; padding:2px 8px; border-radius:10px; font-weight:600; }
    .badge-linked { background:#F0FDF4; color:#166534; font-size:0.7rem; padding:2px 8px; border-radius:10px; font-weight:600; }
    .ef-group-header td { padding:0; border-top:none; border-bottom:1px solid var(--cfp-border); }
    .ef-group-banner {
      display:flex; align-items:center; gap:10px; padding:10px 14px;
      border-top:3px solid var(--grp-color, #2AABB8);
      background:linear-gradient(90deg, color-mix(in srgb, var(--grp-color, #2AABB8) 14%, transparent), transparent);
    }
    .ef-group-banner .ic {
      width:26px; height:26px; border-radius:7px; flex-shrink:0;
      background:var(--grp-color, #2AABB8); color:#fff;
      display:flex; align-items:center; justify-content:center; font-size:0.8rem;
    }
    .ef-group-banner b { font-size:0.88rem; color:var(--cfp-text); }
    .ef-group-banner .cnt {
      margin-left:auto; font-size:0.68rem; font-weight:700; color:var(--grp-color, #2AABB8);
      background:#fff; border:1px solid var(--grp-color, #2AABB8); padding:2px 10px; border-radius:20px;
      white-space:nowrap;
    }
    .save-bar { position:sticky; bottom:0; background:#fff; border-top:1px solid var(--cfp-border); padding:12px 0; z-index:5; }
    /* z-index สูงกว่า .save-bar เสมอ กัน sticky bar ลอยทับบัง header ตอน scroll แล้วกดไม่ติด */
    .section-title { position:relative; z-index:6; font-size:0.88rem; font-weight:600; color:var(--cfp-primary); padding:12px 16px; border-bottom:1px solid var(--cfp-border); display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .section-title .ms-auto { flex-wrap:wrap; }
    .chk-unlink { width:16px; height:16px; cursor:pointer; accent-color:#E05050; }
    #progressWrap { display:none; }

    /* ===== Responsive: จอเล็ก (มือถือ/แท็บเล็ตแนวตั้ง) ===== */
    @media (max-width: 767px) {
      /* tray กอง search+list กับ chip ที่เลือกไว้เป็นแนวตั้งแทนแบ่ง 2 คอลัมน์ (แคบเกินไปถ้าเรียงข้างกัน) */
      .ef-tray-body { grid-template-columns: 1fr; gap:14px; }
      .ef-tray { max-height: 88vh; }
      .panel-list, .chipbox-expand { max-height: 180px; }
      .ef-tray-head { padding:10px 14px; }
      .ef-tray-body { padding:12px 14px; }
      /* ปุ่ม auto-match/ล้าง ใน header เต็มความกว้างแทนบีบเรียงชิดขวา อ่านง่ายกว่า */
      .section-title .ms-auto { width:100%; justify-content:flex-start; margin-left:0 !important; }
      .ef-picker { min-width:0; width:100%; }

      /* ===== ตาราง -> การ์ด (Option A) — ไม่แตะ logic tray/picker เลย แค่จัดเรียง cell ใหม่ด้วย CSS ===== */
      #efTable, #efTable tbody, #efTable tr,
      #linkedTable, #linkedTable tbody, #linkedTable tr { display:block; width:100%; }
      #efTable thead, #linkedTable thead { display:none; }

      #efTable tr:not(.ef-group-header),
      #linkedTable tr:not(.ef-group-header) {
        display:flex; flex-wrap:wrap; align-items:center;
        border:1px solid var(--cfp-border); border-radius:10px;
        padding:10px 12px; margin-bottom:8px; background:#fff;
      }
      #efTable td, #linkedTable td { display:block; border:none !important; padding:2px 4px !important; }
      .ef-group-header td { display:block !important; width:100%; }

      /* #efTable: 1=#, 2=ชื่อ, 3=Scope, 4=Category, 5=Gas, 6=ค่าEF, 7=ปี, 8=Picker, 9=สถานะ */
      #efTable td:nth-child(1), #efTable td:nth-child(3),
      #efTable td:nth-child(4), #efTable td:nth-child(5), #efTable td:nth-child(7) { display:none; }
      #efTable td:nth-child(2) { order:1; flex:1 1 60%; font-weight:600; font-size:0.88rem; }
      #efTable td:nth-child(6) {
        order:2; flex:0 0 auto; margin-left:auto; text-align:right;
        display:flex; align-items:baseline; gap:4px;
      }
      #efTable td:nth-child(6) > div { font-size:0.7rem; color:var(--cfp-text-muted); margin:0 !important; }
      /* แถวปุ่ม+สถานะ อยู่บรรทัดเดียวกัน คั่นด้วยเส้นบางจากส่วนบน แทนที่จะกองสูงเป็น 2 บรรทัดแยก */
      #efTable td:nth-child(8) {
        order:3; flex:1 1 auto; margin-top:10px; padding-top:8px !important;
        border-top:1px solid var(--cfp-border);
      }
      #efTable td:nth-child(9) {
        order:4; flex:0 0 auto; margin-top:10px; padding-top:8px !important;
        border-top:1px solid var(--cfp-border); text-align:right;
      }

      /* #linkedTable: 1=ชื่อ, 2=Scope, 3=Category, 4=Gas, 5=ค่าEF, 6=ปี, 7=ผูกกับ Item */
      #linkedTable td:nth-child(2), #linkedTable td:nth-child(3),
      #linkedTable td:nth-child(4), #linkedTable td:nth-child(6) { display:none; }
      #linkedTable td:nth-child(1) { order:1; flex:1 1 auto; font-weight:600; font-size:0.88rem; }
      #linkedTable td:nth-child(5) { order:2; flex:0 0 auto; margin-left:auto; text-align:right; }
      #linkedTable td:nth-child(7) {
        order:3; flex:1 1 100%; margin-top:10px; padding-top:8px !important;
        border-top:1px solid var(--cfp-border);
      }
    }
</head>
<body>
<div class="d-flex">
  <?php include '../includes/sidebar.php'; ?>
  <div class="cfp-main">
    <?php $pageTitle='ผูก EF กับรายการ Activity'; $pageIcon='link-45deg'; include '../includes/topbar.php'; ?>
    <div class="cfp-content">

      <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
          <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);">
            <i class="bi bi-link-45deg me-2"></i>จัดการการผูก EF กับรายการ Activity
          </h5>
          <div style="font-size:0.78rem;color:var(--cfp-text-muted);margin-top:2px;">
            ผูก หรือ ยกเลิกการผูก ค่า EF กับรายการกิจกรรม
          </div>
        </div>
        <a href="ef_value.php" class="btn btn-sm btn-outline-secondary font-prompt">
          <i class="bi bi-arrow-left me-1"></i>กลับหน้า EF Value
        </a>
      </div>

      <!-- KPI -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-4">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div style="width:44px;height:44px;border-radius:10px;background:#FEF2F2;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
              <i class="bi bi-unlink" style="color:#B91C1C;"></i>
            </div>
            <div>
              <div id="kpiUnlink" style="font-size:1.5rem;font-weight:700;color:#B91C1C;line-height:1.1;"><?php echo $unlinkCount; ?></div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ยังไม่ได้ผูก</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div style="width:44px;height:44px;border-radius:10px;background:#F0FDF4;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
              <i class="bi bi-link-45deg" style="color:#166534;"></i>
            </div>
            <div>
              <div id="kpiLinkedDB" style="font-size:1.5rem;font-weight:700;color:#166534;line-height:1.1;"><?php echo $linkedCount; ?></div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ผูกแล้วในฐานข้อมูล</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div style="width:44px;height:44px;border-radius:10px;background:#EFF6FF;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
              <i class="bi bi-check2-circle" style="color:#1D4ED8;"></i>
            </div>
            <div>
              <div id="kpiPending" style="font-size:1.5rem;font-weight:700;color:#1D4ED8;line-height:1.1;">0</div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">เลือกแล้ว (รอบันทึก)</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════ SECTION 1: ยังไม่ผูก ═══════════ -->
      <div class="cfp-card mb-3" style="padding:0;overflow:hidden;">
        <div class="section-title">
          <i class="bi bi-link-45deg" style="color:#2AABB8;"></i>
          ผูก EF กับ Activity Item
          <span class="badge-unlink ms-1"><?php echo $unlinkCount; ?> รายการ</span>
          <div class="ms-auto d-flex gap-2">
            <?php if ($unlinkCount > 0): ?>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span style="font-size:0.72rem;color:var(--cfp-text-muted);">Scope:</span>
              <button class="scope-tab active" onclick="filterScope('all', this)">ทั้งหมด</button>
              <?php
              $scopeCounts = array('Scope1'=>0,'Scope2'=>0,'Scope3'=>0);
              foreach ($efRows as $r) { if (isset($scopeCounts[$r['Scope']])) $scopeCounts[$r['Scope']]++; }
              foreach ($scopeCounts as $sc => $cnt) {
                  if ($cnt > 0) {
                      $scNo = str_replace('Scope','Scope ',$sc);
                      echo '<button class="scope-tab" onclick="filterScope(\''.$sc.'\', this)">'.$scNo.' <span style="font-size:0.65rem;background:#E5E7EB;color:#374151;padding:0 5px;border-radius:8px;">'.$cnt.'</span></button>';
                  }
              }
              ?>
            </div>
            <button class="btn btn-sm font-prompt" style="background:#F59E0B;color:#fff;font-size:0.78rem;" onclick="autoMatch()">
              <i class="bi bi-magic me-1"></i>Auto-match
            </button>
            <button class="btn btn-sm font-prompt" style="background:#E5E7EB;color:#374151;font-size:0.78rem;" onclick="clearLinkSel()">
              <i class="bi bi-x-circle me-1"></i>ล้าง
            </button>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($unlinkCount === 0): ?>
        <div class="text-center py-4" style="color:var(--cfp-text-muted);">
          <i class="bi bi-check-circle-fill" style="font-size:2rem;color:#4CAF50;display:block;margin-bottom:8px;"></i>
          EF ทุกรายการผูก Activity Item ครบแล้ว
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="ef-table w-100" id="efTable">
            <thead>
              <tr>
                <th style="width:32px;">#</th>
                <th style="min-width:220px;">ชื่อ EF</th>
                <th style="width:56px;">Scope</th>
                <th style="width:90px;">Category</th>
                <th style="width:56px;">Gas</th>
                <th style="width:90px;">ค่า EF</th>
                <th style="width:50px;">ปี</th>
                <th style="min-width:220px;">ผูกกับ Activity Item</th>
                <th style="width:70px;">สถานะ</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $prevGroupKeyUnlinked = null;
              foreach ($efRows as $idx => $ef):
                  $sno  = $scopeToNo[$ef['Scope']] ?? 0;
                  $efid = (int)$ef['EFID'];
                  $scope = htmlspecialchars($ef['Scope'] ?? '');
                  $cat   = htmlspecialchars($ef['Category'] ?? '');
                  $groupKeyU = $scope . '|' . $cat;
                  if ($groupKeyU !== $prevGroupKeyUnlinked) {
                      $prevGroupKeyUnlinked = $groupKeyU;
                      $catLabelU = $catLabelMap[$ef['Category'] ?? ''] ?? ($cat ?: '—');
                      $grpColorU = ($scope==='Scope1'?'#2AABB8':($scope==='Scope2'?'#F59E0B':'#8B5CF6'));
                      $grpIconU  = $catIconMap[$ef['Category'] ?? ''] ?? 'bi-collection';
                      $grpCntU   = $groupCountsUnlinked[$groupKeyU] ?? 0;
                      ?>
              <tr class="ef-group-header" data-scope="<?php echo $scope; ?>">
                <td colspan="9">
                  <div class="ef-group-banner" style="--grp-color:<?php echo $grpColorU; ?>;">
                    <div class="ic"><i class="bi <?php echo $grpIconU; ?>"></i></div>
                    <b><?php echo $scope; ?> — <?php echo htmlspecialchars($catLabelU); ?></b>
                    <span class="cnt"><?php echo $grpCntU; ?> รายการ</span>
                  </div>
                </td>
              </tr>
              <?php } ?>
              <tr data-efid="<?php echo $efid; ?>"
                  data-efcode="<?php echo htmlspecialchars($ef['EFCode'] ?? ''); ?>"
                  data-efnamedisplay="<?php echo htmlspecialchars($ef['EFName'] ?? ''); ?>"
                  data-scope="<?php echo $scope; ?>"
                  data-scopeno="<?php echo $sno; ?>"
                  data-category="<?php echo htmlspecialchars($ef['Category'] ?? ''); ?>"
                  data-gastype="<?php echo htmlspecialchars($ef['GasType'] ?? ''); ?>"
                  data-efvalue="<?php echo (float)$ef['EFValue']; ?>"
                  data-unit="<?php echo htmlspecialchars($ef['Unit'] ?? ''); ?>"
                  data-year="<?php echo (int)$ef['YearApply']; ?>"
                  data-efname="<?php echo htmlspecialchars(strtolower($ef['EFName'])); ?>">
                <td style="color:var(--cfp-text-muted);font-size:0.72rem;"><?php echo $idx+1; ?></td>
                <td>
                  <div style="font-weight:500;white-space:nowrap;"><?php echo htmlspecialchars(cfpEfDisplayName($ef['EFName'])); ?></div>
                </td>
                <td>
                  <span style="font-size:0.7rem;padding:2px 8px;border-radius:10px;color:#fff;font-weight:600;background:<?php
                      echo ($scope==='Scope1'?'#2AABB8':($scope==='Scope2'?'#F59E0B':'#8B5CF6')); ?>;">
                    <?php echo $scope; ?>
                  </span>
                </td>
                <td style="font-size:0.66rem;color:var(--cfp-text-muted);white-space:nowrap;"><?php echo $cat ?: '—'; ?></td>
                <td><span style="font-size:0.7rem;padding:1px 7px;border-radius:8px;color:#fff;font-weight:600;background:<?php echo $gasColors[$ef['GasType'] ?? ''] ?? '#888'; ?>;"><?php echo htmlspecialchars($ef['GasType'] ?? ''); ?></span></td>
                <td style="font-family:monospace;font-size:0.82rem;color:var(--cfp-primary);font-weight:600;">
                  <?php echo number_format((float)$ef['EFValue'], 6); ?>
                  <?php if ($ef['Unit']) echo '<div style="font-size:0.68rem;color:var(--cfp-text-muted);">'.htmlspecialchars($ef['Unit']).'</div>'; ?>
                </td>
                <td style="font-size:0.82rem;"><?php echo (int)$ef['YearApply']; ?></td>
                <td>
                  <div class="ef-picker" data-efid="<?php echo $efid; ?>" data-scopeno="<?php echo $sno; ?>">
                    <div class="chipbox" id="chipbox_<?php echo $efid; ?>"></div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                      <button type="button" class="btn-add-item" id="toggleBtn_<?php echo $efid; ?>" onclick="openTray(<?php echo $efid; ?>)">
                        <i class="bi bi-plus-circle me-1"></i>เพิ่มรายการ
                      </button>
                      <span class="btn-clear-chips" id="rowClear_<?php echo $efid; ?>" style="display:none;" onclick="clearRowChips(<?php echo $efid; ?>)">
                        <i class="bi bi-trash3 me-1"></i>ล้างทั้งหมด
                      </span>
                    </div>
                  </div>
                </td>
                <td id="status_<?php echo $efid; ?>" class="text-center">
                  <span class="badge-unlink">ว่าง</span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <!-- Save bar (link) -->
        <div class="save-bar px-3" id="linkSaveBar" style="<?php echo $unlinkCount===0 ? 'display:none;' : ''; ?>">
          <div class="d-flex align-items-center gap-3 flex-wrap">
            <div style="font-size:0.82rem;color:var(--cfp-text-muted);">
              เลือกแล้ว <strong id="selCount">0</strong> จาก <?php echo $unlinkCount; ?> รายการ
            </div>
            <div id="progressWrap" style="flex:1;min-width:100px;">
              <div style="font-size:0.72rem;color:var(--cfp-text-muted);margin-bottom:3px;">กำลังบันทึก...</div>
              <div style="background:var(--cfp-bg);border-radius:4px;height:6px;overflow:hidden;">
                <div id="progressBar" style="background:var(--cfp-primary);height:100%;width:0%;transition:width .3s;"></div>
              </div>
            </div>
            <div class="ms-auto">
              <button id="btnSave" class="btn-cfp-add" onclick="saveAll()">
                <i class="bi bi-check-circle me-1"></i>บันทึกทั้งหมด
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ Docked Tray (Option D) — แผงเดียวใช้ร่วมกันทุกแถว ลอยติดขอบล่างจอเสมอ ═══ -->
      <div class="ef-tray" id="efTray" style="display:none;">
        <div class="ef-tray-head">
          <div>
            <b id="trayEfName">—</b>
            <small id="trayEfMeta">—</small>
          </div>
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn-cfp-add btn-sm" onclick="saveAll()">
              <i class="bi bi-check-circle me-1"></i>บันทึกทั้งหมด
            </button>
            <span class="ef-tray-close" onclick="closeTray()"><i class="bi bi-x-lg"></i></span>
          </div>
        </div>
        <div class="ef-tray-body">
          <div>
            <div class="c-col-title">ค้นหา &amp; เลือก Activity Item</div>
            <input type="text" class="panel-search-full" id="traySearch" placeholder="🔍 พิมพ์ค้นหา Activity Item..."
                   oninput="cfpFilterPanel(currentTrayEfid, this.value)">
            <div class="panel-list" id="trayPanelList"></div>
          </div>
          <div>
            <div class="c-col-title" style="display:flex;justify-content:space-between;align-items:center;">
              <span>เลือกแล้ว (<span id="trayChipCount">0</span>)</span>
              <span class="btn-clear-chips" onclick="clearTrayChips()"><i class="bi bi-trash3 me-1"></i>ล้างทั้งหมด</span>
            </div>
            <div class="chipbox-expand" id="trayChipbox"></div>
          </div>
        </div>
      </div>

      <!-- ═══════════ SECTION 2: ผูกแล้ว / Unlink ═══════════ -->
      <div class="cfp-card" style="padding:0;overflow:hidden;">
        <div class="section-title" style="cursor:pointer;" onclick="toggleLinkedSection()">
          <i class="bi bi-database-check" style="color:#166534;"></i>
          EF ที่ผูกแล้ว — คลิกเพื่อดูและยกเลิกการผูก
          <span class="badge-linked ms-1"><?php echo $linkedCount; ?> รายการ</span>
          <i class="bi bi-chevron-down ms-auto" id="linkedChevron"></i>
        </div>
        <div id="linkedSection" style="display:none;">
          <?php if ($linkedCount === 0): ?>
          <div class="text-center py-4" style="color:var(--cfp-text-muted);font-size:0.85rem;">ยังไม่มีรายการที่ผูกแล้ว</div>
          <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="ef-table w-100" id="linkedTable">
              <thead>
                <tr>
                  <th style="min-width:220px;">ชื่อ EF</th>
                  <th style="width:56px;">Scope</th>
                  <th style="width:110px;">Category</th>
                  <th style="width:56px;">Gas</th>
                  <th style="width:90px;">ค่า EF</th>
                  <th style="width:50px;">ปี</th>
                  <th style="min-width:220px;">ผูกกับ Activity Item</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $prevGroupKey = null;
                foreach ($linkedGroupOrder as $efid):
                    $ef    = $linkedGroups[$efid]['ef'];
                    $scope = htmlspecialchars($ef['Scope'] ?? '');
                    $sno   = $scopeToNo[$ef['Scope']] ?? 0;
                    $cat   = $ef['Category'] ?? '';
                    $groupKey = $scope . '|' . $cat;
                    if ($groupKey !== $prevGroupKey) {
                        $prevGroupKey = $groupKey;
                        $catLabel = $catLabelMap[$cat] ?? ($cat ?: '—');
                        $grpColor = ($scope==='Scope1'?'#2AABB8':($scope==='Scope2'?'#F59E0B':'#8B5CF6'));
                        $grpIcon  = $catIconMap[$cat] ?? 'bi-collection';
                        $grpCnt   = $groupCountsLinked[$groupKey] ?? 0;
                        ?>
                <tr class="ef-group-header">
                  <td colspan="7">
                    <div class="ef-group-banner" style="--grp-color:<?php echo $grpColor; ?>;">
                      <div class="ic"><i class="bi <?php echo $grpIcon; ?>"></i></div>
                      <b><?php echo $scope; ?> — <?php echo htmlspecialchars($catLabel); ?></b>
                      <span class="cnt"><?php echo $grpCnt; ?> รายการ</span>
                    </div>
                  </td>
                </tr>
                <?php } ?>
                <tr data-efid="<?php echo $efid; ?>"
                    data-efcode="<?php echo htmlspecialchars($ef['EFCode'] ?? ''); ?>"
                    data-efnamedisplay="<?php echo htmlspecialchars($ef['EFName'] ?? ''); ?>"
                    data-efname="<?php echo htmlspecialchars(strtolower($ef['EFName'] ?? '')); ?>"
                    data-scope="<?php echo $scope; ?>"
                    data-scopeno="<?php echo $sno; ?>"
                    data-category="<?php echo htmlspecialchars($ef['Category'] ?? ''); ?>"
                    data-gastype="<?php echo htmlspecialchars($ef['GasType'] ?? ''); ?>"
                    data-efvalue="<?php echo (float)$ef['EFValue']; ?>"
                    data-unit="<?php echo htmlspecialchars($ef['Unit'] ?? ''); ?>"
                    data-year="<?php echo (int)$ef['YearApply']; ?>">
                  <td>
                    <div style="font-weight:500;white-space:nowrap;"><?php echo htmlspecialchars(cfpEfDisplayName($ef['EFName'])); ?></div>
                  </td>
                  <td>
                    <span style="font-size:0.7rem;padding:2px 8px;border-radius:10px;color:#fff;font-weight:600;background:<?php
                        echo ($scope==='Scope1'?'#2AABB8':($scope==='Scope2'?'#F59E0B':'#8B5CF6')); ?>;">
                      <?php echo $scope; ?>
                    </span>
                  </td>
                  <td style="font-size:0.66rem;color:var(--cfp-text-muted);white-space:nowrap;"><?php echo htmlspecialchars($cat ?: '—'); ?></td>
                  <td><span style="font-size:0.7rem;padding:1px 7px;border-radius:8px;color:#fff;font-weight:600;background:<?php echo $gasColors[$ef['GasType'] ?? ''] ?? '#888'; ?>;"><?php echo htmlspecialchars($ef['GasType'] ?? ''); ?></span></td>
                  <td style="font-family:monospace;font-size:0.82rem;color:var(--cfp-primary);font-weight:600;">
                    <?php echo number_format((float)$ef['EFValue'], 6); ?>
                    <?php if ($ef['Unit']) echo '<span style="font-size:0.72rem;color:var(--cfp-text-muted);margin-left:4px;">'.htmlspecialchars($ef['Unit']).'</span>'; ?>
                  </td>
                  <td style="font-size:0.82rem;"><?php echo (int)$ef['YearApply']; ?></td>
                  <td>
                    <div class="chipbox" id="chipbox_<?php echo $efid; ?>"></div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                      <button type="button" class="btn-add-item" style="padding:2px 10px;font-size:0.68rem;" onclick="openTray(<?php echo $efid; ?>)">
                        <i class="bi bi-plus-circle me-1"></i>เพิ่ม Item ให้ EF นี้
                      </button>
                      <span class="pending-add-badge" data-efid="<?php echo $efid; ?>"
                            style="display:none;font-size:0.68rem;color:#1D4ED8;font-weight:700;background:#EFF6FF;padding:2px 8px;border-radius:10px;white-space:nowrap;"></span>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="linked-unlink-bar" id="linkedUnlinkBar">
            <span style="font-size:0.82rem;color:#B91C1C;font-weight:600;">
              <i class="bi bi-unlink me-1"></i>เลือกยกเลิกการผูก (<span id="linkedUnlinkCount">0</span>)
            </span>
            <span class="btn-linked-unlink-clear" onclick="clearLinkedUnlinkSel()">ล้างที่เลือก</span>
            <button type="button" class="btn-cfp-add btn-cfp-add-danger btn-sm ms-auto" onclick="unlinkSelectedLinked()">
              <i class="bi bi-unlink me-1"></i>ยกเลิกการผูกที่เลือก
            </button>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- cfp-content -->
  </div><!-- cfp-main -->
</div>

<?php if ($toastMsg): ?>
<div id="cfpToast" class="position-fixed bottom-0 end-0 m-3 toast show align-items-center text-white border-0"
     style="background:<?php echo $toastType==='success'?'#2E7D32':'#B71C1C'; ?>;z-index:9999;min-width:260px;">
  <div class="d-flex">
    <div class="toast-body font-prompt"><?php echo htmlspecialchars($toastMsg); ?></div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest('.toast').remove()"></button>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
var ITEMS   = <?php echo json_encode($jsItems, JSON_UNESCAPED_UNICODE); ?>;
/* ItemID ที่มี EF ผูกอยู่แล้ว (จาก DB ตอนโหลดหน้า) — กัน autoMatch แนะนำซ้ำโดยไม่ตั้งใจ
   (1 Item ยังมี EF ได้แค่ตัวเดียว แม้ EF ตัวเดียวจะผูกได้หลาย Item ก็ตาม) */
var ALREADY_LINKED_ITEM_IDS = <?php echo json_encode(array_values(array_unique(array_map(function($r){ return (int)$r['ItemID']; }, $linkedRows)))); ?>;
/* efid -> [itemID, ...] ที่ผูกอยู่แล้วในนี้ตอนโหลดหน้า — ใช้ seed ค่าเริ่มต้นตอนเปิด tray
   จากตาราง "ผูกแล้ว" จะได้เห็น checkbox ติ๊กถูกต้องตามของเดิม ไม่ใช่เริ่มจากว่างเปล่า */
var EF_TO_ITEMS_MAP = <?php
    $map = array();
    foreach ($linkedRows as $r) {
        $map[(int)$r['EFID']][] = (int)$r['ItemID'];
    }
    echo json_encode($map);
?>;
/* efid ที่อยู่ในตาราง "ยังไม่ผูก" (Section 1) — ใช้แยกนับ KPI/save-bar ไม่ให้ปนกับ
   efid ที่เปิด tray จากตาราง "ผูกแล้ว" (Section 2) ซึ่งไม่ได้นับเป็น "เลือกใหม่" */
var SECTION1_EFIDS = <?php echo json_encode(array_values(array_map(function($r){ return (int)$r['EFID']; }, $efRows))); ?>.reduce(function(o,id){ o[id]=true; return o; }, {});
var CSRF    = '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>';
var pending    = {}; /* efid → [itemID, ...] (รอ link, EF เดียวผูกได้หลาย Item) */

/* ตัดคำซ้ำกับหัวข้อกลุ่ม (Stationary/On-road/Off-road) ออกจากชื่อ EF ตอนแสดงผล — คู่กับ cfpEfDisplayName() ฝั่ง PHP */
function cfpEfDisplayNameJs(name) {
    return (name || '').replace(/\s*\((Off-road|On-road|Stationary)\)\s*/i, ' ').replace(/\s+/g, ' ').trim();
}

/* ═══ SECTION 1: Link ═══ */

function filterScope(scope, btn) {
    document.querySelectorAll('.scope-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#efTable tbody tr').forEach(function(tr) {
        tr.style.display = (scope === 'all' || tr.dataset.scope === scope) ? '' : 'none';
    });
    closeTray(); /* กันแถวที่ tray เปิดอยู่หายไปจากการกรองแล้ว tray ค้างผิดที่ */
}

/* ═══ Docked Tray Picker — เลือก Activity Item ผูกกับ EF (Option D) ═══ */
var GRP_LABELS = {
    Stationary:'Stationary Combustion',
    'Mobile-OnRoad':'Mobile Combustion — On-road', 'Mobile-OffRoad':'Mobile Combustion — Off-road',
    'Mobile-Unclassified':'Mobile Combustion — ยังไม่ระบุ On/Off-road',
    Fugitive:'Fugitive Emissions', Process:'Process Emissions'
};
var GRP_ICONS  = {
    Stationary:'bi-fire',
    'Mobile-OnRoad':'bi-signpost-split', 'Mobile-OffRoad':'bi-cone-striped', 'Mobile-Unclassified':'bi-question-diamond',
    Fugitive:'bi-droplet-half', Process:'bi-gear-wide-connected'
};
var GRP_COLORS = {
    Stationary:'#2AABB8',
    'Mobile-OnRoad':'#1D4ED8', 'Mobile-OffRoad':'#C2410C', 'Mobile-Unclassified':'#9CA3AF',
    Fugitive:'#F59E0B', Process:'#8B5CF6'
};
/* key สำหรับจัดกลุ่ม/สีของ item ตัวหนึ่ง — Mobile แยกย่อยตาม roadType, ประเภทอื่นใช้ type ตรงๆ */
function itemGroupKey(it) {
    if (!it) { return ''; }
    if (it.type === 'Mobile') {
        return it.roadType === 'OnRoad' ? 'Mobile-OnRoad' :
               it.roadType === 'OffRoad' ? 'Mobile-OffRoad' : 'Mobile-Unclassified';
    }
    return it.type || 'อื่นๆ';
}
var currentTrayEfid = null; /* EF ที่ tray กำลังแสดงอยู่ตอนนี้ (มี tray เดียวใช้ร่วมกันทุกแถว) */

function itemById(id) {
    return ITEMS.filter(function(x) { return x.id === id; })[0];
}
function itemNameById(id) {
    var it = itemById(id);
    return it ? it.name : ('Item #' + id);
}
function itemChipColor(id) {
    var it = itemById(id);
    return (it && GRP_COLORS[itemGroupKey(it)]) || '#2AABB8';
}

function buildPanel(efid, scopeNo) {
    var list = document.getElementById('trayPanelList');
    if (!list) { return; }
    var items = ITEMS.filter(function(it) { return it.scopeNo === scopeNo; });
    var html = '';
    if (scopeNo === 1) {
        var groups = {};
        items.forEach(function(it) {
            var g = itemGroupKey(it);
            (groups[g] = groups[g] || []).push(it);
        });
        /* เรียงตามลำดับ Stationary -> Mobile (On-road -> Off-road -> ยังไม่ระบุ) -> Fugitive -> Process
           ตาม PDF/TGO ไม่ใช้ Object.keys() ตรงๆ เพราะลำดับจะขึ้นกับว่าเจอ Item ไหนก่อนในข้อมูลดิบ */
        var GRP_ORDER = ['Stationary', 'Mobile-OnRoad', 'Mobile-OffRoad', 'Mobile-Unclassified', 'Fugitive', 'Process'];
        var orderedKeys = GRP_ORDER.filter(function(g) { return groups[g]; })
            .concat(Object.keys(groups).filter(function(g) { return GRP_ORDER.indexOf(g) === -1; }));
        orderedKeys.forEach(function(g) {
            html += '<div class="panel-group" style="--grp-color:' + (GRP_COLORS[g] || '#888') + ';">' +
                    '<i class="bi ' + (GRP_ICONS[g] || 'bi-collection') + '"></i>' + (GRP_LABELS[g] || g) + '</div>';
            groups[g].forEach(function(it) { html += panelItemHtml(efid, it); });
        });
    } else {
        items.forEach(function(it) { html += panelItemHtml(efid, it); });
    }
    list.innerHTML = html || '<div class="panel-empty">ไม่มี Activity Item ในหมวดนี้</div>';
}

function panelItemHtml(efid, it) {
    var checked = (pending[efid] || []).indexOf(it.id) !== -1;
    return '<label class="panel-item" data-name="' + it.name.toLowerCase() + '">' +
           '<input type="checkbox" data-itemid="' + it.id + '" ' + (checked ? 'checked' : '') +
           ' onchange="togglePickItem(' + efid + ',' + it.id + ',this.checked)">' +
           it.name + '</label>';
}

/* เปิด/ปิด tray ที่ติดขอบล่างจอ — ใช้ร่วมกันทุกแถว เปลี่ยนเนื้อหาตาม efid ที่เลือก */
function openTray(efid) {
    var tr = document.querySelector('tr[data-efid="' + efid + '"]');
    if (!tr) { return; }
    /* Section 1 (ยังไม่ผูก) มี .ef-picker ให้อ่าน scopeNo, Section 2 (ผูกแล้ว) ไม่มี
       เลยอ่านจาก data-scopeno ของ <tr> เองเป็น fallback แทน ใช้ปุ่มนี้ได้ทั้ง 2 ตาราง */
    var pickerDiv = document.querySelector('.ef-picker[data-efid="' + efid + '"]');
    var scopeNo = pickerDiv ? parseInt(pickerDiv.dataset.scopeno) : parseInt(tr.dataset.scopeno);

    /* ถ้ายังไม่เคยแตะ efid นี้ใน session นี้เลย ให้ seed จาก Item ที่ผูกอยู่แล้วจริงใน DB
       (เฉพาะกรณีเปิดจากตาราง "ผูกแล้ว") กัน checkbox เริ่มว่างทั้งที่จริงมี Item ผูกอยู่แล้ว */
    if (!pending[efid] && EF_TO_ITEMS_MAP[efid]) {
        pending[efid] = EF_TO_ITEMS_MAP[efid].slice();
    }

    /* รีเซ็ตปุ่ม + chip สรุปของแถวก่อนหน้ากลับมาแสดงตามปกติ */
    document.querySelectorAll('.btn-add-item').forEach(function(b) {
        b.classList.remove('open');
        b.innerHTML = '<i class="bi bi-plus-circle me-1"></i>เพิ่มรายการ';
    });
    document.querySelectorAll('.chipbox').forEach(function(b) { b.style.display = ''; });

    currentTrayEfid = efid;
    document.getElementById('trayEfName').textContent = tr.dataset.efnamedisplay || '';
    document.getElementById('trayEfMeta').textContent =
        (tr.dataset.scope || '') + ' · ' + (tr.dataset.category || '—') + ' · ' +
        parseFloat(tr.dataset.efvalue).toFixed(4) + (tr.dataset.unit ? ' ' + tr.dataset.unit + '/หน่วย' : '');

    buildPanel(efid, scopeNo);
    renderChips(efid);

    var tray = document.getElementById('efTray');
    tray.style.display = 'flex';
    /* ซ่อน save-bar ชั่วคราวตอน tray เปิด กันซ้อนกันที่ขอบล่างจอ (คืนสถานะตอนปิด tray) */
    var saveBar = document.getElementById('linkSaveBar');
    if (saveBar) { saveBar.dataset.hiddenByTray = saveBar.style.display !== 'none' ? '1' : '0'; saveBar.style.display = 'none'; }

    var btn = document.getElementById('toggleBtn_' + efid);
    if (btn) { btn.classList.add('open'); btn.innerHTML = '<i class="bi bi-dash-circle me-1"></i>กำลังแก้ไข'; }
    var chipboxTop = document.getElementById('chipbox_' + efid);
    if (chipboxTop) { chipboxTop.style.display = 'none'; }
    var rowClearTop = document.getElementById('rowClear_' + efid);
    if (rowClearTop) { rowClearTop.style.display = 'none'; }

    var search = document.getElementById('traySearch');
    if (search) { search.value = ''; cfpFilterPanel(efid, ''); search.focus(); }

    tray.scrollIntoView({ behavior: 'smooth', block: 'end' });
}

function closeTray() {
    document.getElementById('efTray').style.display = 'none';
    document.querySelectorAll('.btn-add-item').forEach(function(b) {
        b.classList.remove('open');
        b.innerHTML = '<i class="bi bi-plus-circle me-1"></i>เพิ่มรายการ';
    });
    document.querySelectorAll('.chipbox').forEach(function(b) { b.style.display = ''; });
    var saveBar = document.getElementById('linkSaveBar');
    if (saveBar && saveBar.dataset.hiddenByTray === '1') { saveBar.style.display = ''; }
    var wasOpenEfid = currentTrayEfid;
    currentTrayEfid = null;
    /* คำนวณสถานะปุ่ม "ล้างทั้งหมด" ของแถวที่เพิ่งปิด tray ใหม่ให้ตรงกับ pending ปัจจุบัน */
    if (wasOpenEfid !== null) { renderChips(wasOpenEfid); }
}

function cfpFilterPanel(efid, kw) {
    kw = kw.trim().toLowerCase();
    var list = document.getElementById('trayPanelList');
    if (!list) { return; }
    list.querySelectorAll('.panel-item').forEach(function(row) {
        row.style.display = (kw === '' || row.dataset.name.indexOf(kw) !== -1) ? '' : 'none';
    });
    list.querySelectorAll('.panel-group').forEach(function(grp) {
        var next = grp.nextElementSibling, hasVisible = false;
        while (next && !next.classList.contains('panel-group')) {
            if (next.style.display !== 'none') { hasVisible = true; }
            next = next.nextElementSibling;
        }
        grp.style.display = hasVisible ? '' : 'none';
    });
}

function togglePickItem(efid, itemId, checked) {
    var arr = pending[efid] || [];
    if (checked) {
        if (arr.indexOf(itemId) === -1) { arr.push(itemId); }
    } else {
        arr = arr.filter(function(id) { return id !== itemId; });
    }
    if (arr.length > 0) { pending[efid] = arr; } else { delete pending[efid]; }
    renderChips(efid);
    updateKpi();
}

function removeChip(efid, itemId) {
    togglePickItem(efid, itemId, false);
    /* sync checkbox ใน tray panel ถ้ากำลังเปิดแสดง EF ตัวนี้อยู่ */
    if (currentTrayEfid === efid) {
        var list = document.getElementById('trayPanelList');
        if (list) {
            var cb = list.querySelector('input[data-itemid="' + itemId + '"]');
            if (cb) { cb.checked = false; }
        }
    }
}

/* ล้าง Item ที่เลือกไว้ทั้งหมดของ EF ที่ tray กำลังเปิดอยู่ในครั้งเดียว */
function clearTrayChips() {
    var efid = currentTrayEfid;
    if (efid === null) { return; }
    delete pending[efid];
    renderChips(efid);
    var list = document.getElementById('trayPanelList');
    if (list) { list.querySelectorAll('input[type="checkbox"]').forEach(function(cb) { cb.checked = false; }); }
    updateKpi();
}

/* ล้าง Item ที่เลือกไว้ของแถวนั้นๆ โดยตรงจากตาราง ไม่ต้องเปิด tray ก่อน */
function clearRowChips(efid) {
    delete pending[efid];
    renderChips(efid);
    if (currentTrayEfid === efid) {
        var list = document.getElementById('trayPanelList');
        if (list) { list.querySelectorAll('input[type="checkbox"]').forEach(function(cb) { cb.checked = false; }); }
    }
    updateKpi();
}

function renderChips(efid) {
    var box   = document.getElementById('chipbox_' + efid);
    var st    = document.getElementById('status_' + efid);
    var tr    = document.querySelector('tr[data-efid="' + efid + '"]');
    var arr   = pending[efid] || [];
    var isExistingLinkedEF = !!EF_TO_ITEMS_MAP[efid];
    var chipsHtml = arr.map(function(id) {
        return '<span class="item-chip" style="--chip-color:' + itemChipColor(id) + ';">' + itemNameById(id) +
               '<button type="button" onclick="removeChip(' + efid + ',' + id + ')">✕</button></span>';
    }).join('');
    /* แถว Section 2 (EF ที่ผูกอยู่แล้วจริงใน DB) มี chipbox แสดงรายการที่ผูกแล้ว
       ผ่าน renderLinkedChips() ซึ่งกด ✕ แล้วยกเลิกจริงทันที — ห้าม renderChips() มาทับด้วย pending (แค่ pending ในหน่วยความจำ ไม่ persist) */
    if (box && !isExistingLinkedEF) { box.innerHTML = chipsHtml; }
    var rowClear = document.getElementById('rowClear_' + efid);
    if (rowClear) { rowClear.style.display = arr.length > 0 ? '' : 'none'; }
    /* ถ้า tray กำลังเปิดแสดง EF ตัวนี้อยู่ ให้อัปเดต chip + count ใน tray ด้วย */
    if (currentTrayEfid === efid) {
        var boxTray = document.getElementById('trayChipbox');
        var cntEl   = document.getElementById('trayChipCount');
        if (boxTray) { boxTray.innerHTML = chipsHtml || '<span style="font-size:0.76rem;color:var(--cfp-text-muted);">ยังไม่ได้เลือกรายการ</span>'; }
        if (cntEl)   { cntEl.textContent = arr.length; }
    }
    if (arr.length > 0) {
        if (tr) { tr.classList.add('linked'); }
        if (st) { st.innerHTML = '<span class="badge-linked"><i class="bi bi-check2"></i> เลือกแล้ว (' + arr.length + ')</span>'; }
    } else {
        if (tr) { tr.classList.remove('linked'); }
        if (st) { st.innerHTML = '<span class="badge-unlink">ว่าง</span>'; }
    }

    /* ตาราง "ผูกแล้ว" (Section 2) ไม่มี chipbox/status ให้ต่อ — แสดง badge บอกจำนวนที่
       เพิ่มใหม่ยังไม่บันทึกแทน เทียบกับที่ผูกอยู่จริงใน DB ตอนโหลดหน้า (EF_TO_ITEMS_MAP) */
    var existingIds = EF_TO_ITEMS_MAP[efid] || [];
    var newCount = arr.filter(function(id) { return existingIds.indexOf(id) === -1; }).length;
    document.querySelectorAll('.pending-add-badge[data-efid="' + efid + '"]').forEach(function(badge) {
        if (newCount > 0) {
            badge.textContent = '+' + newCount + ' รอบันทึก';
            badge.style.display = '';
        } else {
            badge.style.display = 'none';
        }
    });
}

function updateKpi() {
    /* นับเฉพาะ efid ที่อยู่ในตาราง "ยังไม่ผูก" (Section 1) — efid จากตาราง "ผูกแล้ว"
       ที่ถูก seed เข้า pending ตอนเปิด tray ไม่ควรถูกนับเป็น "เลือกใหม่รอบันทึก" */
    var pCnt = Object.keys(pending).filter(function(id) { return SECTION1_EFIDS[id]; }).length;
    var kpiUnlink  = document.getElementById('kpiUnlink');
    var kpiPending = document.getElementById('kpiPending');
    var selCount   = document.getElementById('selCount');
    if (kpiUnlink)     { kpiUnlink.textContent  = <?php echo $unlinkCount; ?> - pCnt; }
    if (kpiPending)    { kpiPending.textContent  = pCnt; }
    if (selCount)      { selCount.textContent    = pCnt; }
}

/* ═══ Section 2 (ผูกแล้ว) — แสดง Item ที่ผูกอยู่จริงเป็น chip พร้อม checkbox เลือกเพื่อยกเลิกผูกทีละหลายรายการ ═══ */
var linkedUnlinkSel = {}; /* itemID → true (ติ๊กรอยกเลิกการผูก) */

function renderLinkedChips(efid) {
    var box = document.getElementById('chipbox_' + efid);
    if (!box) { return; }
    var ids = EF_TO_ITEMS_MAP[efid] || [];
    box.innerHTML = ids.map(function(id) {
        var checked = linkedUnlinkSel[id] ? 'checked' : '';
        return '<label class="item-chip item-chip-select" style="--chip-color:' + itemChipColor(id) + ';">' +
               '<input type="checkbox" ' + checked + ' onchange="toggleLinkedUnlinkSel(' + id + ',this.checked)">' +
               itemNameById(id) + '</label>';
    }).join('');
}

function initLinkedChips() {
    Object.keys(EF_TO_ITEMS_MAP).forEach(function(efid) { renderLinkedChips(parseInt(efid)); });
}

function toggleLinkedUnlinkSel(itemId, checked) {
    if (checked) { linkedUnlinkSel[itemId] = true; } else { delete linkedUnlinkSel[itemId]; }
    updateLinkedUnlinkBar();
}

function clearLinkedUnlinkSel() {
    linkedUnlinkSel = {};
    Object.keys(EF_TO_ITEMS_MAP).forEach(function(efid) { renderLinkedChips(parseInt(efid)); });
    updateLinkedUnlinkBar();
}

function updateLinkedUnlinkBar() {
    var ids = Object.keys(linkedUnlinkSel);
    var bar = document.getElementById('linkedUnlinkBar');
    var cnt = document.getElementById('linkedUnlinkCount');
    if (cnt) { cnt.textContent = ids.length; }
    if (bar) { bar.classList.toggle('show', ids.length > 0); }
}

function unlinkSelectedLinked() {
    var ids = Object.keys(linkedUnlinkSel).map(Number);
    if (ids.length === 0) { return; }
    Swal.fire({
        icon: 'warning',
        title: 'ยืนยันการยกเลิกการผูก',
        html: 'ยกเลิกการผูก <strong>' + ids.length + ' รายการ</strong>?<br><small style="color:#6B7280;">Item เหล่านี้จะไม่ผูกกับ EF อีกต่อไป</small>',
        showCancelButton: true,
        confirmButtonText: 'ยกเลิกการผูก',
        cancelButtonText: 'ปิด',
        confirmButtonColor: '#E05050',
        cancelButtonColor: '#6B7280'
    }).then(function(result) {
        if (!result.isConfirmed) { return; }
        doUnlink(ids, false);
    });
}

/* เทียบ Category ของ EF (Stationary/Mobile-OnRoad/Mobile-OffRoad/Fugitive/Process)
   กับ Scope1Type + MobileRoadType ของ Item — ตอนนี้ Item มีฟิลด์ MobileRoadType
   (OnRoad/OffRoad) แยกแล้ว จึงเทียบ Mobile-OnRoad/OffRoad แบบเป๊ะได้เลย
   ถ้า Item เป็น Mobile แต่ยังไม่ได้ระบุ OnRoad/OffRoad (roadType ว่าง) ถือว่าไม่ match
   (ไม่เดา — ต้องให้แอดมินไปกำหนดที่หน้า "รายการกิจกรรม" ก่อน) */
function cfpCategoryMatchesType(cat, it) {
    if (!cat || !it || !it.type) { return false; }
    cat = cat.toLowerCase();
    var type = it.type.toLowerCase();
    if (cat === 'mobile-onroad')  { return type === 'mobile' && it.roadType === 'OnRoad'; }
    if (cat === 'mobile-offroad') { return type === 'mobile' && it.roadType === 'OffRoad'; }
    return cat === type;
}

function autoMatch() {
    var matched = 0;
    /* Item ที่ถูกใช้ไปแล้ว — ทั้งจาก DB เดิม และที่เพิ่งจับคู่ในรอบ autoMatch นี้เอง
       กันไม่ให้ Gasohol E10/E20/E85 ฯลฯ แย่งกันจับ Item เดียวกันซ้ำ (ดีไซน์คือ 1 Item = 1 EF) */
    var takenItemIDs = {};
    ALREADY_LINKED_ITEM_IDS.forEach(function(id) { takenItemIDs[id] = true; });
    for (var pid in pending) { pending[pid].forEach(function(iid) { takenItemIDs[iid] = true; }); }

    document.querySelectorAll('#efTable tbody tr').forEach(function(tr) {
        if (tr.style.display === 'none') { return; }
        var efid    = parseInt(tr.dataset.efid);
        var efname  = tr.dataset.efname;
        var scopeNo = parseInt(tr.dataset.scopeno);
        var cat     = (tr.dataset.category || '').toLowerCase();

        var candidates = ITEMS.filter(function(it) { return it.scopeNo === scopeNo && !takenItemIDs[it.id]; });
        if (scopeNo === 1 && cat) {
            /* กรองแบบเข้ม (hard filter) ไม่ fallback ไปหมวดอื่นอีกต่อไป —
               ถ้าไม่มี Item ในหมวดที่ตรงกันเลย แปลว่ายังไม่ควรแนะนำอะไร ดีกว่าแนะนำผิดหมวด */
            candidates = candidates.filter(function(it) { return cfpCategoryMatchesType(cat, it); });
        }
        if (candidates.length === 0) { return; }

        var best = null, bestScore = 0;
        candidates.forEach(function(it) {
            var iname = it.name.toLowerCase();
            var score = 0;
            if (efname === iname) { score = 100; }
            else if (efname.indexOf(iname) !== -1 || iname.indexOf(efname) !== -1) { score = 80; }
            else {
                var ew = efname.split(/\s+/);
                var iw = iname.split(/\s+/);
                var overlap = ew.filter(function(w) { return w.length > 2 && iw.indexOf(w) !== -1; }).length;
                score = overlap * 20;
            }
            if (score > bestScore) { bestScore = score; best = it; }
        });

        if (best && bestScore >= 20) {
            togglePickItem(efid, best.id, true);
            matched++; takenItemIDs[best.id] = true;
        }
    });
    Swal.fire({ icon: 'info', title: 'Auto-match เสร็จสิ้น', text: 'จับคู่ได้ ' + matched + ' รายการ — กรุณาตรวจสอบก่อนบันทึก', confirmButtonColor: '#1B3A4A' });
}

function clearLinkSel() {
    document.querySelectorAll('.ef-picker').forEach(function(box) {
        var efid = parseInt(box.dataset.efid);
        if (isNaN(efid)) { return; }
        delete pending[efid];
        renderChips(efid);
    });
    closeTray();
    updateKpi();
}

function saveAll() {
    var pairs = [];
    for (var efid in pending) {
        pending[efid].forEach(function(itemID) {
            pairs.push({ efid: parseInt(efid), itemID: itemID });
        });
    }
    if (pairs.length === 0) {
        Swal.fire({ icon: 'warning', title: 'ยังไม่ได้เลือก', text: 'กรุณาเลือก Activity Item ก่อนบันทึก', confirmButtonColor: '#1B3A4A' });
        return;
    }
    Swal.fire({
        icon: 'question',
        title: 'ยืนยันการบันทึก',
        text: 'บันทึกการผูก ' + pairs.length + ' รายการ?',
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#1B3A4A',
        cancelButtonColor: '#6B7280'
    }).then(function(result) {
        if (!result.isConfirmed) { return; }

        var btn = document.getElementById('btnSave');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>กำลังบันทึก...';
        document.getElementById('progressWrap').style.display = 'block';

        fetch('ef_link_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF, action: 'link', pairs: pairs })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('progressBar').style.width = '100%';
            if (data.ok) {
                /* รีเฟรชหน้าอัตโนมัติแทนการแก้ DOM เอง — เพราะตอนนี้มีหัวข้อกลุ่ม/จำนวนรายการต่อกลุ่ม
                   ที่คำนวณฝั่ง PHP ตอนโหลดหน้า ถ้าแก้ DOM เองจะไม่ sync กับตัวเลขกลุ่มให้ครบ */
                showToast('บันทึกสำเร็จ ' + data.saved + ' รายการ กำลังรีเฟรชหน้า...', 'success');
                setTimeout(function () { location.reload(); }, 900);
            } else {
                showToast('เกิดข้อผิดพลาด: ' + (data.error || 'ไม่ทราบสาเหตุ'), 'error');
            }
        })
        .catch(function(e) { showToast('เชื่อมต่อไม่ได้: ' + e.message, 'error'); })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-save me-1"></i>บันทึกทั้งหมด';
            setTimeout(function() { document.getElementById('progressWrap').style.display = 'none'; }, 1200);
        });
    });
}

/* ═══ SECTION 2: Unlink ═══ */

function toggleLinkedSection() {
    var sec = document.getElementById('linkedSection');
    var chv = document.getElementById('linkedChevron');
    var open = sec.style.display !== 'none';
    sec.style.display = open ? 'none' : '';
    chv.className = open ? 'bi bi-chevron-down ms-auto' : 'bi bi-chevron-up ms-auto';
}


function doUnlink(ids, force) {
    fetch('ef_link_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: CSRF, action: 'unlink', itemIds: ids, force: force })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.requireConfirm) {
            Swal.fire({
                icon: 'warning',
                title: 'พบข้อมูล Draft ที่ใช้ EF นี้อยู่',
                html: 'มี <strong>' + data.draftCount + ' รายการ Draft</strong> ที่ใช้ EF ที่เลือกอยู่<br>' +
                      '<small style="color:#6B7280;">ถ้ายังคง Unlink และผู้ใช้บันทึกข้อมูล Draft นั้นใหม่ CO2e จะเป็น null</small>',
                showCancelButton: true,
                confirmButtonText: 'ยืนยัน ยกเลิกการผูก',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#E05050',
                cancelButtonColor: '#6B7280'
            }).then(function(r) {
                if (r.isConfirmed) { doUnlink(ids, true); }
            });
            return;
        }
        if (data.ok) {
            /* รีเฟรชหน้าอัตโนมัติ — เหตุผลเดียวกับ saveAll() (หัวข้อกลุ่ม/จำนวนต่อกลุ่มคำนวณฝั่ง PHP) */
            showToast('ยกเลิกการผูกสำเร็จ ' + data.unlinked + ' รายการ กำลังรีเฟรชหน้า...', 'success');
            setTimeout(function () { location.reload(); }, 900);
        } else {
            showToast('เกิดข้อผิดพลาด: ' + (data.error || 'ไม่ทราบสาเหตุ'), 'error');
        }
    })
    .catch(function(e) { showToast('เชื่อมต่อไม่ได้: ' + e.message, 'error'); });
}

function appendToSection1(srcTr) {
    var efid     = srcTr.dataset.efid;
    var efcode   = srcTr.dataset.efcode   || '';
    var efname   = srcTr.dataset.efnamedisplay || '';
    var efnameLc = srcTr.dataset.efname   || '';
    var scope    = srcTr.dataset.scope    || '';
    var scopeNo  = parseInt(srcTr.dataset.scopeno) || 0;
    var cat      = srcTr.dataset.category || '';
    var gasType  = srcTr.dataset.gastype  || '';
    var efval    = parseFloat(srcTr.dataset.efvalue) || 0;
    var unit     = srcTr.dataset.unit     || '';
    var year     = srcTr.dataset.year     || '';

    var scopeColor = scope === 'Scope1' ? '#2AABB8' : (scope === 'Scope2' ? '#F59E0B' : '#8B5CF6');
    var GAS_COLORS = { CO2:'#3B82F6', CH4:'#EC4899', N2O:'#84CC16', HFCs:'#B45309', CO2e:'#16A34A' };
    var gasColor = GAS_COLORS[gasType] || '#888';

    /* สร้าง dropdown options filter ตาม scopeNo */
    var opts = '<option value="">— ไม่ผูก —</option>';
    var groups = {};
    ITEMS.forEach(function(it) {
        if (it.scopeNo !== scopeNo) { return; }
        var grpKey = scopeNo === 1 ? (it.type || 'อื่นๆ') : 'items';
        if (!groups[grpKey]) { groups[grpKey] = []; }
        groups[grpKey].push(it);
    });
    var grpLabels = { Stationary: 'Stationary Combustion', Mobile: 'Mobile Combustion', Fugitive: 'Fugitive Emissions', Process: 'Process Emissions' };
    Object.keys(groups).forEach(function(grp) {
        var label = grpLabels[grp] || grp;
        if (scopeNo === 1) { opts += '<optgroup label="' + label + '">'; }
        groups[grp].forEach(function(it) {
            opts += '<option value="' + it.id + '">' + it.code + ' — ' + it.name + '</option>';
        });
        if (scopeNo === 1) { opts += '</optgroup>'; }
    });

    var tbody = document.querySelector('#efTable tbody');
    if (!tbody) { return; }

    /* นับลำดับที่ */
    var rowCount = tbody.querySelectorAll('tr').length + 1;

    var tr = document.createElement('tr');
    tr.dataset.efid     = efid;
    tr.dataset.scope    = scope;
    tr.dataset.scopeno  = scopeNo;
    tr.dataset.category = cat;
    tr.dataset.efname   = efnameLc;
    tr.innerHTML =
        '<td style="color:var(--cfp-text-muted);font-size:0.72rem;">' + rowCount + '</td>' +
        '<td><div style="font-weight:500;white-space:nowrap;">' + cfpEfDisplayNameJs(efname) + '</div></td>' +
        '<td><span style="font-size:0.7rem;padding:2px 8px;border-radius:10px;color:#fff;font-weight:600;background:' + scopeColor + ';">' + scope + '</span></td>' +
        '<td style="font-size:0.66rem;color:var(--cfp-text-muted);white-space:nowrap;">' + (cat || '—') + '</td>' +
        '<td><span style="font-size:0.7rem;padding:1px 7px;border-radius:8px;color:#fff;font-weight:600;background:' + gasColor + ';">' + gasType + '</span></td>' +
        '<td style="font-family:monospace;font-size:0.82rem;color:var(--cfp-primary);font-weight:600;">' +
            efval.toFixed(6) +
            (unit ? '<div style="font-size:0.68rem;color:var(--cfp-text-muted);">' + unit + '</div>' : '') +
        '</td>' +
        '<td style="font-size:0.82rem;">' + year + '</td>' +
        '<td><select class="item-sel" id="sel_' + efid + '" data-efid="' + efid + '" onchange="onSelChange(this,' + efid + ')">' + opts + '</select></td>' +
        '<td id="status_' + efid + '" class="text-center"><span class="badge-unlink">ว่าง</span></td>';

    tbody.appendChild(tr);

    /* แสดง save bar และอัปเดต counter */
    document.getElementById('linkSaveBar').style.display = '';
}

function appendToLinkedTable(srcTr, itemID) {
    var efid   = srcTr.dataset.efid;
    var efcode = srcTr.dataset.efcode        || '';
    var efname = srcTr.dataset.efnamedisplay || '';
    var scope  = srcTr.dataset.scope         || '';
    var efval  = parseFloat(srcTr.dataset.efvalue) || 0;
    var unit   = srcTr.dataset.unit          || '';
    var year   = srcTr.dataset.year          || '';

    var item = ITEMS.filter(function (it) { return it.id === parseInt(itemID); })[0];
    var itemCode = item ? item.code : '';
    var itemName = item ? item.name : '';

    var scopeColor = scope === 'Scope1' ? '#2AABB8' : (scope === 'Scope2' ? '#F59E0B' : '#8B5CF6');

    var tbody = document.querySelector('#linkedTable tbody');
    if (!tbody) { return; }

    var tr = document.createElement('tr');
    tr.dataset.efid           = efid;
    tr.dataset.efcode         = efcode;
    tr.dataset.efnamedisplay  = efname;
    tr.dataset.efname         = efname.toLowerCase();
    tr.dataset.scope          = scope;
    tr.dataset.category       = srcTr.dataset.category || '';
    tr.dataset.gastype        = srcTr.dataset.gastype   || '';
    tr.dataset.efvalue        = efval;
    tr.dataset.unit           = unit;
    tr.dataset.year           = year;
    tr.innerHTML =
        '<td class="text-center"><input type="checkbox" class="chk-unlink" value="' + efid + '" onchange="onUnlinkCheck(this)"></td>' +
        '<td><div style="font-weight:500;white-space:nowrap;">' + cfpEfDisplayNameJs(efname) + '</div></td>' +
        '<td><span style="font-size:0.7rem;padding:2px 8px;border-radius:10px;color:#fff;font-weight:600;background:' + scopeColor + ';">' + scope + '</span></td>' +
        '<td style="font-family:monospace;font-size:0.82rem;color:var(--cfp-primary);font-weight:600;">' +
            efval.toFixed(6) +
            (unit ? '<span style="font-size:0.72rem;color:var(--cfp-text-muted);margin-left:4px;">' + unit + '</span>' : '') +
        '</td>' +
        '<td style="font-size:0.82rem;">' + year + '</td>' +
        '<td>' + (itemCode
            ? '<span class="badge-linked"><i class="bi bi-link-45deg"></i>' + itemName + '</span>'
            : '<span style="font-size:0.72rem;color:var(--cfp-text-muted);">— ไม่พบ Item —</span>') + '</td>';

    tbody.appendChild(tr);
}

function showToast(msg, type) {
    var t = document.createElement('div');
    t.className = 'position-fixed bottom-0 end-0 m-3 toast show align-items-center text-white border-0';
    t.style.cssText = 'background:' + (type==='success'?'#2E7D32':'#B71C1C') + ';z-index:9999;min-width:260px;';
    t.innerHTML = '<div class="d-flex"><div class="toast-body font-prompt">' + msg +
        '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest(\'.toast\').remove()"></button></div>';
    document.body.appendChild(t);
    setTimeout(function() { t.remove(); }, 4000);
}

initLinkedChips();
</script>
</body>
</html>

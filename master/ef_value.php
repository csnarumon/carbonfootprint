<?php
/* ==============================================
   master/ef_value.php
   จัดการค่า Emission Factor (EF) แยกตามปี
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

/* ===== Import Result ===== */
$importResult = null;
if (!empty($_SESSION['import_result'])) {
    $importResult = $_SESSION['import_result'];
    unset($_SESSION['import_result']);
}

/* ===== EFSource dropdown (เฉพาะแหล่งที่มีค่า EF ใช้งานอยู่จริง) ===== */
$resSrc = sqlsrv_query($conn,
    "SELECT DISTINCT s.SourceID, s.SourceCode, s.SourceName, s.YearApply
     FROM CFP_EFSource s
     WHERE s.IsActive=1
       AND EXISTS (SELECT 1 FROM CFP_EFValue e WHERE e.SourceID=s.SourceID AND e.IsActive=1)
     ORDER BY s.YearApply DESC, s.SourceCode");
$sources = array();
while ($r = sqlsrv_fetch_array($resSrc, SQLSRV_FETCH_ASSOC)) { $sources[] = $r; }

/* ===== ดึง EF Values — ผูกกับ Item ได้หลายตัว (many-to-one ผ่าน CFP_ActivityItem.EFID)
   ใช้ STRING_AGG รวมชื่อ Item ทั้งหมดที่ผูกกับ EF แถวนี้ไว้ในคอลัมน์เดียว ===== */
$res  = sqlsrv_query($conn, "
    SELECT e.EFID, e.EFCode, e.EFName, e.EFValue, e.GWP, e.Unit,
           e.Scope, e.Category, e.GasType, e.YearApply, e.ValidUntil, e.EffectiveDate,
           e.SourceID, e.IsActive,
           e.CreatedDate, e.PreviousEFID,
           s.SourceCode, s.SourceName,
           li.LinkedItemNames, li.LinkedItemCount
    FROM CFP_EFValue e
    LEFT JOIN CFP_EFSource s ON e.SourceID = s.SourceID
    OUTER APPLY (
        /* ใช้ FOR XML PATH แทน STRING_AGG เพราะ SQL Server instance นี้ compatibility level
           ไม่รองรับ STRING_AGG (ต้องการ 2017+/level 130) — FOR XML PATH ใช้ได้ทุกเวอร์ชัน */
        SELECT STUFF((
            SELECT ', ' + a2.ItemName
            FROM CFP_ActivityItem a2
            WHERE a2.EFID = e.EFID
            FOR XML PATH('')
        ), 1, 2, '') AS LinkedItemNames,
        (SELECT COUNT(*) FROM CFP_ActivityItem a3 WHERE a3.EFID = e.EFID) AS LinkedItemCount
    ) li
    ORDER BY e.Scope, CASE e.Category
        WHEN 'Stationary' THEN 1
        WHEN 'Mobile-OffRoad' THEN 2
        WHEN 'Mobile-OnRoad' THEN 3
        WHEN 'Fugitive' THEN 4
        WHEN 'Process' THEN 5
        WHEN 'Electricity' THEN 6
        ELSE 99 END, e.YearApply DESC, e.EFName");
$rows = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }

/* ===== KPI ===== */
$total    = count($rows);
$active   = 0; $inactive = 0;
foreach ($rows as $r) { if ($r['IsActive']) { $active++; } else { $inactive++; } }
$usedCount = 0;
$resUsed = sqlsrv_query($conn, "SELECT COUNT(DISTINCT EFID) AS Cnt FROM CFP_ActivityData WHERE EFID IS NOT NULL");
if ($resUsed) { $rU = sqlsrv_fetch_array($resUsed, SQLSRV_FETCH_ASSOC); $usedCount = $rU ? (int)$rU['Cnt'] : 0; }

/* ===== Labels ===== */
$scopeColors = array('Scope1'=>'#2AABB8','Scope2'=>'#F59E0B','Scope3'=>'#8B5CF6');
$gasColors   = array('CO2'=>'#3B82F6','CH4'=>'#EC4899','N2O'=>'#84CC16','HFCs'=>'#B45309','CO2e'=>'#16A34A');
/* ไอคอนต่อหมวด สำหรับหัวข้อกลุ่ม (แบบเดียวกับ ef_link.php) */
$catIconMap = array(
    'Stationary'     => 'bi-fire',
    'Mobile-OnRoad'  => 'bi-truck',
    'Mobile-OffRoad' => 'bi-truck-flatbed',
    'Fugitive'       => 'bi-droplet-half',
    'Process'        => 'bi-gear-wide-connected',
    'Electricity'    => 'bi-lightning-charge-fill',
);

/* ชื่อกลุ่มอ้างอิงตามหัวข้อจริงใน Emission Factor_2569.pdf — ใช้จัดกลุ่มแถวใน DataTables (RowGroup) */
$catLabelMap = array(
    'Stationary'     => 'Stationary Source',
    'Mobile-OnRoad'  => 'Mobile Source — On-road vehicles',
    'Mobile-OffRoad' => 'Mobile Source — Off-road vehicles/mobile equipment',
    'Fugitive'       => 'Fugitive Emissions',
    'Process'        => 'Process Emissions',
    'Electricity'    => 'Electricity, grid mix',
);
function cfpEfGroupLabel($scope, $cat, $catLabelMap) {
    $label = $catLabelMap[$cat] ?? ($cat ?: 'อื่นๆ');
    return $scope . ' — ' . $label;
}
/* ลำดับกลุ่มให้ตรงกับ Emission Factor_2569.pdf (Stationary -> Mobile Off-road -> Mobile On-road -> ...)
   ใช้เป็นคีย์ sort ที่ซ่อนไว้ให้ DataTables เรียงตามนี้แทนการเรียงตัวอักษร (ซึ่งจะเอา Mobile ขึ้นก่อน Stationary) */
$catSortMap = array(
    'Stationary' => 1, 'Mobile-OffRoad' => 2, 'Mobile-OnRoad' => 3,
    'Fugitive' => 4, 'Process' => 5, 'Electricity' => 6,
);
$scopeSortMap = array('Scope1' => 1, 'Scope2' => 2, 'Scope3' => 3);
function cfpEfGroupSort($scope, $cat, $scopeSortMap, $catSortMap) {
    $sw = $scopeSortMap[$scope] ?? 9;
    $cw = $catSortMap[$cat] ?? 99;
    return $sw * 100 + $cw;
}
/* ตัดคำซ้ำกับหัวข้อกลุ่ม (Stationary/On-road/Off-road) ออกจากชื่อ EF ตอนแสดงผล
   เพราะกลุ่มด้านบนบอกอยู่แล้ว ไม่ต้องพูดซ้ำในชื่อรายการ */
function cfpEfDisplayName($name) {
    $name = preg_replace('/\s*\((Off-road|On-road|Stationary)\)\s*/i', ' ', $name);
    return trim(preg_replace('/\s+/', ' ', $name));
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ค่า Emission Factor — GHG Management System</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/rowgroup/1.4.1/css/rowGroup.dataTables.min.css" rel="stylesheet">
  <link href="../assets/css/cfp-theme.css?v=<?php echo filemtime('../assets/css/cfp-theme.css'); ?>" rel="stylesheet">
  <style>
    body { font-family:'Prompt',sans-serif; }
    .font-prompt { font-family:'Prompt',sans-serif !important; }
    .kpi-icon-box { width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0; }
    .btn-action { width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;font-size:0.8rem; }
    .status-dot { width:8px;height:8px;border-radius:50%;display:inline-block; }
    /* หัวกลุ่ม — ตั้งใจให้ต่างจาก ef_link.php เล็กน้อย (accent ซ้ายแทนบน + badge สีทึบ)
       กันดูซ้ำกันเกินไปทั้งที่เป็นคนละหน้าที่ใช้งาน
       sticky ใต้ topbar (58px) เหมือน ef_link.php — ต้องใช้ background ทึบแทน transparent ตอน sticky
       กันเนื้อหาแถวอื่นเลื่อนทะลุขึ้นมาซ้อนให้เห็น */
    tr.dtrg-group td { padding:6px 0 !important; border-top:none !important; background:#fff !important; position:sticky; top:58px; z-index:20; }
    .ef-group-banner {
      display:flex; align-items:center; gap:10px; padding:9px 14px;
      border-left:4px solid var(--grp-color, #2AABB8);
      border-radius:8px;
      background:var(--cfp-bg,#F3F8F9);
    }
    .ef-group-banner .ic {
      width:24px; height:24px; border-radius:50%; flex-shrink:0;
      background:#fff; color:var(--grp-color, #2AABB8); border:1.5px solid var(--grp-color, #2AABB8);
      display:flex; align-items:center; justify-content:center; font-size:0.72rem;
    }
    .ef-group-banner b { font-size:0.86rem; color:var(--cfp-text); font-weight:600; }
    .ef-group-banner .cnt {
      margin-left:auto; font-size:0.68rem; font-weight:700; color:#fff;
      background:var(--grp-color, #2AABB8); padding:2px 10px; border-radius:20px;
      white-space:nowrap;
    }
    .scope-badge { display:inline-block;padding:2px 8px;border-radius:10px;font-size:0.72rem;font-weight:600;color:#fff; }
    .gas-badge { display:inline-block;padding:1px 7px;border-radius:8px;font-size:0.7rem;font-weight:600;color:#fff; }
    .ef-value { font-family:monospace;font-size:0.85rem;font-weight:600;color:var(--cfp-primary); }
  </style>
</head>
<body>
<div class="d-flex">
  <?php include '../includes/sidebar.php'; ?>
  <div class="cfp-main">
    <?php $pageTitle='ค่า Emission Factor (EF)'; $pageIcon='database'; include '../includes/topbar.php'; ?>
    <div class="cfp-content">

      <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
          <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);">
            <i class="bi bi-database me-2"></i>จัดการค่า Emission Factor
          </h5>
          <div style="font-size:0.78rem;color:var(--cfp-text-muted);margin-top:2px;">
            การตั้งค่าข้อมูลพื้นฐาน › ค่า EF แยกตามปี / แหล่งอ้างอิง
          </div>
        </div>
      </div>

      <!-- KPI -->
      <div class="row g-3 mb-3">
        <?php
        $kpis = array(
            array('icon'=>'database',         'color'=>'#2AABB8','bg'=>'#E4F7F9','val'=>$total,     'label'=>'รายการทั้งหมด'),
            array('icon'=>'check-circle-fill','color'=>'#2E7D32','bg'=>'#E8F5E9','val'=>$active,    'label'=>'ใช้งานอยู่'),
            array('icon'=>'slash-circle',     'color'=>'#9E9E9E','bg'=>'#F5F5F5','val'=>$inactive,  'label'=>'ปิดใช้งาน'),
            array('icon'=>'link-45deg',       'color'=>'#8B5CF6','bg'=>'#F3EEFF','val'=>$usedCount, 'label'=>'ถูกใช้คำนวณแล้ว'),
        );
        foreach ($kpis as $k) { ?>
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:<?php echo $k['bg']; ?>;">
              <i class="bi bi-<?php echo $k['icon']; ?>" style="color:<?php echo $k['color']; ?>;"></i>
            </div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:<?php echo $k['color']; ?>;line-height:1.1;"><?php echo $k['val']; ?></div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);"><?php echo $k['label']; ?></div>
            </div>
          </div>
        </div>
        <?php } ?>
      </div>

      <!-- Table Card -->
      <div class="cfp-card">
        <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);margin-bottom:12px;">
          <i class="bi bi-database me-2"></i>รายการค่า Emission Factor
        </div>

        <!-- Toolbar -->
        <div class="cfp-page-toolbar mb-3">
          <div class="d-flex gap-2 flex-wrap flex-grow-1">
            <div class="cfp-search-wrap flex-grow-1" style="position:relative;">
            <input type="text" id="fltKeyword" class="form-control font-prompt" style="padding-right:28px;"
                   style="font-size:0.85rem;min-width:160px;" placeholder="ค้นหารหัส / ชื่อ EF...">
            <button type="button" class="cfp-search-clear" onclick="clearKeyword()" title="ล้างคำค้นหา" style="display:none;position:absolute;right:6px;top:50%;transform:translateY(-50%);border:none;background:none;padding:2px;line-height:1;color:var(--cfp-text-muted,#888);font-size:0.95rem;cursor:pointer;z-index:2;"><i class="bi bi-x-circle-fill"></i></button>
            </div>
            <select id="fltScope" class="form-select font-prompt" style="font-size:0.85rem;max-width:130px;">
              <option value="">ทุก Scope</option>
              <option value="Scope1">Scope 1</option>
              <option value="Scope2">Scope 2</option>
              <option value="Scope3">Scope 3</option>
            </select>
            <select id="fltYear" class="form-select font-prompt" style="font-size:0.85rem;max-width:110px;">
              <option value="">ทุกปี</option>
              <?php
              $years = array_unique(array_column($rows, 'YearApply'));
              rsort($years);
              foreach ($years as $y) { if ($y) { echo '<option value="'.$y.'">'.$y.'</option>'; } }
              ?>
            </select>
            <select id="fltSource" class="form-select font-prompt" style="font-size:0.85rem;max-width:150px;">
              <option value="">ทุกแหล่งอ้างอิง</option>
              <?php foreach ($sources as $s) { ?>
              <option value="<?php echo $s['SourceID']; ?>"><?php echo htmlspecialchars($s['SourceCode']); ?></option>
              <?php } ?>
            </select>
            <select id="fltStatus" class="form-select font-prompt" style="font-size:0.85rem;max-width:120px;">
              <option value="">สถานะทั้งหมด</option>
              <option value="1">ใช้งาน</option>
              <option value="0">ปิด</option>
            </select>
            <button class="btn btn-outline-secondary btn-sm" onclick="clearFilter()">
              <i class="bi bi-x-circle me-1"></i>ล้าง
            </button>
          </div>
          <div class="cfp-page-toolbar-actions">
            <?php
            $resUnlink = sqlsrv_query($conn, "
                SELECT COUNT(*) AS Cnt FROM CFP_EFValue e
                WHERE e.IsActive=1 AND NOT EXISTS (SELECT 1 FROM CFP_ActivityItem ai WHERE ai.EFID = e.EFID)");
            $unlinkCnt = 0;
            if ($resUnlink) { $rU = sqlsrv_fetch_array($resUnlink, SQLSRV_FETCH_ASSOC); $unlinkCnt = (int)($rU['Cnt'] ?? 0); }
            ?>
            <?php if ($unlinkCnt > 0): ?>
            <a href="ef_link.php" class="btn btn-sm font-prompt"
               style="background:#F59E0B;color:#fff;font-size:0.78rem;display:inline-flex;align-items:center;gap:5px;border-radius:7px;padding:5px 14px;">
              <i class="bi bi-link-45deg"></i>ผูก Activity Item
              <span style="background:rgba(0,0,0,.2);border-radius:8px;padding:0 6px;font-size:0.68rem;"><?php echo $unlinkCnt; ?></span>
            </a>
            <?php endif; ?>
            <button class="btn-cfp-import" onclick="openImportModal()">
              <i class="bi bi-file-earmark-spreadsheet"></i>Import Excel
            </button>
            <button class="btn-cfp-add" onclick="openModal(0)">
              <i class="bi bi-plus-circle"></i>เพิ่มค่า EF
            </button>
          </div>
        </div>

        <!-- Table -->
        <div id="tblEF-wrap">
          <table id="tblEF" class="table table-bordered table-hover align-middle" style="width:100%;font-size:0.82rem;">
            <thead>
              <tr>
                <th class="cfp-th-expand"></th>
                <th class="cfp-th-num" style="width:32px;">#</th>
                <th class="d-none">กลุ่ม</th>
                <th class="d-none">ลำดับกลุ่ม</th>
                <th style="min-width:280px;">ชื่อ EF / รายการ</th>
                <th class="cfp-col-hide text-center" style="width:56px;">Scope</th>
                <th class="cfp-col-hide text-center" style="width:60px;">Gas</th>
                <th class="cfp-col-hide text-center" style="width:95px;">ค่า EF</th>
                <th class="cfp-col-hide text-center" style="width:50px;">GWP</th>
                <th class="cfp-col-hide text-center" style="width:56px;">ปี</th>
                <th class="cfp-col-hide text-center" style="width:100px;">แหล่งอ้างอิง</th>
                <th style="width:72px;" class="text-center">สถานะ</th>
                <th class="cfp-col-hide text-center" style="width:110px;white-space:nowrap;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $i => $r) {
                $sColor = $scopeColors[$r['Scope']] ?? '#999';
                $gColor = $gasColors[$r['GasType']] ?? '#888';
                $groupLabel = cfpEfGroupLabel($r['Scope'] ?? '', $r['Category'] ?? '', $catLabelMap);
                $groupSort  = cfpEfGroupSort($r['Scope'] ?? '', $r['Category'] ?? '', $scopeSortMap, $catSortMap);
              ?>
              <tr data-status="<?php echo $r['IsActive']?'1':'0'; ?>"
                  data-scope="<?php echo htmlspecialchars($r['Scope']??''); ?>"
                  data-category="<?php echo htmlspecialchars($r['Category']??''); ?>"
                  data-year="<?php echo $r['YearApply']??''; ?>"
                  data-source="<?php echo $r['SourceID']??''; ?>">
                <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
                <td class="cfp-td-num"><?php echo $i+1; ?></td>
                <td class="d-none"><?php echo htmlspecialchars($groupLabel); ?></td>
                <td class="d-none"><?php echo (int)$groupSort; ?></td>
                <td>
                  <div style="font-weight:500;"><?php echo htmlspecialchars(cfpEfDisplayName($r['EFName'])); ?></div>
                  <?php if (!empty($r['LinkedItemNames'])) { ?>
                  <div style="font-size:0.7rem;color:var(--cfp-text-muted);" title="<?php echo htmlspecialchars($r['LinkedItemNames']); ?>">
                    <i class="bi bi-link me-1"></i><?php echo htmlspecialchars($r['LinkedItemNames']); ?>
                    <?php if ((int)($r['LinkedItemCount'] ?? 0) > 1) { ?>
                    <span style="font-weight:600;">(<?php echo (int)$r['LinkedItemCount']; ?> รายการ)</span>
                    <?php } ?>
                  </div>
                  <?php } ?>
                </td>
                <td class="cfp-col-hide text-center">
                  <?php if ($r['Scope']) { ?>
                  <span class="scope-badge" style="background:<?php echo $sColor; ?>;">
                    <?php echo htmlspecialchars($r['Scope']); ?>
                  </span>
                  <?php } ?>
                </td>
                <td class="cfp-col-hide text-center">
                  <?php if ($r['GasType']) { ?>
                  <span class="gas-badge" style="background:<?php echo $gColor; ?>;">
                    <?php echo htmlspecialchars($r['GasType']); ?>
                  </span>
                  <?php } ?>
                </td>
                <td class="cfp-col-hide text-center">
                  <span class="ef-value"><?php echo number_format((float)$r['EFValue'], 6); ?></span>
                  <div style="font-size:0.68rem;color:var(--cfp-text-muted);">
                    <?php echo htmlspecialchars($r['Unit']??''); ?>
                  </div>
                </td>
                <td class="cfp-col-hide text-center" style="font-size:0.8rem;font-weight:500;">
                  <?php echo number_format((float)$r['GWP'], 1); ?>
                </td>
                <td class="cfp-col-hide text-center" style="font-weight:600;color:var(--cfp-primary);">
                  <?php echo $r['YearApply'] ?? '—'; ?>
                </td>
                <td class="cfp-col-hide text-center">
                  <?php if (!empty($r['SourceCode'])) { ?>
                  <span style="font-size:0.72rem;background:#EEF6F8;color:#2AABB8;padding:2px 6px;border-radius:6px;font-weight:600;">
                    <?php echo htmlspecialchars($r['SourceCode']); ?>
                  </span>
                  <?php } else { echo '<span style="color:#ccc;">—</span>'; } ?>
                </td>
                <td class="text-center">
                  <?php if ($r['IsActive']) { ?>
                    <span class="status-dot" style="background:#4CAF50;"></span>
                    <span style="font-size:0.78rem;color:#2E7D32;">ใช้งาน</span>
                  <?php } else { ?>
                    <span class="status-dot" style="background:#ccc;"></span>
                    <span style="font-size:0.78rem;color:#9E9E9E;">ปิด</span>
                  <?php } ?>
                  <?php
                  /* แสดง badge หมดอายุ */
                  if (!empty($r['ValidUntil'])) {
                    $vu = $r['ValidUntil'] instanceof DateTime ? $r['ValidUntil'] : new DateTime($r['ValidUntil']);
                    $today = new DateTime('today');
                    $diff  = (int)$today->diff($vu)->format('%r%a'); /* ลบ = หมดแล้ว */
                    if ($diff < 0) { ?>
                    <br><span style="font-size:0.68rem;background:#FEE2E2;color:#B91C1C;border-radius:4px;padding:1px 6px;font-weight:600;">
                      <i class="bi bi-exclamation-triangle-fill me-1"></i>หมดอายุแล้ว
                    </span>
                    <?php } elseif ($diff <= 30) { ?>
                    <br><span style="font-size:0.68rem;background:#FEF3C7;color:#92400E;border-radius:4px;padding:1px 6px;font-weight:600;">
                      <i class="bi bi-clock me-1"></i>หมดใน <?php echo $diff; ?> วัน
                    </span>
                    <?php }
                  }
                  ?>
                </td>
                <td class="cfp-col-hide text-center" style="white-space:nowrap;">
                  <div class="cfp-action-group">
                    <button class="btn btn-outline-primary btn-action me-1 cfp-act-primary"
                            onclick="openModal(<?php echo (int)$r['EFID']; ?>)" title="แก้ไข">
                      <i class="bi bi-pencil-square"></i><span class="cfp-act-label">แก้ไข</span>
                    </button>
                    <div class="cfp-act-secondary">
                      <button class="btn btn-outline-secondary btn-action me-1 cfp-act-toggle"
                              onclick="viewHistory('<?php echo htmlspecialchars(addslashes($r['EFCode'])); ?>')" title="ดูประวัติการแก้ไข">
                        <i class="bi bi-clock-history"></i><span class="cfp-act-label">ประวัติ</span>
                      </button>
                      <button class="btn btn-action <?php echo $r['IsActive']?'btn-outline-danger':'btn-outline-success'; ?> cfp-act-del"
                              onclick="confirmToggle(<?php echo (int)$r['EFID']; ?>,<?php echo $r['IsActive']?1:0; ?>,'<?php echo htmlspecialchars(addslashes($r['EFName'])); ?>')"
                              title="<?php echo $r['IsActive']?'ปิด':'เปิด'; ?>">
                        <i class="bi bi-<?php echo $r['IsActive']?'toggle2-off':'toggle2-on'; ?>"></i><span class="cfp-act-label"><?php echo $r['IsActive']?'ปิด':'เปิด'; ?></span>
                      </button>
                    </div>
                  </div>
                </td>
              </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Modal Import -->
<div class="modal fade" id="modalImport" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
    <div class="modal-content font-prompt">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff;padding:0.75rem 1.25rem;">
        <h6 class="modal-title mb-0">
          <i class="bi bi-file-earmark-spreadsheet me-2"></i>Import ค่า EF จาก Excel
        </h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formImport" method="POST" action="ef_value_import.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div class="modal-body">
          <!-- Dropzone -->
          <div id="dropzone"
               style="border:2px dashed var(--cfp-primary);border-radius:10px;padding:28px 16px;
                      text-align:center;cursor:pointer;background:#EEF6F8;transition:background 0.2s;"
               onclick="document.getElementById('importFile').click()"
               ondragover="event.preventDefault();this.style.background='#D8EDF4';"
               ondragleave="this.style.background='#EEF6F8';"
               ondrop="handleDrop(event)">
            <i class="bi bi-cloud-upload" style="font-size:2rem;color:var(--cfp-primary);display:block;margin-bottom:8px;"></i>
            <div style="font-size:0.88rem;font-weight:600;color:var(--cfp-primary);">คลิกหรือลากไฟล์มาวางที่นี่</div>
            <div style="font-size:0.75rem;color:var(--cfp-text-muted);margin-top:4px;">รองรับเฉพาะ .xlsx ขนาดไม่เกิน 5 MB</div>
            <div id="fileNameDisplay" style="margin-top:10px;font-size:0.82rem;color:#2E7D32;font-weight:500;display:none;"></div>
          </div>
          <input type="file" id="importFile" name="import_file" accept=".xlsx" style="display:none;"
                 onchange="handleFileSelect(this)">

          <!-- Download template -->
          <div class="mt-3 text-center">
            <a href="../assets/templates/EF_Import_Template.xlsx" download
               style="font-size:0.8rem;color:var(--cfp-primary);">
              <i class="bi bi-download me-1"></i>ดาวน์โหลด Template
            </a>
          </div>

          <!-- คำแนะนำ -->
          <div class="mt-3 p-2 rounded" style="background:#F9FAFB;font-size:0.75rem;color:var(--cfp-text-muted);">
            <i class="bi bi-info-circle me-1"></i>
            กรอกข้อมูลใน Sheet <strong>EF_Import</strong> ตั้งแต่แถวที่ 12 เป็นต้นไป
            ระบบจะข้ามแถวที่ซ้ำกัน (EFName + Scope + ปีเดียวกัน)
          </div>
        </div>
        <div class="modal-footer" style="background:#F9FAFB;">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="button" class="btn-cfp-add" onclick="submitImport()">
            <i class="bi bi-upload me-1"></i>นำเข้าข้อมูล
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal เพิ่ม/แก้ไข -->
<div class="modal fade" id="modalEF" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content font-prompt">
      <div class="modal-header">
        <h6 class="modal-title mb-0" id="modalTitle">
          <i class="bi bi-plus-circle me-2"></i>เพิ่มค่า EF
        </h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formEF" method="POST" action="ef_value_save.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" id="fAction" value="create">
        <input type="hidden" name="EFID"   id="fID"     value="0">
        <div class="modal-body">
          <div class="row g-3">

            <!-- รหัส EF -->
            <div class="col-md-4">
              <label class="form-label">รหัส EF</label>
              <input type="text" class="form-control font-prompt" id="fCodeDisplay"
                     value="ระบบสร้างให้อัตโนมัติ" readonly
                     style="background:#F0F0F0;color:var(--cfp-text-muted);font-size:0.85rem;">
            </div>

            <!-- Scope -->
            <div class="col-md-4">
              <label class="form-label form-required">Scope</label>
              <select class="form-select font-prompt" name="Scope" id="fScope" required>
                <option value="">— เลือก Scope —</option>
                <option value="Scope1">Scope 1 — Direct</option>
                <option value="Scope2">Scope 2 — Energy</option>
                <option value="Scope3">Scope 3 — Other</option>
              </select>
            </div>

            <!-- Gas Type -->
            <div class="col-md-4">
              <label class="form-label form-required">Gas Type</label>
              <select class="form-select font-prompt" name="GasType" id="fGasType" required>
                <option value="">— เลือก Gas —</option>
                <option value="CO2">CO₂</option>
                <option value="CH4">CH₄</option>
                <option value="N2O">N₂O</option>
                <option value="HFCs">HFCs</option>
                <option value="CO2e">CO₂e (รวม)</option>
              </select>
            </div>

            <!-- ชื่อ EF -->
            <div class="col-12">
              <label class="form-label form-required">ชื่อ EF</label>
              <input type="text" class="form-control font-prompt" name="EFName" id="fName"
                     maxlength="300" required placeholder="เช่น Diesel Combustion CO2">
            </div>

            <!-- ผูกกับ ActivityItem — อ่านอย่างเดียว จัดการจริงที่หน้า ef_link.php (ผูกได้หลาย Item ต่อ EF) -->
            <div class="col-12">
              <label class="form-label">ผูกกับรายการกิจกรรม (Activity Item)</label>
              <div id="fLinkedItemsDisplay" class="form-control font-prompt" style="font-size:0.8rem;background:#F9FAFB;min-height:38px;display:flex;align-items:center;">
                —
              </div>
              <div class="form-text">
                จัดการการผูก/ยกเลิกผูกได้ที่หน้า <a href="ef_link.php" target="_blank">ผูก EF กับ Activity Item</a>
                (1 EF ผูกได้หลาย Item พร้อมกัน)
              </div>
            </div>

            <!-- ค่า EF -->
            <div class="col-md-4">
              <label class="form-label form-required">ค่า EF</label>
              <input type="number" class="form-control font-prompt" name="EFValue" id="fValue"
                     step="0.000001" min="0" required placeholder="0.000000">
            </div>

            <!-- GWP -->
            <div class="col-md-4">
              <label class="form-label form-required">GWP</label>
              <input type="number" class="form-control font-prompt" name="GWP" id="fGWP"
                     step="0.000001" min="0" value="1" required>
              <div class="form-text">CO₂ = 1, CH₄ = 28, N₂O = 265</div>
            </div>

            <!-- Unit -->
            <div class="col-md-4">
              <label class="form-label">หน่วย</label>
              <input type="text" class="form-control font-prompt" name="Unit" id="fUnit"
                     maxlength="100" placeholder="เช่น kgCO2e/liter">
            </div>

            <!-- ปีที่ใช้ -->
            <div class="col-md-4">
              <label class="form-label form-required">ปีที่ใช้ (ค.ศ.)</label>
              <input type="number" class="form-control font-prompt" name="YearApply" id="fYear"
                     min="2000" max="2100" required placeholder="<?php echo date('Y'); ?>">
            </div>

            <!-- ใช้ได้ถึงวันที่ -->
            <div class="col-md-4">
              <label class="form-label">ใช้ได้ถึงวันที่</label>
              <input type="date" class="form-control font-prompt" name="ValidUntil" id="fValidUntil">
              <div class="form-text">ถ้าไม่ระบุ = ไม่มีวันหมดอายุ</div>
            </div>

            <!-- วันที่เริ่มใช้ -->
            <div class="col-md-4">
              <label class="form-label">วันที่เริ่มใช้</label>
              <input type="date" class="form-control font-prompt" name="EffectiveDate" id="fEffectiveDate">
              <div class="form-text">ถ้าไม่ระบุ = วันที่บันทึก</div>
            </div>

            <!-- หมวด (Category) -->
            <div class="col-md-4">
              <label class="form-label form-required">หมวด</label>
              <select class="form-select font-prompt" name="Category" id="fCategory" required>
                <?php foreach ($catLabelMap as $catKey => $catLabel) { ?>
                <option value="<?php echo htmlspecialchars($catKey); ?>">
                  <?php echo htmlspecialchars($catLabel); ?>
                </option>
                <?php } ?>
              </select>
              <div class="form-text">ใช้จัดกลุ่มแสดงผลในตาราง</div>
            </div>

            <!-- แหล่งอ้างอิง -->
            <div class="col-md-4">
              <label class="form-label">แหล่งอ้างอิง</label>
              <select class="form-select font-prompt" name="SourceID" id="fSourceID">
                <option value="">— ไม่ระบุ —</option>
                <?php foreach ($sources as $s) { ?>
                <option value="<?php echo $s['SourceID']; ?>">
                  <?php echo htmlspecialchars($s['SourceCode'].' — '.$s['SourceName'].' ('.$s['YearApply'].')'); ?>
                </option>
                <?php } ?>
              </select>
            </div>

            <!-- สถานะ (แสดงเฉพาะ update) -->
            <div class="col-md-4" id="statusWrap" style="display:none;">
              <label class="form-label">สถานะ</label>
              <select class="form-select font-prompt" name="IsActive" id="fActive">
                <option value="1">ใช้งาน</option>
                <option value="0">ปิดใช้งาน</option>
              </select>
            </div>

          </div>
        </div>
        <div class="modal-footer" style="background:#F9FAFB;">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn-cfp-add">
            <i class="bi bi-check-circle me-1"></i>บันทึก
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Hidden toggle form -->
<form id="formToggle" method="POST" action="ef_value_save.php" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="action" value="toggle">
  <input type="hidden" name="EFID" id="ftID" value="0">
</form>

<!-- Toast -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:1080;">
  <div id="toastSuccess" class="toast align-items-center text-white border-0" style="background:#2E7D32;" role="alert">
    <div class="d-flex"><div class="toast-body d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i><span id="toastMsg">บันทึกเรียบร้อย</span></div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
  <div id="toastError" class="toast align-items-center text-white border-0" style="background:#C62828;" role="alert">
    <div class="d-flex"><div class="toast-body d-flex align-items-center gap-2"><i class="bi bi-x-circle-fill"></i><span id="toastErrMsg">เกิดข้อผิดพลาด</span></div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/rowgroup/1.4.1/js/dataTables.rowGroup.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../assets/js/cfp-table-mobile.js"></script>
<script>
var efData = <?php
    $map = array();
    foreach ($rows as $r) {
        $map[(int)$r['EFID']] = array(
            'code'     => $r['EFCode'],
            'name'     => $r['EFName'],
            'scope'    => $r['Scope'] ?? '',
            'category' => $r['Category'] ?? '',
            'gasType'  => $r['GasType'] ?? '',
            'value'    => (float)$r['EFValue'],
            'gwp'      => (float)$r['GWP'],
            'unit'     => $r['Unit'] ?? '',
            'year'     => (int)($r['YearApply'] ?? 0),
            'sourceID'   => (int)($r['SourceID'] ?? 0),
            'linkedItemNames' => $r['LinkedItemNames'] ?? '',
            'linkedItemCount' => (int)($r['LinkedItemCount'] ?? 0),
            'active'     => (int)$r['IsActive'],
            'validUntil'    => $r['ValidUntil'] instanceof DateTime ? $r['ValidUntil']->format('Y-m-d') : ($r['ValidUntil'] ?? ''),
            'effectiveDate' => $r['EffectiveDate'] instanceof DateTime ? $r['EffectiveDate']->format('Y-m-d') : ($r['EffectiveDate'] ?? ''),
            'createdDate'=> $r['CreatedDate'] instanceof DateTime ? $r['CreatedDate']->format('Y-m-d H:i') : '',
            'sourceCode' => $r['SourceCode'] ?? '',
        );
    }
    echo json_encode($map);
?>;

/* DataTable custom filter */
$.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
    var sc  = $('#fltScope').val();
    var yr  = $('#fltYear').val();
    var src = $('#fltSource').val();
    var st  = $('#fltStatus').val();
    var row = $('#tblEF').DataTable().row(dataIndex).node();
    if (sc  && $(row).attr('data-scope')  !== sc)  { return false; }
    if (yr  && $(row).attr('data-year')   !== yr)  { return false; }
    if (src && $(row).attr('data-source') !== src) { return false; }
    if (st  && $(row).attr('data-status') !== st)  { return false; }
    return true;
});

var CAT_ICON_MAP    = <?php echo json_encode($catIconMap); ?>;
var SCOPE_COLOR_MAP = <?php echo json_encode($scopeColors); ?>;

var tblApi;
$(document).ready(function() {
    tblApi = $('#tblEF').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order: [[3,'asc'],[9,'desc'],[6,'asc']],
        paging: false,
        columnDefs: [
            { targets: 0, orderable: false, searchable: false },
            { targets: 2, visible: false },
            { targets: 3, visible: false }
        ],
        rowGroup: {
            dataSrc: 2,
            startRender: function (rows, group) {
                var firstNode = rows.nodes()[0];
                var scope = firstNode ? firstNode.getAttribute('data-scope') : '';
                var cat   = firstNode ? firstNode.getAttribute('data-category') : '';
                var color = SCOPE_COLOR_MAP[scope] || '#2AABB8';
                var icon  = CAT_ICON_MAP[cat] || 'bi-collection';
                var colCount = document.querySelectorAll('#tblEF thead th').length;
                return $('<tr class="dtrg-group"><td colspan="' + colCount + '">'
                    + '<div class="ef-group-banner" style="--grp-color:' + color + ';">'
                    + '<div class="ic"><i class="bi ' + icon + '"></i></div>'
                    + '<b>' + $('<div>').text(group).html() + '</b>'
                    + '<span class="cnt">' + rows.count() + ' รายการ</span>'
                    + '</div></td></tr>');
            }
        },
        drawCallback: function () { cfpInitMobileExpand('tblEF'); },
        dom: '<"row align-items-center mb-2"<"col">>rtip'
    });
    $('#fltKeyword').on('keyup', function() { tblApi.search(this.value).draw(); });
    $('#fltScope,#fltYear,#fltSource,#fltStatus').on('change', function() { tblApi.draw(); });

    cfpBindMobileExpand('tblEF');
});

$('#fltKeyword').on('input', function () {
    $(this).closest('.cfp-search-wrap').find('.cfp-search-clear').toggle(this.value.length > 0);
});
function clearKeyword() {
    $('#fltKeyword').val('').trigger('keyup').trigger('input').focus();
}
function clearFilter() {
    $('#fltKeyword,#fltScope,#fltYear,#fltSource,#fltStatus').val('');
    tblApi.search('').draw();
}

/* ── ดูประวัติการแก้ไข (revision chain) ของ EF ตัวหนึ่ง ── */
function viewHistory(code) {
    var list = Object.values(efData).filter(function(d) { return d.code === code; });
    list.sort(function(a, b) { return (a.createdDate || '').localeCompare(b.createdDate || ''); });

    if (!list.length) {
        Swal.fire({ icon: 'info', title: 'ไม่พบประวัติ', customClass: { popup: 'font-prompt' } });
        return;
    }

    var rowsHtml = list.map(function(d, i) {
        var statusBadge = d.active
            ? '<span style="color:#2E7D32;font-weight:600;">ใช้งานอยู่</span>'
            : '<span style="color:#9E9E9E;">ปิดใช้งาน (เวอร์ชันเก่า)</span>';
        return '<tr>' +
            '<td style="padding:4px 8px;text-align:center;">' + (i + 1) + '</td>' +
            '<td style="padding:4px 8px;text-align:right;">' + d.value + '</td>' +
            '<td style="padding:4px 8px;text-align:center;">' + d.year + '</td>' +
            '<td style="padding:4px 8px;">' + (d.sourceCode || '—') + '</td>' +
            '<td style="padding:4px 8px;">' + (d.createdDate || '—') + '</td>' +
            '<td style="padding:4px 8px;">' + statusBadge + '</td>' +
            '</tr>';
    }).join('');

    Swal.fire({
        title: 'ประวัติการแก้ไข: ' + code,
        html:
            '<div style="max-height:320px;overflow-y:auto;text-align:left;">' +
            '<table style="width:100%;font-size:0.8rem;border-collapse:collapse;">' +
            '<thead><tr style="background:#F3F4F6;">' +
            '<th style="padding:4px 8px;">#</th><th style="padding:4px 8px;">ค่า EF</th>' +
            '<th style="padding:4px 8px;">ปี</th><th style="padding:4px 8px;">Source</th>' +
            '<th style="padding:4px 8px;">วันที่บันทึก</th><th style="padding:4px 8px;">สถานะ</th>' +
            '</tr></thead><tbody>' + rowsHtml + '</tbody></table></div>',
        confirmButtonText: 'ปิด',
        customClass: { popup: 'font-prompt' },
        width: 600
    });
}

function openModal(id) {
    /* reset */
    document.getElementById('fAction').value     = 'create';
    document.getElementById('fID').value         = '0';
    document.getElementById('fCodeDisplay').value= 'ระบบสร้างให้อัตโนมัติ';
    document.getElementById('fScope').value      = '';
    document.getElementById('fGasType').value    = '';
    document.getElementById('fName').value       = '';
    document.getElementById('fValue').value      = '';
    document.getElementById('fGWP').value        = '1';
    document.getElementById('fUnit').value       = '';
    document.getElementById('fYear').value       = '';
    document.getElementById('fValidUntil').value = '';
    document.getElementById('fEffectiveDate').value = '';
    document.getElementById('fCategory').value   = 'Stationary';
    document.getElementById('fSourceID').value   = '';
    document.getElementById('fActive').value     = '1';
    document.getElementById('statusWrap').style.display = 'none';
    document.getElementById('fLinkedItemsDisplay').textContent = '—';

    if (id === 0) {
        document.getElementById('modalTitle').innerHTML =
            '<i class="bi bi-plus-circle me-2"></i>เพิ่มค่า EF';
    } else {
        var d = efData[id]; if (!d) { return; }
        document.getElementById('modalTitle').innerHTML =
            '<i class="bi bi-pencil-square me-2"></i>แก้ไขค่า EF';
        document.getElementById('fAction').value      = 'update';
        document.getElementById('fID').value          = id;
        document.getElementById('fCodeDisplay').value = d.code;
        document.getElementById('fScope').value       = d.scope;
        document.getElementById('fGasType').value     = d.gasType;
        document.getElementById('fName').value        = d.name;
        document.getElementById('fValue').value       = d.value;
        document.getElementById('fGWP').value         = d.gwp;
        document.getElementById('fUnit').value        = d.unit;
        document.getElementById('fYear').value        = d.year || '';
        document.getElementById('fValidUntil').value  = d.validUntil || '';
        document.getElementById('fEffectiveDate').value = d.effectiveDate || '';
        document.getElementById('fCategory').value    = d.category || 'Stationary';
        document.getElementById('fSourceID').value    = d.sourceID || '';
        document.getElementById('fActive').value      = d.active;
        document.getElementById('fLinkedItemsDisplay').textContent =
            d.linkedItemCount > 0 ? (d.linkedItemCount + ' รายการ: ' + d.linkedItemNames) : '— ยังไม่มี Item ผูก —';
        document.getElementById('statusWrap').style.display = '';
    }
    new bootstrap.Modal(document.getElementById('modalEF')).show();
}

function confirmToggle(id, cur, name) {
    var act = cur ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    Swal.fire({
        title: act + '?', html: '<b>' + name + '</b>',
        icon: cur ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonColor: cur ? '#DC3545' : '#4CAF50',
        cancelButtonColor: '#6C757D',
        confirmButtonText: act, cancelButtonText: 'ยกเลิก',
        reverseButtons: true, customClass: { popup: 'font-prompt' }
    }).then(function(r) {
        if (r.isConfirmed) {
            document.getElementById('ftID').value = id;
            document.getElementById('formToggle').submit();
        }
    });
}

/* ===== Import Modal ===== */
function openImportModal() {
    document.getElementById('importFile').value = '';
    document.getElementById('fileNameDisplay').style.display = 'none';
    document.getElementById('fileNameDisplay').textContent   = '';
    document.getElementById('dropzone').style.background = '#EEF6F8';
    new bootstrap.Modal(document.getElementById('modalImport')).show();
}

function handleFileSelect(input) {
    if (input.files && input.files[0]) {
        showFileName(input.files[0].name);
    }
}

function handleDrop(event) {
    event.preventDefault();
    document.getElementById('dropzone').style.background = '#EEF6F8';
    var file = event.dataTransfer.files[0];
    if (file) {
        var dt = new DataTransfer();
        dt.items.add(file);
        document.getElementById('importFile').files = dt.files;
        showFileName(file.name);
    }
}

function showFileName(name) {
    var el = document.getElementById('fileNameDisplay');
    el.textContent = '✓ ' + name;
    el.style.display = 'block';
}

function submitImport() {
    if (!document.getElementById('importFile').files.length) {
        Swal.fire({
            icon: 'warning', title: 'กรุณาเลือกไฟล์',
            text: 'กรุณาเลือกไฟล์ .xlsx ก่อนนำเข้าข้อมูล',
            confirmButtonColor: '#2AABB8',
            customClass: { popup: 'font-prompt' }
        });
        return;
    }
    document.getElementById('formImport').submit();
}

<?php if ($importResult !== null) { ?>
/* ===== แสดงผล Import ===== */
(function() {
    var res = <?php echo json_encode($importResult); ?>;
    var html = '<div style="text-align:left;font-family:Prompt,sans-serif;font-size:0.88rem;">';
    html += '<div style="margin-bottom:10px;">';
    html += '<span style="color:#2E7D32;font-weight:600;">✓ สำเร็จ: ' + res.success + ' รายการ</span><br>';
    if (res.skip > 0)    { html += '<span style="color:#F59E0B;">↷ ข้าม (ซ้ำ): ' + res.skip + ' รายการ</span><br>'; }
    if (res.fail > 0)    { html += '<span style="color:#E05050;">✗ ล้มเหลว: ' + res.fail + ' รายการ</span>'; }
    html += '</div>';
    if (res.errors && res.errors.length > 0) {
        html += '<div style="background:#FFF3CD;border-radius:6px;padding:8px;font-size:0.78rem;max-height:200px;overflow-y:auto;">';
        html += '<strong>รายละเอียด:</strong><br>';
        res.errors.forEach(function(e) { html += '• ' + e + '<br>'; });
        html += '</div>';
    }
    html += '</div>';
    Swal.fire({
        title: 'ผลการ Import',
        html: html,
        icon: res.success > 0 ? 'success' : 'warning',
        confirmButtonColor: '#2AABB8',
        customClass: { popup: 'font-prompt' }
    });
})();
<?php } ?>

function showToast(msg, isError) {
    var id  = isError ? 'toastError' : 'toastSuccess';
    var mid = isError ? 'toastErrMsg' : 'toastMsg';
    document.getElementById(mid).textContent = msg;
    new bootstrap.Toast(document.getElementById(id), { delay: 3000 }).show();
}
<?php if ($toastMsg) { ?>
showToast('<?php echo htmlspecialchars(addslashes($toastMsg)); ?>',
          <?php echo $toastType==='error'?'true':'false'; ?>);
<?php } ?>
</script>
</body>
</html>

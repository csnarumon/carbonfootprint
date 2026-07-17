<?php
/* ==============================================
   master/activityitem.php
   จัดการรายการกิจกรรม (Activity Item)
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

/* ===== Unit dropdown ===== */
$resUnit = sqlsrv_query($conn, "SELECT UnitID, UnitCode, UnitName FROM CFP_Unit WHERE IsActive=1 ORDER BY UnitName");
$units   = array();
while ($r = sqlsrv_fetch_array($resUnit, SQLSRV_FETCH_ASSOC)) { $units[] = $r; }

/* ===== ดึง Sites ทั้งหมด ===== */
$resSite = sqlsrv_query($conn, "SELECT SiteID, SiteCode, SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
$sites   = array();
while ($r = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) { $sites[] = $r; }

/* ===== ดึง ItemSite mapping ทั้งหมด (key = ItemID → array of SiteID) ===== */
$itemSiteMap = array();
$resIS = sqlsrv_query($conn, "SELECT ItemID, SiteID FROM CFP_ActivityItemSite WHERE IsActive=1");
if ($resIS) {
    while ($r = sqlsrv_fetch_array($resIS, SQLSRV_FETCH_ASSOC)) {
        $itemSiteMap[(int)$r['ItemID']][] = (int)$r['SiteID'];
    }
}

/* ===== ดึงรายการ ===== */
$res  = sqlsrv_query($conn, "
    SELECT a.ItemID, a.ItemCode, a.ItemName, a.ItemNameEN,
           a.ScopeNo, a.CategoryNo, a.Scope1Type, a.UnitID, u.UnitName,
           a.InputMethod, a.Description, a.SortOrder, a.IsActive
    FROM CFP_ActivityItem a
    LEFT JOIN CFP_Unit u ON a.UnitID = u.UnitID
    ORDER BY a.ScopeNo, a.CategoryNo, a.SortOrder, a.ItemName");
$rows = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }

/* ===== KPI ===== */
$total = count($rows);
$active = 0; $inactive = 0;
foreach ($rows as $r) { if ($r['IsActive']) { $active++; } else { $inactive++; } }
$resUsed = sqlsrv_query($conn, "SELECT COUNT(DISTINCT ItemID) AS Cnt FROM CFP_ActivityData WHERE ItemID IS NOT NULL");
$usedCount = 0;
if ($resUsed) {
    $rU = sqlsrv_fetch_array($resUsed, SQLSRV_FETCH_ASSOC);
    $usedCount = $rU ? (int)$rU['Cnt'] : 0;
}

/* ===== Labels ===== */
$scopeColors = array(1=>'#2AABB8', 2=>'#F59E0B', 3=>'#8B5CF6');
$catLabels   = array(
    1=>'Purchased goods',2=>'Capital goods',3=>'Fuel & energy',
    4=>'Upstream transport',5=>'Waste',6=>'Business travel',
    7=>'Employee commuting',8=>'Upstream leased',9=>'Downstream transport',
    10=>'Processing',11=>'Use of sold',12=>'End-of-life',
    13=>'Downstream leased',14=>'Franchises',15=>'Investments',
);
$inputLabels = array(1=>'Manual',2=>'OCR',3=>'Import',4=>'API');
$scope1Labels = array(
    'Stationary' => 'Stationary Combustion',
    'Mobile'     => 'Mobile Combustion',
    'Fugitive'   => 'Fugitive Emission',
    'Process'    => 'Industrial Process',
);
/* Scope 2 Category — ต้องตรงกับ $catLabels ใน scope2.php ทุกประการ
   หมายเหตุ: เหลือแค่ 2 รายการที่มีหลักฐานยืนยันจาก CFO_application_V.1.pdf
   (ไอน้ำ/ความร้อน/District Cooling เอาออกก่อน เพราะยังไม่มีข้อมูลใช้งานจริง — เพิ่มกลับได้ทีหลังถ้าต้องใช้) */
$scope2Labels = array(
    1 => 'ไฟฟ้า Grid Mix (MEA/PEA)',
    2 => 'ไฟฟ้า Solar PV / REC',
);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>รายการกิจกรรม — ระบบบริหารจัดการคาร์บอนองค์กร</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="../assets/css/cfp-theme.css?v=<?php echo filemtime('../assets/css/cfp-theme.css'); ?>" rel="stylesheet">
  <style>
    body { font-family:'Prompt',sans-serif; }
    .font-prompt { font-family:'Prompt',sans-serif !important; }
    .kpi-icon-box { width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0; }
    .btn-action { width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;font-size:0.8rem; }
    .status-dot { width:8px;height:8px;border-radius:50%;display:inline-block; }
    .scope-badge { display:inline-block;padding:2px 8px;border-radius:10px;font-size:0.72rem;font-weight:600;color:#fff; }
    .cat-badge { display:inline-block;padding:2px 7px;border-radius:8px;font-size:0.7rem;background:#F3F4F6;color:#4A7A88; }
  </style>
</head>
<body>
<div class="d-flex">
  <?php include '../includes/sidebar.php'; ?>
  <div class="cfp-main">
    <?php $pageTitle='รายการกิจกรรม'; $pageIcon='list-check'; include '../includes/topbar.php'; ?>
    <div class="cfp-content">

      <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
          <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);">
            <i class="bi bi-list-check me-2"></i>จัดการรายการกิจกรรม
          </h5>
          <div style="font-size:0.78rem;color:var(--cfp-text-muted);margin-top:2px;">
            การตั้งค่าข้อมูลพื้นฐาน › รายการกิจกรรม (Activity Item)
          </div>
        </div>
      </div>

      <!-- KPI -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#E4F7F9;"><i class="bi bi-list-check" style="color:#2AABB8;"></i></div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:var(--cfp-primary);line-height:1.1;"><?php echo $total; ?></div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">รายการทั้งหมด</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#E8F5E9;"><i class="bi bi-check-circle-fill" style="color:#2E7D32;"></i></div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:#2E7D32;line-height:1.1;"><?php echo $active; ?></div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ใช้งานอยู่</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#F5F5F5;"><i class="bi bi-slash-circle" style="color:#9E9E9E;"></i></div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:#9E9E9E;line-height:1.1;"><?php echo $inactive; ?></div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ปิดใช้งาน</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#F3EEFF;"><i class="bi bi-database-check" style="color:#8B5CF6;"></i></div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:#8B5CF6;line-height:1.1;"><?php echo $usedCount; ?></div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ถูกใช้บันทึกแล้ว</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Table -->
      <div class="cfp-card">
        <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);margin-bottom:12px;">
          <i class="bi bi-list-check me-2"></i>รายการกิจกรรมทั้งหมด
        </div>
        <div class="cfp-page-toolbar mb-3">
          <div class="d-flex gap-2 flex-wrap flex-grow-1" style="max-width:680px;">
            <div class="cfp-search-wrap flex-grow-1" style="position:relative;">
            <input type="text" id="fltKeyword" class="form-control font-prompt" style="font-size:0.85rem;min-width:160px;padding-right:28px;" placeholder="ค้นหาชื่อรายการ...">
            <button type="button" class="cfp-search-clear" onclick="clearKeyword()" title="ล้างคำค้นหา" style="display:none;position:absolute;right:6px;top:50%;transform:translateY(-50%);border:none;background:none;padding:2px;line-height:1;color:var(--cfp-text-muted,#888);font-size:0.95rem;cursor:pointer;z-index:2;"><i class="bi bi-x-circle-fill"></i></button>
            </div>
            <select id="fltScope" class="form-select font-prompt" style="font-size:0.85rem;max-width:130px;">
              <option value="">ทุก Scope</option>
              <option value="1">Scope 1</option>
              <option value="2">Scope 2</option>
              <option value="3">Scope 3</option>
            </select>
            <select id="fltCat" class="form-select font-prompt" style="font-size:0.85rem;max-width:130px;">
              <option value="">ทุก Category</option>
              <?php for ($c=1;$c<=15;$c++) { echo '<option value="'.$c.'">Cat.'.$c.'</option>'; } ?>
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
            <button class="btn-cfp-add" onclick="openModal(0)">
              <i class="bi bi-plus-circle"></i>เพิ่มรายการ
            </button>
          </div>
        </div>

        <div class="table-responsive">
          <table id="tblItem" class="table table-bordered table-hover align-middle" style="width:100%;font-size:0.85rem;">
            <thead>
              <tr>
                <th class="cfp-th-expand"></th>
                <th class="cfp-th-num" style="width:40px;">#</th>
                <th>ชื่อรายการ</th>
                <th class="cfp-col-hide text-center" style="width:90px;">Scope</th>
                <th class="cfp-col-hide text-center" style="width:120px;">Category / ประเภท</th>
                <th class="cfp-col-hide text-center" style="width:80px;">หน่วย</th>
                <th class="cfp-col-hide text-center" style="width:70px;">วิธีกรอก</th>
                <th style="width:80px;" class="text-center">สถานะ</th>
                <th class="cfp-col-hide text-center" style="width:110px;">Site</th>
                <th class="cfp-col-hide text-center" style="width:110px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $i => $r) {
                $sNo      = (int)$r['ScopeNo'];
                $cNo      = $r['CategoryNo'] !== null ? (int)$r['CategoryNo'] : null;
                $sColor   = $scopeColors[$sNo] ?? '#999';
                $itemID   = (int)$r['ItemID'];
                $assignedSites = $itemSiteMap[$itemID] ?? array();
                $siteCount = count($assignedSites);
              ?>
              <tr data-status="<?php echo $r['IsActive']?'1':'0'; ?>"
                  data-scope="<?php echo $sNo; ?>"
                  data-cat="<?php echo $cNo??''; ?>">
                <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
                <td class="cfp-td-num"><?php echo $i+1; ?></td>
                <td>
                  <div style="font-weight:500;"><?php echo htmlspecialchars($r['ItemName']); ?></div>
                  <?php if (!empty($r['ItemNameEN'])) { ?>
                  <div style="font-size:0.72rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($r['ItemNameEN']); ?></div>
                  <?php } ?>
                </td>
                <td class="cfp-col-hide text-center">
                  <span class="scope-badge" style="background:<?php echo $sColor; ?>;">Scope<?php echo $sNo; ?></span>
                </td>
                <td class="cfp-col-hide text-center">
                  <?php if ($sNo === 3 && $cNo !== null) { ?>
                  <span class="cat-badge">Cat.<?php echo $cNo; ?></span>
                  <div style="font-size:0.68rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($catLabels[$cNo]??''); ?></div>
                  <?php } elseif ($sNo === 2 && $cNo !== null) { ?>
                  <span class="cat-badge"><?php echo htmlspecialchars($scope2Labels[$cNo]??''); ?></span>
                  <?php } elseif ($sNo === 1 && !empty($r['Scope1Type'])) { ?>
                  <span class="cat-badge"><?php echo htmlspecialchars($r['Scope1Type']); ?></span>
                  <div style="font-size:0.68rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($scope1Labels[$r['Scope1Type']]??''); ?></div>
                  <?php } else { ?>
                  <span style="color:var(--cfp-text-muted);font-size:0.75rem;">—</span>
                  <?php } ?>
                </td>
                <td class="cfp-col-hide text-center" style="font-size:0.78rem;color:var(--cfp-text-mid);">
                  <?php echo htmlspecialchars($r['UnitName']??'—'); ?>
                </td>
                <td class="cfp-col-hide text-center">
                  <span style="font-size:0.72rem;color:var(--cfp-text-muted);">
                    <?php echo $inputLabels[(int)$r['InputMethod']]??'Manual'; ?>
                  </span>
                </td>
                <td class="text-center">
                  <?php if ($r['IsActive']) { ?>
                    <span class="status-dot" style="background:#4CAF50;"></span>
                    <span style="font-size:0.78rem;color:#2E7D32;">ใช้งาน</span>
                  <?php } else { ?>
                    <span class="status-dot" style="background:#ccc;"></span>
                    <span style="font-size:0.78rem;color:#9E9E9E;">ปิด</span>
                  <?php } ?>
                </td>
                <td class="cfp-col-hide">
                  <?php
                  /* คำนวณสี bg/text/border จาก scopeColor */
                  $sHex   = $scopeColors[$sNo] ?? '#999';
                  /* สี bg อ่อน ~15% opacity, border ~40% opacity */
                  $bgMap  = array('#2AABB8'=>'#E0F4F7','#F59E0B'=>'#FEF3C7','#8B5CF6'=>'#EDE9FE','#999'=>'#F3F4F6');
                  $bdMap  = array('#2AABB8'=>'#A8D8DF','#F59E0B'=>'#FCD34D','#8B5CF6'=>'#C4B5FD','#999'=>'#D1D5DB');
                  $txMap  = array('#2AABB8'=>'#1B7A8A','#F59E0B'=>'#92400E','#8B5CF6'=>'#5B21B6','#999'=>'#6B7280');
                  $bgTag  = $bgMap[$sHex]  ?? '#F3F4F6';
                  $bdTag  = $bdMap[$sHex]  ?? '#D1D5DB';
                  $txTag  = $txMap[$sHex]  ?? '#6B7280';
                  ?>
                  <?php if ($siteCount > 0) { ?>
                  <div class="d-flex flex-wrap gap-1" style="cursor:pointer;"
                       onclick="openSiteModal(<?php echo $itemID; ?>,'<?php echo htmlspecialchars(addslashes($r['ItemName'])); ?>')"
                       title="คลิกเพื่อแก้ไข Site">
                    <?php foreach ($assignedSites as $sid) {
                      $sc = '';
                      foreach ($sites as $s) { if ((int)$s['SiteID'] === $sid) { $sc = $s['SiteCode']; break; } }
                      if ($sc !== '') { ?>
                    <span style="display:inline-block;background:<?php echo $bgTag; ?>;color:<?php echo $txTag; ?>;
                                 border:1px solid <?php echo $bdTag; ?>;border-radius:4px;
                                 font-size:0.68rem;font-weight:600;padding:1px 6px;line-height:1.6;">
                      <?php echo htmlspecialchars($sc); ?>
                    </span>
                    <?php } } ?>
                  </div>
                  <?php } else { ?>
                  <span style="display:inline-block;background:#FEE2E2;color:#B91C1C;border:1px solid #FCA5A5;
                               border-radius:4px;font-size:0.68rem;font-weight:600;padding:1px 6px;
                               cursor:pointer;line-height:1.6;"
                        onclick="openSiteModal(<?php echo $itemID; ?>,'<?php echo htmlspecialchars(addslashes($r['ItemName'])); ?>')"
                        title="คลิกเพื่อกำหนด Site">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>ยังไม่ได้กำหนด
                  </span>
                  <?php } ?>
                </td>
                <td class="cfp-col-hide text-center">
                  <div class="cfp-action-group">
                    <button class="btn btn-outline-primary btn-action me-1 cfp-act-primary"
                            onclick="openModal(<?php echo $itemID; ?>)" title="แก้ไข">
                      <i class="bi bi-pencil-square"></i><span class="cfp-act-label">แก้ไข</span>
                    </button>
                    <div class="cfp-act-secondary">
                      <button class="btn btn-action <?php echo $r['IsActive']?'btn-outline-danger':'btn-outline-success'; ?> me-1 cfp-act-toggle"
                              onclick="confirmToggle(<?php echo $itemID; ?>,<?php echo $r['IsActive']?1:0; ?>,'<?php echo htmlspecialchars(addslashes($r['ItemName'])); ?>')"
                              title="<?php echo $r['IsActive']?'ปิดใช้งาน':'เปิดใช้งาน'; ?>">
                        <i class="bi bi-<?php echo $r['IsActive']?'toggle2-off':'toggle2-on'; ?>"></i><span class="cfp-act-label"><?php echo $r['IsActive']?'ปิดใช้งาน':'เปิดใช้งาน'; ?></span>
                      </button>
                      <button class="btn btn-outline-warning btn-action cfp-act-del"
                              onclick="confirmDelete(<?php echo $itemID; ?>,'<?php echo htmlspecialchars(addslashes($r['ItemName'])); ?>')"
                              title="ลบ">
                        <i class="bi bi-trash"></i><span class="cfp-act-label">ลบ</span>
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

<!-- Modal เพิ่ม/แก้ไข -->
<div class="modal fade" id="modalItem" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content font-prompt">
      <div class="modal-header">
        <h6 class="modal-title mb-0" id="modalTitle"><i class="bi bi-plus-circle me-2"></i>เพิ่มรายการกิจกรรม</h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formItem" method="POST" action="activityitem_save.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" id="fAction" value="create">
        <input type="hidden" name="ItemID" id="fID" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">รหัสรายการ</label>
              <input type="text" class="form-control font-prompt" id="fCodeDisplay"
                     value="ระบบสร้างให้อัตโนมัติ" readonly
                     style="background:#F0F0F0;color:var(--cfp-text-muted);font-size:0.85rem;">
            </div>
            <div class="col-md-4">
              <label class="form-label form-required">Scope</label>
              <select class="form-select font-prompt" name="ScopeNo" id="fScopeNo" required onchange="onScopeChange()">
                <option value="">— เลือก Scope —</option>
                <option value="1">Scope 1 — Direct Emissions</option>
                <option value="2">Scope 2 — Energy Indirect</option>
                <option value="3">Scope 3 — Other Indirect</option>
              </select>
            </div>
            <div class="col-md-4" id="catWrap" style="display:none;">
              <label class="form-label form-required" id="catWrapLabel">Category</label>
              <select class="form-select font-prompt" name="CategoryNo" id="fCategoryNo">
                <option value="">— เลือก Category —</option>
              </select>
            </div>
            <div class="col-md-4" id="scope1Wrap" style="display:none;">
              <label class="form-label form-required">ประเภท (Scope 1)</label>
              <select class="form-select font-prompt" name="Scope1Type" id="fScope1Type">
                <option value="">— เลือกประเภท —</option>
                <?php foreach ($scope1Labels as $val => $label) {
                  echo '<option value="'.htmlspecialchars($val).'">'.htmlspecialchars($label).'</option>';
                } ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label form-required">ชื่อรายการ (ภาษาไทย)</label>
              <input type="text" class="form-control font-prompt" name="ItemName" id="fName"
                     placeholder="เช่น น้ำยางสด" maxlength="300" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">ชื่อรายการ (English)</label>
              <input type="text" class="form-control font-prompt" name="ItemNameEN" id="fNameEN"
                     placeholder="e.g. Natural Rubber Latex" maxlength="300">
            </div>
            <div class="col-md-4">
              <label class="form-label">หน่วยวัด</label>
              <select class="form-select font-prompt" name="UnitID" id="fUnitID">
                <option value="">— เลือกหน่วย —</option>
                <?php foreach ($units as $u) { ?>
                <option value="<?php echo $u['UnitID']; ?>"><?php echo htmlspecialchars($u['UnitCode'].' — '.$u['UnitName']); ?></option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">วิธีกรอกข้อมูล</label>
              <select class="form-select font-prompt" name="InputMethod" id="fInputMethod">
                <option value="1">Manual — กรอกเอง</option>
                <option value="2">OCR — สแกนเอกสาร</option>
                <option value="3">Import — นำเข้า Excel</option>
                <option value="4">API — ดึงจากระบบ</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">ลำดับแสดง</label>
              <input type="number" class="form-control font-prompt" name="SortOrder" id="fSort" value="99" min="1" max="999">
            </div>
            <div class="col-12">
              <label class="form-label">คำอธิบาย</label>
              <textarea class="form-control font-prompt" name="Description" id="fDesc" rows="2" maxlength="500"></textarea>
            </div>
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
          <button type="submit" class="btn-cfp-add"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Hidden forms -->
<form id="formDelete" method="POST" action="activityitem_save.php" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="ItemID" id="fdID" value="0">
</form>
<form id="formToggle" method="POST" action="activityitem_save.php" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="action" value="toggle">
  <input type="hidden" name="ItemID" id="ftID" value="0">
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

<!-- Modal กำหนด Site -->
<div class="modal fade" id="modalSite" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content font-prompt">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff;padding:0.75rem 1.25rem;">
        <h6 class="modal-title mb-0">
          <i class="bi bi-geo-alt-fill me-2"></i>กำหนด Site (โรงงาน)
        </h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3 p-2 rounded" style="background:#EEF6F8;font-size:0.83rem;">
          <i class="bi bi-list-check me-1" style="color:var(--cfp-primary);"></i>
          <span id="siteModalItemName" style="font-weight:600;"></span>
        </div>
        <div style="font-size:0.78rem;color:var(--cfp-text-mid);margin-bottom:8px;">
          เลือก Site ที่ใช้รายการนี้ <span style="color:var(--cfp-text-muted);">(ไม่เลือก = ไม่มีโรงงานไหนเห็นรายการนี้)</span>
        </div>
        <div class="d-flex gap-2 mb-2">
          <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                  style="font-size:0.72rem;" onclick="selectAllSite(true)">เลือกทั้งหมด</button>
          <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                  style="font-size:0.72rem;" onclick="selectAllSite(false)">ล้างทั้งหมด</button>
        </div>
        <div id="siteCheckboxList">
          <?php foreach ($sites as $s) { ?>
          <div class="form-check mb-1" style="font-size:0.85rem;">
            <input class="form-check-input site-chk" type="checkbox"
                   id="siteChk<?php echo $s['SiteID']; ?>"
                   value="<?php echo $s['SiteID']; ?>">
            <label class="form-check-label" for="siteChk<?php echo $s['SiteID']; ?>">
              <span style="font-weight:500;"><?php echo htmlspecialchars($s['SiteName']); ?></span>
              <span style="font-size:0.72rem;color:var(--cfp-text-muted);"> (<?php echo htmlspecialchars($s['SiteCode']); ?>)</span>
            </label>
          </div>
          <?php } ?>
          <?php if (empty($sites)) { ?>
          <div style="color:var(--cfp-text-muted);font-size:0.82rem;">ยังไม่มี Site — กรุณาเพิ่ม Site ในระบบก่อนค่ะ</div>
          <?php } ?>
        </div>
      </div>
      <div class="modal-footer" style="background:#F9FAFB;">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn-cfp-add" onclick="saveSiteAssign()">
          <i class="bi bi-check-circle me-1"></i>บันทึก
        </button>
      </div>
    </div>
  </div>
</div>
<!-- END Modal Site -->

<!-- Hidden form save site -->
<form id="formSiteAssign" method="POST" action="activityitem_save.php" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="action"  value="assign_site">
  <input type="hidden" name="ItemID"  id="fSiteItemID" value="0">
  <input type="hidden" name="SiteIDs" id="fSiteIDs"    value="">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../assets/js/cfp-table-mobile.js"></script>
<script>
/* Category options แยกตาม Scope — ใช้สลับ populate ช่อง #fCategoryNo เดียวกัน */
var catOptionsScope2 = <?php echo json_encode($scope2Labels, JSON_UNESCAPED_UNICODE); ?>;
var catOptionsScope3 = <?php
    $s3 = array();
    foreach ($catLabels as $c => $lbl) { $s3[$c] = 'Cat.' . $c . ' — ' . $lbl; }
    echo json_encode($s3, JSON_UNESCAPED_UNICODE);
?>;

function populateCategoryOptions(scopeVal) {
    var sel = document.getElementById('fCategoryNo');
    var lbl = document.getElementById('catWrapLabel');
    var opts = (scopeVal === '2') ? catOptionsScope2 : catOptionsScope3;
    lbl.textContent = 'Category (Scope ' + scopeVal + ')';
    sel.innerHTML = '<option value="">— เลือก Category —</option>';
    for (var key in opts) {
        if (opts.hasOwnProperty(key)) {
            var opt = document.createElement('option');
            opt.value = key;
            opt.textContent = opts[key];
            sel.appendChild(opt);
        }
    }
}

var itemData = <?php
    $map = array();
    foreach ($rows as $r) {
        $map[(int)$r['ItemID']] = array(
            'code'       => $r['ItemCode'],
            'name'       => $r['ItemName'],
            'nameEN'     => $r['ItemNameEN'] ?? '',
            'scopeNo'    => (int)$r['ScopeNo'],
            'categoryNo' => $r['CategoryNo'] !== null ? (int)$r['CategoryNo'] : 0,
            'scope1Type' => $r['Scope1Type'] ?? '',
            'unitID'     => (int)($r['UnitID'] ?? 0),
            'inputMethod'=> (int)$r['InputMethod'],
            'desc'       => $r['Description'] ?? '',
            'sort'       => (int)$r['SortOrder'],
            'active'     => (int)$r['IsActive'],
        );
    }
    echo json_encode($map);
?>;

$.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
    var sc  = $('#fltScope').val();
    var cat = $('#fltCat').val();
    var st  = $('#fltStatus').val();
    var row = $('#tblItem').DataTable().row(dataIndex).node();
    if (sc  && $(row).attr('data-scope')  !== sc)  { return false; }
    if (cat && $(row).attr('data-cat')    !== cat) { return false; }
    if (st  && $(row).attr('data-status') !== st)  { return false; }
    return true;
});

var tblApi;
$(document).ready(function() {
    /* ── กู้คืนค่า filter dropdown ที่เลือกไว้ก่อนหน้า (ก่อน save) จาก sessionStorage ── */
    var savedFlt = JSON.parse(sessionStorage.getItem('activityitemFilters') || '{}');
    if (savedFlt.scope)  { $('#fltScope').val(savedFlt.scope); }
    if (savedFlt.cat)    { $('#fltCat').val(savedFlt.cat); }
    if (savedFlt.status) { $('#fltStatus').val(savedFlt.status); }

    tblApi = $('#tblItem').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order: [[3,'asc'],[4,'asc'],[1,'asc']],
        pageLength: 25,
        lengthMenu: [[10,25,50,100],[10,25,50,100]],
        dom: '<"row align-items-center mb-2"<"col-auto"l><"col">>rtip',
        stateSave: true,       /* จำ pagination/คำค้นหา/ลำดับ ให้อัตโนมัติ (DataTables built-in) */
        stateDuration: 3600,   /* จำไว้ 1 ชั่วโมง */
        autoWidth: false,      /* กันไม่ให้ DataTables คำนวณความกว้างคอลัมน์เองแล้วบีบ "ชื่อรายการ" จนแคบ */
        columnDefs: [
            { targets: 0, orderable: false, searchable: false, width: '32px' },
            { targets: 1, width: '40px' },
            { targets: 2, width: '32%' },   /* คอลัมน์ "ชื่อรายการ" */
            { targets: 3, width: '90px' },
            { targets: 4, width: '120px' },
            { targets: 5, width: '80px' },
            { targets: 6, width: '70px' },
            { targets: 7, width: '80px' },
            { targets: 8, width: '110px' },
            { targets: 9, width: '110px', className: 'text-nowrap' }
        ],
        drawCallback: function () { cfpInitMobileExpand('tblItem'); }
    });

    /* ── บันทึกค่า filter ทุกครั้งที่เปลี่ยน + re-apply ตอนโหลดหน้า ── */
    function saveFilterState() {
        sessionStorage.setItem('activityitemFilters', JSON.stringify({
            scope:  $('#fltScope').val(),
            cat:    $('#fltCat').val(),
            status: $('#fltStatus').val()
        }));
    }
    $('#fltKeyword').on('keyup', function() { tblApi.search(this.value).draw(); });
    $('#fltScope, #fltCat, #fltStatus').on('change', function() { saveFilterState(); tblApi.draw(); });

    /* re-apply ค่าที่กู้คืนมาตอนโหลดหน้าเสร็จ (ต้อง draw หลัง DataTable พร้อมแล้ว) */
    if (savedFlt.scope || savedFlt.cat || savedFlt.status) { tblApi.draw(); }

    cfpBindMobileExpand('tblItem');
});

$('#fltKeyword').on('input', function () {
    $(this).closest('.cfp-search-wrap').find('.cfp-search-clear').toggle(this.value.length > 0);
});
function clearKeyword() {
    $('#fltKeyword').val('').trigger('keyup').trigger('input').focus();
}
function clearFilter() {
    $('#fltKeyword,#fltScope,#fltCat,#fltStatus').val('');
    sessionStorage.removeItem('activityitemFilters');
    tblApi.search('').draw();
}

function onScopeChange() {
    var sc   = document.getElementById('fScopeNo').value;
    var wrap = document.getElementById('catWrap');
    var cat  = document.getElementById('fCategoryNo');
    var s1Wrap = document.getElementById('scope1Wrap');
    var s1     = document.getElementById('fScope1Type');
    if (sc === '2' || sc === '3') {
        populateCategoryOptions(sc);
        wrap.style.display=''; cat.required=true;
    } else {
        wrap.style.display='none'; cat.required=false; cat.value='';
    }
    if (sc === '1') { s1Wrap.style.display=''; s1.required=true; }
    else            { s1Wrap.style.display='none'; s1.required=false; s1.value=''; }
}

function openModal(id) {
    /* reset */
    document.getElementById('fAction').value='create'; document.getElementById('fID').value='0';
    document.getElementById('fCodeDisplay').value='ระบบสร้างให้อัตโนมัติ';
    document.getElementById('fScopeNo').value=''; document.getElementById('fCategoryNo').value='';
    document.getElementById('fScope1Type').value='';
    document.getElementById('fName').value=''; document.getElementById('fNameEN').value='';
    document.getElementById('fUnitID').value=''; document.getElementById('fInputMethod').value='1';
    document.getElementById('fSort').value='99'; document.getElementById('fDesc').value='';
    document.getElementById('fActive').value='1';
    document.getElementById('catWrap').style.display='none';
    document.getElementById('scope1Wrap').style.display='none';
    document.getElementById('statusWrap').style.display='none';

    if (id === 0) {
        document.getElementById('modalTitle').innerHTML='<i class="bi bi-plus-circle me-2"></i>เพิ่มรายการกิจกรรม';
    } else {
        var d = itemData[id]; if (!d) { return; }
        document.getElementById('modalTitle').innerHTML='<i class="bi bi-pencil-square me-2"></i>แก้ไขรายการกิจกรรม';
        document.getElementById('fAction').value='update'; document.getElementById('fID').value=id;
        document.getElementById('fCodeDisplay').value=d.code;
        document.getElementById('fScopeNo').value=d.scopeNo;
        document.getElementById('fName').value=d.name; document.getElementById('fNameEN').value=d.nameEN;
        document.getElementById('fUnitID').value=d.unitID||'';
        document.getElementById('fInputMethod').value=d.inputMethod;
        document.getElementById('fSort').value=d.sort; document.getElementById('fDesc').value=d.desc;
        document.getElementById('fActive').value=d.active;
        document.getElementById('statusWrap').style.display='';
        if (d.scopeNo===3) {
            populateCategoryOptions('3');
            document.getElementById('catWrap').style.display='';
            document.getElementById('fCategoryNo').required=true;
            document.getElementById('fCategoryNo').value=d.categoryNo||'';
        }
        if (d.scopeNo===2) {
            populateCategoryOptions('2');
            document.getElementById('catWrap').style.display='';
            document.getElementById('fCategoryNo').required=true;
            document.getElementById('fCategoryNo').value=d.categoryNo||'';
        }
        if (d.scopeNo===1) {
            document.getElementById('scope1Wrap').style.display='';
            document.getElementById('fScope1Type').required=true;
            document.getElementById('fScope1Type').value=d.scope1Type||'';
        }
    }
    new bootstrap.Modal(document.getElementById('modalItem')).show();
}

function confirmToggle(id, cur, name) {
    var act=cur?'ปิดใช้งาน':'เปิดใช้งาน', col=cur?'#DC3545':'#4CAF50';
    Swal.fire({ title:act+' รายการ?', html:'<b>'+name+'</b>', icon:cur?'warning':'question',
        showCancelButton:true, confirmButtonColor:col, cancelButtonColor:'#6C757D',
        confirmButtonText:act, cancelButtonText:'ยกเลิก', reverseButtons:true,
        customClass:{popup:'font-prompt'} })
    .then(function(r) { if (r.isConfirmed) { document.getElementById('ftID').value=id; document.getElementById('formToggle').submit(); } });
}

function confirmDelete(id, name) {
    Swal.fire({ title:'ยืนยันการลบ?', html:'ต้องการลบ "<b>'+name+'</b>" ใช่หรือไม่',
        icon:'warning', showCancelButton:true, confirmButtonColor:'#E05050', cancelButtonColor:'#9E9E9E',
        confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก', customClass:{popup:'font-prompt'} })
    .then(function(r) { if (r.isConfirmed) { document.getElementById('fdID').value=id; document.getElementById('formDelete').submit(); } });
}

var itemSiteData = <?php echo json_encode($itemSiteMap); ?>;
var currentSiteItemID = 0;

function openSiteModal(itemID, itemName) {
    currentSiteItemID = itemID;
    document.getElementById('siteModalItemName').textContent = itemName;
    /* reset checkboxes */
    document.querySelectorAll('.site-chk').forEach(function(c) { c.checked = false; });
    /* โหลดค่าเดิม */
    var assigned = itemSiteData[itemID] || [];
    assigned.forEach(function(sid) {
        var el = document.getElementById('siteChk' + sid);
        if (el) { el.checked = true; }
    });
    new bootstrap.Modal(document.getElementById('modalSite')).show();
}

function selectAllSite(checked) {
    document.querySelectorAll('.site-chk').forEach(function(c) { c.checked = checked; });
}

function saveSiteAssign() {
    var selected = [];
    document.querySelectorAll('.site-chk:checked').forEach(function(c) { selected.push(c.value); });
    document.getElementById('fSiteItemID').value = currentSiteItemID;
    document.getElementById('fSiteIDs').value    = selected.join(',');
    document.getElementById('formSiteAssign').submit();
}

function showToast(msg, isError) {
    var id=isError?'toastError':'toastSuccess', mid=isError?'toastErrMsg':'toastMsg';
    document.getElementById(mid).textContent=msg;
    new bootstrap.Toast(document.getElementById(id),{delay:3000}).show();
}
<?php if ($toastMsg) { ?>
showToast('<?php echo htmlspecialchars(addslashes($toastMsg)); ?>',<?php echo $toastType==='error'?'true':'false'; ?>);
<?php } ?>
</script>
</body>
</html>
<?php
/* ==============================================
   master/vehicle.php
   จัดการข้อมูลยานพาหนะ — เฉพาะ Admin (4)
   แก้ไข: Filter 2 บรรทัด + Search ทำงานได้ (ปรับเป็น searching: true)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4, 5));

$conn      = getConnection();
$myUserID  = (int)$_SESSION['user_id'];

/* ===== Toast จาก session ===== */
$toastMsg  = '';
$toastType = 'success';
if (!empty($_SESSION['toast_msg'])) {
    $toastMsg  = $_SESSION['toast_msg'];
    $toastType = $_SESSION['toast_type'] ?? 'success';
    unset($_SESSION['toast_msg'], $_SESSION['toast_type']);
}

/* ===== ดึง Site dropdown ===== */
$resSite = sqlsrv_query($conn, "SELECT SiteID, SiteCode, SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
$sites   = array();
while ($r = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) { $sites[] = $r; }

$siteCodeMap = array();
foreach ($sites as $s) { $siteCodeMap[(int)$s['SiteID']] = $s['SiteCode']; }

$resMaxBySite = sqlsrv_query($conn,
    "SELECT SiteID, MAX(VehicleCode) AS MaxCode FROM CFP_Vehicle
     WHERE VehicleCode LIKE 'VH-%' GROUP BY SiteID");
$maxCodeBySite = array();
if ($resMaxBySite) {
    while ($rx = sqlsrv_fetch_array($resMaxBySite, SQLSRV_FETCH_ASSOC)) {
        $maxCodeBySite[(int)$rx['SiteID']] = $rx['MaxCode'];
    }
}

/* ===== ประเภทพาหนะ ===== */
$resVT = @sqlsrv_query($conn, "SELECT TypeID, TypeCode, TypeName FROM CFP_VehicleType WHERE IsActive=1 ORDER BY SortOrder, TypeName");
$vehicleTypes = array();
if ($resVT) { while ($r = sqlsrv_fetch_array($resVT, SQLSRV_FETCH_ASSOC)) { $vehicleTypes[] = $r; } }

/* ===== ประเภทเชื้อเพลิง ===== */
$resFuel = @sqlsrv_query($conn, "SELECT TypeID, TypeCode, TypeName FROM CFP_FuelType WHERE IsActive=1 ORDER BY TypeName");
$fuelTypes = array();
if ($resFuel) { while ($r = sqlsrv_fetch_array($resFuel, SQLSRV_FETCH_ASSOC)) { $fuelTypes[] = $r; } }

/* ===== ดึงรายการยานพาหนะ ===== */
$sql = "
    SELECT
        v.VehicleID, v.VehicleCode, v.VehicleName,
        v.VehicleTypeID, vt.TypeName AS VehicleTypeName,
        v.LicensePlate, v.FuelTypeID, f.TypeName AS FuelTypeName,
        v.CombustionType, v.EngineSize, v.YearModel,
        v.SiteID, s.SiteName,
        v.Remark, v.IsActive, v.CreatedDate
    FROM CFP_Vehicle v
    LEFT JOIN CFP_Site s ON s.SiteID = v.SiteID
    LEFT JOIN CFP_VehicleType vt ON vt.TypeID = v.VehicleTypeID
    LEFT JOIN CFP_FuelType f ON f.TypeID = v.FuelTypeID
    ORDER BY v.VehicleCode
";
$res  = @sqlsrv_query($conn, $sql);
$rows = array();
if ($res) { while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; } }

/* ---- Filter options (จาก Master) ---- */
$siteFilterOptions = array();
$resSiteAll = sqlsrv_query($conn, "SELECT SiteID, SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
if ($resSiteAll) {
    while ($row = sqlsrv_fetch_array($resSiteAll, SQLSRV_FETCH_ASSOC)) {
        $siteFilterOptions[$row['SiteID']] = $row['SiteName'];
    }
}

$typeFilterOptions = array();
$resTypeAll = sqlsrv_query($conn, "SELECT TypeID, TypeName FROM CFP_VehicleType WHERE IsActive=1 ORDER BY TypeName");
if ($resTypeAll) {
    while ($row = sqlsrv_fetch_array($resTypeAll, SQLSRV_FETCH_ASSOC)) {
        $typeFilterOptions[$row['TypeID']] = $row['TypeName'];
    }
}

$fuelFilterOptions = array();
$resFuelAll = sqlsrv_query($conn, "SELECT TypeID, TypeName FROM CFP_FuelType WHERE IsActive=1 ORDER BY TypeName");
if ($resFuelAll) {
    while ($row = sqlsrv_fetch_array($resFuelAll, SQLSRV_FETCH_ASSOC)) {
        $fuelFilterOptions[$row['TypeID']] = $row['TypeName'];
    }
}
/* ===== สร้าง $map สำหรับ JavaScript ===== */
$map = array();
foreach ($rows as $r) {
    $map[$r['VehicleID']] = array(
        'code'      => $r['VehicleCode'],
        'name'      => $r['VehicleName'],
        'type'      => $r['VehicleTypeID'] ? (int)$r['VehicleTypeID'] : '',
        'site'      => $r['SiteID'] ? (int)$r['SiteID'] : '',
        'fuel'      => $r['FuelTypeID'] ? (int)$r['FuelTypeID'] : '',
        'combustion'=> $r['CombustionType'] ?? 'Mobile',
        'plate'     => $r['LicensePlate'] ?? '',
        'engine'    => $r['EngineSize'] ? (float)$r['EngineSize'] : '',
        'year'      => $r['YearModel'] ? (int)$r['YearModel'] : '',
        'remark'    => $r['Remark'] ?? '',
    );
}

$pageTitle = 'ยานพาหนะ';
$pageIcon  = 'truck';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ยานพาหนะ — ระบบบริหารจัดการคาร์บอนองค์กร</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="/carbonfootprint/assets/css/cfp-theme.css?v=<?php echo filemtime('../assets/css/cfp-theme.css'); ?>" rel="stylesheet">

  <!-- Krajee FileInput + Font Awesome -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/css/fileinput.min.css" media="all" rel="stylesheet">

  <style>
    body { font-family: 'Prompt', sans-serif; }
    .font-prompt { font-family: 'Prompt', sans-serif !important; }
    .btn-action { width:30px; height:30px; padding:0; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; font-size:0.8rem; }
    .status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
    .file-drop-zone { border:2px dashed #2AABB8 !important; border-radius:10px !important; background:#EEF6F8 !important; }
    .file-drop-zone:hover { background:#D8EDF4 !important; }
    .file-preview-frame { border-radius:8px !important; }
    .file-preview-frame:hover { border-color:#2AABB8 !important; box-shadow:0 0 10px rgba(42,171,184,0.2) !important; }
    .file-actions .file-upload-button { display:none !important; }
    .cfp-btn-del:hover { background:rgba(220,53,69,1) !important; transform:scale(1.1); }

    /* ---- Toolbar 2 บรรทัด (อยู่ภายใน Card) ---- */
    .cfp-page-toolbar {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-bottom: 14px;
        width: 100%;
    }
    .toolbar-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        width: 100%;
    }
    .toolbar-row .toolbar-left {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        flex: 1 1 auto;
    }
    .toolbar-row .toolbar-left .form-control,
    .toolbar-row .toolbar-left .form-select {
        font-size: 0.85rem;
        font-family: 'Prompt', sans-serif;
    }
    .toolbar-row .toolbar-left .form-control {
        min-width: 200px;
        max-width: 300px;
        flex: 0 1 auto;
    }
    .toolbar-row .toolbar-left .form-select {
        min-width: 130px;
        max-width: 180px;
        flex: 0 1 auto;
    }
    .toolbar-actions {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-shrink: 0;
        margin-left: auto;
    }
    .toolbar-row-filters .toolbar-left {
        flex: 1 1 auto;
    }
    .toolbar-row-filters .toolbar-left .form-select {
        min-width: 140px;
        max-width: 200px;
    }

    @media (max-width: 768px) {
        .toolbar-row { flex-direction: column; align-items: stretch; }
        .toolbar-row .toolbar-left { flex-wrap: wrap; }
        .toolbar-row .toolbar-left .form-control,
        .toolbar-row .toolbar-left .form-select {
            min-width: 100%;
            max-width: 100%;
            flex: 1 1 100%;
        }
        .toolbar-actions {
            justify-content: stretch;
            margin-left: 0;
            width: 100%;
        }
        .toolbar-actions .btn { flex: 1; }
    }
  </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<div class="cfp-main">
  <?php include '../includes/topbar.php'; ?>

  <div class="cfp-content">

    <!-- Page Header -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <div>
        <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);">
          <i class="bi bi-truck me-2" style="color:var(--cfp-green);"></i>จัดการยานพาหนะ
        </h5>
        <div style="font-size:0.78rem;color:var(--cfp-text-muted);margin-top:2px;">
          ทะเบียนทรัพย์สิน (Scope 1) › ยานพาหนะ
        </div>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-3">
      <?php
        $total  = count($rows);
        $active = count(array_filter($rows, function($r){ return (bool)$r['IsActive']; }));
        $siteCount = count(array_unique(array_filter(array_column($rows, 'SiteID'))));
      ?>
      <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
          <div style="font-size:1.6rem;font-weight:700;color:var(--cfp-primary);"><?php echo $total; ?></div>
          <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ทั้งหมด</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
          <div style="font-size:1.6rem;font-weight:700;color:var(--cfp-green);"><?php echo $active; ?></div>
          <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ใช้งาน</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
          <div style="font-size:1.6rem;font-weight:700;color:#F57C00;"><?php echo $total - $active; ?></div>
          <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ปิดใช้งาน</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
          <div style="font-size:1.6rem;font-weight:700;color:#7B1FA2;"><?php echo $siteCount; ?></div>
          <div style="font-size:0.75rem;color:var(--cfp-text-muted);">Site ที่มียานพาหนะ</div>
        </div>
      </div>
    </div>

    <!-- ===== Table Card (พร้อม Toolbar 2 บรรทัด) ===== -->
    <div class="cfp-card">
        <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);margin-bottom:12px;">
          <i class="bi bi-card-list me-2"></i>รายการยานพาหนะ
        </div>

      <!-- Toolbar 2 บรรทัด -->
      <div class="cfp-page-toolbar">
          <!-- บรรทัดที่ 1: ค้นหา + สถานะ + ล้าง + ปุ่มเพิ่ม -->
          <div class="toolbar-row">
              <div class="toolbar-left">
                  <input type="text" id="fltKeyword" class="form-control font-prompt"
                         placeholder="ค้นหารหัส / ชื่อยานพาหนะ / ทะเบียน...">
                  <select id="fltStatus" class="form-select font-prompt">
                      <option value="">สถานะทั้งหมด</option>
                      <option value="ใช้งาน">ใช้งาน</option>
                      <option value="ปิด">ปิดใช้งาน</option>
                  </select>
                  <button class="btn btn-outline-secondary btn-sm" onclick="clearFilter()">
                      <i class="bi bi-x-circle me-1"></i>ล้าง
                  </button>
              </div>
              <div class="toolbar-actions">
                  <button class="btn-cfp-add btn-sm" onclick="openModal(0)">
                      <i class="bi bi-plus-circle me-1"></i>เพิ่มยานพาหนะ
                  </button>
              </div>
          </div>

          <!-- บรรทัดที่ 2: Site + ประเภท + เชื้อเพลิง -->
          <div class="toolbar-row toolbar-row-filters">
              <div class="toolbar-left">
                  <select id="fltSite" class="form-select font-prompt">
                      <option value="">Site ทั้งหมด</option>
                      <?php foreach ($siteFilterOptions as $id => $name) { ?>
                      <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
                      <?php } ?>
                  </select>
                  <select id="fltType" class="form-select font-prompt">
                      <option value="">ประเภททั้งหมด</option>
                      <?php foreach ($typeFilterOptions as $id => $name) { ?>
                      <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
                      <?php } ?>
                  </select>
                  <select id="fltFuel" class="form-select font-prompt">
                      <option value="">เชื้อเพลิงทั้งหมด</option>
                      <?php foreach ($fuelFilterOptions as $id => $name) { ?>
                      <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
                      <?php } ?>
                  </select>
              </div>
          </div>
      </div>

      <div class="table-responsive">
        <table id="tblVehicle" class="table table-bordered table-hover align-middle" style="width:100%">
          <thead>
            <tr>
              <th class="cfp-th-expand"></th>
              <th class="cfp-th-num" style="width:40px">#</th>
              <th style="width:120px">ทะเบียนรถ</th>
              <th class="cfp-col-hide" style="width:140px">ประเภท</th>
              <th class="cfp-col-hide" style="width:120px">เชื้อเพลิง</th>
              <th class="cfp-col-hide" style="width:110px">การเผาไหม้</th>
              <th class="cfp-col-hide" style="width:100px">Site</th>
              <th class="text-center" style="width:80px">สถานะ</th>
              <th class="cfp-col-hide text-center" style="width:90px">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $i => $r) { ?>
            <tr data-status="<?php echo $r['IsActive'] ? 'ใช้งาน' : 'ปิด'; ?>"
                data-site="<?php echo htmlspecialchars($r['SiteName'] ?? ''); ?>"
                data-type="<?php echo htmlspecialchars($r['VehicleTypeName'] ?? ''); ?>"
                data-fuel="<?php echo htmlspecialchars($r['FuelTypeName'] ?? ''); ?>">
              <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
              <td class="cfp-td-num"><?php echo $i + 1; ?></td>
              <td>
                <?php echo htmlspecialchars($r['LicensePlate'] ?? '—'); ?>
                <div><code style="font-size:0.7rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($r['VehicleCode']); ?></code></div>
              </td>
              <td class="cfp-col-hide"><?php echo htmlspecialchars($r['VehicleTypeName'] ?? '—'); ?></td>
              <td class="cfp-col-hide"><?php echo htmlspecialchars($r['FuelTypeName'] ?? '—'); ?></td>
              <td class="cfp-col-hide"><span style="font-size:0.78rem;"><?php echo $r['CombustionType'] ?? '—'; ?></span></td>
              <td class="cfp-col-hide"><?php echo htmlspecialchars($r['SiteName'] ?? '—'); ?></td>
              <td class="text-center">
                <?php if ($r['IsActive']) { ?>
                  <span class="status-dot" style="background:#4CAF50;"></span><span style="font-size:0.78rem;color:#2E7D32;"> ใช้งาน</span>
                <?php } else { ?>
                  <span class="status-dot" style="background:#ccc;"></span><span style="font-size:0.78rem;color:#9E9E9E;"> ปิด</span>
                <?php } ?>
              </td>
              <td class="cfp-col-hide text-center">
                <div class="cfp-action-group">
                  <button class="btn btn-outline-primary btn-action me-1 cfp-act-primary"
                          onclick="openModal(<?php echo $r['VehicleID']; ?>)" title="แก้ไข">
                    <i class="bi bi-pencil-square"></i><span class="cfp-act-label">แก้ไข</span>
                  </button>
                  <div class="cfp-act-secondary">
                    <button class="btn btn-action <?php echo $r['IsActive'] ? 'btn-outline-danger' : 'btn-outline-success'; ?> cfp-act-toggle"
                            onclick="confirmToggle(<?php echo $r['VehicleID']; ?>, <?php echo $r['IsActive'] ? 1 : 0; ?>, '<?php echo htmlspecialchars(addslashes($r['VehicleName'])); ?>')"
                            title="<?php echo $r['IsActive'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน'; ?>">
                      <i class="bi bi-<?php echo $r['IsActive'] ? 'toggle2-off' : 'toggle2-on'; ?>"></i><span class="cfp-act-label"><?php echo $r['IsActive'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน'; ?></span>
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

  </div><!-- /.cfp-content -->
</div><!-- /.cfp-main -->

<!-- ===== Modal เพิ่ม/แก้ไขยานพาหนะ ===== -->
<div class="modal fade" id="modalVehicle" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content" style="font-family:'Prompt',sans-serif;">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff;border-radius:var(--bs-modal-inner-border-radius) var(--bs-modal-inner-border-radius) 0 0;">
        <h6 class="modal-title mb-0" id="modalTitle">
          <i class="bi bi-truck me-2"></i>เพิ่มยานพาหนะ
        </h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formVehicle" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action"     id="fAction"    value="create">
        <input type="hidden" name="id"         id="fID"        value="0">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div class="modal-body">
          <div class="row g-3">

            <!-- รหัส + ชื่อ -->
            <div class="col-md-4">
              <label class="form-label">รหัส (Auto)</label>
              <input type="text" class="form-control" name="VehicleCode" id="fCode"
                     maxlength="50" readonly
                     style="font-family:'Prompt',sans-serif;background:#f8f9fa;">
            </div>
            <div class="col-md-4">
              <label class="form-label form-required">ทะเบียนรถ</label>
              <input type="text" class="form-control" name="LicensePlate" id="fPlate"
                     placeholder="กข-1234" maxlength="50" required
                     style="font-family:'Prompt',sans-serif;text-transform:uppercase">
            </div>
            <div class="col-md-4">
              <label class="form-label form-required">Site</label>
              <select class="form-select" name="SiteID" id="fSite"
                      required onchange="updateAutoCode()"
                      style="font-family:'Prompt',sans-serif">
                <option value="">— เลือก Site —</option>
                <?php foreach ($sites as $s) { ?>
                <option value="<?php echo $s['SiteID']; ?>"><?php echo htmlspecialchars($s['SiteName']); ?></option>
                <?php } ?>
              </select>
            </div>

            <input type="hidden" name="VehicleName" id="fName">
            <input type="hidden" name="YearModel"  id="fYear">
            <input type="hidden" name="EngineSize" id="fEngine">

            <!-- ประเภทพาหนะ + เชื้อเพลิง + การเผาไหม้ -->
            <div class="col-md-4">
              <label class="form-label">ประเภทพาหนะ</label>
              <select class="form-select" name="VehicleTypeID" id="fType"
                      style="font-family:'Prompt',sans-serif">
                <option value="">— เลือกประเภท —</option>
                <?php foreach ($vehicleTypes as $t) { ?>
                <option value="<?php echo $t['TypeID']; ?>"><?php echo htmlspecialchars($t['TypeName']); ?></option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">ประเภทเชื้อเพลิง</label>
              <select class="form-select" name="FuelTypeID" id="fFuel"
                      style="font-family:'Prompt',sans-serif">
                <option value="">— ไม่ระบุ —</option>
                <?php foreach ($fuelTypes as $f) { ?>
                <option value="<?php echo $f['TypeID']; ?>"><?php echo htmlspecialchars($f['TypeName']); ?></option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">ประเภทการเผาไหม้</label>
              <select class="form-select" name="CombustionType" id="fCombustion"
                      style="font-family:'Prompt',sans-serif">
                <option value="Mobile">Mobile — เคลื่อนที่</option>
                <option value="Stationary">Stationary — อยู่กับที่</option>
              </select>
            </div>

            <!-- หมายเหตุ -->
            <div class="col-12">
              <label class="form-label">หมายเหตุ</label>
              <textarea class="form-control" name="Remark" id="fRemark"
                        rows="2" maxlength="500" placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"
                        style="font-family:'Prompt',sans-serif;resize:none;"></textarea>
            </div>

          </div><!-- /.row -->

          <!-- ===== Upload Panel (Krajee) ===== -->
          <div class="form-group mt-3">
            <label style="font-weight:600;color:var(--cfp-primary);">
              <i class="bi bi-images me-1"></i>รูปภาพ / เอกสารแนบ
              <span class="text-secondary small" style="font-weight:400;">
                รองรับ .png .jpg .pdf &nbsp;|&nbsp; สูงสุด 10 ไฟล์ &nbsp;|&nbsp; รวมไม่เกิน 10 MB
              </span>
            </label>
            <div class="file-loading">
              <input id="file-1" name="file[]" type="file" class="file" multiple
                     data-browse-on-zone-click="true">
            </div>
            <div id="cfp-file-error" class="mt-2 text-danger small" style="display:none;"></div>
          </div>

        </div><!-- /.modal-body -->
        <div class="modal-footer" style="background:#F9FAFB;">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
            <i class="bi bi-x-circle me-1"></i>ยกเลิก
          </button>
          <button type="button" class="btn-cfp-add btn-sm" id="btnSaveVehicle">
            <i class="bi bi-check-circle me-1"></i>บันทึก
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999">
  <div id="toastSuccess" class="toast align-items-center text-bg-success border-0" style="font-family:'Prompt',sans-serif;">
    <div class="d-flex">
      <div class="toast-body" id="toastMsg"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
  <div id="toastError" class="toast align-items-center text-bg-danger border-0" style="font-family:'Prompt',sans-serif;">
    <div class="d-flex">
      <div class="toast-body" id="toastErrMsg"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../assets/js/cfp-table-mobile.js"></script>
<script src="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/js/fileinput.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/themes/fas/theme.min.js"></script>

<script>
// ============================================================
// ตัวแปรหลัก
// ============================================================
var currentAssetID     = 0;
var previewCount       = 0;
var savedPreviewFiles  = [];
var savedPreviewConfig = [];
var pendingFiles       = [];
var pendingDeletes     = [];
var _cfpDupTimer       = null;

var vehicleData = <?php echo json_encode($map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var csrfToken   = <?php echo json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

var SITE_CODES  = <?php echo json_encode($siteCodeMap, JSON_UNESCAPED_UNICODE); ?>;
var MAX_CODES   = <?php echo json_encode($maxCodeBySite, JSON_UNESCAPED_UNICODE); ?>;

function updateAutoCode() {
    var isEdit = (parseInt(document.getElementById('fID').value) || 0) > 0;
    if (isEdit) { return; }
    var siteID = parseInt(document.getElementById('fSite').value) || 0;
    var codeEl = document.getElementById('fCode');
    if (!siteID) { codeEl.value = ''; return; }
    var sc      = SITE_CODES[siteID] || 'SITE';
    var maxCode = MAX_CODES[siteID]  || '';
    var nextNum = 1;
    if (maxCode) {
        var m = maxCode.match(/(\d+)$/);
        if (m) { nextNum = parseInt(m[1]) + 1; }
    }
    codeEl.value = 'VH-' + sc + '-' + String(nextNum).padStart(3, '0');
}

// ============================================================
// โหลดรูปจาก DB
// ============================================================
function loadPreviewFromDB(assetID, callback) {
    var previewFiles  = [];
    var previewConfig = [];
    if (assetID <= 0) {
        savedPreviewFiles  = [];
        savedPreviewConfig = [];
        if (callback) { callback([], []); }
        return;
    }
    $.ajax({
        url: 'get_asset_images.php',
        method: 'POST',
        data: { asset_type: 'Vehicle', asset_id: assetID },
        dataType: 'json',
        async: false,
        success: function(res) {
            if (res.success && res.images) {
                res.images.forEach(function(img) {
                    previewFiles.push(img.url);
                    var fileType = 'file';
                    var urlLower = (img.url || '').toLowerCase();
                    if (urlLower.match(/\.(jpg|jpeg|png|gif|webp)$/)) {
                        fileType = 'image';
                    } else if (urlLower.match(/\.pdf$/)) {
                        fileType = 'pdf';
                    }
                    previewConfig.push({
                        caption: img.caption,
                        size: img.size,
                        key: img.id,
                        type: fileType
                    });
                });
            }
            savedPreviewFiles  = previewFiles.slice();
            savedPreviewConfig = previewConfig.slice();
        }
    });
    if (callback) { callback(previewFiles, previewConfig); }
}

// ============================================================
// initFileInput
// ============================================================
function initFileInput(assetID, previewFiles, previewConfig) {
    currentAssetID = assetID;
    pendingFiles   = [];
    if (!previewFiles || !previewConfig) {
        loadPreviewFromDB(assetID, function(files, config) {
            savedPreviewFiles  = files.slice();
            savedPreviewConfig = config.slice();
            previewCount = files.length;
            _initFileInput(files, config);
        });
    } else {
        savedPreviewFiles  = previewFiles.slice();
        savedPreviewConfig = previewConfig.slice();
        previewCount = previewFiles.length;
        _initFileInput(previewFiles, previewConfig);
    }
}

// ============================================================
// _initFileInput — Krajee FileInput
// ============================================================
function _initFileInput(previewFiles, previewConfig) {
    if ($('#file-1').data('fileinput')) {
        $('#file-1').fileinput('destroy');
    }

    var isEdit = (currentAssetID > 0);

    $('#file-1').fileinput({
        theme                   : 'fas',
        uploadUrl               : 'vehicle_save.php',
        uploadAsync             : false,
        showUpload              : false,
        showRemove              : false,
        autoUpload              : false,
        overwriteInitial        : false,
        append                  : true,
        deleteUrl               : false,
        initialPreviewAsData    : true,
        allowedFileExtensions   : ['png', 'jpg', 'jpeg', 'pdf'],
        maxFileSize             : 10240,
        maxFileCount            : 10,
        previewFileType         : 'any',
        fileActionSettings      : { showUpload: false, showRemove: true, showZoom: true, showDrag: false },
        msgInvalidFileExtension : 'ไฟล์ "{name}" ไม่รองรับ กรุณาเลือกเฉพาะ .png .jpg .pdf',
        msgSizeTooLarge         : 'ไฟล์ "{name}" ขนาด {size} KB ใหญ่เกินไป (สูงสุด {maxSize} KB)',
        msgFilesTooMany         : 'เลือกไฟล์มากเกินไป ({n} ไฟล์) สูงสุด {m} ไฟล์รวมกัน',
        initialPreview          : previewFiles,
        initialPreviewConfig    : previewConfig,
        removeFromPreviewOnError: true,
        dropZoneEnabled         : true,
        browseOnZoneClick       : true,
        uploadExtraData         : function() {
            var data = {};
            $('#formVehicle').serializeArray().forEach(function(item) {
                if (data[item.name] !== undefined) { data[item.name] += ',' + item.value; }
                else { data[item.name] = item.value; }
            });
            data['action']          = $('#fAction').val() || 'create';
            data['id']              = $('#fID').val() || '0';
            data['asset_type']      = 'Vehicle';
            data['asset_id']        = currentAssetID;
            data['csrf_token']      = $('input[name="csrf_token"]').val() || csrfToken;
            data['pending_deletes'] = pendingDeletes.join(',');
            return data;
        }
    })
    .on('fileshown', function() { injectDeleteButtons(); })
    .on('fileloaded', function(event, file, previewId, index) {
        pendingFiles.push(file.name.toLowerCase());
        setTimeout(function() { injectNewFileDeleteBtn(previewId, index); }, 100);
    })
    .on('filecleared filereset', function() {
        pendingFiles = [];
        $('#cfp-file-error').hide().text('');
    })
    .on('fileremoved', function(event, id, index) {
        if (index >= 0 && index < pendingFiles.length) { pendingFiles.splice(index, 1); }
        $('#cfp-file-error').hide().text('');
    })
    .on('fileerror', function(event, data, msg) {
        showUploadErrorSwal('ไม่สามารถเพิ่มไฟล์ได้', [msg], null);
    })
    .on('filebatchuploadsuccess', function(event, data) {
        var response = data.response;
        if (response && response.success) {
            var successTitle = isEdit ? 'บันทึกข้อมูลสำเร็จ!' : 'เพิ่มข้อมูลสำเร็จ!';
            var modalEl = document.getElementById('modalVehicle');
            var bsModal = bootstrap.Modal.getInstance(modalEl);

            if (modalEl._cfpSaving) return;
            modalEl._cfpSaving = true;

            bsModal.hide();

            modalEl.addEventListener('hidden.bs.modal', function onHidden() {
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                modalEl._cfpSaving = false;

                Swal.fire({
                    icon: 'success',
                    title: successTitle,
                    timer: 5000,
                    timerProgressBar: true,
                    showConfirmButton: true,
                    confirmButtonText: 'ตกลง',
                    confirmButtonColor: '#2AABB8',
                    allowOutsideClick: false,
                    customClass: { popup: 'font-prompt' }
                }).then(function() {
                    location.reload();
                });
            });
        } else {
            Swal.fire({
                icon: 'error', title: 'เกิดข้อผิดพลาด',
                text: response.msg || 'ไม่สามารถบันทึกข้อมูลได้',
                confirmButtonText: 'ตกลง', confirmButtonColor: '#DC3545',
                customClass: { popup: 'font-prompt' }
            });
            $('#btnSaveVehicle').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>บันทึก');
        }
    })
    .on('filebatchuploaderror', function(event, data, msg) {
        var errorMsg = (data.response && data.response.msg) ? data.response.msg : (msg || 'อัปโหลดไฟล์ล้มเหลว');
        Swal.fire({ icon: 'error', title: 'อัปโหลดไฟล์ไม่สำเร็จ', text: errorMsg, customClass: { popup: 'font-prompt' } });
        $('#btnSaveVehicle').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>บันทึก');
    });

    setTimeout(injectDeleteButtons, 600);

    // ===== ดัก change =====
    var inputEl = document.getElementById('file-1');
    if (inputEl._cfpDupHandler) {
        inputEl.removeEventListener('change', inputEl._cfpDupHandler, true);
    }
    inputEl._cfpDupHandler = function() {
        var files = inputEl.files;
        if (!files || !files.length) { return; }

        var dupNames = [];
        var dt = new DataTransfer();

        for (var i = 0; i < files.length; i++) {
            var name = files[i].name.toLowerCase();
            var dupInDB = savedPreviewConfig.some(function(c) {
                return (c.caption || '').toLowerCase() === name;
            });
            var dupInPending = pendingFiles.some(function(f) { return f === name; });
            var dupInBatch = false;
            for (var j = 0; j < i; j++) {
                if (files[j].name.toLowerCase() === name) { dupInBatch = true; break; }
            }

            if (dupInDB || dupInPending || dupInBatch) {
                dupNames.push(files[i].name);
            } else {
                dt.items.add(files[i]);
            }
        }

        if (dupNames.length > 0) {
            try { inputEl.files = dt.files; } catch(ex) {}
            clearTimeout(_cfpDupTimer);
            _cfpDupTimer = setTimeout(function() {
                var names = dupNames.slice();
                showUploadErrorSwal(
                    names.length === 1 ? 'พบไฟล์ซ้ำ' : 'พบไฟล์ซ้ำ ' + names.length + ' ไฟล์',
                    names.map(function(n) { return '<i class="fa fa-file me-1"></i>' + n; }),
                    'ไฟล์เหล่านี้มีอยู่ในระบบแล้ว จึงไม่ถูกเพิ่มเข้าในรายการ'
                );
            }, 50);
        }
    };
    inputEl.addEventListener('change', inputEl._cfpDupHandler, true);
}

// ============================================================
// showUploadErrorSwal
// ============================================================
function showUploadErrorSwal(title, items, subtitle) {
    var itemsHtml = items.map(function(item) { return '<div class="cfp-err-item">' + item + '</div>'; }).join('');
    var subtitleHtml = subtitle ? '<p class="cfp-err-subtitle">' + subtitle + '</p>' : '';
    Swal.fire({
        icon: 'warning',
        title: '<span class="cfp-err-title">' + title + '</span>',
        html: subtitleHtml + '<div class="cfp-err-list">' + itemsHtml + '</div>',
        confirmButtonText: 'ตกลง รับทราบ',
        confirmButtonColor: '#2AABB8',
        customClass: { popup: 'font-prompt cfp-upload-err-popup' },
        didOpen: function() {
            if (!document.getElementById('cfp-err-style')) {
                var s = document.createElement('style');
                s.id = 'cfp-err-style';
                s.textContent = '.cfp-upload-err-popup{max-width:420px!important;border-radius:14px!important;}' +
                    '.cfp-err-title{font-size:1rem;font-weight:600;color:#E65100;}' +
                    '.cfp-err-subtitle{font-size:0.78rem;color:#666;margin:4px 0 10px;}' +
                    '.cfp-err-list{background:#FFF8F0;border:1px solid #FFE0B2;border-radius:8px;padding:8px 12px;text-align:left;max-height:160px;overflow-y:auto;margin-top:6px;}' +
                    '.cfp-err-item{font-size:0.78rem;color:#BF360C;padding:3px 0;border-bottom:1px dashed #FFCC80;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
                    '.cfp-err-item:last-child{border-bottom:none;}';
                document.head.appendChild(s);
            }
        }
    });
}

// ============================================================
// injectDeleteButtons
// ============================================================
function injectDeleteButtons() {
    var $wrap = $('#file-1').closest('.file-input');
    $wrap.find('.file-preview-frame').each(function() {
        var $frame  = $(this);
        var frameId = $frame.attr('id') || '';
        if (frameId.indexOf('init') < 0) { return; }
        $frame.find('.kv-file-remove').hide();
        if ($frame.find('.cfp-btn-del').length) { return; }
        var match = frameId.match(/init-(\d+)$/);
        if (!match) { return; }
        var frameIdx = parseInt(match[1], 10);
        var cfg = savedPreviewConfig[frameIdx];
        if (!cfg || !cfg.key) { return; }
        appendDelBtn($frame, function() { confirmDeleteFromDB(cfg.key); });
    });
}

// ============================================================
// injectNewFileDeleteBtn
// ============================================================
function injectNewFileDeleteBtn(previewId, fileIndex) {
    var $frame = $('#' + previewId);
    if (!$frame.length || $frame.find('.cfp-btn-del').length) { return; }
    $frame.find('.kv-file-remove').hide();
    appendDelBtn($frame, function() {
        pendingFiles.splice(fileIndex, 1);
        $frame.remove();
        $('#cfp-file-error').hide().text('');
    });
}

// ============================================================
// appendDelBtn
// ============================================================
function appendDelBtn($frame, onConfirm) {
    var $btn = $(
        '<button type="button" class="cfp-btn-del" title="ลบไฟล์" ' +
        'style="position:absolute;top:3px;right:3px;z-index:9999;' +
        'background:rgba(220,53,69,0.85);border:none;border-radius:50%;' +
        'width:24px;height:24px;padding:0;cursor:pointer;' +
        'display:flex;align-items:center;justify-content:center;">' +
        '<i class="fa fa-times" style="color:#fff;font-size:11px;pointer-events:none;"></i>' +
        '</button>'
    );
    $frame.css('position', 'relative').append($btn);
    $btn.on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        onConfirm();
    });
}

// ============================================================
// confirmDeleteFromDB
// ============================================================
function confirmDeleteFromDB(key) {
    Swal.fire({
        title: 'ยืนยันการลบไฟล์?',
        text : 'ไฟล์จะถูกลบเมื่อกดบันทึก',
        icon : 'warning',
        showCancelButton : true,
        confirmButtonColor: '#DC3545',
        cancelButtonColor : '#6C757D',
        confirmButtonText: 'ลบ',
        cancelButtonText : 'ยกเลิก',
        reverseButtons   : true,
        customClass      : { popup: 'font-prompt' }
    }).then(function(result) {
        if (!result.isConfirmed) { return; }

        pendingDeletes.push(key);

        var frameIdx = -1;
        for (var i = 0; i < savedPreviewConfig.length; i++) {
            if (savedPreviewConfig[i].key == key) { frameIdx = i; break; }
        }
        if (frameIdx >= 0) {
            $('#thumb-file-1-init-' + frameIdx).fadeOut(150, function() { $(this).remove(); });
            savedPreviewConfig.splice(frameIdx, 1);
            savedPreviewFiles.splice(frameIdx, 1);
            previewCount = savedPreviewConfig.length;
        }
    });
}

// ============================================================
// openModal
// ============================================================
function openModal(id) {
    var form = document.getElementById('formVehicle');
    form.reset();
    pendingFiles   = [];
    pendingDeletes = [];
    $('#cfp-file-error').hide().text('');

    if (id === 0) {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-truck me-2"></i>เพิ่มยานพาหนะ';
        document.getElementById('fAction').value = 'create';
        document.getElementById('fID').value     = '0';
        currentAssetID     = 0;
        document.getElementById('fCode').value = '';
        savedPreviewFiles  = [];
        savedPreviewConfig = [];
        previewCount       = 0;
        initFileInput(0);
    } else {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขยานพาหนะ';
        document.getElementById('fAction').value = 'update';
        document.getElementById('fID').value     = id;
        currentAssetID = id;

        var d = vehicleData[id];
        if (d) {
            document.getElementById('fCode').value      = d.code      || '';
            document.getElementById('fName').value      = d.name      || '';
            document.getElementById('fPlate').value     = d.plate     || '';
            document.getElementById('fYear').value      = d.year      || '';
            document.getElementById('fEngine').value    = d.engine    || '';
            document.getElementById('fType').value      = d.type      || '';
            document.getElementById('fSite').value      = d.site      || '';
            document.getElementById('fFuel').value      = d.fuel      || '';
            document.getElementById('fCombustion').value = d.combustion || 'Mobile';
            document.getElementById('fRemark').value   = d.remark     || '';
        }

        loadPreviewFromDB(id, function(files, config) {
            savedPreviewFiles  = files.slice();
            savedPreviewConfig = config.slice();
            previewCount       = files.length;
            initFileInput(id, files, config);
        });
    }

    $('#btnSaveVehicle').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>บันทึก');
    new bootstrap.Modal(document.getElementById('modalVehicle')).show();
}

// ============================================================
// Pre-fill จากคำขอเพิ่มทรัพย์สิน (master/asset_requests.php ส่ง query string มา)
// ============================================================
function cfpSelectByText(selectEl, text) {
    if (!selectEl || !text) { return; }
    text = text.trim();
    for (var i = 0; i < selectEl.options.length; i++) {
        if (selectEl.options[i].text.indexOf(text) !== -1) { selectEl.selectedIndex = i; return; }
    }
}

function cfpApplyPrefillFromRequest() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('prefill') !== '1') { return; }

    openModal(0);

    var name = params.get('name');
    var site = params.get('site');
    var vtype = params.get('vehicletype');
    var fuel = params.get('fueltype');
    var remark = params.get('remark');

    if (name) { document.getElementById('fPlate').value = name; }
    if (site) { document.getElementById('fSite').value = site; }
    if (vtype) { cfpSelectByText(document.getElementById('fType'), vtype); }
    if (fuel) { cfpSelectByText(document.getElementById('fFuel'), fuel); }
    if (remark) { document.getElementById('fRemark').value = remark; }
}

// ============================================================
// DOMContentLoaded
// ============================================================
document.addEventListener('DOMContentLoaded', function() {

    cfpApplyPrefillFromRequest();

    document.getElementById('btnSaveVehicle').addEventListener('click', function(e) {
        e.preventDefault();
        var btn = this;

        if (!$('#fPlate').val().trim()) {
            Swal.fire({ icon: 'warning', title: 'ข้อมูลไม่ครบ', text: 'กรุณากรอกทะเบียนรถ', confirmButtonText: 'ตกลง', customClass: { popup: 'font-prompt' } });
            return;
        }

        var totalCount = savedPreviewConfig.length + pendingFiles.length;
        if (totalCount > 10) {
            Swal.fire({ icon: 'error', title: 'ไฟล์มากเกินไป', text: 'จำนวนไฟล์รวมกันต้องไม่เกิน 10 ไฟล์ (มีอยู่แล้ว ' + savedPreviewConfig.length + ' ไฟล์)', confirmButtonText: 'ตกลง', customClass: { popup: 'font-prompt' } });
            return;
        }

        var rawFiles = document.getElementById('file-1').files;
        var totalSize = 0;
        if (rawFiles) { for (var i = 0; i < rawFiles.length; i++) { totalSize += rawFiles[i].size; } }
        if (totalSize > 10 * 1024 * 1024) {
            Swal.fire({ icon: 'error', title: 'ไฟล์รวมใหญ่เกินไป', text: 'ขนาดไฟล์รวมกันต้องไม่เกิน 10 MB', confirmButtonText: 'ตกลง', customClass: { popup: 'font-prompt' } });
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>กำลังบันทึก...';
        $('#file-1').fileinput('upload');
    });

    // ===== DataTable (ปรับให้ search ทำงาน) =====
    var table = $('#tblVehicle').DataTable({
        language  : { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order     : [[1, 'asc']],
        pageLength: 25,
        dom       : 'lrtip',
        searching : true,  // ✅ เปิดใช้งาน search เพื่อให้ table.search() ทำงาน
        columnDefs: [{ targets: 0, orderable: false, searchable: false }],
        drawCallback: function () { cfpInitMobileExpand('tblVehicle'); }
    });

    // ✅ ซ่อน Search Box ของ DataTable (ใช้ custom แทน)
    $('#tblVehicle_filter').hide();

    cfpBindMobileExpand('tblVehicle');

    // ===== Custom Search =====
    $('#fltKeyword').on('keyup', function() {
        table.search(this.value).draw();
    });

    // ===== Custom Filter: ใช้ data-attributes =====
    $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
        var row = table.row(dataIndex).node();
        var status = $('#fltStatus').val();
        var site = $('#fltSite').val();
        var type = $('#fltType').val();
        var fuel = $('#fltFuel').val();

        if (status !== '' && $(row).attr('data-status') !== status) return false;
        if (site !== '' && $(row).attr('data-site') !== site) return false;
        if (type !== '' && $(row).attr('data-type') !== type) return false;
        if (fuel !== '' && $(row).attr('data-fuel') !== fuel) return false;
        return true;
    });

    // ===== Event listeners: เมื่อเลือก filter ให้ redraw =====
    $('#fltStatus, #fltSite, #fltType, #fltFuel').on('change', function() {
        table.draw();
    });

    // ===== Clear Filters =====
    window.clearFilter = function() {
        $('#fltKeyword').val('');
        $('#fltStatus').val('');
        $('#fltSite').val('');
        $('#fltType').val('');
        $('#fltFuel').val('');
        table.search('').draw();
    };

    // ===== Toast =====
    var toastMsg  = <?php echo json_encode($toastMsg,  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var toastType = <?php echo json_encode($toastType === 'error', JSON_HEX_TAG); ?>;
    if (toastMsg && toastMsg !== '') { showToast(toastMsg, toastType); }
});

// ============================================================
// Toast
// ============================================================
function showToast(msg, isError) {
    if (!msg || msg === '') { return; }
    var id  = isError ? 'toastError'  : 'toastSuccess';
    var mid = isError ? 'toastErrMsg' : 'toastMsg';
    document.getElementById(mid).textContent = msg;
    new bootstrap.Toast(document.getElementById(id), { delay: 3000 }).show();
}

// ============================================================
// confirmToggle
// ============================================================
function confirmToggle(id, isActive, name) {
    var action = isActive ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    Swal.fire({
        title: action + 'ยานพาหนะ?', html: '<b>' + name + '</b>',
        icon : isActive ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: action, cancelButtonText: 'ยกเลิก',
        confirmButtonColor: isActive ? '#DC3545' : '#4CAF50',
        customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (result.isConfirmed) {
            var f = document.createElement('form');
            f.method = 'POST'; f.action = 'vehicle_save.php';
            f.innerHTML = '<input name="action" value="toggle"><input name="id" value="' + id + '"><input name="is_active" value="' + isActive + '">';
            document.body.appendChild(f);
            f.submit();
        }
    });
}
</script>
</body>
</html>
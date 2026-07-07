<?php
/* ==============================================
   master/equipment.php
   จัดการข้อมูลเครื่องจักร — เฉพาะ Admin (4)
   แก้ไข: ใช้ event delegation บน .kv-file-remove แทน filepredelete
          เพราะ filepredelete + Swal async ทำงานร่วมกันไม่ได้
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4));

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
$resSite = sqlsrv_query($conn, "SELECT SiteID, SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
$sites   = array();
while ($r = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) { $sites[] = $r; }

/* EquipmentType dropdown */
$resEType = @sqlsrv_query($conn, "SELECT TypeID, TypeCode, TypeName FROM CFP_EquipmentType WHERE IsActive=1 ORDER BY SortOrder, TypeName");
$eTypes   = array();
if ($resEType) { while ($r = sqlsrv_fetch_array($resEType, SQLSRV_FETCH_ASSOC)) { $eTypes[] = $r; } }

/* FuelType dropdown */
$resFuel  = @sqlsrv_query($conn, "SELECT TypeID, TypeCode, TypeName FROM CFP_FuelType WHERE IsActive=1 ORDER BY TypeName");
$fuelTypes = array();
if ($resFuel) { while ($r = sqlsrv_fetch_array($resFuel, SQLSRV_FETCH_ASSOC)) { $fuelTypes[] = $r; } }

/* ===== ดึงรายการเครื่องจักร ===== */
$sql = "
    SELECT
        e.EquipmentID, e.EquipmentCode, e.EquipmentName,
        e.EquipmentTypeID, et.TypeName AS EquipmentTypeName,
        e.FuelTypeID, f.TypeName AS FuelTypeName,
        e.CombustionType,
        e.Capacity, e.CapacityUnit, e.YearInstall,
        e.SiteID, s.SiteName,
        e.Remark, e.IsActive, e.CreatedDate
    FROM CFP_Equipment e
    LEFT JOIN CFP_Site s           ON s.SiteID  = e.SiteID
    LEFT JOIN CFP_EquipmentType et ON et.TypeID = e.EquipmentTypeID
    LEFT JOIN CFP_FuelType f       ON f.TypeID  = e.FuelTypeID
    ORDER BY e.EquipmentCode
";
$res  = @sqlsrv_query($conn, $sql);
$rows = array();
if ($res) { while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; } }

/* ===== สร้าง $map สำหรับ JavaScript (ใช้ตอนแก้ไข) ===== */
$map = array();
foreach ($rows as $r) {
    $map[$r['EquipmentID']] = array(
        'code'      => $r['EquipmentCode'],
        'name'      => $r['EquipmentName'],
        'type'      => $r['EquipmentTypeID'] ? (int)$r['EquipmentTypeID'] : '',
        'site'      => $r['SiteID'] ? (int)$r['SiteID'] : '',
        'fuel'      => $r['FuelTypeID'] ? (int)$r['FuelTypeID'] : '',
        'combustion'=> $r['CombustionType'] ?? 'Stationary',
        'cap'       => $r['Capacity'] ? (float)$r['Capacity'] : '',
        'capunit'   => $r['CapacityUnit'] ?? '',
        'year'      => $r['YearInstall'] ? (int)$r['YearInstall'] : '',
        'remark'    => $r['Remark'] ?? '',
    );
}

$pageTitle = 'เครื่องจักร';
$pageIcon  = 'gear-wide-connected';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>เครื่องจักร — ระบบบริหารจัดการคาร์บอนองค์กร</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">

  <!-- Krajee FileInput + Font Awesome -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/css/fileinput.min.css" media="all" rel="stylesheet">

  <style>

body{font-family:'Prompt',sans-serif;}
.font-prompt{font-family:'Prompt',sans-serif!important;}
.kpi-icon-box{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
.btn-action{width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;font-size:0.8rem;}
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;}

    .file-drop-zone {
      border: 2px dashed #2AABB8 !important;
      border-radius: 10px !important;
      background: #EEF6F8 !important;
    }
    .file-drop-zone:hover {
      background: #D8EDF4 !important;
    }
    .file-preview-frame {
      border-radius: 8px !important;
    }
    .file-preview-frame:hover {
      border-color: #2AABB8 !important;
      box-shadow: 0 0 10px rgba(42,171,184,0.2) !important;
    }
    .file-preview .close {
      font-size: 1.2rem !important;
    }
    /* ซ่อนเฉพาะปุ่ม upload ใต้ไฟล์ใหม่ คง trash icon ไว้ */
    .file-actions .file-upload-button { display:none !important; }
    .cfp-btn-del:hover { background:rgba(220,53,69,1) !important; transform:scale(1.1); }
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
          <i class="bi bi-gear-wide-connected me-2" style="color:var(--cfp-green);"></i>จัดการเครื่องจักร
        </h5>
        <div style="font-size:0.78rem;color:var(--cfp-text-muted);margin-top:2px;">
          ทะเบียนทรัพย์สิน (Scope 1) › เครื่องจักร
        </div>
      </div>
      <button class="btn-cfp-add btn-sm" onclick="openModal(0)">
        <i class="bi bi-plus-circle me-1"></i>เพิ่มเครื่องจักร
      </button>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
          <div style="font-size:1.6rem;font-weight:700;color:var(--cfp-primary);">
            <?php echo count($rows); ?>
          </div>
          <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ทั้งหมด</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
          <div style="font-size:1.6rem;font-weight:700;color:var(--cfp-green);">
            <?php echo count(array_filter($rows, function($r){ return (bool)$r['IsActive']; })); ?>
          </div>
          <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ใช้งาน</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
          <div style="font-size:1.6rem;font-weight:700;color:#F57C00;">
            <?php echo count(array_filter($rows, function($r){ return !(bool)$r['IsActive']; })); ?>
          </div>
          <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ปิดใช้งาน</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
          <div style="font-size:1.6rem;font-weight:700;color:#7B1FA2;">
            <?php
              $siteCount = count(array_unique(array_filter(array_column($rows, 'SiteID'))));
              echo $siteCount;
            ?>
          </div>
          <div style="font-size:0.75rem;color:var(--cfp-text-muted);">Site ที่มีเครื่องจักร</div>
        </div>
      </div>
    </div>

    <!-- Table Card -->
    <div class="cfp-card">
      <div class="table-responsive">
        <table id="tblEquipment" class="table table-bordered table-hover align-middle" style="width:100%">
          <thead>
            <tr>
              <th style="width:40px">#</th>
              <th style="width:110px">รหัส</th>
              <th>ชื่อเครื่องจักร</th>
              <th style="width:130px">ประเภท</th>
              <th style="width:120px">Site</th>
              <th style="width:120px">เชื้อเพลิง</th>
              <th class="text-center" style="width:80px">สถานะ</th>
              <th class="text-center" style="width:90px">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $i => $r) { ?>
            <tr>
              <td><?php echo $i+1; ?></td>
              <td><code><?php echo htmlspecialchars($r['EquipmentCode']); ?></code></td>
              <td>
                <div class="fw-500"><?php echo htmlspecialchars($r['EquipmentName']); ?></div>
                <?php if (!empty($r['Remark'])) { ?>
                <div style="font-size:0.72rem;color:var(--cfp-text-muted);">
                  <?php echo htmlspecialchars($r['Remark']); ?>
                </div>
                <?php } ?>
              </td>
              <td><?php echo htmlspecialchars($r['EquipmentTypeName'] ?? '—'); ?></td>
              <td><?php echo htmlspecialchars($r['SiteName'] ?? '—'); ?></td>
              <td><?php echo htmlspecialchars($r['FuelTypeName'] ?? '—'); ?></td>
              <td class="text-center">
                <?php if ($r['IsActive']) { ?>
                  <span class="status-dot" style="background:#4CAF50;"></span><span style="font-size:0.78rem;color:#2E7D32;"> ใช้งาน</span>
                <?php } else { ?>
                  <span class="status-dot" style="background:#ccc;"></span><span style="font-size:0.78rem;color:#9E9E9E;"> ปิด</span>
                <?php } ?>
              </td>
              <td class="text-center">
                <button class="btn btn-outline-primary btn-sm py-0 px-2 me-1"
                        onclick="openModal(<?php echo $r['EquipmentID']; ?>)"
                        title="แก้ไข">
                  <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-outline-<?php echo $r['IsActive'] ? 'danger' : 'success'; ?> btn-sm py-0 px-2"
                        onclick="confirmToggle(<?php echo $r['EquipmentID']; ?>, <?php echo $r['IsActive'] ? 1 : 0; ?>, '<?php echo htmlspecialchars(addslashes($r['EquipmentName'])); ?>')"
                        title="<?php echo $r['IsActive'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน'; ?>">
                  <i class="bi bi-<?php echo $r['IsActive'] ? 'toggle-on' : 'toggle-off'; ?>"></i>
                </button>
              </td>
            </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    </div><!-- /.cfp-card -->

  </div><!-- /.cfp-content -->
</div><!-- /.cfp-main -->

<!-- ===== Modal เพิ่ม/แก้ไขเครื่องจักร ===== -->
<div class="modal fade" id="modalEquipment" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content" style="font-family:'Prompt',sans-serif;">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff;border-radius:var(--bs-modal-inner-border-radius) var(--bs-modal-inner-border-radius) 0 0;">
        <h6 class="modal-title mb-0" id="modalTitle">
          <i class="bi bi-gear-wide-connected me-2"></i>เพิ่มเครื่องจักร
        </h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formEquipment" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" id="fAction" value="create">
        <input type="hidden" name="id"     id="fID"     value="0">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div class="modal-body">
          <div class="row g-3">

            <!-- รหัส + ชื่อ -->
            <div class="col-md-4">
              <label class="form-label form-required">รหัสเครื่องจักร</label>
              <input type="text" class="form-control" name="EquipmentCode" id="fCode"
                     placeholder="เช่น EQ-001" maxlength="50" required
                     style="font-family:'Prompt',sans-serif;text-transform:uppercase">
            </div>
            <div class="col-md-8">
              <label class="form-label form-required">ชื่อเครื่องจักร</label>
              <input type="text" class="form-control" name="EquipmentName" id="fName"
                     placeholder="ชื่อเครื่องจักร" maxlength="300" required
                     style="font-family:'Prompt',sans-serif">
            </div>

            <!-- ประเภท + Site -->
            <div class="col-md-6">
              <label class="form-label">ประเภทเครื่องจักร</label>
              <select class="form-select" name="EquipmentTypeID" id="fType"
                      style="font-family:'Prompt',sans-serif">
                <option value="">— เลือกประเภท —</option>
                <?php foreach ($eTypes as $t) { ?>
                <option value="<?php echo $t['TypeID']; ?>">
                  <?php echo htmlspecialchars($t['TypeName']); ?>
                </option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Site / หน่วยงาน</label>
              <select class="form-select" name="SiteID" id="fSite"
                      style="font-family:'Prompt',sans-serif">
                <option value="">— ไม่ระบุ —</option>
                <?php foreach ($sites as $s) { ?>
                <option value="<?php echo $s['SiteID']; ?>">
                  <?php echo htmlspecialchars($s['SiteName']); ?>
                </option>
                <?php } ?>
              </select>
            </div>

            <!-- ประเภทเชื้อเพลิง + ประเภทการเผาไหม้ -->
            <div class="col-md-6">
              <label class="form-label">ประเภทเชื้อเพลิง</label>
              <select class="form-select" name="FuelTypeID" id="fFuel"
                      style="font-family:'Prompt',sans-serif">
                <option value="">— ไม่ระบุ —</option>
                <?php foreach ($fuelTypes as $f) { ?>
                <option value="<?php echo $f['TypeID']; ?>">
                  <?php echo htmlspecialchars($f['TypeName']); ?>
                </option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">ประเภทการเผาไหม้</label>
              <select class="form-select" name="CombustionType" id="fCombustion"
                      style="font-family:'Prompt',sans-serif">
                <option value="Stationary">Stationary — อยู่กับที่ (เตาอบ, ปั๊ม, Generator)</option>
                <option value="Mobile">Mobile — เคลื่อนที่ (forklift, รถตัดหญ้า)</option>
              </select>
            </div>

            <!-- กำลัง + หน่วย -->
            <div class="col-md-4">
              <label class="form-label">กำลังการผลิต</label>
              <input type="number" class="form-control" name="Capacity" id="fCap"
                     placeholder="0.00" step="0.01" min="0"
                     style="font-family:'Prompt',sans-serif">
            </div>
            <div class="col-md-2">
              <label class="form-label">หน่วย</label>
              <input type="text" class="form-control" name="CapacityUnit" id="fCapUnit"
                     placeholder="kW" maxlength="50"
                     style="font-family:'Prompt',sans-serif">
            </div>

            <!-- ปีที่ติดตั้ง -->
            <div class="col-md-3">
              <label class="form-label">ปีที่ติดตั้ง (ค.ศ.)</label>
              <input type="number" class="form-control" name="YearInstall" id="fYear"
                     placeholder="<?php echo date('Y'); ?>"
                     min="1900" max="<?php echo date('Y'); ?>"
                     style="font-family:'Prompt',sans-serif">
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
              <label for="file-1" style="font-weight:600;color:var(--cfp-primary);">
                  <i class="bi bi-images me-1"></i>รูปภาพ / เอกสารแนบ
                  <span class="text-secondary small" style="font-weight:400;">
                      <i class="fa fa-info-circle me-1"></i> รองรับ .png .jpg .pdf  |  สูงสุด 10 ไฟล์  |  รวมไม่เกิน 10 MB
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
          <button type="button" class="btn-cfp-add btn-sm" id="btnSaveEquipment">
            <i class="bi bi-check-circle me-1"></i>บันทึก
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toast Success -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999">
  <div id="toastSuccess" class="toast align-items-center text-bg-success border-0"
       style="font-family:'Prompt',sans-serif;">
    <div class="d-flex">
      <div class="toast-body" id="toastMsg"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
  <div id="toastError" class="toast align-items-center text-bg-danger border-0"
       style="font-family:'Prompt',sans-serif;">
    <div class="d-flex">
      <div class="toast-body" id="toastErrMsg"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<!-- ===== JavaScript ===== -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Krajee FileInput JS (ต้องโหลดหลัง jQuery) -->
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
var pendingFiles       = []; // ไฟล์ใหม่ที่ browse รอ upload

var equipData = <?php
    $mapData = isset($map) ? $map : array();
    echo json_encode($mapData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;
var csrfToken = <?php
    $token = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
    echo json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

// ============================================================
// โหลดไฟล์จาก DB
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
        data: { asset_type: 'Equipment', asset_id: assetID },
        dataType: 'json',
        async: false,
        success: function(res) {
            if (res.success && res.images) {
                res.images.forEach(function(img) {
                    previewFiles.push(img.url);
                    previewConfig.push({
                        caption : img.caption,
                        size    : img.size,
                        key     : img.id,
                        type    : img.fileType || 'image'
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
// initFileInput — wrapper
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
// กฎ: png/jpg/pdf | ไม่เกิน 10 ไฟล์รวม | ไม่เกิน 10 MB รวม
// ============================================================
function _initFileInput(previewFiles, previewConfig) {
    if ($('#file-1').data('fileinput')) {
        $('#file-1').fileinput('destroy');
    }

    var isEdit = (currentAssetID > 0);

    $('#file-1').fileinput({
        theme                   : 'fas',
        uploadUrl               : 'equipment_save.php',
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
        // ซ่อนปุ่ม upload + trash ใต้ไฟล์ใหม่ที่ browse
        fileActionSettings      : { showUpload: false, showRemove: true,  showZoom: true, showDrag: false },
        // Error messages ภาษาไทย
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
            $('#formEquipment').serializeArray().forEach(function(item) {
                if (data[item.name] !== undefined) { data[item.name] += ',' + item.value; }
                else { data[item.name] = item.value; }
            });
            data['action']     = $('#fAction').val() || 'create';
            data['id']         = $('#fID').val() || '0';
            data['asset_type'] = 'Equipment';
            data['asset_id']   = currentAssetID;
            data['csrf_token'] = $('input[name="csrf_token"]').val() || csrfToken;
            return data;
        }
    })
    // หลัง render preview ครบ → inject ปุ่ม ✕
    .on('fileshown', function() { injectDeleteButtons(); })
    // fileloaded: ไฟล์ผ่าน Krajee validate แล้ว → inject ✕ + เก็บชื่อ
    .on('fileloaded', function(event, file, previewId, index) {
        pendingFiles.push(file.name.toLowerCase());
        setTimeout(function() { injectNewFileDeleteBtn(previewId, index); }, 100);
    })
    .on('filecleared filereset', function() {
        pendingFiles = [];
        $('#cfp-file-error').hide().text('');
    })
    .on('fileremoved', function(event, id, index) {
        if (index >= 0 && index < pendingFiles.length) {
            pendingFiles.splice(index, 1);
        }
        $('#cfp-file-error').hide().text('');
    })
    .on('fileerror', function(event, data, msg) {
        showUploadErrorSwal('ไม่สามารถเพิ่มไฟล์ได้', [msg], null);
    })
    .on('filebatchuploadsuccess', function(event, data) {
        var response = data.response;
        if (response && response.success) {
            var bsModal = bootstrap.Modal.getInstance(document.getElementById('modalEquipment'));
            if (bsModal) { bsModal.hide(); }
            setTimeout(function() {
                if (isEdit) {
                    Swal.fire({
                        icon: 'success', title: 'บันทึกข้อมูลสำเร็จ!',
                        timer: 5000, timerProgressBar: true,
                        showConfirmButton: true, confirmButtonText: 'ตกลง',
                        confirmButtonColor: '#2AABB8', allowOutsideClick: false,
                        customClass: { popup: 'font-prompt' }
                    }).then(function() { location.reload(); });
                } else {
                    Swal.fire({
                        icon: 'success', title: 'เพิ่มข้อมูลสำเร็จ!',
                        timer: 5000, timerProgressBar: true,
                        showConfirmButton: true, confirmButtonText: 'ตกลง',
                        confirmButtonColor: '#2AABB8', allowOutsideClick: false,
                        customClass: { popup: 'font-prompt' }
                    }).then(function() { location.reload(); });
                }
            }, 400);
        } else {
            Swal.fire({
                icon: 'error', title: 'เกิดข้อผิดพลาด',
                text: response.msg || 'ไม่สามารถบันทึกข้อมูลได้',
                confirmButtonText: 'ตกลง', confirmButtonColor: '#DC3545',
                customClass: { popup: 'font-prompt' }
            });
            $('#btnSaveEquipment').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>บันทึก');
        }
    })
    .on('filebatchuploaderror', function(event, data, msg) {
        var errorMsg = (data.response && data.response.msg) ? data.response.msg : (msg || 'อัปโหลดไฟล์ล้มเหลว');
        Swal.fire({ icon: 'error', title: 'อัปโหลดไฟล์ไม่สำเร็จ', text: errorMsg, customClass: { popup: 'font-prompt' } });
        $('#btnSaveEquipment').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>บันทึก');
    });

    setTimeout(injectDeleteButtons, 600);

    // ===== ดัก change บน input ก่อน Krajee — ตรวจซ้ำทั้ง batch =====
    // ต้องผูกใหม่ทุกครั้งที่ destroy+init (ใช้ .off ก่อน)
    $('#file-1').off('change.cfpDupCheck').on('change.cfpDupCheck', function(e) {
        var input   = this;
        var files   = input.files;
        if (!files || !files.length) { return; }

        var dupNames  = [];
        var dt        = new DataTransfer();

        for (var i = 0; i < files.length; i++) {
            var name    = files[i].name.toLowerCase();
            var dupInDB = savedPreviewConfig.some(function(c) {
                return (c.caption || '').toLowerCase() === name;
            });
            var dupInPending = pendingFiles.some(function(f) {
                return f.toLowerCase() === name;
            });
            // ตรวจซ้ำใน batch เดียวกัน
            var dupInBatch = false;
            for (var j = 0; j < i; j++) {
                if (files[j].name.toLowerCase() === name) { dupInBatch = true; break; }
            }

            if (dupInDB || dupInPending || dupInBatch) {
                dupNames.push(files[i].name);
            } else {
                dt.items.add(files[i]); // เก็บเฉพาะไฟล์ที่ไม่ซ้ำ
            }
        }

        if (dupNames.length > 0) {
            // แทน files ด้วยเฉพาะที่ไม่ซ้ำ
            // DataTransfer ต้องทำก่อน Krajee อ่าน files
            try { input.files = dt.files; } catch(ex) {}

            showUploadErrorSwal(
                dupNames.length === 1 ? 'พบไฟล์ซ้ำ' : 'พบไฟล์ซ้ำ ' + dupNames.length + ' ไฟล์',
                dupNames.map(function(n) {
                    return '<i class="fa fa-file me-1"></i>' + n;
                }),
                'ไฟล์เหล่านี้มีอยู่ในระบบแล้ว จึงไม่ถูกเพิ่มเข้าในรายการ'
            );
        }
    });
}

// ============================================================
// showUploadErrorSwal — Swal สวยงามสำหรับ error อัพโหลด
// title: string, items: array of html string, subtitle: string|null
// ============================================================
function showUploadErrorSwal(title, items, subtitle) {
    var itemsHtml = items.map(function(item) {
        return '<div class="cfp-err-item">' + item + '</div>';
    }).join('');

    var subtitleHtml = subtitle
        ? '<p class="cfp-err-subtitle">' + subtitle + '</p>'
        : '';

    Swal.fire({
        icon             : 'warning',
        title            : '<span class="cfp-err-title">' + title + '</span>',
        html             :
            subtitleHtml +
            '<div class="cfp-err-list">' + itemsHtml + '</div>',
        confirmButtonText: 'ตกลง รับทราบ',
        confirmButtonColor: '#2AABB8',
        customClass      : { popup: 'font-prompt cfp-upload-err-popup' },
        didOpen          : function() {
            // inject style เฉพาะ popup นี้
            if (!document.getElementById('cfp-err-style')) {
                var s = document.createElement('style');
                s.id = 'cfp-err-style';
                s.textContent = [
                    '.cfp-upload-err-popup { max-width:420px !important; border-radius:14px !important; }',
                    '.cfp-err-title { font-size:1rem; font-weight:600; color:#E65100; }',
                    '.cfp-err-subtitle { font-size:0.78rem; color:#666; margin:4px 0 10px; }',
                    '.cfp-err-list { background:#FFF8F0; border:1px solid #FFE0B2; border-radius:8px;',
                    '  padding:8px 12px; text-align:left; max-height:160px; overflow-y:auto; margin-top:6px; }',
                    '.cfp-err-item { font-size:0.78rem; color:#BF360C; padding:3px 0;',
                    '  border-bottom:1px dashed #FFCC80; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }',
                    '.cfp-err-item:last-child { border-bottom:none; }',
                ].join(' ');
                document.head.appendChild(s);
            }
        }
    });
}

// ============================================================
// showFileError — แสดง error ใต้ dropzone ไม่ปิด form
// ============================================================
function showFileError(msg) {
    $('#cfp-file-error').text(msg).show();
}

// ============================================================
// injectDeleteButtons — ✕ บน initialPreview (ไฟล์จาก DB)
// ใช้ frameId pattern "thumb-file-1-init-N" map กับ savedPreviewConfig[N]
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
        var imageKey = cfg.key;

        appendDelBtn($frame, function() { confirmDeleteFromDB(imageKey); });
    });
}

// ============================================================
// injectNewFileDeleteBtn — ✕ บนไฟล์ใหม่ที่ browse (ยังไม่ upload)
// ============================================================
function injectNewFileDeleteBtn(previewId, fileIndex) {
    var $frame = $('#' + previewId);
    if (!$frame.length || $frame.find('.cfp-btn-del').length) { return; }
    $frame.find('.kv-file-remove').hide();
    appendDelBtn($frame, function() {
        // ลบออกจาก preview และ pendingFiles
        pendingFiles.splice(fileIndex, 1);
        $frame.remove();
        $('#cfp-file-error').hide().text('');
    });
}

// ============================================================
// appendDelBtn — สร้างปุ่ม ✕ custom
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
// confirmDeleteFromDB — Swal confirm แล้วลบ DB
// ============================================================
function confirmDeleteFromDB(key) {
    Swal.fire({
        title: 'ยืนยันการลบไฟล์?',
        text : 'ต้องการลบไฟล์นี้ออกจากระบบหรือไม่? ไม่สามารถกู้คืนได้',
        icon : 'warning',
        showCancelButton : true,
        confirmButtonColor: '#DC3545',
        cancelButtonColor : '#6C757D',
        confirmButtonText: 'ลบเลย',
        cancelButtonText : 'ยกเลิก',
        reverseButtons   : true,
        customClass      : { popup: 'font-prompt' }
    }).then(function(result) {
        if (!result.isConfirmed) { return; }
        Swal.fire({ title: 'กำลังลบไฟล์...', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });
        $.post('delete_asset_image.php', {
            fileid: key, asset_type: 'Equipment', asset_id: currentAssetID
        }, function(res) {
            if (res.success) {
                var newConfig = [], newFiles = [];
                for (var i = 0; i < savedPreviewConfig.length; i++) {
                    if (savedPreviewConfig[i].key != key) {
                        newConfig.push(savedPreviewConfig[i]);
                        newFiles.push(savedPreviewFiles[i]);
                    }
                }
                savedPreviewConfig = newConfig;
                savedPreviewFiles  = newFiles;
                previewCount       = newConfig.length;
                _initFileInput(savedPreviewFiles, savedPreviewConfig);
                Swal.fire({ icon: 'success', title: 'ลบไฟล์สำเร็จ', timer: 1200, showConfirmButton: false, customClass: { popup: 'font-prompt' } });
            } else {
                Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: res.msg || 'เกิดข้อผิดพลาด', confirmButtonText: 'ตกลง', customClass: { popup: 'font-prompt' } });
            }
        }, 'json').fail(function() {
            Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์', confirmButtonText: 'ตกลง' });
        });
    });
}

// ============================================================
// openModal
// ============================================================
function openModal(id) {
    var form = document.getElementById('formEquipment');
    form.reset();
    pendingFiles = [];
    $('#cfp-file-error').hide().text('');

    if (id === 0) {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-gear-wide-connected me-2"></i>เพิ่มเครื่องจักร';
        document.getElementById('fAction').value = 'create';
        document.getElementById('fID').value     = '0';
        currentAssetID     = 0;
        savedPreviewFiles  = [];
        savedPreviewConfig = [];
        previewCount       = 0;
        initFileInput(0);
    } else {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขเครื่องจักร';
        document.getElementById('fAction').value = 'update';
        document.getElementById('fID').value     = id;
        currentAssetID = id;

        var d = equipData[id];
        if (d) {
            document.getElementById('fCode').value       = d.code      || '';
            document.getElementById('fName').value       = d.name      || '';
            document.getElementById('fType').value       = d.type      || '';
            document.getElementById('fSite').value       = d.site      || '';
            document.getElementById('fFuel').value       = d.fuel      || '';
            document.getElementById('fCombustion').value = d.combustion || 'Stationary';
            document.getElementById('fCap').value        = d.cap       || '';
            document.getElementById('fCapUnit').value    = d.capunit   || '';
            document.getElementById('fYear').value       = d.year      || '';
            document.getElementById('fRemark').value     = d.remark    || '';
        }

        loadPreviewFromDB(id, function(files, config) {
            savedPreviewFiles  = files.slice();
            savedPreviewConfig = config.slice();
            previewCount       = files.length;
            initFileInput(id, files, config);
        });
    }

    $('#btnSaveEquipment').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>บันทึก');
    new bootstrap.Modal(document.getElementById('modalEquipment')).show();
}

// ============================================================
// DOMContentLoaded
// ============================================================
document.addEventListener('DOMContentLoaded', function() {

    document.getElementById('btnSaveEquipment').addEventListener('click', function(e) {
        e.preventDefault();
        var btn = this;

        // ตรวจ required
        if (!$('#fCode').val().trim() || !$('#fName').val().trim()) {
            Swal.fire({ icon: 'warning', title: 'ข้อมูลไม่ครบ', text: 'กรุณากรอกรหัสและชื่อเครื่องจักร', confirmButtonText: 'ตกลง', customClass: { popup: 'font-prompt' } });
            return;
        }

        // ตรวจจำนวนรวม (DB + pending)
        var totalCount = savedPreviewConfig.length + pendingFiles.length;
        if (totalCount > 10) {
            Swal.fire({ icon: 'error', title: 'ไฟล์มากเกินไป', text: 'จำนวนไฟล์รวมกันต้องไม่เกิน 10 ไฟล์ (มีอยู่แล้ว ' + savedPreviewConfig.length + ' ไฟล์)', confirmButtonText: 'ตกลง', customClass: { popup: 'font-prompt' } });
            return;
        }

        // ตรวจขนาดรวมไฟล์ใหม่
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

    $('#tblEquipment').DataTable({
        language  : { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order     : [[1, 'asc']],
        pageLength: 25,
        dom       : '<"row align-items-center mb-2"<"col-md-6"l><"col-md-6 text-end"f>>rtip'
    });

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
        title: action + 'เครื่องจักร?', html: '<b>' + name + '</b>',
        icon : isActive ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: action, cancelButtonText: 'ยกเลิก',
        confirmButtonColor: isActive ? '#DC3545' : '#4CAF50',
        customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (result.isConfirmed) {
            var f = document.createElement('form');
            f.method = 'POST'; f.action = 'equipment_save.php';
            f.innerHTML = '<input name="action" value="toggle"><input name="id" value="' + id + '"><input name="is_active" value="' + isActive + '">';
            document.body.appendChild(f);
            f.submit();
        }
    });
}
</script>
</body>
</html>
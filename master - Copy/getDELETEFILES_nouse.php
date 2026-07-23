<?php
/* ==============================================
   master/equipment.php
   จัดการข้อมูลเครื่องจักร — เฉพาะ Admin (4)
   ใช้ Krajee FileInput สำหรับอัปโหลดไฟล์
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


/* ===== ฟังก์ชันดึงรูปที่มีอยู่สำหรับ Krajee ===== */
function getAssetImages($assetID) {
    global $conn;
    $files = [];
    if ($assetID <= 0) return $files;
    $sql = "SELECT ImageID, FileName, OriginalName, FileSize, MimeType, IsPrimary 
            FROM CFP_AssetImage 
            WHERE AssetType='Equipment' AND AssetID=? 
            ORDER BY IsPrimary DESC, SortOrder ASC";
    $res = sqlsrv_query($conn, $sql, array($assetID));
    while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
        $files[] = [
            'id'       => (int)$row['ImageID'],
            'caption'  => $row['OriginalName'],
            'size'     => (int)$row['FileSize'],
            'url'      => '/carbonfootprint/uploads/assets/equipment/' . $row['FileName'],
            'isPrimary'=> (bool)$row['IsPrimary'],
        ];
    }
    return $files;
}

/* ===== ข้อมูลสำหรับ modal (populate) ===== */
$equipData = array();
foreach ($rows as $r) {
    $equipData[$r['EquipmentID']] = array(
        'code'     => $r['EquipmentCode'],
        'name'     => $r['EquipmentName'],
        'type'     => $r['EquipmentTypeID'] ? (int)$r['EquipmentTypeID'] : '',
        'site'     => $r['SiteID'] ? (int)$r['SiteID'] : '',
        'fuel'     => $r['FuelTypeID'] ? (int)$r['FuelTypeID'] : '',
        'combustion' => $r['CombustionType'] ?? 'Stationary',
        'cap'      => $r['Capacity'] ? (float)$r['Capacity'] : '',
        'capunit'  => $r['CapacityUnit'] ?? '',
        'year'     => $r['YearInstall'] ? (int)$r['YearInstall'] : '',
        'remark'   => $r['Remark'] ?? '',
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
  
  <!-- Krajee FileInput CSS (Font Awesome 6) -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/css/fileinput.min.css" media="all" rel="stylesheet">
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
                  <span class="badge bg-success">ใช้งาน</span>
                <?php } else { ?>
                  <span class="badge bg-secondary">ปิด</span>
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
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="font-family:'Prompt',sans-serif;">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff;border-radius:var(--bs-modal-inner-border-radius) var(--bs-modal-inner-border-radius) 0 0;">
        <h6 class="modal-title mb-0" id="modalTitle">
          <i class="bi bi-gear-wide-connected me-2"></i>เพิ่มเครื่องจักร
        </h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formEquipment" method="POST">
        <input type="hidden" name="action" id="fAction" value="create">
        <input type="hidden" name="id"     id="fID"     value="0">
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
            <label style="font-weight:600;color:var(--cfp-primary);">
              <i class="bi bi-images me-1"></i>รูปภาพ / เอกสารแนบ
            </label>
            <div class="file-loading">
              <input id="file-1" name="file[]" type="file" class="file" multiple 
                     data-browse-on-zone-click="true">
            </div>
            <div id="file-error-msg" style="display:none; color:#dc3545; margin-top:6px;">
              <i class="fa fa-exclamation-circle"></i> กรุณาเลือกไฟล์อย่างน้อย 1 ไฟล์
            </div>
            <div id="kartik-file-errors"></div>
          </div>

        </div><!-- /.modal-body -->
        <div class="modal-footer" style="background:#F9FAFB;">
          <button type="button" class="btn btn-outline-secondary btn-sm btn-sm w-25" data-bs-dismiss="modal">
            <i class="bi bi-x-circle me-1"></i>ยกเลิก
          </button>
          <button type="submit" class="btn-cfp-add btn-sm">
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

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Krajee FileInput JS -->
<script src="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/js/fileinput.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/themes/fas/theme.min.js"></script>

<script>
/* ===== ข้อมูลเครื่องจักรสำหรับ populate modal ===== */
var equipData = <?php echo json_encode($equipData, JSON_UNESCAPED_UNICODE); ?>;

var currentAssetID = 0;
var fileInputInitialized = false;

/* ===== ฟังก์ชันโหลดรูปที่มีอยู่สำหรับ Krajee ===== */
function loadFilePreview(assetID) {
    currentAssetID = assetID;
    var previewFiles = [];
    var previewConfig = [];

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
                        caption: img.caption,
                        size: img.size,
                        key: img.id,
                        url: 'delete_asset_image.php',
                        extra: { fileid: img.id, asset_type: 'Equipment', asset_id: assetID }
                    });
                });
            }
        }
    });

    // ถ้ามี instance อยู่แล้วให้ destroy ทิ้ง
    if (fileInputInitialized) {
        $('#file-1').fileinput('destroy');
        fileInputInitialized = false;
    }

    // เริ่มต้น FileInput
    $('#file-1').fileinput({
        theme: 'fas',
        uploadUrl: 'upload_handler.php',
        uploadAsync: false,
        showUpload: false,
        autoUpload: false,
        overwriteInitial: false,
        append: true,
        allowedFileExtensions: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
        maxFileSize: 5120,
        maxFileCount: 50,
        initialPreview: previewFiles,
        initialPreviewConfig: previewConfig,
        initialPreviewAsData: true,
        removeFromPreviewOnError: true,
        dropZoneEnabled: true,
        browseOnZoneClick: true,
        uploadExtraData: function() {
            var data = {};
            $('#formEquipment').serializeArray().forEach(function(item) {
                if (data[item.name] !== undefined) {
                    data[item.name] += ',' + item.value;
                } else {
                    data[item.name] = item.value;
                }
            });
            data['asset_type'] = 'Equipment';
            data['asset_id'] = currentAssetID;
            data['csrf_token'] = '<?php echo $_SESSION['csrf_token']; ?>';
            return data;
        }
    })
    .on('filepredelete', function(event, key) {
        event.preventDefault();
        Swal.fire({
            title: 'ยืนยันการลบ?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก'
        }).then(function(result) {
            if (result.isConfirmed) {
                $.post('delete_asset_image.php', {
                    fileid: key,
                    asset_type: 'Equipment',
                    asset_id: currentAssetID
                }, function(res) {
                    if (res.success) {
                        $('#file-1').fileinput('refresh');
                        Swal.fire({ icon: 'success', title: 'ลบไฟล์สำเร็จ', timer: 1500, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: res.msg });
                    }
                }, 'json');
            }
        });
        return false;
    })
    .on('filebatchuploadsuccess', function(event, data) {
        var response = data.response;
        if (response && response.success) {
            Swal.fire({
                icon: 'success',
                title: 'บันทึกข้อมูลและอัปโหลดไฟล์สำเร็จ',
                timer: 1500,
                showConfirmButton: false
            });
            setTimeout(function() { location.reload(); }, 1500);
        } else {
            Swal.fire({ icon: 'error', title: 'อัปโหลดไฟล์ไม่สำเร็จ', text: response.msg || '' });
        }
    })
    .on('filebatchuploaderror', function(event, data, msg) {
        Swal.fire({ icon: 'error', title: 'อัปโหลดไฟล์ล้มเหลว', text: msg });
    });

    fileInputInitialized = true;
}

/* ===== เปิด Modal ===== */
function openModal(id) {
    var form = document.getElementById('formEquipment');
    form.reset();

    if (id === 0) {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-gear-wide-connected me-2"></i>เพิ่มเครื่องจักร';
        document.getElementById('fAction').value = 'create';
        document.getElementById('fID').value = '0';
        currentAssetID = 0;

        // ถ้ามี instance อยู่แล้วให้ destroy ทิ้ง
        if (fileInputInitialized) {
            $('#file-1').fileinput('destroy');
            fileInputInitialized = false;
        }

        // เริ่มต้น FileInput แบบไม่มี initialPreview (assetID=0)
        $('#file-1').fileinput({
            theme: 'fas',
            uploadUrl: 'upload_handler.php',
            uploadAsync: false,
            showUpload: false,
            autoUpload: false,
            overwriteInitial: false,
            append: true,
            allowedFileExtensions: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
            maxFileSize: 5120,
            maxFileCount: 50,
            initialPreview: [],
            initialPreviewConfig: [],
            initialPreviewAsData: true,
            dropZoneEnabled: true,
            browseOnZoneClick: true,
            uploadExtraData: function() {
                var data = {};
                $('#formEquipment').serializeArray().forEach(function(item) {
                    if (data[item.name] !== undefined) {
                        data[item.name] += ',' + item.value;
                    } else {
                        data[item.name] = item.value;
                    }
                });
                data['asset_type'] = 'Equipment';
                data['asset_id'] = currentAssetID;
                data['csrf_token'] = '<?php echo $_SESSION['csrf_token']; ?>';
                return data;
            }
        });
        fileInputInitialized = true;
    } else {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขเครื่องจักร';
        document.getElementById('fAction').value = 'update';
        document.getElementById('fID').value = id;

        // เติมข้อมูลลงฟอร์ม
        var d = equipData[id];
        document.getElementById('fCode').value = d.code || '';
        document.getElementById('fName').value = d.name || '';
        document.getElementById('fType').value = d.type || '';
        document.getElementById('fSite').value = d.site || '';
        document.getElementById('fFuel').value = d.fuel || '';
        document.getElementById('fCombustion').value = d.combustion || 'Stationary';
        document.getElementById('fCap').value = d.cap || '';
        document.getElementById('fCapUnit').value = d.capunit || '';
        document.getElementById('fYear').value = d.year || '';
        document.getElementById('fRemark').value = d.remark || '';

        // โหลดรูปที่มีอยู่
        loadFilePreview(id);
    }

    new bootstrap.Modal(document.getElementById('modalEquipment')).show();
}

/* ===== Submit Form ===== */
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('formEquipment').addEventListener('submit', function(e) {
        e.preventDefault();
        var btnSave = this.querySelector('[type=submit]');
        var origHTML = btnSave.innerHTML;
        btnSave.disabled = true;
        btnSave.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>กำลังบันทึก...';

        var fd = new FormData(this);
        fetch('equipment_save.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                btnSave.disabled = false; btnSave.innerHTML = origHTML;
                showToast(res.msg || 'เกิดข้อผิดพลาด', true);
                return;
            }

            var assetID = res.assetID || parseInt(document.getElementById('fID').value) || 0;
            currentAssetID = assetID;

            // ถ้าสำเร็จและมี assetID ให้ทำการอัปโหลดไฟล์
            if (assetID > 0) {
                // อัปเดต extraData ให้ใช้ assetID ที่ได้
                $('#file-1').fileinput('refresh', {
                    uploadExtraData: function() {
                        var data = {};
                        $('#formEquipment').serializeArray().forEach(function(item) {
                            if (data[item.name] !== undefined) {
                                data[item.name] += ',' + item.value;
                            } else {
                                data[item.name] = item.value;
                            }
                        });
                        data['asset_type'] = 'Equipment';
                        data['asset_id'] = assetID;
                        data['csrf_token'] = '<?php echo $_SESSION['csrf_token']; ?>';
                        return data;
                    }
                });
                // เริ่มอัปโหลดไฟล์
                $('#file-1').fileinput('upload');
            } else {
                btnSave.disabled = false; btnSave.innerHTML = origHTML;
                showToast('บันทึกข้อมูลสำเร็จ แต่ไม่พบ asset_id', true);
            }
        })
        .catch(function() {
            btnSave.disabled = false; btnSave.innerHTML = origHTML;
            showToast('เชื่อมต่อ server ไม่ได้', true);
        });
    });
});

/* ===== Toggle Status ===== */
function confirmToggle(id, isActive, name) {
    var action  = isActive ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    Swal.fire({
        title: action + 'เครื่องจักร?',
        html: '<b>' + name + '</b>',
        icon: isActive ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: action,
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: isActive ? '#DC3545' : '#4CAF50',
        customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (result.isConfirmed) {
            var f = document.createElement('form');
            f.method = 'POST';
            f.action = 'equipment_save.php';
            f.innerHTML = '<input name="action" value="toggle">' +
                          '<input name="id" value="' + id + '">' +
                          '<input name="is_active" value="' + isActive + '">';
            document.body.appendChild(f);
            f.submit();
        }
    });
}

/* ===== Toast ===== */
function showToast(msg, isError) {
    var id = isError ? 'toastError' : 'toastSuccess';
    var mid = isError ? 'toastErrMsg' : 'toastMsg';
    document.getElementById(mid).textContent = msg;
    var toast = new bootstrap.Toast(document.getElementById(id), { delay: 3000 });
    toast.show();
}

/* ===== DataTable ===== */
$(document).ready(function() {
    $('#tblEquipment').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order: [[1, 'asc']],
        pageLength: 25,
        dom: '<"row align-items-center mb-2"<"col-md-6"l><"col-md-6 text-end"f>>rtip'
    });
});

<?php if ($toastMsg) { ?>
showToast(
    '<?php echo htmlspecialchars(addslashes($toastMsg)); ?>',
    <?php echo $toastType === 'error' ? 'true' : 'false'; ?>
);
<?php } ?>
</script>
</body>
</html>
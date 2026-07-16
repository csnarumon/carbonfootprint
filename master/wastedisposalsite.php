<?php
/* ==============================================
   master/wastedisposalsite.php
   สถานที่กำจัดขยะ — Admin (4), SustainAdmin (5)
   หมายเหตุ: ตาราง CFP_WasteDisposalSite ใช้ SiteID/SiteCode/SiteName
   (ต่างจากตารางอื่นที่ใช้ TypeID/TypeCode/TypeName)
   มีฟิลด์เสริม Address, Province
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

define('CODE_PREFIX', 'WDS');

requireRole(array(4, 5));

$conn = getConnection();

/* ===== Toast จาก redirect ===== */
$toastMsg  = '';
$toastType = '';
if (!empty($_SESSION['toast'])) {
    $toastMsg  = $_SESSION['toast']['msg'];
    $toastType = $_SESSION['toast']['type'];
    unset($_SESSION['toast']);
}

$importResult = null;
if (!empty($_SESSION['import_result'])) {
    $importResult = $_SESSION['import_result'];
    unset($_SESSION['import_result']);
}

/* ===== ดึงรายการสถานที่กำจัดขยะ ===== */
$res = sqlsrv_query($conn, "
    SELECT SiteID, SiteCode, SiteName, Address, Province, Description, SortOrder, IsActive
    FROM CFP_WasteDisposalSite
    ORDER BY SortOrder, SiteName");
$rows = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $rows[] = $r;
}

$total    = count($rows);
$active   = 0;
$inactive = 0;
foreach ($rows as $r) {
    if ($r['IsActive']) { $active++; } else { $inactive++; }
}

/* นับจำนวนสถานที่ที่ถูกใช้งานจริง (รวม CFP_WasteAsset.DisposalSiteID + CFP_Waste.WasteDisposalSiteID) — ต้องตรงกับ usageByType ด้านล่าง */
$usedCount = 0;
$resUsed = @sqlsrv_query($conn, "
    SELECT COUNT(DISTINCT SiteID) AS Cnt FROM (
        SELECT DisposalSiteID AS SiteID FROM CFP_WasteAsset WHERE DisposalSiteID IS NOT NULL
        UNION
        SELECT WasteDisposalSiteID AS SiteID FROM CFP_Waste WHERE WasteDisposalSiteID IS NOT NULL
    ) AS used");
if ($resUsed !== false) {
    $rowUsed   = sqlsrv_fetch_array($resUsed, SQLSRV_FETCH_ASSOC);
    $usedCount = $rowUsed ? (int)$rowUsed['Cnt'] : 0;
}

/* จำนวนที่นำไปใช้จริงแยกตาม SiteID (รวม CFP_WasteAsset.DisposalSiteID + CFP_Waste.WasteDisposalSiteID) */
$usageByType = array();
$resU1 = @sqlsrv_query($conn, "SELECT DisposalSiteID, COUNT(*) AS Cnt FROM CFP_WasteAsset WHERE DisposalSiteID IS NOT NULL GROUP BY DisposalSiteID");
if ($resU1) {
    while ($rU1 = sqlsrv_fetch_array($resU1, SQLSRV_FETCH_ASSOC)) {
        $sid = (int)$rU1['DisposalSiteID'];
        $usageByType[$sid] = ($usageByType[$sid] ?? 0) + (int)$rU1['Cnt'];
    }
}
$resU2 = @sqlsrv_query($conn, "SELECT WasteDisposalSiteID, COUNT(*) AS Cnt FROM CFP_Waste WHERE WasteDisposalSiteID IS NOT NULL GROUP BY WasteDisposalSiteID");
if ($resU2) {
    while ($rU2 = sqlsrv_fetch_array($resU2, SQLSRV_FETCH_ASSOC)) {
        $sid = (int)$rU2['WasteDisposalSiteID'];
        $usageByType[$sid] = ($usageByType[$sid] ?? 0) + (int)$rU2['Cnt'];
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>สถานที่กำจัดขยะ — ระบบบริหารจัดการคาร์บอนองค์กร</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="../assets/css/cfp-theme.css" rel="stylesheet">
  <style>
    body { font-family: 'Prompt', sans-serif; }
    .font-prompt { font-family: 'Prompt', sans-serif !important; }
    .kpi-icon-box { width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0; }
    .btn-action { width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;font-size:0.8rem; }
    .status-dot { width:8px;height:8px;border-radius:50%;display:inline-block; }
  </style>
</head>
<body>
<div class="d-flex">
  <?php include '../includes/sidebar.php'; ?>
  <div class="cfp-main">
    <?php $pageTitle = 'สถานที่กำจัดขยะ'; $pageIcon = 'geo-alt'; include '../includes/topbar.php'; ?>
    <div class="cfp-content">

     <!-- Page Header -->
      <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
          <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);">
            <i class="bi bi-gear-wide-connected me-2" style="color:var(--cfp-green);"></i>จัดการสถานที่กำจัดขยะ
          </h5>
          <div style="font-size:0.78rem;color:var(--cfp-text-muted);margin-top:2px;">
            การตั้งค่าข้อมูลพื้นฐาน › สถานที่กำจัดขยะ
          </div>
        </div>
        
      </div>

      <!-- KPI -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#E3F2FD;"><i class="bi bi-geo-alt" style="color:#1565C0;"></i></div>
            <div><div style="font-size:1.5rem;font-weight:700;color:var(--cfp-primary);line-height:1.1;"><?php echo $total; ?></div>
            <div style="font-size:0.75rem;color:var(--cfp-text-muted);">สถานที่ทั้งหมด</div></div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#E8F5E9;"><i class="bi bi-check-circle-fill" style="color:#2E7D32;"></i></div>
            <div><div style="font-size:1.5rem;font-weight:700;color:#2E7D32;line-height:1.1;"><?php echo $active; ?></div>
            <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ใช้งานอยู่</div></div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#F5F5F5;"><i class="bi bi-slash-circle" style="color:#9E9E9E;"></i></div>
            <div><div style="font-size:1.5rem;font-weight:700;color:#9E9E9E;line-height:1.1;"><?php echo $inactive; ?></div>
            <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ปิดใช้งาน</div></div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#FFF3E0;"><i class="bi bi-trash" style="color:#E65100;"></i></div>
            <div><div style="font-size:1.5rem;font-weight:700;color:#E65100;line-height:1.1;"><?php echo $usedCount; ?></div>
            <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ถูกใช้งานในข้อมูลขยะ</div></div>
          </div>
        </div>
      </div>

      <!-- TABLE CARD -->
      <div class="cfp-card">
        <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);margin-bottom:12px;">
          <i class="bi bi-geo-alt me-2"></i>รายการสถานที่กำจัดขยะ
        </div>
        <div class="cfp-page-toolbar mb-3">
          <div class="d-flex gap-2 flex-grow-1" style="max-width:560px;">
            <div class="cfp-search-wrap flex-grow-1" style="position:relative;">
            <input type="text" id="fltKeyword" class="form-control font-prompt" style="font-size:0.85rem;padding-right:28px;" placeholder="ค้นหารหัส / ชื่อสถานที่ / จังหวัด...">
            <button type="button" class="cfp-search-clear" onclick="clearKeyword()" title="ล้างคำค้นหา" style="display:none;position:absolute;right:6px;top:50%;transform:translateY(-50%);border:none;background:none;padding:2px;line-height:1;color:var(--cfp-text-muted,#888);font-size:0.95rem;cursor:pointer;z-index:2;"><i class="bi bi-x-circle-fill"></i></button>
            </div>
            <select id="fltStatus" class="form-select font-prompt" style="font-size:0.85rem;max-width:160px;">
              <option value="">สถานะทั้งหมด</option>
              <option value="1">ใช้งาน</option>
              <option value="0">ปิด</option>
            </select>
            <button class="btn btn-outline-secondary" style="font-size:0.85rem;white-space:nowrap;" onclick="clearFilter()">
              <i class="bi bi-x-circle me-1"></i>ล้าง
            </button>
          </div>
          <div class="cfp-page-toolbar-actions">
            <button class="btn-cfp-import" onclick="openImportModal()"><i class="bi bi-file-earmark-spreadsheet"></i>Import Excel</button>
            <button class="btn-cfp-add" onclick="openModal(0)"><i class="bi bi-plus-circle"></i>เพิ่มสถานที่กำจัดขยะ</button>
          </div>
        </div>
        <div class="table-responsive">
          <table id="tblType" class="table table-bordered table-hover align-middle" style="width:100%">
            <thead>
              <tr>
                <th style="width:40px;">#</th>
                <th style="min-width:180px;">ชื่อสถานที่</th>
                <th style="width:130px;">จังหวัด</th>
                <th>คำอธิบาย</th>
                <th class="text-center" style="width:80px;">ลำดับ</th>
                <th class="text-center" style="width:150px;">จำนวนทรัพย์สินที่ใช้</th>
                <th class="text-center" style="width:90px;">สถานะ</th>
                <th class="text-center" style="width:110px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $i => $r) {
                $usedN = $usageByType[(int)$r['SiteID']] ?? 0;
              ?>
              <tr data-status="<?php echo $r['IsActive'] ? '1' : '0'; ?>">
                <td><?php echo $i + 1; ?></td>
                <td>
                  <span style="white-space:nowrap;"><?php echo htmlspecialchars($r['SiteName']); ?></span>
                  <div><code style="font-size:0.7rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($r['SiteCode']); ?></code></div>
                  <?php if ($r['Address']) { ?>
                    <div style="font-size:0.75rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($r['Address']); ?></div>
                  <?php } ?>
                </td>
                <td style="font-size:0.82rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($r['Province'] ?? '—'); ?></td>
                <td class="text-muted" style="font-size:0.7rem;color:#6c757d;">
                  <?php echo htmlspecialchars($r['Description'] ?? '—'); ?>
                </td>
                 <td class="text-center"><?php echo (int)$r['SortOrder']; ?></td>
                <td class="text-center">
                  <?php if ($usedN > 0) { ?>
                    <span class="badge" style="background:#FFF3E0;color:#E65100;font-weight:600;" title="มีการนำไปใช้ — ลบไม่ได้ ต้องปิดใช้งานแทน">
                      นำไปใช้ <?php echo $usedN; ?> รายการ
                    </span>
                  <?php } else { ?>
                    <span class="badge" style="background:#F5F5F5;color:#9E9E9E;font-weight:500;" title="ไม่มีการนำไปใช้ ลบได้ปลอดภัย">
                      ไม่ได้นำไปใช้
                    </span>
                  <?php } ?>
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
                <td class="text-center">
                  <button class="btn btn-outline-primary btn-action me-1" onclick="openModal(<?php echo (int)$r['SiteID']; ?>)" title="แก้ไข">
                    <i class="bi bi-pencil-square"></i>
                  </button>
                  <button class="btn btn-action <?php echo $r['IsActive'] ? 'btn-outline-danger' : 'btn-outline-success'; ?> me-1"
                          onclick="confirmToggle(<?php echo (int)$r['SiteID']; ?>, <?php echo $r['IsActive'] ? 1 : 0; ?>, '<?php echo htmlspecialchars(addslashes($r['SiteName'])); ?>')"
                          title="<?php echo $r['IsActive'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน'; ?>">
                    <i class="bi bi-<?php echo $r['IsActive'] ? 'toggle2-off' : 'toggle2-on'; ?>"></i>
                  </button>
                  <button class="btn btn-outline-warning btn-action" onclick="confirmDelete(<?php echo (int)$r['SiteID']; ?>, '<?php echo htmlspecialchars(addslashes($r['SiteName'])); ?>')" title="ลบ">
                    <i class="bi bi-trash"></i>
                  </button>
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
<div class="modal fade" id="modalType" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content font-prompt">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff;">
        <h6 class="modal-title mb-0" id="modalTitle"><i class="bi bi-plus-circle me-2"></i>เพิ่มสถานที่กำจัดขยะ</h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formType" method="POST" action="wastedisposalsite_save.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" id="fAction" value="create">
        <input type="hidden" name="SiteID" id="fID" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">รหัสสถานที่</label>
              <input type="text" class="form-control font-prompt" id="fCodeDisplay" value="ระบบสร้างให้อัตโนมัติ" readonly style="background:#F0F0F0;color:var(--cfp-text-muted);">
            </div>
            <div class="col-md-8">
              <label class="form-label form-required">ชื่อสถานที่</label>
              <input type="text" class="form-control font-prompt" name="SiteName" id="fName" placeholder="เช่น บ่อขยะสุวรรณภูมิ" maxlength="200" required>
            </div>
            <div class="col-12">
              <label class="form-label">ที่อยู่</label>
              <input type="text" class="form-control font-prompt" name="Address" id="fAddress" placeholder="เช่น 123 หมู่ 5 ต.บางพลี อ.บางพลี" maxlength="300">
            </div>
            <div class="col-md-6">
              <label class="form-label">จังหวัด</label>
              <input type="text" class="form-control font-prompt" name="Province" id="fProvince" placeholder="เช่น สมุทรปราการ" maxlength="100">
            </div>
            <div class="col-md-6">
              <label class="form-label">ลำดับแสดง</label>
              <input type="number" class="form-control font-prompt" name="SortOrder" id="fSort" value="99" min="1" max="999">
            </div>
            <div class="col-12">
              <label class="form-label">คำอธิบาย</label>
              <textarea class="form-control font-prompt" name="Description" id="fDesc" rows="2" maxlength="500"></textarea>
            </div>
            <div class="col-md-6" id="statusWrap" style="display:none;">
              <label class="form-label">สถานะ</label>
              <select class="form-select font-prompt" name="IsActive" id="fActive">
                <option value="1">ใช้งาน</option>
                <option value="0">ปิดใช้งาน</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer" style="background:#F9FAFB;">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
          <button type="submit" class="btn-cfp-add"><i class="bi bi-check-circle"></i>บันทึก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Import -->
<div class="modal fade modal-cfp" id="modalImport" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content font-prompt">
      <div class="modal-header">
        <h6 class="modal-title"><i class="bi bi-file-earmark-spreadsheet"></i> Import สถานที่กำจัดขยะ จาก Excel</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="formImport" method="POST" action="wastedisposalsite_import.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div class="modal-body">
          <div class="mb-3 d-flex justify-content-between align-items-center">
            <label class="form-label mb-0">เลือกไฟล์ Excel <span style="color:var(--cfp-danger);">*</span></label>
            <a href="wastedisposalsite_template.php" class="cfp-import-template-link"><i class="bi bi-download"></i> ดาวน์โหลดไฟล์ตัวอย่าง (Template)</a>
          </div>
          <div class="cfp-import-dropzone" id="dropZone" onclick="document.getElementById('importFile').click()">
            <i class="bi bi-cloud-arrow-up-fill"></i>
            <p class="mb-1 mt-2" style="font-size:0.85rem;">คลิกหรือลากไฟล์ Excel มาวางที่นี่</p>
            <p style="font-size:0.72rem;color:var(--cfp-text-muted);">คอลัมน์: ชื่อสถานที่, ที่อยู่, จังหวัด, คำอธิบาย, ลำดับ </p>
            <input type="file" id="importFile" name="import_file" accept=".xlsx" style="display:none;" onchange="handleFileSelect(this)">
          </div>
          <div id="importFileName" style="margin-top:10px;font-size:0.82rem;color:var(--cfp-primary);"></div>

          <div class="mt-2" style="font-size:0.78rem;color:var(--cfp-text-muted);">
            <strong style="color:var(--cfp-primary);">กฎการนำเข้า:</strong>
            <ul class="mb-0 ps-3">
              <li>รองรับไฟล์ .xlsx เท่านั้น ขนาดไม่เกิน 5 MB</li>
              <li>ระบบสร้างรหัสสถานที่ให้อัตโนมัติทุกแถว (<?php echo CODE_PREFIX; ?>-0001, ...)
            <!-- Badge สีส้ม  -->
                <span class="badge badge-sm" style="background-color: #fd6a01ff; color: #fff; ">
                  Auto 
                </span></li>
              <li>ชื่อสถานที่ซ้ำกับข้อมูลเดิม หรือซ้ำกันเองในไฟล์ → ข้ามแถวนั้น</li>
              <li>ไม่กรอกชื่อสถานที่ → ไม่นำเข้าแถวนั้น</li>
            </ul>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">ปิด</button>
          <button type="submit" class="btn-cfp-add"><i class="bi bi-upload"></i> นำเข้าข้อมูล</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:1080;">
  <div id="toastSuccess" class="toast align-items-center text-white border-0" style="background:#2E7D32;" role="alert">
    <div class="d-flex"><div class="toast-body d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i><span id="toastMsg">บันทึกข้อมูลเรียบร้อย</span></div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
  <div id="toastError" class="toast align-items-center text-white border-0" style="background:#C62828;" role="alert">
    <div class="d-flex"><div class="toast-body d-flex align-items-center gap-2"><i class="bi bi-x-circle-fill"></i><span id="toastErrMsg">เกิดข้อผิดพลาด</span></div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
</div>

<form id="formDelete" method="POST" action="wastedisposalsite_save.php" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="SiteID" id="fdID" value="0">
</form>
<form id="formToggle" method="POST" action="wastedisposalsite_save.php" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="action" value="toggle">
  <input type="hidden" name="SiteID" id="ftID" value="0">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
var typeData = <?php
    $map = array();
    foreach ($rows as $r) {
        $map[$r['SiteID']] = array(
            'code'    => $r['SiteCode'],
            'name'    => $r['SiteName'],
            'address' => $r['Address'],
            'province'=> $r['Province'],
            'desc'    => $r['Description'],
            'sort'    => (int)$r['SortOrder'],
            'active'  => (int)$r['IsActive'],
        );
    }
    echo json_encode($map, JSON_UNESCAPED_UNICODE);
?>;

$.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
    var statusVal = $('#fltStatus').val();
    if (statusVal === '') { return true; }
    var rowNode = $('#tblType').DataTable().row(dataIndex).node();
    return $(rowNode).attr('data-status') === statusVal;
});

var tblTypeApi;
$(document).ready(function () {
    tblTypeApi = $('#tblType').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order: [[4, 'asc']], pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        dom: '<"row align-items-center mb-2"<"col-auto"l><"col">>rtip'
    });
    $('#fltKeyword').on('keyup', function () { tblTypeApi.search(this.value).draw(); });
    $('#fltStatus').on('change', function () { tblTypeApi.draw(); });
});

$('#fltKeyword').on('input', function () {
    $(this).closest('.cfp-search-wrap').find('.cfp-search-clear').toggle(this.value.length > 0);
});
function clearKeyword() {
    $('#fltKeyword').val('').trigger('keyup').trigger('input').focus();
}
function clearFilter() {
    $('#fltKeyword').val(''); $('#fltStatus').val('');
    tblTypeApi.search('').draw();
}

function openModal(id) {
    var modal = new bootstrap.Modal(document.getElementById('modalType'));
    document.getElementById('fName').value    = '';
    document.getElementById('fAddress').value = '';
    document.getElementById('fProvince').value= '';
    document.getElementById('fDesc').value    = '';
    document.getElementById('fSort').value    = '99';
    document.getElementById('fActive').value  = '1';
    document.getElementById('statusWrap').style.display = 'none';

    if (id === 0) {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>เพิ่มสถานที่กำจัดขยะ';
        document.getElementById('fAction').value = 'create';
        document.getElementById('fID').value     = '0';
        document.getElementById('fCodeDisplay').value = 'ระบบสร้างให้อัตโนมัติ';
    } else {
        var d = typeData[id];
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขสถานที่กำจัดขยะ';
        document.getElementById('fAction').value  = 'update';
        document.getElementById('fID').value      = id;
        document.getElementById('fCodeDisplay').value = d.code;
        document.getElementById('fName').value    = d.name;
        document.getElementById('fAddress').value = (d.address !== null ? d.address : '');
        document.getElementById('fProvince').value= (d.province !== null ? d.province : '');
        document.getElementById('fDesc').value    = d.desc;
        document.getElementById('fSort').value    = d.sort;
        document.getElementById('fActive').value  = d.active;
        document.getElementById('statusWrap').style.display = '';
    }
    modal.show();
}

function confirmDelete(id, name) {
    Swal.fire({ title:'ยืนยันการลบ?', html:'ต้องการลบ "<b>'+name+'</b>" ใช่หรือไม่', icon:'warning',
        showCancelButton:true, confirmButtonColor:'#2AABB8', cancelButtonColor:'#9E9E9E',
        confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก', customClass:{popup:'font-prompt'}
    }).then(function(r){ if(r.isConfirmed){ document.getElementById('fdID').value=id; document.getElementById('formDelete').submit(); } });
}

function confirmToggle(id, currentActive, name) {
    var action = currentActive ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    Swal.fire({ title:action+'สถานที่กำจัดขยะ?', html:'สถานที่: <b>'+name+'</b>', icon:currentActive?'warning':'question',
        showCancelButton:true, confirmButtonColor:currentActive?'#DC3545':'#4CAF50', cancelButtonColor:'#6C757D',
        confirmButtonText:action, cancelButtonText:'ยกเลิก', reverseButtons:true, customClass:{popup:'font-prompt'}
    }).then(function(r){ if(r.isConfirmed){ document.getElementById('ftID').value=id; document.getElementById('formToggle').submit(); } });
}

function openImportModal() {
    document.getElementById('formImport').reset();
    document.getElementById('importFileName').textContent = '';
    new bootstrap.Modal(document.getElementById('modalImport')).show();
}
function handleFileSelect(input) {
    var nameBox = document.getElementById('importFileName');
    nameBox.innerHTML = (input.files && input.files.length > 0)
        ? '<i class="bi bi-file-earmark-check-fill me-1"></i>' + input.files[0].name : '';
}
(function () {
    var dz = document.getElementById('dropZone');
    var fi = document.getElementById('importFile');
    if (!dz) { return; }
    ['dragenter','dragover'].forEach(function(e){ dz.addEventListener(e, function(ev){ ev.preventDefault(); dz.classList.add('dragover'); }); });
    ['dragleave','drop'].forEach(function(e){ dz.addEventListener(e, function(ev){ ev.preventDefault(); dz.classList.remove('dragover'); }); });
    dz.addEventListener('drop', function(e){ if(e.dataTransfer.files && e.dataTransfer.files.length>0){ fi.files=e.dataTransfer.files; handleFileSelect(fi); } });
})();
document.getElementById('formImport').addEventListener('submit', function(e){
    if (!document.getElementById('importFile').files || document.getElementById('importFile').files.length === 0) {
        e.preventDefault();
        Swal.fire({ title:'กรุณาเลือกไฟล์', text:'ยังไม่ได้เลือกไฟล์ Excel', icon:'warning',
            confirmButtonColor:'#2AABB8', confirmButtonText:'ตกลง', customClass:{popup:'font-prompt'} });
    }
});
function showToast(msg, isError) {
    var id = isError ? 'toastError' : 'toastSuccess';
    var mid = isError ? 'toastErrMsg' : 'toastMsg';
    document.getElementById(mid).textContent = msg;
    new bootstrap.Toast(document.getElementById(id), {delay:3000}).show();
}
<?php if ($toastMsg) { ?>
showToast('<?php echo htmlspecialchars(addslashes($toastMsg)); ?>', <?php echo ($toastType==='error')?'true':'false'; ?>);
<?php } ?>
<?php if ($importResult) { ?>
Swal.fire({ title:'ผลการนำเข้าข้อมูล', icon:<?php echo($importResult['fail']>0)?"'warning'":"'success'"; ?>,
    html:'<div style="text-align:left;font-size:0.9rem;">'
        +'<div class="mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>นำเข้าสำเร็จ: <b><?php echo(int)$importResult['success'];?></b> แถว</div>'
        +'<div class="mb-1"><i class="bi bi-skip-forward-fill text-warning me-2"></i>ข้าม: <b><?php echo(int)$importResult['skip'];?></b> แถว</div>'
        +'<div class="mb-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>ผิดพลาด: <b><?php echo(int)$importResult['fail'];?></b> แถว</div>'
        <?php if(!empty($importResult['errors'])){?>+'<hr><div style="font-size:0.78rem;color:#C62828;max-height:150px;overflow-y:auto;"><?php echo implode('<br>', array_map(function($e){return htmlspecialchars(addslashes($e));}, $importResult['errors']));?></div>'<?php }?>
        +'</div>',
    confirmButtonColor:'#2AABB8', customClass:{popup:'font-prompt'}
});
<?php } ?>
</script>
</body>
</html>

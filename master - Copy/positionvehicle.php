<?php
/* ==============================================
   master/positionvehicle.php
   ทะเบียนรถประจำตำแหน่ง (Scope 3 Cat.6 — น้ำมันประจำตำแหน่ง)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4, 5));

$conn     = getConnection();
$myUserID = (int)$_SESSION['user_id'];

$toastMsg = ''; $toastType = 'success';
if (!empty($_SESSION['toast_msg'])) {
    $toastMsg  = $_SESSION['toast_msg'];
    $toastType = $_SESSION['toast_type'] ?? 'success';
    unset($_SESSION['toast_msg'], $_SESSION['toast_type']);
}

$resSite = sqlsrv_query($conn, "SELECT SiteID, SiteCode, SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
$sites = array();
while ($r = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) { $sites[] = $r; }

$resVT = sqlsrv_query($conn, "SELECT TypeID, TypeName FROM CFP_VehicleType WHERE IsActive=1 ORDER BY SortOrder, TypeName");
$vehicleTypes = array();
while ($r = sqlsrv_fetch_array($resVT, SQLSRV_FETCH_ASSOC)) { $vehicleTypes[] = $r; }

$resFuel = sqlsrv_query($conn, "SELECT TypeID, TypeName FROM CFP_FuelType WHERE IsActive=1 ORDER BY SortOrder, TypeName");
$fuelTypes = array();
while ($r = sqlsrv_fetch_array($resFuel, SQLSRV_FETCH_ASSOC)) { $fuelTypes[] = $r; }

$res = sqlsrv_query($conn, "
    SELECT p.PositionVehicleID, p.Code, p.SiteID, s.SiteName, p.Position,
           p.PlateNo, p.VehicleTypeID, vt.TypeName AS VehicleTypeName,
           p.FuelTypeID, ft.TypeName AS FuelTypeName, p.FuelPricePerLiter,
           p.Remark, p.IsActive
    FROM CFP_PositionVehicle p
    LEFT JOIN CFP_Site s ON s.SiteID = p.SiteID
    LEFT JOIN CFP_VehicleType vt ON vt.TypeID = p.VehicleTypeID
    LEFT JOIN CFP_FuelType ft ON ft.TypeID = p.FuelTypeID
    ORDER BY p.Code");
$rows = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }

$total  = count($rows);
$active = count(array_filter($rows, function($r){ return (bool)$r['IsActive']; }));

$resMax = sqlsrv_query($conn, "SELECT MAX(Code) AS MaxCode FROM CFP_PositionVehicle WHERE Code LIKE 'PV-%'");
$rMax = sqlsrv_fetch_array($resMax, SQLSRV_FETCH_ASSOC);
$nextNum = 1;
if ($rMax && $rMax['MaxCode'] && preg_match('/(\d+)$/', $rMax['MaxCode'], $m)) { $nextNum = (int)$m[1] + 1; }
$nextCode = 'PV-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

$map = array();
foreach ($rows as $r) {
    $map[(int)$r['PositionVehicleID']] = array(
        'code'      => $r['Code'],
        'siteID'    => $r['SiteID'] ? (int)$r['SiteID'] : '',
        'position'  => $r['Position'],
        'plateNo'   => $r['PlateNo'] ?? '',
        'vtID'      => $r['VehicleTypeID'] ? (int)$r['VehicleTypeID'] : '',
        'ftID'      => $r['FuelTypeID'] ? (int)$r['FuelTypeID'] : '',
        'price'     => $r['FuelPricePerLiter'] !== null ? (float)$r['FuelPricePerLiter'] : '',
        'remark'    => $r['Remark'] ?? '',
    );
}
$pageTitle = 'ทะเบียนรถประจำตำแหน่ง';
?>
<!DOCTYPE html><html lang="th"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ทะเบียนรถประจำตำแหน่ง — CFP</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="/carbonfootprint/assets/css/cfp-theme.css?v=<?php echo filemtime('../assets/css/cfp-theme.css'); ?>" rel="stylesheet">
<style>
body{font-family:'Prompt',sans-serif;}
.font-prompt{font-family:'Prompt',sans-serif!important;}
.btn-action{width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;font-size:0.8rem;}
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;}
</style>
</head><body>
<?php include '../includes/sidebar.php'; ?>
<div class="cfp-main">
<?php include '../includes/topbar.php'; ?>
<div class="cfp-content">

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);"><i class="bi bi-car-front-fill me-2"></i>ทะเบียนรถประจำตำแหน่ง</h5>
    <div style="font-size:0.78rem;color:var(--cfp-text-muted)">ทะเบียนทรัพย์สิน (Scope 3) › รถประจำตำแหน่ง (Cat.6 การเดินทางเพื่อธุรกิจ)</div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="cfp-card py-2 px-3 text-center"><div style="font-size:1.6rem;font-weight:700;color:var(--cfp-primary)"><?php echo $total; ?></div><div style="font-size:0.75rem;color:var(--cfp-text-muted)">ทั้งหมด</div></div></div>
  <div class="col-6 col-md-3"><div class="cfp-card py-2 px-3 text-center"><div style="font-size:1.6rem;font-weight:700;color:#43A047"><?php echo $active; ?></div><div style="font-size:0.75rem;color:var(--cfp-text-muted)">ใช้งาน</div></div></div>
  <div class="col-6 col-md-3"><div class="cfp-card py-2 px-3 text-center"><div style="font-size:1.6rem;font-weight:700;color:#F57C00"><?php echo $total-$active; ?></div><div style="font-size:0.75rem;color:var(--cfp-text-muted)">ปิดใช้งาน</div></div></div>
</div>

<div class="cfp-card">
    <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);margin-bottom:12px;">
        <i class="bi bi-card-list me-2"></i>รายการรถประจำตำแหน่ง
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <input type="text" id="fltKeyword" class="form-control font-prompt" style="max-width:280px;" placeholder="ค้นหาตำแหน่ง / ชื่อ / ทะเบียนรถ...">
        <button class="btn btn-outline-secondary btn-sm" onclick="clearFilter()"><i class="bi bi-x-circle me-1"></i>ล้าง</button>
        <button class="btn-cfp-add btn-sm ms-auto" onclick="openModal(0)"><i class="bi bi-plus-circle me-1"></i>เพิ่มรถประจำตำแหน่ง</button>
    </div>

    <div class="table-responsive">
        <table id="tblPV" class="table table-bordered table-hover align-middle" style="width:100%;font-size:0.85rem;">
            <thead>
                <tr>
                    <th class="cfp-th-expand"></th>
                    <th class="cfp-th-num" style="width:40px;">#</th>
                    <th class="cfp-col-hide" style="width:100px;">รหัส</th>
                    <th>ตำแหน่ง</th>
                    <th class="cfp-col-hide" style="width:110px;">ทะเบียนรถ</th>
                    <th class="cfp-col-hide" style="width:150px;">ประเภทพาหนะ</th>
                    <th class="cfp-col-hide" style="width:130px;">ชนิดเชื้อเพลิง</th>
                    <th class="cfp-col-hide text-end" style="width:110px;">ราคา/ลิตร (บาท)</th>
                    <th class="cfp-col-hide" style="width:110px;">Site</th>
                    <th class="text-center" style="width:80px;">สถานะ</th>
                    <th class="cfp-col-hide text-center" style="width:80px;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $r) { ?>
                <tr data-status="<?php echo $r['IsActive'] ? 'ใช้งาน' : 'ปิด'; ?>">
                    <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
                    <td class="cfp-td-num"><?php echo $i+1; ?></td>
                    <td class="cfp-col-hide"><code><?php echo htmlspecialchars($r['Code']); ?></code></td>
                    <td>
                        <div class="fw-500"><?php echo htmlspecialchars($r['Position']); ?></div>
                    </td>
                    <td class="cfp-col-hide"><?php echo htmlspecialchars($r['PlateNo'] ?? '—'); ?></td>
                    <td class="cfp-col-hide"><?php echo htmlspecialchars($r['VehicleTypeName'] ?? '—'); ?></td>
                    <td class="cfp-col-hide"><?php echo htmlspecialchars($r['FuelTypeName'] ?? '—'); ?></td>
                    <td class="cfp-col-hide text-end"><?php echo $r['FuelPricePerLiter'] !== null ? number_format((float)$r['FuelPricePerLiter'], 2) : '—'; ?></td>
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
                          <button class="btn btn-outline-primary btn-action me-1 cfp-act-primary" onclick="openModal(<?php echo (int)$r['PositionVehicleID']; ?>)" title="แก้ไข">
                            <i class="bi bi-pencil-square"></i><span class="cfp-act-label">แก้ไข</span>
                          </button>
                          <div class="cfp-act-secondary">
                            <button class="btn btn-action <?php echo $r['IsActive']?'btn-outline-danger':'btn-outline-success'; ?> cfp-act-toggle"
                                    onclick="confirmToggle(<?php echo (int)$r['PositionVehicleID']; ?>,<?php echo $r['IsActive']?1:0; ?>,'<?php echo htmlspecialchars(addslashes($r['Position'])); ?>')"
                                    title="<?php echo $r['IsActive']?'ปิด':'เปิด'; ?>">
                                <i class="bi bi-<?php echo $r['IsActive']?'toggle2-off':'toggle2-on'; ?>"></i><span class="cfp-act-label"><?php echo $r['IsActive']?'ปิดใช้งาน':'เปิดใช้งาน'; ?></span>
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
</div></div>

<!-- Modal -->
<div class="modal fade" id="modalPV" tabindex="-1" data-bs-backdrop="static">
<div class="modal-dialog modal-dialog-centered modal-lg">
<div class="modal-content" style="font-family:'Prompt',sans-serif">
<div class="modal-header" style="background:var(--cfp-primary);color:#fff">
  <h6 class="modal-title mb-0" id="modalTitle"><i class="bi bi-car-front-fill me-2"></i>เพิ่มรถประจำตำแหน่ง</h6>
  <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form id="formPV" method="POST">
<input type="hidden" name="action" id="fAction" value="create">
<input type="hidden" name="id" id="fID" value="0">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
<div class="modal-body"><div class="row g-3">
  <div class="col-md-4"><label class="form-label">รหัส (Auto)</label>
    <input type="text" class="form-control" name="Code" id="fCode" readonly style="background:#F3F4F6;color:#6B7280;font-family:'Prompt',sans-serif"></div>
  <div class="col-md-4"><label class="form-label">Site</label>
    <select class="form-select" name="SiteID" id="fSite" style="font-family:'Prompt',sans-serif">
      <option value="">— ไม่ระบุ —</option>
      <?php foreach ($sites as $s) { ?><option value="<?php echo $s['SiteID']; ?>"><?php echo htmlspecialchars($s['SiteName']); ?></option><?php } ?>
    </select></div>
  <div class="col-md-4"><label class="form-label">ทะเบียนรถ</label>
    <input type="text" class="form-control" name="PlateNo" id="fPlateNo" maxlength="50" style="font-family:'Prompt',sans-serif"></div>

  <div class="col-md-6"><label class="form-label form-required">ตำแหน่ง</label>
    <input type="text" class="form-control" name="Position" id="fPosition" maxlength="200" required style="font-family:'Prompt',sans-serif"></div>

  <div class="col-md-4"><label class="form-label">ประเภทพาหนะ</label>
    <select class="form-select" name="VehicleTypeID" id="fVehicleType" style="font-family:'Prompt',sans-serif">
      <option value="">— ไม่ระบุ —</option>
      <?php foreach ($vehicleTypes as $vt) { ?><option value="<?php echo $vt['TypeID']; ?>"><?php echo htmlspecialchars($vt['TypeName']); ?></option><?php } ?>
    </select></div>
  <div class="col-md-4"><label class="form-label">ชนิดเชื้อเพลิง</label>
    <select class="form-select" name="FuelTypeID" id="fFuelType" style="font-family:'Prompt',sans-serif">
      <option value="">— ไม่ระบุ —</option>
      <?php foreach ($fuelTypes as $ft) { ?><option value="<?php echo $ft['TypeID']; ?>"><?php echo htmlspecialchars($ft['TypeName']); ?></option><?php } ?>
    </select></div>
  <div class="col-md-4"><label class="form-label">ราคาเชื้อเพลิงเฉลี่ยต่อลิตร (บาท)</label>
    <input type="number" class="form-control" name="FuelPricePerLiter" id="fPrice" step="0.01" min="0" style="font-family:'Prompt',sans-serif"></div>

  <div class="col-12"><label class="form-label">หมายเหตุ</label>
    <textarea class="form-control" name="Remark" id="fRemark" rows="2" maxlength="500" style="font-family:'Prompt',sans-serif;resize:none"></textarea></div>
</div></div>
<div class="modal-footer" style="background:#F9FAFB">
  <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>ยกเลิก</button>
  <button type="button" class="btn-cfp-add btn-sm" id="btnSave"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
</div>
</form></div></div></div>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999">
  <div id="toastSuccess" class="toast align-items-center text-bg-success border-0" style="font-family:'Prompt',sans-serif"><div class="d-flex"><div class="toast-body" id="toastMsg"></div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>
  <div id="toastError" class="toast align-items-center text-bg-danger border-0" style="font-family:'Prompt',sans-serif"><div class="d-flex"><div class="toast-body" id="toastErrMsg"></div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../assets/js/cfp-table-mobile.js"></script>
<script>
var pvData  = <?php echo json_encode($map, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var NEXT_CODE = <?php echo json_encode($nextCode, JSON_UNESCAPED_UNICODE); ?>;

/* ── Pre-fill จากคำขอเพิ่มทรัพย์สิน (master/asset_requests.php ส่ง query string มา) ── */
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

    var name         = params.get('name');
    var site         = params.get('site');
    var plateNo      = params.get('plateno');
    var vehicleType  = params.get('vehicletype');
    var fuelType     = params.get('fueltype');
    var fuelPrice    = params.get('fuelprice');

    if (name)         { document.getElementById('fPosition').value = name; }
    if (site)         { document.getElementById('fSite').value = site; }
    if (plateNo)      { document.getElementById('fPlateNo').value = plateNo; }
    if (vehicleType)  { cfpSelectByText(document.getElementById('fVehicleType'), vehicleType); }
    if (fuelType)     { cfpSelectByText(document.getElementById('fFuelType'), fuelType); }
    if (fuelPrice)    { document.getElementById('fPrice').value = fuelPrice; }
}

function openModal(id) {
    var form = document.getElementById('formPV');
    form.reset();
    if (id === 0) {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-car-front-fill me-2"></i>เพิ่มรถประจำตำแหน่ง';
        document.getElementById('fAction').value = 'create';
        document.getElementById('fID').value = '0';
        document.getElementById('fCode').value = NEXT_CODE;
    } else {
        var d = pvData[id];
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขรถประจำตำแหน่ง';
        document.getElementById('fAction').value = 'update';
        document.getElementById('fID').value = id;
        document.getElementById('fCode').value = d.code || '';
        document.getElementById('fSite').value = d.siteID || '';
        document.getElementById('fPosition').value = d.position || '';
        document.getElementById('fPlateNo').value = d.plateNo || '';
        document.getElementById('fVehicleType').value = d.vtID || '';
        document.getElementById('fFuelType').value = d.ftID || '';
        document.getElementById('fPrice').value = d.price || '';
        document.getElementById('fRemark').value = d.remark || '';
    }
    new bootstrap.Modal(document.getElementById('modalPV')).show();
}

document.getElementById('btnSave').addEventListener('click', function() {
    if (!document.getElementById('fPosition').value.trim()) {
        Swal.fire({ icon:'warning', title:'ข้อมูลไม่ครบ', text:'กรุณากรอกตำแหน่ง', confirmButtonText:'ตกลง', customClass:{popup:'font-prompt'} });
        return;
    }
    var fd = new FormData(document.getElementById('formPV'));
    fetch('positionvehicle_save.php', { method:'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(res){
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('modalPV')).hide();
                Swal.fire({ icon:'success', title: res.msg, timer:1500, showConfirmButton:false, customClass:{popup:'font-prompt'} })
                    .then(function(){ location.reload(); });
            } else {
                Swal.fire({ icon:'error', title:'ไม่สำเร็จ', text: res.msg, customClass:{popup:'font-prompt'} });
            }
        })
        .catch(function(){ Swal.fire({ icon:'error', title:'เชื่อมต่อ server ไม่ได้', customClass:{popup:'font-prompt'} }); });
});

function confirmToggle(id, isActive, name) {
    var action = isActive ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    Swal.fire({
        title: action + '?', html: '<b>' + name + '</b>', icon: isActive ? 'warning' : 'question',
        showCancelButton: true, confirmButtonText: action, cancelButtonText: 'ยกเลิก',
        confirmButtonColor: isActive ? '#DC3545' : '#4CAF50', customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (result.isConfirmed) {
            var fd = new FormData();
            fd.append('action', 'toggle'); fd.append('id', id); fd.append('is_active', isActive);
            fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            fetch('positionvehicle_save.php', { method:'POST', body: fd }).then(function(){ location.reload(); });
        }
    });
}

$(document).ready(function() {
    cfpApplyPrefillFromRequest();

    var table = $('#tblPV').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order: [[2,'asc']], pageLength: 25, dom: 'lrtip', searching: true,
        columnDefs: [{ targets: 0, orderable: false, searchable: false }],
        drawCallback: function () { cfpInitMobileExpand('tblPV'); }
    });
    $('#tblPV_filter').hide();
    $('#fltKeyword').on('keyup', function() { table.search(this.value).draw(); });
    window.clearFilter = function() { $('#fltKeyword').val(''); table.search('').draw(); };

    cfpBindMobileExpand('tblPV');

    var toastMsg = <?php echo json_encode($toastMsg, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
    var toastErr = <?php echo json_encode($toastType === 'error', JSON_HEX_TAG); ?>;
    if (toastMsg) {
        var id = toastErr ? 'toastError' : 'toastSuccess';
        var mid = toastErr ? 'toastErrMsg' : 'toastMsg';
        document.getElementById(mid).textContent = toastMsg;
        new bootstrap.Toast(document.getElementById(id), { delay: 3000 }).show();
    }
});
</script>
</body></html>

<?php
/* master/equipmenttype.php — จัดการประเภทเครื่องจักร */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

define('CODE_PREFIX', 'EQ');
requireRole(array(4, 5));
$conn = getConnection();

$toastMsg = ''; $toastType = 'success';
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

$res = sqlsrv_query($conn,"SELECT TypeID,TypeCode,TypeName,Description,SortOrder,IsActive FROM CFP_EquipmentType ORDER BY SortOrder,TypeName");
$rows = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }

$total = count($rows); $active = 0; $inactive = 0;
foreach ($rows as $r) { if ($r['IsActive']) { $active++; } else { $inactive++; } }

$resUsed = @sqlsrv_query($conn,"SELECT COUNT(DISTINCT EquipmentTypeID) AS Cnt FROM CFP_Equipment WHERE EquipmentTypeID IS NOT NULL");
$usedCount = 0;
if ($resUsed) { $rU = sqlsrv_fetch_array($resUsed, SQLSRV_FETCH_ASSOC); $usedCount = $rU ? (int)$rU['Cnt'] : 0; }

/* จำนวนที่นำไปใช้จริงแยกตาม TypeID — ใช้ตัดสินใจว่าประเภทไหนลบได้ปลอดภัย */
$usageByType = array();
$resUsageDetail = @sqlsrv_query($conn, "SELECT EquipmentTypeID, COUNT(*) AS Cnt FROM CFP_Equipment WHERE EquipmentTypeID IS NOT NULL GROUP BY EquipmentTypeID");
if ($resUsageDetail) {
    while ($rU2 = sqlsrv_fetch_array($resUsageDetail, SQLSRV_FETCH_ASSOC)) {
        $usageByType[(int)$rU2['EquipmentTypeID']] = (int)$rU2['Cnt'];
    }
}

$pageTitle = 'ประเภทเครื่องจักร'; $pageIcon = 'gear-wide-connected';
?>
<!DOCTYPE html><html lang="th"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ประเภทเครื่องจักร — CFP</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="../assets/css/cfp-theme.css" rel="stylesheet">
<style>
body{font-family:'Prompt',sans-serif;}
.font-prompt{font-family:'Prompt',sans-serif!important;}
.kpi-icon-box{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
.btn-action{width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;font-size:0.8rem;}
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;}
</style>
</head><body>
<div class="d-flex">
<?php include '../includes/sidebar.php'; ?>
<div class="cfp-main">
<?php include '../includes/topbar.php'; ?>
<div class="cfp-content">

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);"><i class="bi bi-gear-wide-connected me-2" style="color:var(--cfp-green);"></i>จัดการประเภทเครื่องจักร</h5>
    <div style="font-size:0.78rem;color:var(--cfp-text-muted);">การตั้งค่าข้อมูลพื้นฐาน › ประเภทเครื่องจักร</div>
  </div>
</div>

<!-- KPI -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
    <div class="kpi-icon-box" style="background:#E3F2FD;"><i class="bi bi-gear-wide-connected" style="color:#1565C0;"></i></div>
    <div><div style="font-size:1.5rem;font-weight:700;color:var(--cfp-primary);line-height:1.1;"><?php echo $total; ?></div><div style="font-size:0.75rem;color:var(--cfp-text-muted);">ประเภททั้งหมด</div></div>
  </div></div>
  <div class="col-6 col-md-3"><div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
    <div class="kpi-icon-box" style="background:#E8F5E9;"><i class="bi bi-check-circle-fill" style="color:#2E7D32;"></i></div>
    <div><div style="font-size:1.5rem;font-weight:700;color:#2E7D32;line-height:1.1;"><?php echo $active; ?></div><div style="font-size:0.75rem;color:var(--cfp-text-muted);">ใช้งานอยู่</div></div>
  </div></div>
  <div class="col-6 col-md-3"><div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
    <div class="kpi-icon-box" style="background:#F5F5F5;"><i class="bi bi-slash-circle" style="color:#9E9E9E;"></i></div>
    <div><div style="font-size:1.5rem;font-weight:700;color:#9E9E9E;line-height:1.1;"><?php echo $inactive; ?></div><div style="font-size:0.75rem;color:var(--cfp-text-muted);">ปิดใช้งาน</div></div>
  </div></div>
  <div class="col-6 col-md-3"><div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
    <div class="kpi-icon-box" style="background:#FFF3E0;"><i class="bi bi-tools" style="color:#E65100;"></i></div>
    <div><div style="font-size:1.5rem;font-weight:700;color:#E65100;line-height:1.1;"><?php echo $usedCount; ?></div><div style="font-size:0.75rem;color:var(--cfp-text-muted);">ถูกใช้งานในเครื่องจักร</div></div>
  </div></div>
</div>

<!-- Table -->
<div class="cfp-card">
  <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);margin-bottom:12px;"><i class="bi bi-gear-wide-connected me-2"></i>รายการประเภทเครื่องจักร</div>
  <div class="cfp-page-toolbar mb-3">

    <div class="d-flex gap-2 flex-grow-1" style="max-width:560px;">
            <div class="cfp-search-wrap flex-grow-1" style="position:relative;">
            <input type="text" id="fltKeyword" class="form-control font-prompt" style="font-size:0.85rem;padding-right:28px;"
                   placeholder="ค้นหารหัส / ชื่อประเภท / คำอธิบาย...">
            <button type="button" class="cfp-search-clear" onclick="clearKeyword()" title="ล้างคำค้นหา" style="display:none;position:absolute;right:6px;top:50%;transform:translateY(-50%);border:none;background:none;padding:2px;line-height:1;color:var(--cfp-text-muted,#888);font-size:0.95rem;cursor:pointer;z-index:2;"><i class="bi bi-x-circle-fill"></i></button>
            </div>
            <select id="fltStatus" class="form-select font-prompt" style="font-size:0.85rem;max-width:160px;">
              <option value="">สถานะทั้งหมด</option>
              <option value="1">ใช้งาน</option>
              <option value="0">ปิด</option>
            </select>
            <button class="btn btn-outline-secondary btn-sm" style="font-size:0.85rem;white-space:nowrap;" onclick="clearFilter()">
              <i class="bi bi-x-circle me-1"></i>ล้าง
            </button>
          </div>
    <div class="cfp-page-toolbar-actions">
      <button class="btn-cfp-import" onclick="openImportModal()"><i class="bi bi-file-earmark-spreadsheet"></i>Import Excel</button>
      <button class="btn-cfp-add" onclick="openModal(0)"><i class="bi bi-plus-circle"></i>เพิ่มประเภทเครื่องจักร</button>
    </div>
  </div>
  <div class="table-responsive">
    <table id="tblType" class="table table-bordered table-hover align-middle" style="width:100%">
      <thead><tr>
        <th style="width:40px">#</th>
        <th style="min-width:180px;">ชื่อประเภท</th>
        <th>คำอธิบาย</th>
        <th style="width:70px" class="text-center">ลำดับ</th>
        <th style="width:150px" class="text-center">จำนวนทรัพย์สินที่ใช้</th>
        <th style="width:90px" class="text-center">สถานะ</th>
        <th style="width:100px" class="text-center">จัดการ</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r) {
        $usedN = $usageByType[(int)$r['TypeID']] ?? 0;
      ?>
      <tr data-status="<?php echo $r['IsActive']?'1':'0'; ?>">
        <td><?php echo $i+1; ?></td>
        <td style="white-space:nowrap;">
          <?php echo htmlspecialchars($r['TypeName']); ?>
          <div><code style="font-size:0.7rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($r['TypeCode']); ?></code></div>
        </td>
        <td class="text-muted" style="font-size:0.7rem;color:#6c757d;"><?php echo htmlspecialchars($r['Description']??''); ?></td>
        <td class="text-center"><?php echo $r['SortOrder']; ?></td>
        <td class="text-center">
          <?php if ($usedN > 0) { ?>
            <span class="badge" style="background:#FFF3E0;color:#E65100;font-weight:600;" title="มีการนำไปใช้ — ลบไม่ได้ ต้องปิดใช้งานแทน">นำไปใช้ <?php echo $usedN; ?> รายการ</span>
          <?php } else { ?>
            <span class="badge" style="background:#F5F5F5;color:#9E9E9E;font-weight:500;" title="ไม่มีการนำไปใช้ ลบได้ปลอดภัย">ไม่ได้นำไปใช้</span>
          <?php } ?>
        </td>
        <td class="text-center">
          <?php if ($r['IsActive']) { ?>
            <span class="status-dot" style="background:#4CAF50;"></span><span style="font-size:0.78rem;color:#2E7D32;"> ใช้งาน</span>
          <?php } else { ?>
            <span class="status-dot" style="background:#ccc;"></span><span style="font-size:0.78rem;color:#9E9E9E;"> ปิด</span>
          <?php } ?>
        </td>
        <td class="text-center">
          <button class="btn btn-outline-primary btn-action me-1" onclick="openModal(<?php echo (int)$r['TypeID']; ?>)" title="แก้ไข"><i class="bi bi-pencil-square"></i></button>
          <button class="btn btn-action <?php echo $r['IsActive']?'btn-outline-danger':'btn-outline-success'; ?> me-1"
                  onclick="confirmToggle(<?php echo (int)$r['TypeID']; ?>,<?php echo $r['IsActive']?1:0; ?>,'<?php echo htmlspecialchars(addslashes($r['TypeName'])); ?>')"
                  title="<?php echo $r['IsActive']?'ปิดใช้งาน':'เปิดใช้งาน'; ?>">
            <i class="bi bi-<?php echo $r['IsActive']?'toggle2-off':'toggle2-on'; ?>"></i></button>
          <button class="btn btn-outline-warning btn-action" onclick="confirmDelete(<?php echo (int)$r['TypeID']; ?>,'<?php echo htmlspecialchars(addslashes($r['TypeName'])); ?>')" title="ลบ">
            <i class="bi bi-trash"></i></button>
        </td>
      </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
</div>
</div></div></div>

<!-- Modal Import -->
<div class="modal fade" id="modalImport" tabindex="-1" data-bs-backdrop="static">
<div class="modal-dialog modal-dialog-centered" style="max-width:460px">
<div class="modal-content font-prompt">
<div class="modal-header" style="background:var(--cfp-primary);color:#fff;padding:0.75rem 1.25rem;">
  <h6 class="modal-title mb-0"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Import ประเภทเครื่องจักร</h6>
  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form id="formImport" method="POST" action="equipmenttype_import.php" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
<div class="modal-body">

<div class="mb-3 d-flex justify-content-between align-items-center">
            <label class="form-label mb-0">เลือกไฟล์ Excel <span style="color:var(--cfp-danger);">*</span></label>
            <a href="equipmenttype_template.php" class="cfp-import-template-link">
              <i class="bi bi-download"></i> ดาวน์โหลดไฟล์ตัวอย่าง (Template)
            </a>
          </div>

  <div id="dropZone" class="text-center p-4 rounded mb-3"
       style="border:2px dashed var(--cfp-primary);background:#EEF6F8;cursor:pointer;"
       onclick="document.getElementById('importFile').click()">
    <i class="bi bi-cloud-upload" style="font-size:2rem;color:var(--cfp-primary);display:block;margin-bottom:8px;"></i>
    <p class="mb-1 mt-2" style="font-size:0.85rem;">คลิกหรือลากไฟล์ Excel มาวางที่นี่</p>
<p style="font-size:0.72rem;color:var(--cfp-text-muted);">คอลัมน์: ชื่อ*, คำอธิบาย, ลำดับ </p>
    <div id="importFileName" style="margin-top:8px;font-size:0.82rem;color:#2E7D32;font-weight:500;"></div>
  </div>
  <input type="file" id="importFile" name="import_file" accept=".xlsx" style="display:none;" onchange="handleFileSelect(this)">
 

 
  <div class="mt-2" style="font-size:0.78rem;color:var(--cfp-text-muted);">
            <strong style="color:var(--cfp-primary);">กฎการนำเข้า:</strong>
            <ul class="mb-0 ps-3">
              <li>รองรับไฟล์ .xlsx เท่านั้น ขนาดไม่เกิน 5 MB</li>
              <li>ระบบสร้างรหัสประเภทให้อัตโนมัติทุกแถว (<?php echo CODE_PREFIX; ?>-0001, <?php echo CODE_PREFIX; ?>-0002, ...)
              <!-- Badge สีส้ม  -->
                <span class="badge badge-sm" style="background-color: #fd6a01ff; color: #fff; ">
                  Auto
                </span>
              </li>
              <li>ไม่กรอกชื่อ → ไม่นำเข้าแถวนั้น</li>
            </ul>
  </div>
</div>
<div class="modal-footer" style="background:#F9FAFB;">
  <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
  <button type="button" class="btn-cfp-add" onclick="submitImport()"><i class="bi bi-upload me-1"></i>นำเข้า</button>
</div>
</form>
</div></div></div>

<!-- Modal เพิ่ม/แก้ไข -->
<div class="modal fade" id="modalType" tabindex="-1" data-bs-backdrop="static">
<div class="modal-dialog modal-dialog-centered" style="max-width:480px">
<div class="modal-content font-prompt">
<div class="modal-header"><h6 class="modal-title mb-0" id="modalTitle"><i class="bi bi-plus-circle me-2"></i>เพิ่มประเภทเครื่องจักร</h6>
<button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<form method="POST" action="equipmenttype_save.php">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
<input type="hidden" name="action" id="fAction" value="create">
<input type="hidden" name="TypeID" id="fID" value="0">
<div class="modal-body"><div class="row g-3">
  <div class="col-12">
    <label class="form-label">รหัส</label>
    <input type="text" class="form-control font-prompt" id="fCodeDisplay" value="ระบบสร้างให้อัตโนมัติ" readonly style="background:#F0F0F0;color:var(--cfp-text-muted);font-size:0.85rem;">
  </div>
  <div class="col-12">
    <label class="form-label form-required">ชื่อประเภทเครื่องจักร</label>
    <input type="text" class="form-control font-prompt" name="TypeName" id="fName" maxlength="200" required placeholder="เช่น เครื่องปั๊มน้ำ, Generator">
  </div>
  <div class="col-12">
    <label class="form-label">คำอธิบาย</label>
    <textarea class="form-control font-prompt" name="Description" id="fDesc" rows="2" maxlength="500"></textarea>
  </div>
  <div class="col-6">
    <label class="form-label">ลำดับแสดง</label>
    <input type="number" class="form-control font-prompt" name="SortOrder" id="fSort" value="99" min="1" max="999">
  </div>
  <div class="col-6" id="statusWrap" style="display:none;">
    <label class="form-label">สถานะ</label>
    <select class="form-select font-prompt" name="IsActive" id="fActive">
      <option value="1">ใช้งาน</option>
      <option value="0">ปิดใช้งาน</option>
    </select>
  </div>
</div></div>
<div class="modal-footer" style="background:#F9FAFB;">
  <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
  <button type="submit" class="btn-cfp-add"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
</div>
</form></div></div></div>

<!-- Hidden forms -->
<form id="formDelete" method="POST" action="equipmenttype_save.php" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="TypeID" id="fdID" value="0">
</form>
<form id="formToggle" method="POST" action="equipmenttype_save.php" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="action" value="toggle">
  <input type="hidden" name="TypeID" id="ftID" value="0">
</form>

<!-- Toast -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:1080;">
  <div id="toastSuccess" class="toast align-items-center text-white border-0" style="background:#2E7D32;" role="alert"><div class="d-flex"><div class="toast-body d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i><span id="toastMsg"></span></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>
  <div id="toastError" class="toast align-items-center text-white border-0" style="background:#C62828;" role="alert"><div class="d-flex"><div class="toast-body d-flex align-items-center gap-2"><i class="bi bi-x-circle-fill"></i><span id="toastErrMsg"></span></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
var typeData = <?php $map=array(); foreach($rows as $r){ $map[$r['TypeID']]=array('code'=>$r['TypeCode'],'name'=>$r['TypeName'],'desc'=>$r['Description']??'','sort'=>(int)$r['SortOrder'],'active'=>(int)$r['IsActive']); } echo json_encode($map); ?>;

$.fn.dataTable.ext.search.push(function(settings,data,dataIndex){
  var sv=$('#fltStatus').val(); if(sv==='') return true;
  return $($('#tblType').DataTable().row(dataIndex).node()).attr('data-status')===sv;
});
var tblApi;
$(document).ready(function(){
  tblApi=$('#tblType').DataTable({language:{url:'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json'},order:[[4,'asc']],pageLength:25,dom:'<"row align-items-center mb-2"<"col-auto"l><"col">>rtip'});
  $('#fltKeyword').on('keyup',function(){tblApi.search(this.value).draw();});
  $('#fltStatus').on('change',function(){tblApi.draw();});
});
$('#fltKeyword').on('input', function () {
    $(this).closest('.cfp-search-wrap').find('.cfp-search-clear').toggle(this.value.length > 0);
});
function clearKeyword() {
    $('#fltKeyword').val('').trigger('keyup').trigger('input').focus();
}
function clearFilter(){$('#fltKeyword,#fltStatus').val('');tblApi.search('').draw();}
function openModal(id){
  document.getElementById('fName').value=''; document.getElementById('fDesc').value='';
  document.getElementById('fSort').value='99'; document.getElementById('fActive').value='1';
  document.getElementById('statusWrap').style.display='none';
  if(id===0){
    document.getElementById('modalTitle').innerHTML='<i class="bi bi-plus-circle me-2"></i>เพิ่มประเภทเครื่องจักร';
    document.getElementById('fAction').value='create'; document.getElementById('fID').value='0';
    document.getElementById('fCodeDisplay').value='ระบบสร้างให้อัตโนมัติ';
  } else {
    var d=typeData[id];
    document.getElementById('modalTitle').innerHTML='<i class="bi bi-pencil-square me-2"></i>แก้ไขประเภทเครื่องจักร';
    document.getElementById('fAction').value='update'; document.getElementById('fID').value=id;
    document.getElementById('fCodeDisplay').value=d.code; document.getElementById('fName').value=d.name;
    document.getElementById('fDesc').value=d.desc; document.getElementById('fSort').value=d.sort;
    document.getElementById('fActive').value=d.active; document.getElementById('statusWrap').style.display='';
  }
  new bootstrap.Modal(document.getElementById('modalType')).show();
}
function confirmDelete(id,name){
  Swal.fire({title:'ยืนยันการลบ?',html:'ต้องการลบ "<b>'+name+'</b>" ใช่หรือไม่',icon:'warning',showCancelButton:true,confirmButtonColor:'#E05050',cancelButtonColor:'#9E9E9E',confirmButtonText:'ลบ',cancelButtonText:'ยกเลิก',customClass:{popup:'font-prompt'}})
  .then(function(r){if(r.isConfirmed){document.getElementById('fdID').value=id;document.getElementById('formDelete').submit();}});
}
function confirmToggle(id,cur,name){
  var act=cur?'ปิดใช้งาน':'เปิดใช้งาน',col=cur?'#DC3545':'#4CAF50';
  Swal.fire({title:act+'ประเภทเครื่องจักร?',html:'ประเภท: <b>'+name+'</b>',icon:cur?'warning':'question',showCancelButton:true,confirmButtonColor:col,cancelButtonColor:'#6C757D',confirmButtonText:act,cancelButtonText:'ยกเลิก',reverseButtons:true,customClass:{popup:'font-prompt'}})
  .then(function(r){if(r.isConfirmed){document.getElementById('ftID').value=id;document.getElementById('formToggle').submit();}});
}
function openImportModal() {
    document.getElementById('formImport').reset();
    document.getElementById('importFileName').textContent = '';
    new bootstrap.Modal(document.getElementById('modalImport')).show();
}
function handleFileSelect(input) {
    var box = document.getElementById('importFileName');
    if (input.files && input.files.length > 0) {
        box.innerHTML = '<i class="bi bi-file-earmark-check-fill me-1"></i>' + input.files[0].name;
    } else { box.textContent = ''; }
}
function submitImport() {
    var f = document.getElementById('importFile');
    if (!f.files || f.files.length === 0) {
        Swal.fire({ title:'กรุณาเลือกไฟล์', icon:'warning', confirmButtonColor:'#2AABB8',
                    customClass:{popup:'font-prompt'} });
        return;
    }
    document.getElementById('formImport').submit();
}
(function(){
    var dz = document.getElementById('dropZone');
    var fi = document.getElementById('importFile');
    if (!dz) { return; }
    ['dragenter','dragover'].forEach(function(e){ dz.addEventListener(e,function(ev){ev.preventDefault();dz.style.background='#D8EDF4';}); });
    ['dragleave','drop'].forEach(function(e){ dz.addEventListener(e,function(ev){ev.preventDefault();dz.style.background='#EEF6F8';}); });
    dz.addEventListener('drop',function(e){
        if (e.dataTransfer.files.length>0){ fi.files=e.dataTransfer.files; handleFileSelect(fi); }
    });
})();

function showToast(msg,isError){
  var id=isError?'toastError':'toastSuccess',mid=isError?'toastErrMsg':'toastMsg';
  document.getElementById(mid).textContent=msg;
  new bootstrap.Toast(document.getElementById(id),{delay:3000}).show();
}
<?php if($toastMsg){?>showToast('<?php echo htmlspecialchars(addslashes($toastMsg));?>',<?php echo $toastType==='error'?'true':'false';?>);<?php }?>
<?php if($importResult){?>
Swal.fire({
    title:'ผลการนำเข้าข้อมูล',
    icon:<?php echo ($importResult['fail']>0)?"'warning'":"'success'";?>,
    html:'<div style="text-align:left;font-size:0.9rem;">'
        +'<div class="mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>สำเร็จ: <b><?php echo (int)$importResult['success'];?></b> แถว</div>'
        +'<div class="mb-1"><i class="bi bi-skip-forward-fill text-warning me-2"></i>ข้าม (ซ้ำ): <b><?php echo (int)$importResult['skip'];?></b> แถว</div>'
        +'<div class="mb-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>ผิดพลาด: <b><?php echo (int)$importResult['fail'];?></b> แถว</div>'
        <?php if(!empty($importResult['errors'])){?>
        +'<hr><div style="font-size:0.78rem;color:#C62828;max-height:150px;overflow-y:auto;"><?php echo implode('<br>',array_map(function($e){return htmlspecialchars(addslashes($e));},$importResult['errors']));?></div>'
        <?php }?>
        +'</div>',
    confirmButtonColor:'#2AABB8',
    customClass:{popup:'font-prompt'}
});
<?php }?>
</script>
</body></html>

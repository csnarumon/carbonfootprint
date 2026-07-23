<?php
/* master/ajax/org_company.php — Tab บริษัท */
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
requireRole(array(4));
$conn = getConnection();

$res  = sqlsrv_query($conn, "SELECT CompanyID,CompanyCode,CompanyName,IsActive,CreatedDate FROM CFP_Company ORDER BY CompanyName");
$rows = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }
?>
<div class="cfp-card">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);">
      <i class="bi bi-building me-2"></i>รายการบริษัท
    </div>
    <button class="btn-cfp-add btn-sm" onclick="openModalCompany(0)">
      <i class="bi bi-plus-circle me-1"></i>เพิ่มบริษัท
    </button>
  </div>
  <div class="table-responsive">
    <table id="tblCompany" class="table table-bordered table-hover align-middle" style="width:100%">
      <thead><tr>
        <th style="width:40px">#</th>
        <th style="width:120px">รหัส</th>
        <th>ชื่อบริษัท</th>
        <th class="text-center" style="width:90px">สถานะ</th>
        <th class="text-center" style="width:90px">จัดการ</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $i => $r) { ?>
        <tr>
          <td><?php echo $i+1; ?></td>
          <td><code><?php echo htmlspecialchars($r['CompanyCode']); ?></code></td>
          <td><?php echo htmlspecialchars($r['CompanyName']); ?></td>
          <td class="text-center">
            <?php if ($r['IsActive']) { ?>
              <span class="badge bg-success">ใช้งาน</span>
            <?php } else { ?>
              <span class="badge bg-secondary">ปิด</span>
            <?php } ?>
          </td>
          <td class="text-center">
            <button class="btn btn-outline-primary btn-sm py-0 px-2 me-1"
                    onclick="openModalCompany(<?php echo $r['CompanyID']; ?>)"
                    title="แก้ไข">
              <i class="bi bi-pencil"></i>
            </button>
            <?php if ($r['IsActive']) { ?>
            <button class="btn btn-outline-danger btn-sm py-0 px-2"
                    onclick="orgDelete('company',<?php echo $r['CompanyID']; ?>,'<?php echo htmlspecialchars(addslashes($r['CompanyName'])); ?>')"
                    title="ลบ">
              <i class="bi bi-trash"></i>
            </button>
            <?php } ?>
          </td>
        </tr>
        <?php } ?>
      </tbody>
    </table>
  </div>
</div>

<script>
/* inject Modal เข้า body (ป้องกัน Modal หายเมื่อ reload Tab) */
(function() {
    if (!document.getElementById('modalCompany')) {
        var div = document.createElement('div');
        div.innerHTML = `<!-- Modal บริษัท -->
<div class="modal fade" id="modalCompany" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width:460px">
    <div class="modal-content" style="font-family:'Prompt',sans-serif">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff">
        <h6 class="modal-title mb-0" id="modalCompanyTitle">
          <i class="bi bi-building me-2"></i>เพิ่มบริษัท
        </h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formCompany" onsubmit="event.preventDefault(); window.orgSave('formCompany','company')">
        <input type="hidden" name="action" id="coAction" value="create">
        <input type="hidden" name="entity" value="company">
        <input type="hidden" name="id"     id="coID"     value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label form-required">รหัสบริษัท</label>
              <input type="text" class="form-control" name="CompanyCode" id="coCode"
                     placeholder="เช่น ABC" maxlength="20" required
                     style="font-family:'Prompt',sans-serif;text-transform:uppercase">
            </div>
            <div class="col-md-8">
              <label class="form-label form-required">ชื่อบริษัท</label>
              <input type="text" class="form-control" name="CompanyName" id="coName"
                     placeholder="ชื่อบริษัท จำกัด" maxlength="200" required
                     style="font-family:'Prompt',sans-serif">
            </div>
          </div>
        </div>
        <div class="modal-footer" style="background:#F9FAFB">
          <button type="button" class="btn btn-outline-secondary btn-sm btn-sm w-25" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn-cfp-add btn-sm">
            <i class="bi bi-check-circle me-1"></i>บันทึก
          </button>
        </div>
      </form>
    </div>
  </div>
</div>`;
        document.body.appendChild(div.firstElementChild);
    }
})();
</script>

<script>
/* JSON data สำหรับ populate Modal */
var coData = <?php
  $map = array();
  foreach ($rows as $r) {
      $map[$r['CompanyID']] = array(
          'code' => $r['CompanyCode'],
          'name' => $r['CompanyName'],
      );
  }
  echo json_encode($map);
?>;

function openModalCompany(id) {
    var modal = new bootstrap.Modal(document.getElementById('modalCompany'));
    document.getElementById('formCompany').reset();
    if (id === 0) {
        document.getElementById('modalCompanyTitle').innerHTML = '<i class="bi bi-building me-2"></i>เพิ่มบริษัท';
        document.getElementById('coAction').value = 'create';
        document.getElementById('coID').value = '0';
    } else {
        var d = coData[id];
        document.getElementById('modalCompanyTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขบริษัท';
        document.getElementById('coAction').value = 'update';
        document.getElementById('coID').value     = id;
        document.getElementById('coCode').value   = d.code;
        document.getElementById('coName').value   = d.name;
    }
    modal.show();
}

</script>

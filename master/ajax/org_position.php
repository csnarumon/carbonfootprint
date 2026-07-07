<?php
/* master/ajax/org_position.php — Tab ตำแหน่ง */
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
requireRole(array(4));
$conn = getConnection();

$res = sqlsrv_query($conn,"SELECT CompanyID,CompanyName FROM CFP_Company WHERE IsActive=1 ORDER BY CompanyName");
$companies = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $companies[] = $r; }

$res = sqlsrv_query($conn,"
    SELECT p.PositionID,p.PositionCode,p.PositionName,p.CompanyID,p.IsActive,
           c.CompanyName
    FROM CFP_Position p
    LEFT JOIN CFP_Company c ON p.CompanyID=c.CompanyID
    ORDER BY c.CompanyName, p.PositionName");
$rows = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }
?>
<div class="cfp-card">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);">
      <i class="bi bi-person-badge me-2"></i>รายการตำแหน่ง
    </div>
    <button class="btn-cfp-add btn-sm" onclick="openModalPosition(0)">
      <i class="bi bi-plus-circle me-1"></i>เพิ่มตำแหน่ง
    </button>
  </div>
  <div class="table-responsive">
    <table id="tblPosition" class="table table-bordered table-hover align-middle" style="width:100%">
      <thead><tr>
        <th style="width:40px">#</th>
        <th style="width:120px">รหัส</th>
        <th>ชื่อตำแหน่ง</th>
        <th>บริษัท</th>
        <th class="text-center" style="width:80px">สถานะ</th>
        <th class="text-center" style="width:80px">จัดการ</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $i => $r) { ?>
        <tr>
          <td><?php echo $i+1; ?></td>
          <td><code><?php echo htmlspecialchars($r['PositionCode']); ?></code></td>
          <td><?php echo htmlspecialchars($r['PositionName']); ?></td>
          <td style="font-size:0.82rem"><?php echo htmlspecialchars($r['CompanyName'] ?? '—'); ?></td>
          <td class="text-center">
            <span class="badge <?php echo $r['IsActive'] ? 'bg-success' : 'bg-secondary'; ?>">
              <?php echo $r['IsActive'] ? 'ใช้งาน' : 'ปิด'; ?>
            </span>
          </td>
          <td class="text-center">
            <button class="btn btn-outline-primary btn-sm py-0 px-2 me-1"
                    onclick="openModalPosition(<?php echo $r['PositionID']; ?>)" title="แก้ไข">
              <i class="bi bi-pencil"></i>
            </button>
            <?php if ($r['IsActive']) { ?>
            <button class="btn btn-outline-danger btn-sm py-0 px-2"
                    onclick="orgDelete('position',<?php echo $r['PositionID']; ?>,'<?php echo htmlspecialchars(addslashes($r['PositionName'])); ?>')"
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

<!-- Modal ตำแหน่ง -->
<div class="modal fade" id="modalPosition" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
    <div class="modal-content" style="font-family:'Prompt',sans-serif">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff">
        <h6 class="modal-title mb-0" id="modalPositionTitle">
          <i class="bi bi-person-badge me-2"></i>เพิ่มตำแหน่ง
        </h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formPosition" onsubmit="event.preventDefault(); window.orgSave('formPosition','position')">
        <input type="hidden" name="action"  id="poAction" value="create">
        <input type="hidden" name="entity"  value="position">
        <input type="hidden" name="id"      id="poID"     value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label form-required">บริษัท</label>
              <select class="form-select" name="CompanyID" id="poCompany" required
                      style="font-family:'Prompt',sans-serif">
                <option value="">— เลือกบริษัท —</option>
                <?php foreach ($companies as $c) { ?>
                <option value="<?php echo $c['CompanyID']; ?>">
                  <?php echo htmlspecialchars($c['CompanyName']); ?>
                </option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label form-required">รหัสตำแหน่ง</label>
              <input type="text" class="form-control" name="PositionCode" id="poCode"
                     placeholder="เช่น ENG-01" maxlength="20" required
                     style="font-family:'Prompt',sans-serif;text-transform:uppercase">
            </div>
            <div class="col-md-8">
              <label class="form-label form-required">ชื่อตำแหน่ง</label>
              <input type="text" class="form-control" name="PositionName" id="poName"
                     placeholder="ชื่อตำแหน่งงาน" maxlength="200" required
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
</div>

<script>
var poData = <?php
  $map = array();
  foreach ($rows as $r) {
      $map[$r['PositionID']] = array(
          'company' => (int)$r['CompanyID'],
          'code'    => $r['PositionCode'],
          'name'    => $r['PositionName'],
      );
  }
  echo json_encode($map);
?>;

function openModalPosition(id) {
    var modal = new bootstrap.Modal(document.getElementById('modalPosition'));
    document.getElementById('formPosition').reset();
    if (id === 0) {
        document.getElementById('modalPositionTitle').innerHTML = '<i class="bi bi-person-badge me-2"></i>เพิ่มตำแหน่ง';
        document.getElementById('poAction').value = 'create';
        document.getElementById('poID').value     = '0';
    } else {
        var d = poData[id];
        document.getElementById('modalPositionTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขตำแหน่ง';
        document.getElementById('poAction').value   = 'update';
        document.getElementById('poID').value       = id;
        document.getElementById('poCompany').value  = d.company;
        document.getElementById('poCode').value     = d.code;
        document.getElementById('poName').value     = d.name;
    }
    modal.show();
}

</script>

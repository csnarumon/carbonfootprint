<?php
/* master/ajax/org_department.php — Tab แผนก */
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
requireRole(array(4));
$conn = getConnection();

$res = sqlsrv_query($conn,"SELECT DivisionID,DivisionName,CompanyID FROM CFP_Division WHERE IsActive=1 ORDER BY DivisionName");
$divisions = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $divisions[] = $r; }

$res = sqlsrv_query($conn,"
    SELECT dp.DeptID,dp.DeptCode,dp.DeptName,dp.DivisionID,dp.SortOrder,dp.IsActive,
           dv.DivisionName, c.CompanyName
    FROM CFP_Department dp
    LEFT JOIN CFP_Division dv ON dp.DivisionID=dv.DivisionID
    LEFT JOIN CFP_Company  c  ON dv.CompanyID=c.CompanyID
    ORDER BY c.CompanyName, dv.DivisionName, dp.DeptName");
$rows = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }

/* สร้าง option HTML ฝ่าย ก่อน — เพื่อไม่ให้ PHP อยู่ใน JS template literal */
$divisionOptions = '<option value="">— เลือกฝ่าย —</option>';
foreach ($divisions as $d) {
    $divisionOptions .= '<option value="' . (int)$d['DivisionID'] . '">'
                      . htmlspecialchars($d['DivisionName'])
                      . '</option>';
}
?>
<div class="cfp-card">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);">
      <i class="bi bi-diagram-3 me-2"></i>รายการแผนก
    </div>
    <button class="btn-cfp-add btn-sm" onclick="openModalDept(0)">
      <i class="bi bi-plus-circle me-1"></i>เพิ่มแผนก
    </button>
  </div>
  <div class="table-responsive">
    <table id="tblDept" class="table table-bordered table-hover align-middle" style="width:100%">
      <thead><tr>
        <th style="width:40px">#</th>
        <th>ชื่อแผนก</th>
        <th>ฝ่าย</th>
        <th>บริษัท</th>
        <th class="text-center" style="width:80px">สถานะ</th>
        <th class="text-center" style="width:80px">จัดการ</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $i => $r) { ?>
        <tr>
          <td><?php echo $i+1; ?></td>
          <td>
            <?php echo htmlspecialchars($r['DeptName']); ?>
            <code style="font-size:0.72rem;color:#888;margin-left:6px"><?php echo htmlspecialchars($r['DeptCode']); ?></code>
          </td>
          <td style="font-size:0.82rem"><?php echo htmlspecialchars($r['DivisionName'] ?? '—'); ?></td>
          <td style="font-size:0.82rem"><?php echo htmlspecialchars($r['CompanyName'] ?? '—'); ?></td>
          <td class="text-center">
            <span class="badge <?php echo $r['IsActive'] ? 'bg-success' : 'bg-secondary'; ?>">
              <?php echo $r['IsActive'] ? 'ใช้งาน' : 'ปิด'; ?>
            </span>
          </td>
          <td class="text-center">
            <button class="btn btn-outline-primary btn-sm py-0 px-2 me-1"
                    onclick="openModalDept(<?php echo $r['DeptID']; ?>)" title="แก้ไข">
              <i class="bi bi-pencil"></i>
            </button>
            <?php if ($r['IsActive']) { ?>
            <button class="btn btn-outline-danger btn-sm py-0 px-2"
                    onclick="orgDelete('department',<?php echo $r['DeptID']; ?>,'<?php echo htmlspecialchars(addslashes($r['DeptName'])); ?>')"
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

<?php
/* สร้าง Modal HTML ด้วย PHP string — ไม่ใช้ JS template literal
   เพื่อให้ PHP foreach ทำงานได้ปกติโดยไม่ conflict กับ backtick */
$modalHtml = '
<div class="modal fade" id="modalDept" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width:520px">
    <div class="modal-content" style="font-family:\'Prompt\',sans-serif">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff">
        <h6 class="modal-title mb-0" id="modalDeptTitle">
          <i class="bi bi-diagram-3 me-2"></i>เพิ่มแผนก
        </h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formDept" onsubmit="event.preventDefault(); window.orgSave(\'formDept\',\'department\')">
        <input type="hidden" name="action"   id="dpAction" value="create">
        <input type="hidden" name="entity"   value="department">
        <input type="hidden" name="id"       id="dpID"     value="0">
        <input type="hidden" name="DeptCode" id="dpCode"   value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label form-required">ฝ่าย</label>
              <select class="form-select" name="DivisionID" id="dpDivision" required
                      style="font-family:\'Prompt\',sans-serif">
                ' . $divisionOptions . '
              </select>
            </div>
            <div class="col-12">
              <label class="form-label form-required">ชื่อแผนก</label>
              <input type="text" class="form-control" name="DeptName" id="dpName"
                     placeholder="ชื่อแผนก" maxlength="200" required
                     style="font-family:\'Prompt\',sans-serif">
              <div class="form-text" id="dpCodeHint">
                <i class="bi bi-magic me-1 text-success"></i>
                รหัสแผนก: <strong id="dpCodeDisplay" class="text-success">สร้างอัตโนมัติ</strong>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">ลำดับแสดง</label>
              <input type="number" class="form-control" name="SortOrder" id="dpSort"
                     value="99" min="1" max="999"
                     style="font-family:\'Prompt\',sans-serif">
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
</div>';
?>

<script>
/* inject Modal เข้า body — ลบเก่าออกเสมอ */
(function() {
    var old = document.getElementById('modalDept');
    if (old) old.remove();

    var div = document.createElement('div');
    div.innerHTML = <?php echo json_encode($modalHtml); ?>;
    document.body.appendChild(div.firstElementChild);
})();
</script>

<script>
var dpData = <?php
    $map = array();
    foreach ($rows as $r) {
        $map[$r['DeptID']] = array(
            'division' => (int)$r['DivisionID'],
            'code'     => $r['DeptCode'],
            'name'     => $r['DeptName'],
            'sort'     => (int)$r['SortOrder'],
        );
    }
    echo json_encode($map);
?>;

function openModalDept(id) {
    var modal = new bootstrap.Modal(document.getElementById('modalDept'));

    /* reset ด้วยมือ — ไม่ใช้ form.reset() เพราะ reset hidden field ด้วย */
    document.getElementById('dpDivision').value = '';
    document.getElementById('dpName').value     = '';
    document.getElementById('dpSort').value     = '99';

    if (id === 0) {
        /* โหมดเพิ่มใหม่ */
        document.getElementById('modalDeptTitle').innerHTML = '<i class="bi bi-diagram-3 me-2"></i>เพิ่มแผนก';
        document.getElementById('dpAction').value = 'create';
        document.getElementById('dpID').value     = '0';
        document.getElementById('dpCode').value   = '';
        document.getElementById('dpCodeDisplay').textContent = 'สร้างอัตโนมัติ';
        document.getElementById('dpCodeDisplay').style.color = '#4CAF50';
    } else {
        /* โหมดแก้ไข */
        var d = dpData[id];
        document.getElementById('modalDeptTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขแผนก';
        document.getElementById('dpAction').value   = 'update';
        document.getElementById('dpID').value       = id;
        document.getElementById('dpDivision').value = d.division;
        document.getElementById('dpCode').value     = d.code;
        document.getElementById('dpName').value     = d.name;
        document.getElementById('dpSort').value     = d.sort;
        document.getElementById('dpCodeDisplay').textContent = d.code;
        document.getElementById('dpCodeDisplay').style.color = '#1B3A4A';
    }
    modal.show();
}
</script>
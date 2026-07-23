<?php
/* master/ajax/org_section.php — Tab หน่วยงาน (เฉพาะโรงงาน) */
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
requireRole(array(4));
$conn = getConnection();

/* แผนกจากฝ่ายโรงงานเท่านั้น */
$res  = sqlsrv_query($conn,"
    SELECT dp.DeptID, dp.DeptName, dv.DivisionName
    FROM CFP_Department dp
    JOIN CFP_Division dv ON dp.DivisionID=dv.DivisionID
    WHERE dp.IsActive=1 AND dv.DivisionType='Factory'
    ORDER BY dv.DivisionName, dp.DeptName");
$depts = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $depts[] = $r; }

$res  = sqlsrv_query($conn,"
    SELECT sc.SectionID,sc.SectionCode,sc.SectionName,sc.DeptID,sc.IsActive,
           dp.DeptName, dv.DivisionName
    FROM CFP_Section sc
    LEFT JOIN CFP_Department dp ON sc.DeptID=dp.DeptID
    LEFT JOIN CFP_Division   dv ON dp.DivisionID=dv.DivisionID
    ORDER BY dv.DivisionName, dp.DeptName, sc.SectionName");
$rows = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }
?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-3" style="font-size:0.83rem">
  <i class="bi bi-info-circle-fill"></i>
  หน่วยงานมีเฉพาะใน<strong>ฝ่ายโรงงาน</strong>เท่านั้น — dropdown แผนกจะแสดงเฉพาะแผนกที่สังกัดโรงงาน
</div>
<div class="cfp-card">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);">
      <i class="bi bi-grid-3x3-gap me-2"></i>รายการหน่วยงาน
    </div>
    <button class="btn-cfp-add btn-sm" onclick="openModalSection(0)">
      <i class="bi bi-plus-circle me-1"></i>เพิ่มหน่วยงาน
    </button>
  </div>
  <div class="table-responsive">
    <table id="tblSection" class="table table-bordered table-hover align-middle" style="width:100%">
      <thead><tr>
        <th style="width:40px">#</th>
        <th style="width:100px">รหัส</th>
        <th>ชื่อหน่วยงาน</th>
        <th>แผนก</th>
        <th>ฝ่าย</th>
        <th class="text-center" style="width:80px">สถานะ</th>
        <th class="text-center" style="width:80px">จัดการ</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $i => $r) { ?>
        <tr>
          <td><?php echo $i+1; ?></td>
          <td><code><?php echo htmlspecialchars($r['SectionCode']); ?></code></td>
          <td><?php echo htmlspecialchars($r['SectionName']); ?></td>
          <td style="font-size:0.82rem"><?php echo htmlspecialchars($r['DeptName'] ?? '—'); ?></td>
          <td style="font-size:0.82rem"><?php echo htmlspecialchars($r['DivisionName'] ?? '—'); ?></td>
          <td class="text-center">
            <span class="badge <?php echo $r['IsActive'] ? 'bg-success' : 'bg-secondary'; ?>">
              <?php echo $r['IsActive'] ? 'ใช้งาน' : 'ปิด'; ?>
            </span>
          </td>
          <td class="text-center">
            <button class="btn btn-outline-primary btn-sm py-0 px-2 me-1"
                    onclick="openModalSection(<?php echo $r['SectionID']; ?>)" title="แก้ไข">
              <i class="bi bi-pencil"></i>
            </button>
            <?php if ($r['IsActive']) { ?>
            <button class="btn btn-outline-danger btn-sm py-0 px-2"
                    onclick="orgDelete('section',<?php echo $r['SectionID']; ?>,'<?php echo htmlspecialchars(addslashes($r['SectionName'])); ?>')"
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
    if (!document.getElementById('modalSection')) {
        var div = document.createElement('div');
        div.innerHTML = `<!-- Modal หน่วยงาน -->
<div class="modal fade" id="modalSection" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width:500px">
    <div class="modal-content" style="font-family:'Prompt',sans-serif">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff">
        <h6 class="modal-title mb-0" id="modalSectionTitle">
          <i class="bi bi-grid-3x3-gap me-2"></i>เพิ่มหน่วยงาน
        </h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formSection" onsubmit="event.preventDefault(); window.orgSave('formSection','section')">
        <input type="hidden" name="action" id="scAction" value="create">
        <input type="hidden" name="entity" value="section">
        <input type="hidden" name="id"     id="scID"     value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label form-required">แผนก (เฉพาะโรงงาน)</label>
              <select class="form-select" name="DeptID" id="scDept" required
                      style="font-family:'Prompt',sans-serif">
                <option value="">— เลือกแผนก —</option>
                <?php foreach ($depts as $d) { ?>
                <option value="<?php echo $d['DeptID']; ?>">
                  [<?php echo htmlspecialchars($d['DivisionName']); ?>]
                  <?php echo htmlspecialchars($d['DeptName']); ?>
                </option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label form-required">รหัสหน่วยงาน</label>
              <input type="text" class="form-control" name="SectionCode" id="scCode"
                     placeholder="เช่น SC-01" maxlength="20" required
                     style="font-family:'Prompt',sans-serif;text-transform:uppercase">
            </div>
            <div class="col-md-8">
              <label class="form-label form-required">ชื่อหน่วยงาน</label>
              <input type="text" class="form-control" name="SectionName" id="scName"
                     placeholder="ชื่อหน่วยงาน" maxlength="200" required
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
var scData = <?php
  $map = array();
  foreach ($rows as $r) {
      $map[$r['SectionID']] = array(
          'dept' => (int)$r['DeptID'],
          'code' => $r['SectionCode'],
          'name' => $r['SectionName'],
      );
  }
  echo json_encode($map);
?>;

function openModalSection(id) {
    var modal = new bootstrap.Modal(document.getElementById('modalSection'));
    document.getElementById('formSection').reset();
    if (id === 0) {
        document.getElementById('modalSectionTitle').innerHTML = '<i class="bi bi-grid-3x3-gap me-2"></i>เพิ่มหน่วยงาน';
        document.getElementById('scAction').value = 'create';
        document.getElementById('scID').value     = '0';
    } else {
        var d = scData[id];
        document.getElementById('modalSectionTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขหน่วยงาน';
        document.getElementById('scAction').value = 'update';
        document.getElementById('scID').value     = id;
        document.getElementById('scDept').value   = d.dept;
        document.getElementById('scCode').value   = d.code;
        document.getElementById('scName').value   = d.name;
    }
    modal.show();
}

</script>

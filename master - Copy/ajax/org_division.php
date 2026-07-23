<?php
/* master/ajax/org_division.php — Tab ฝ่าย */
require_once '../../includes/auth_check.php';
require_once '../../config/db.php';
requireRole(array(4));
$conn = getConnection();

$res = sqlsrv_query($conn,"SELECT CompanyID,CompanyName FROM CFP_Company WHERE IsActive=1 ORDER BY CompanyName");
$companies = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $companies[] = $r; }

$res = sqlsrv_query($conn,"SELECT SiteID,SiteCode,SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
$sites = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $sites[] = $r; }

$res = sqlsrv_query($conn,"
    SELECT d.DivisionID,d.DivisionCode,d.DivisionName,d.DivisionType,
           d.CompanyID,d.SiteID,d.SortOrder,d.IsActive,
           c.CompanyName, s.SiteName
    FROM CFP_Division d
    LEFT JOIN CFP_Company c ON d.CompanyID=c.CompanyID
    LEFT JOIN CFP_Site    s ON d.SiteID=s.SiteID
    ORDER BY c.CompanyName, d.DivisionName");
$rows = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }

/* สร้าง option HTML ก่อน — ไม่ให้ PHP อยู่ใน JS */
$companyOptions = '<option value="">— เลือกบริษัท —</option>';
foreach ($companies as $c) {
    $companyOptions .= '<option value="' . (int)$c['CompanyID'] . '">'
                     . htmlspecialchars($c['CompanyName'])
                     . '</option>';
}

$siteOptions = '<option value="">— เลือก Site —</option>';
foreach ($sites as $s) {
    $siteOptions .= '<option value="' . (int)$s['SiteID'] . '">'
                  . '[' . htmlspecialchars($s['SiteCode']) . '] '
                  . htmlspecialchars($s['SiteName'])
                  . '</option>';
}

/* สร้าง Modal HTML ด้วย PHP string */
$modalHtml = '
<div class="modal fade" id="modalDivision" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="font-family:\'Prompt\',sans-serif">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff">
        <h6 class="modal-title mb-0" id="modalDivTitle">
          <i class="bi bi-diagram-2 me-2"></i>เพิ่มฝ่าย
        </h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formDivision" onsubmit="event.preventDefault(); window.orgSave(\'formDivision\',\'division\')">
        <input type="hidden" name="action" id="dvAction" value="create">
        <input type="hidden" name="entity" value="division">
        <input type="hidden" name="id"     id="dvID"     value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label form-required">บริษัท</label>
              <select class="form-select" name="CompanyID" id="dvCompany" required
                      style="font-family:\'Prompt\',sans-serif">
                ' . $companyOptions . '
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label form-required">ประเภทฝ่าย</label>
              <select class="form-select" name="DivisionType" id="dvType" required
                      onchange="toggleSiteField()"
                      style="font-family:\'Prompt\',sans-serif">
                <option value="">— เลือกประเภท —</option>
                <option value="HO">สำนักงานใหญ่</option>
                <option value="Factory">โรงงาน</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label form-required">รหัสฝ่าย</label>
              <input type="text" class="form-control" name="DivisionCode" id="dvCode"
                     placeholder="เช่น DIV-CBI" maxlength="20" required
                     style="font-family:\'Prompt\',sans-serif;text-transform:uppercase">
            </div>
            <div class="col-md-8">
              <label class="form-label form-required">ชื่อฝ่าย</label>
              <input type="text" class="form-control" name="DivisionName" id="dvName"
                     placeholder="ชื่อฝ่าย" maxlength="200" required
                     style="font-family:\'Prompt\',sans-serif">
            </div>
            <div class="col-md-6" id="siteFieldWrap" style="display:none">
              <label class="form-label">Site (Carbon Data)
                <span style="font-size:0.75rem;color:var(--cfp-text-muted)">เฉพาะโรงงาน</span>
              </label>
              <select class="form-select" name="SiteID" id="dvSite"
                      style="font-family:\'Prompt\',sans-serif">
                ' . $siteOptions . '
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">ลำดับแสดง</label>
              <input type="number" class="form-control" name="SortOrder" id="dvSort"
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
<div class="cfp-card">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);">
      <i class="bi bi-diagram-2 me-2"></i>รายการฝ่าย
    </div>
    <button class="btn-cfp-add btn-sm" onclick="openModalDivision(0)">
      <i class="bi bi-plus-circle me-1"></i>เพิ่มฝ่าย
    </button>
  </div>
  <div class="table-responsive">
    <table id="tblDivision" class="table table-bordered table-hover align-middle" style="width:100%">
      <thead><tr>
        <th style="width:40px">#</th>
        <th style="width:100px">รหัส</th>
        <th>ชื่อฝ่าย</th>
        <th>บริษัท</th>
        <th class="text-center" style="width:90px">ประเภท</th>
        <th>Site (โรงงาน)</th>
        <th class="text-center" style="width:80px">สถานะ</th>
        <th class="text-center" style="width:80px">จัดการ</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $i => $r) { ?>
        <tr>
          <td><?php echo $i+1; ?></td>
          <td><code><?php echo htmlspecialchars($r['DivisionCode']); ?></code></td>
          <td><?php echo htmlspecialchars($r['DivisionName']); ?></td>
          <td style="font-size:0.82rem"><?php echo htmlspecialchars($r['CompanyName'] ?? '—'); ?></td>
          <td class="text-center">
            <?php if ($r['DivisionType'] === 'Factory') { ?>
              <span class="badge" style="background:#E8F5E9;color:#2E7D32;">โรงงาน</span>
            <?php } else { ?>
              <span class="badge" style="background:#E3F2FD;color:#1565C0;">สำนักงานใหญ่</span>
            <?php } ?>
          </td>
          <td style="font-size:0.82rem"><?php echo htmlspecialchars($r['SiteName'] ?? '—'); ?></td>
          <td class="text-center">
            <span class="badge <?php echo $r['IsActive'] ? 'bg-success' : 'bg-secondary'; ?>">
              <?php echo $r['IsActive'] ? 'ใช้งาน' : 'ปิด'; ?>
            </span>
          </td>
          <td class="text-center">
            <button class="btn btn-outline-primary btn-sm py-0 px-2 me-1"
                    onclick="openModalDivision(<?php echo $r['DivisionID']; ?>)" title="แก้ไข">
              <i class="bi bi-pencil"></i>
            </button>
            <?php if ($r['IsActive']) { ?>
            <button class="btn btn-outline-danger btn-sm py-0 px-2"
                    onclick="orgDelete('division',<?php echo $r['DivisionID']; ?>,'<?php echo htmlspecialchars(addslashes($r['DivisionName'])); ?>')"
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
/* inject Modal — ลบเก่าออกเสมอ ป้องกัน Modal เก่าค้าง */
(function() {
    var old = document.getElementById('modalDivision');
    if (old) old.remove();

    var div = document.createElement('div');
    div.innerHTML = <?php echo json_encode($modalHtml); ?>;
    document.body.appendChild(div.firstElementChild);
})();
</script>

<script>
var dvData = <?php
    $map = array();
    foreach ($rows as $r) {
        $map[$r['DivisionID']] = array(
            'company' => (int)$r['CompanyID'],
            'site'    => (int)$r['SiteID'],
            'code'    => $r['DivisionCode'],
            'name'    => $r['DivisionName'],
            'type'    => $r['DivisionType'],
            'sort'    => (int)$r['SortOrder'],
        );
    }
    echo json_encode($map);
?>;

function toggleSiteField() {
    var t = document.getElementById('dvType').value;
    document.getElementById('siteFieldWrap').style.display = (t === 'Factory') ? '' : 'none';
    if (t !== 'Factory') { document.getElementById('dvSite').value = ''; }
}

function openModalDivision(id) {
    var modal = new bootstrap.Modal(document.getElementById('modalDivision'));

    /* reset ด้วยมือ — ไม่ใช้ form.reset() */
    document.getElementById('dvCompany').value = '';
    document.getElementById('dvType').value    = '';
    document.getElementById('dvCode').value    = '';
    document.getElementById('dvName').value    = '';
    document.getElementById('dvSort').value    = '99';
    document.getElementById('dvSite').value    = '';
    document.getElementById('siteFieldWrap').style.display = 'none';

    if (id === 0) {
        document.getElementById('modalDivTitle').innerHTML = '<i class="bi bi-diagram-2 me-2"></i>เพิ่มฝ่าย';
        document.getElementById('dvAction').value = 'create';
        document.getElementById('dvID').value     = '0';
    } else {
        var d = dvData[id];
        document.getElementById('modalDivTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขฝ่าย';
        document.getElementById('dvAction').value  = 'update';
        document.getElementById('dvID').value      = id;
        document.getElementById('dvCompany').value = d.company;
        document.getElementById('dvType').value    = d.type;
        document.getElementById('dvCode').value    = d.code;
        document.getElementById('dvName').value    = d.name;
        document.getElementById('dvSort').value    = d.sort;
        toggleSiteField();
        if (d.site) { document.getElementById('dvSite').value = d.site; }
    }
    modal.show();
}
</script>
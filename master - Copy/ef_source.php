<?php
/* ==============================================
   master/ef_source.php
   จัดการแหล่งอ้างอิง EF (CFP_EFSource) — Admin (4), SustainAdmin (5)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

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

/* ===== ดึงรายการแหล่งอ้างอิง EF ===== */
$res = sqlsrv_query($conn, "
    SELECT SourceID, SourceCode, SourceName, SourceVersion, YearApply,
           Organization, Remark, IsActive
    FROM CFP_EFSource
    ORDER BY YearApply DESC, SourceCode");
$rows = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }

/* ===== นับจำนวนค่า EF ที่ผูกกับแต่ละแหล่ง ===== */
$resUsage = sqlsrv_query($conn, "
    SELECT SourceID, COUNT(1) AS Cnt
    FROM CFP_EFValue
    WHERE IsActive = 1 AND SourceID IS NOT NULL
    GROUP BY SourceID");
$usageBySource = array();
while ($u = sqlsrv_fetch_array($resUsage, SQLSRV_FETCH_ASSOC)) {
    $usageBySource[(int)$u['SourceID']] = (int)$u['Cnt'];
}

$total    = count($rows);
$active   = 0;
$inactive = 0;
foreach ($rows as $r) {
    if ($r['IsActive']) { $active++; } else { $inactive++; }
}
$usedCount = count($usageBySource);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>แหล่งอ้างอิง EF — GHG Management System</title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="../assets/css/cfp-theme.css?v=<?php echo filemtime('../assets/css/cfp-theme.css'); ?>" rel="stylesheet">

  <style>
    body { font-family: 'Prompt', sans-serif; }
    .font-prompt { font-family: 'Prompt', sans-serif !important; }
    .kpi-icon-box {
      width: 44px; height: 44px;
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.2rem;
      flex-shrink: 0;
    }
    .btn-action {
      width: 30px; height: 30px;
      padding: 0;
      display: inline-flex; align-items: center; justify-content: center;
      border-radius: 6px;
      font-size: 0.8rem;
    }
    .status-dot {
      width: 8px; height: 8px;
      border-radius: 50%;
      display: inline-block;
    }
  </style>
</head>
<body>

<div class="d-flex">

  <?php include '../includes/sidebar.php'; ?>

  <div class="cfp-main">

    <?php
    $pageTitle = 'แหล่งอ้างอิง EF';
    $pageIcon  = 'journal-bookmark';
    include '../includes/topbar.php';
    ?>

    <div class="cfp-content">

      <!-- Page Header -->
      <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
          <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);">
            <i class="bi bi-journal-bookmark me-2" style="color:var(--cfp-green);"></i>จัดการแหล่งอ้างอิง EF
          </h5>
          <div style="font-size:0.78rem;color:var(--cfp-text-muted);margin-top:2px;">
            ข้อมูลอ้างอิง › แหล่งอ้างอิง EF
          </div>
        </div>
      </div>

      <!-- ===== KPI SUMMARY ===== -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#E3F2FD;">
              <i class="bi bi-journal-bookmark" style="color:#1565C0;"></i>
            </div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:var(--cfp-primary);line-height:1.1;">
                <?php echo $total; ?>
              </div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">แหล่งทั้งหมด</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#E8F5E9;">
              <i class="bi bi-check-circle-fill" style="color:#2E7D32;"></i>
            </div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:#2E7D32;line-height:1.1;">
                <?php echo $active; ?>
              </div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ใช้งานอยู่</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#F5F5F5;">
              <i class="bi bi-slash-circle" style="color:#9E9E9E;"></i>
            </div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:#9E9E9E;line-height:1.1;">
                <?php echo $inactive; ?>
              </div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ปิดใช้งาน</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#FFF3E0;">
              <i class="bi bi-link-45deg" style="color:#E65100;"></i>
            </div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:#E65100;line-height:1.1;">
                <?php echo $usedCount; ?>
              </div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ถูกใช้ใน ค่า EF</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ===== TABLE CARD ===== -->
      <div class="cfp-card">
        <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);margin-bottom:12px;">
          <i class="bi bi-journal-bookmark me-2"></i>รายการแหล่งอ้างอิง EF
        </div>

        <div class="cfp-page-toolbar mb-3">
          <div class="d-flex gap-2 flex-grow-1" style="max-width:520px;">
            <div class="cfp-search-wrap flex-grow-1" style="position:relative;">
            <input type="text" id="fltKeyword" class="form-control font-prompt" style="font-size:0.85rem;padding-right:28px;"
                   placeholder="ค้นหารหัส / ชื่อแหล่งอ้างอิง...">
            <button type="button" class="cfp-search-clear" onclick="clearKeyword()" title="ล้างคำค้นหา" style="display:none;position:absolute;right:6px;top:50%;transform:translateY(-50%);border:none;background:none;padding:2px;line-height:1;color:var(--cfp-text-muted,#888);font-size:0.95rem;cursor:pointer;z-index:2;"><i class="bi bi-x-circle-fill"></i></button>
            </div>
            <select id="fltStatus" class="form-select font-prompt" style="font-size:0.85rem;max-width:140px;">
              <option value="">สถานะทั้งหมด</option>
              <option value="1">ใช้งาน</option>
              <option value="0">ปิด</option>
            </select>
            <button class="btn btn-outline-secondary btn-sm" style="font-size:0.85rem;white-space:nowrap;" onclick="clearFilter()">
              <i class="bi bi-x-circle me-1"></i>ล้าง
            </button>
            <div class="form-check d-flex align-items-center gap-1 ms-1" style="white-space:nowrap;">
              <input type="checkbox" class="form-check-input mt-0" id="fltUsedOnly" checked style="cursor:pointer;">
              <label class="form-check-label font-prompt" for="fltUsedOnly" style="font-size:0.82rem;color:var(--cfp-text-muted);cursor:pointer;">เฉพาะที่มีค่า EF</label>
            </div>
          </div>
          <div class="cfp-page-toolbar-actions">
            <button class="btn-cfp-add" onclick="openModal(0)">
              <i class="bi bi-plus-circle"></i>เพิ่มแหล่งอ้างอิง
            </button>
          </div>
        </div>

        <div class="table-responsive">
          <table id="tblSource" class="table table-bordered table-hover align-middle" style="width:100%">
            <thead>
              <tr>
                <th class="cfp-th-expand"></th>
                <th class="cfp-th-num" style="width:40px;">#</th>
                <th style="min-width:200px;">ชื่อแหล่งอ้างอิง</th>
                <th class="cfp-col-hide" style="width:100px;">เวอร์ชัน</th>
                <th class="cfp-col-hide text-center" style="width:80px;">ปีที่ใช้</th>
                <th class="cfp-col-hide">หน่วยงาน</th>
                <th class="cfp-col-hide text-center" style="width:90px;">จำนวนค่า EF</th>
                <th class="text-center" style="width:90px;">สถานะ</th>
                <th class="cfp-col-hide text-center" style="width:90px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $i => $r) {
                $usedN = $usageBySource[(int)$r['SourceID']] ?? 0;
              ?>
              <tr data-status="<?php echo $r['IsActive'] ? '1' : '0'; ?>" data-used="<?php echo $usedN > 0 ? '1' : '0'; ?>">
                <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
                <td class="cfp-td-num"><?php echo $i + 1; ?></td>
                <td>
                  <?php echo htmlspecialchars($r['SourceName']); ?>
                  <div><code style="font-size:0.7rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($r['SourceCode']); ?></code></div>
                </td>
                <td class="cfp-col-hide" style="font-size:0.82rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($r['SourceVersion'] ?? '—'); ?></td>
                <td class="cfp-col-hide text-center" style="font-weight:600;color:var(--cfp-primary);"><?php echo (int)$r['YearApply']; ?></td>
                <td class="cfp-col-hide" style="font-size:0.82rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($r['Organization'] ?? '—'); ?></td>
                <td class="cfp-col-hide text-center">
                  <?php if ($usedN > 0) { ?>
                  <span class="badge" style="background:#EEF6F8;color:#2AABB8;font-weight:600;"><?php echo $usedN; ?></span>
                  <?php } else { ?>
                  <span style="color:#ccc;">0</span>
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
                <td class="cfp-col-hide text-center">
                  <div class="cfp-action-group">
                    <button class="btn btn-outline-primary btn-action me-1 cfp-act-primary"
                            onclick="openModal(<?php echo (int)$r['SourceID']; ?>)" title="แก้ไข">
                      <i class="bi bi-pencil-square"></i><span class="cfp-act-label">แก้ไข</span>
                    </button>
                    <div class="cfp-act-secondary">
                      <button class="btn btn-action <?php echo $r['IsActive'] ? 'btn-outline-danger' : 'btn-outline-success'; ?> cfp-act-toggle"
                              onclick="confirmToggle(<?php echo (int)$r['SourceID']; ?>, <?php echo $r['IsActive'] ? 1 : 0; ?>, '<?php echo htmlspecialchars(addslashes($r['SourceName'])); ?>')"
                              title="<?php echo $r['IsActive'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน'; ?>">
                        <i class="bi bi-<?php echo $r['IsActive'] ? 'toggle2-off' : 'toggle2-on'; ?>"></i><span class="cfp-act-label"><?php echo $r['IsActive'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน'; ?></span>
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

    </div>
  </div>
</div>

<!-- ===== Modal เพิ่ม/แก้ไข ===== -->
<div class="modal fade" id="modalSource" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content font-prompt">
      <div class="modal-header">
        <h6 class="modal-title mb-0" id="modalTitle">
          <i class="bi bi-plus-circle me-2"></i>เพิ่มแหล่งอ้างอิง EF
        </h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formSource" method="POST" action="ef_source_save.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" id="fAction" value="create">
        <input type="hidden" name="SourceID" id="fID" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-5">
              <label class="form-label form-required">รหัสแหล่งอ้างอิง</label>
              <input type="text" class="form-control font-prompt" name="SourceCode" id="fCode"
                     placeholder="เช่น TGO-CFO-2569" maxlength="50" required>
            </div>
            <div class="col-md-7">
              <label class="form-label form-required">ชื่อแหล่งอ้างอิง</label>
              <input type="text" class="form-control font-prompt" name="SourceName" id="fName"
                     placeholder="เช่น TGO Emission Factor CFO" maxlength="200" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">เวอร์ชัน</label>
              <input type="text" class="form-control font-prompt" name="SourceVersion" id="fVersion"
                     placeholder="เช่น v1.0" maxlength="30">
            </div>
            <div class="col-md-4">
              <label class="form-label form-required">ปีที่ใช้</label>
              <input type="number" class="form-control font-prompt" name="YearApply" id="fYear"
                     min="2000" max="2100" required>
            </div>
            <div class="col-md-4" id="statusWrap" style="display:none;">
              <label class="form-label">สถานะ</label>
              <select class="form-select font-prompt" name="IsActive" id="fActive">
                <option value="1">ใช้งาน</option>
                <option value="0">ปิดใช้งาน</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">หน่วยงาน</label>
              <input type="text" class="form-control font-prompt" name="Organization" id="fOrg"
                     placeholder="เช่น องค์การบริหารจัดการก๊าซเรือนกระจก (TGO)" maxlength="200">
            </div>
            <div class="col-12">
              <label class="form-label">หมายเหตุ</label>
              <textarea class="form-control font-prompt" name="Remark" id="fRemark"
                        rows="2" maxlength="300"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer" style="background:#F9FAFB;">
          <button type="button" class="btn btn-outline-secondary btn-sm w-25" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn-cfp-add btn-sm">
            <i class="bi bi-check-circle me-1"></i>บันทึก
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ===== Toast ===== -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:1080;">
  <div id="toastSuccess" class="toast align-items-center text-white border-0"
       style="background:#2E7D32;" role="alert">
    <div class="d-flex">
      <div class="toast-body d-flex align-items-center gap-2">
        <i class="bi bi-check-circle-fill"></i>
        <span id="toastMsg">บันทึกข้อมูลเรียบร้อย</span>
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
  <div id="toastError" class="toast align-items-center text-white border-0"
       style="background:#C62828;" role="alert">
    <div class="d-flex">
      <div class="toast-body d-flex align-items-center gap-2">
        <i class="bi bi-x-circle-fill"></i>
        <span id="toastErrMsg">เกิดข้อผิดพลาด</span>
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<!-- Hidden Form สำหรับ Toggle -->
<form id="formToggle" method="POST" action="ef_source_save.php" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="action" value="toggle">
  <input type="hidden" name="SourceID" id="ftID" value="0">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../assets/js/cfp-table-mobile.js"></script>

<script>
var sourceData = <?php
    $map = array();
    foreach ($rows as $r) {
        $map[$r['SourceID']] = array(
            'code'    => $r['SourceCode'],
            'name'    => $r['SourceName'],
            'version' => $r['SourceVersion'] ?? '',
            'year'    => (int)$r['YearApply'],
            'org'     => $r['Organization'] ?? '',
            'remark'  => $r['Remark'] ?? '',
            'active'  => (int)$r['IsActive'],
        );
    }
    echo json_encode($map);
?>;

var tblApi;

$(document).ready(function () {
    tblApi = $('#tblSource').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order:      [[4, 'desc']],
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        dom: '<"row align-items-center mb-2"<"col-auto"l><"col">>rtip',
        columnDefs: [{ targets: 0, orderable: false, searchable: false }],
        drawCallback: function () { cfpInitMobileExpand('tblSource'); }
    });

    $('#fltKeyword').on('keyup', function () {
        tblApi.search(this.value).draw();
    });
    $('#fltStatus, #fltUsedOnly').on('change', function () {
        tblApi.draw();
    });

    cfpBindMobileExpand('tblSource');
});

$.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
    var statusVal   = $('#fltStatus').val();
    var usedOnly    = $('#fltUsedOnly').is(':checked');
    var rowNode     = $('#tblSource').DataTable().row(dataIndex).node();
    if (statusVal && $(rowNode).attr('data-status') !== statusVal) { return false; }
    if (usedOnly && $(rowNode).attr('data-used') !== '1') { return false; }
    return true;
});

$('#fltKeyword').on('input', function () {
    $(this).closest('.cfp-search-wrap').find('.cfp-search-clear').toggle(this.value.length > 0);
});
function clearKeyword() {
    $('#fltKeyword').val('').trigger('keyup').trigger('input').focus();
}
function clearFilter() {
    $('#fltKeyword').val('');
    $('#fltStatus').val('');
    tblApi.search('').draw();
}

function openModal(id) {
    var modal = new bootstrap.Modal(document.getElementById('modalSource'));

    document.getElementById('fVersion').value = '';
    document.getElementById('fYear').value    = '';
    document.getElementById('fOrg').value     = '';
    document.getElementById('fRemark').value  = '';
    document.getElementById('fActive').value  = '1';
    document.getElementById('statusWrap').style.display = 'none';

    if (id === 0) {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>เพิ่มแหล่งอ้างอิง EF';
        document.getElementById('fAction').value = 'create';
        document.getElementById('fID').value     = '0';
        document.getElementById('fCode').value   = '';
        document.getElementById('fCode').readOnly = false;
        document.getElementById('fCode').style.background = '';
        document.getElementById('fCode').style.color = '';
        document.getElementById('fName').value   = '';
    } else {
        var d = sourceData[id];
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขแหล่งอ้างอิง EF';
        document.getElementById('fAction').value = 'update';
        document.getElementById('fID').value     = id;
        document.getElementById('fCode').value   = d.code;
        document.getElementById('fCode').readOnly = true;
        document.getElementById('fCode').style.background = '#F0F0F0';
        document.getElementById('fCode').style.color = 'var(--cfp-text-muted)';
        document.getElementById('fName').value    = d.name;
        document.getElementById('fVersion').value = d.version;
        document.getElementById('fYear').value    = d.year;
        document.getElementById('fOrg').value     = d.org;
        document.getElementById('fRemark').value  = d.remark;
        document.getElementById('fActive').value  = d.active;
        document.getElementById('statusWrap').style.display = '';
    }
    modal.show();
}

function confirmToggle(id, currentActive, name) {
    var action = currentActive ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    var icon   = currentActive ? 'warning' : 'question';
    var color  = currentActive ? '#DC3545' : '#4CAF50';
    Swal.fire({
        title: action + 'แหล่งอ้างอิง?',
        html: 'แหล่งอ้างอิง: <b>' + name + '</b>',
        icon: icon,
        showCancelButton: true,
        confirmButtonColor: color,
        cancelButtonColor: '#6C757D',
        confirmButtonText: action,
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true,
        customClass: { popup: 'font-prompt' }
    }).then(function (result) {
        if (result.isConfirmed) {
            document.getElementById('ftID').value = id;
            document.getElementById('formToggle').submit();
        }
    });
}

function showToast(msg, isError) {
    var id  = isError ? 'toastError' : 'toastSuccess';
    var mid = isError ? 'toastErrMsg' : 'toastMsg';
    document.getElementById(mid).textContent = msg;
    var toast = new bootstrap.Toast(document.getElementById(id), { delay: 3000 });
    toast.show();
}

<?php if ($toastMsg) { ?>
showToast(
    '<?php echo htmlspecialchars(addslashes($toastMsg)); ?>',
    <?php echo ($toastType === 'error') ? 'true' : 'false'; ?>
);
<?php } ?>
</script>

</body>
</html>

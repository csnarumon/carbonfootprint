<?php
/* ==============================================
   master/lookup_watersource.php
   แหล่งน้ำ — CFP_WaterSource
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(4, 5));

$conn      = getConnection();
$pageTitle = 'แหล่งน้ำ';
$pageIcon  = 'water';
$tableName = 'CFP_WaterSource';
$importType = 'WaterSource';

/* ========== AJAX: Save (Insert/Update) ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    header('Content-Type: application/json');

    $typeID      = isset($_POST['SourceID']) ? (int)$_POST['SourceID'] : 0;
    $typeCode    = trim($_POST['TypeCode']);
    $typeName    = trim($_POST['TypeName']);
    $description = trim($_POST['Description']);
    $sortOrder   = (int)$_POST['SortOrder'];
    $isActive    = isset($_POST['IsActive']) ? 1 : 0;
    $userID      = (int)$_SESSION['user_id'];

    if ($typeCode === '' || $typeName === '') {
        echo json_encode(array('success' => false, 'message' => 'กรุณากรอกรหัสและชื่อให้ครบถ้วน'));
        exit;
    }

    if ($typeID > 0) {
        /* UPDATE */
        $sql = "UPDATE $tableName SET
                    TypeCode = ?, TypeName = ?, Description = ?,
                    SortOrder = ?, IsActive = ?,
                    UpdatedBy = ?, UpdatedDate = GETDATE()
                WHERE SourceID = ?";
        $params = array($typeCode, $typeName, $description, $sortOrder, $isActive, $userID, $typeID);
        $oldAction = 'UPDATE_LOOKUP';
    } else {
        /* INSERT */
        $sql = "INSERT INTO $tableName (TypeCode, TypeName, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
                VALUES (?, ?, ?, ?, ?, ?, GETDATE())";
        $params = array($typeCode, $typeName, $description, $sortOrder, $isActive, $userID);
        $oldAction = 'INSERT_LOOKUP';
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        $msg = 'เกิดข้อผิดพลาด';
        if (strpos($errors[0]['message'], 'UQ_') !== false) {
            $msg = 'รหัสนี้มีอยู่แล้วในระบบ';
        }
        echo json_encode(array('success' => false, 'message' => $msg));
        exit;
    }

    logAction($conn, $userID, $oldAction, $tableName, $typeID, null, null, "บันทึก{$pageTitle}: $typeName");
    echo json_encode(array('success' => true, 'message' => 'บันทึกข้อมูลสำเร็จ'));
    exit;
}

/* ========== AJAX: Delete ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    header('Content-Type: application/json');

    $typeID = (int)$_POST['SourceID'];
    $userID = (int)$_SESSION['user_id'];

    /* ตรวจสอบว่ามีการใช้งานอ้างอิงอยู่หรือไม่ ก่อนลบจริง — soft delete แทน */
    $sql  = "UPDATE $tableName SET IsActive = 0, UpdatedBy = ?, UpdatedDate = GETDATE() WHERE SourceID = ?";
    $stmt = sqlsrv_query($conn, $sql, array($userID, $typeID));

    if ($stmt === false) {
        echo json_encode(array('success' => false, 'message' => 'ไม่สามารถลบข้อมูลได้'));
        exit;
    }

    logAction($conn, $userID, 'DELETE_LOOKUP', $tableName, $typeID, null, null, "ปิดใช้งาน{$pageTitle} ID: $typeID");
    echo json_encode(array('success' => true, 'message' => 'ลบข้อมูลสำเร็จ'));
    exit;
}

/* ========== AJAX: Import Excel ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import') {
    header('Content-Type: application/json');
    /* TODO: ใช้ PhpSpreadsheet อ่านไฟล์ที่ $_FILES['importFile']
       วน insert ทีละแถวลง CFP_WaterSource
       บันทึกผลลง CFP_ImportLog (ImportType = 'WaterSource')
       รูปแบบคอลัมน์ Excel ที่คาดหวัง: TypeCode | TypeName | Description | SortOrder
    */
    echo json_encode(array('success' => false, 'message' => 'ฟังก์ชัน Import อยู่ระหว่างพัฒนา'));
    exit;
}

/* ========== ดึงข้อมูลแสดงในตาราง ========== */
$sql  = "SELECT SourceID, TypeCode, TypeName, Description, SortOrder, IsActive
         FROM $tableName ORDER BY SortOrder ASC, SourceID ASC";
$stmt = sqlsrv_query($conn, $sql);
$rows = array();
if ($stmt !== false) {
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $pageTitle; ?> — ระบบบริหารจัดการคาร์บอนองค์กร</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="/carbonfootprint/assets/css/cfp-theme.css?v=<?php echo filemtime('../assets/css/cfp-theme.css'); ?>" rel="stylesheet">
</head>
<body>
<div class="d-flex">
  <?php include '../includes/sidebar.php'; ?>
  <div class="cfp-main">
    <?php include '../includes/topbar.php'; ?>

    <div class="cfp-content" style="padding:20px;">

      <div class="cfp-page-toolbar">
        <div class="cfp-page-toolbar-title">
          <div class="title-icon"><i class="bi bi-<?php echo $pageIcon; ?>"></i></div>
          <?php echo $pageTitle; ?>
        </div>
        <div class="cfp-page-toolbar-actions">
          <button class="btn-cfp-import" onclick="openImportModal()">
            <i class="bi bi-file-earmark-spreadsheet"></i> Import Excel
          </button>
          <button class="btn-cfp-add" onclick="openFormModal()">
            <i class="bi bi-plus-circle"></i> เพิ่ม<?php echo $pageTitle; ?>
          </button>
        </div>
      </div>

      <div class="cfp-card">
        <div class="cfp-table-wrap">
          <table id="tblLookup" class="table table-hover" style="width:100%;">
            <thead>
              <tr>
                <th class="cfp-th-expand"></th>
                <th class="cfp-th-num" style="width:50px;">#</th>
                <th>ชื่อ</th>
                <th class="cfp-col-hide">คำอธิบาย</th>
                <th class="cfp-col-hide" style="width:80px;">ลำดับ</th>
                <th style="width:100px;">สถานะ</th>
                <th class="cfp-col-hide" style="width:100px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php $i = 1; foreach ($rows as $row) { ?>
              <tr>
                <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
                <td class="cfp-td-num"><?php echo $i++; ?></td>
                <td>
                  <?php echo htmlspecialchars($row['TypeName']); ?>
                  <div><code style="font-size:0.7rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($row['TypeCode']); ?></code></div>
                </td>
                <td class="cfp-col-hide"><?php echo htmlspecialchars($row['Description'] ?? ''); ?></td>
                <td class="cfp-col-hide"><?php echo (int)$row['SortOrder']; ?></td>
                <td>
                  <?php if ($row['IsActive']) { ?>
                    <span class="badge-cfp-active">ใช้งาน</span>
                  <?php } else { ?>
                    <span class="badge-cfp-inactive">ปิดใช้งาน</span>
                  <?php } ?>
                </td>
                <td class="cfp-col-hide">
                  <div class="cfp-action-group">
                    <button class="btn-cfp-icon btn-cfp-icon-edit cfp-act-primary"
                            onclick='editRow(<?php echo json_encode($row); ?>)'
                            title="แก้ไข">
                      <i class="bi bi-pencil-fill"></i><span class="cfp-act-label">แก้ไข</span>
                    </button>
                    <div class="cfp-act-secondary">
                      <button class="btn-cfp-icon btn-cfp-icon-del cfp-act-del"
                              onclick="deleteRow(<?php echo (int)$row['SourceID']; ?>, '<?php echo htmlspecialchars($row['TypeName'], ENT_QUOTES); ?>')"
                              title="ลบ">
                        <i class="bi bi-trash3-fill"></i><span class="cfp-act-label">ลบ</span>
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

<!-- ===== Modal: Add/Edit Form ===== -->
<div class="modal fade modal-cfp" id="modalForm" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title" id="modalFormTitle"><i class="bi bi-plus-circle"></i> เพิ่ม<?php echo $pageTitle; ?></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="formLookup">
        <div class="modal-body">
          <input type="hidden" id="SourceID" name="SourceID" value="0">

          <div class="mb-3">
            <label class="form-label">รหัส <span style="color:var(--cfp-danger);">*</span></label>
            <input type="text" class="form-control" id="TypeCode" name="TypeCode" maxlength="20" required>
          </div>

          <div class="mb-3">
            <label class="form-label">ชื่อ <span style="color:var(--cfp-danger);">*</span></label>
            <input type="text" class="form-control" id="TypeName" name="TypeName" maxlength="200" required>
          </div>

          <div class="mb-3">
            <label class="form-label">คำอธิบาย</label>
            <textarea class="form-control" id="Description" name="Description" rows="2" maxlength="500"></textarea>
          </div>

          

          <div class="row">
            <div class="col-6">
              <label class="form-label">ลำดับการแสดง</label>
              <input type="number" class="form-control" id="SortOrder" name="SortOrder" value="0">
            </div>
            <div class="col-6 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="IsActive" name="IsActive" checked>
                <label class="form-check-label" for="IsActive">ใช้งาน</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn-cfp-add"><i class="bi bi-check-circle"></i> บันทึก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ===== Modal: Import Excel ===== -->
<div class="modal fade modal-cfp" id="modalImport" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title"><i class="bi bi-file-earmark-spreadsheet"></i> Import <?php echo $pageTitle; ?> จาก Excel</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <div class="mb-3">
          <span class="cfp-import-template-link" onclick="downloadTemplate()">
            <i class="bi bi-download"></i> ดาวน์โหลดไฟล์ตัวอย่าง (Template)
          </span>
        </div>

        <div class="cfp-import-dropzone" id="dropZone" onclick="document.getElementById('importFile').click()">
          <i class="bi bi-cloud-arrow-up-fill"></i>
          <p class="mb-1 mt-2" style="font-size:0.85rem;">คลิกหรือลากไฟล์ Excel มาวางที่นี่</p>
          <p style="font-size:0.72rem;color:var(--cfp-text-muted);">รองรับ .xlsx, .xls — คอลัมน์: รหัส, ชื่อ, คำอธิบาย, ลำดับ</p>
          <input type="file" id="importFile" accept=".xlsx,.xls" style="display:none;" onchange="handleFileSelect(this)">
        </div>

        <div id="importFileName" style="margin-top:10px;font-size:0.82rem;color:var(--cfp-primary);"></div>

        <div id="importSummary" class="cfp-import-summary" style="display:none;">
          <div class="cfp-import-stat success">
            <div class="num" id="sumSuccess">0</div>
            <div class="lbl">สำเร็จ</div>
          </div>
          <div class="cfp-import-stat fail">
            <div class="num" id="sumFail">0</div>
            <div class="lbl">ผิดพลาด</div>
          </div>
          <div class="cfp-import-stat skip">
            <div class="num" id="sumSkip">0</div>
            <div class="lbl">ข้าม (ซ้ำ)</div>
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">ปิด</button>
        <button type="button" class="btn-cfp-add" id="btnDoImport" onclick="doImport()" disabled>
          <i class="bi bi-upload"></i> นำเข้าข้อมูล
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="../assets/js/cfp-table-mobile.js"></script>
<script>
var modalForm   = new bootstrap.Modal(document.getElementById('modalForm'));
var modalImport = new bootstrap.Modal(document.getElementById('modalImport'));

$(document).ready(function () {
    $('#tblLookup').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order:      [[4, 'asc']],
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        dom: '<"row align-items-center mb-2"<"col-auto"l><"col"f>>rtip',
        columnDefs: [{ targets: 0, orderable: false, searchable: false }, { orderable: false, targets: 6 }],
        drawCallback: function () { cfpInitMobileExpand('tblLookup'); }
    });

    cfpBindMobileExpand('tblLookup');
});

/* ===== Add/Edit Modal ===== */
function openFormModal() {
    document.getElementById('formLookup').reset();
    document.getElementById('SourceID').value = 0;
    document.getElementById('IsActive').checked = true;
    document.getElementById('modalFormTitle').innerHTML = '<i class="bi bi-plus-circle"></i> เพิ่ม<?php echo $pageTitle; ?>';
    modalForm.show();
}

function editRow(row) {
    document.getElementById('SourceID').value      = row.SourceID;
    document.getElementById('TypeCode').value     = row.TypeCode;
    document.getElementById('TypeName').value     = row.TypeName;
    document.getElementById('Description').value  = row.Description || '';
    document.getElementById('SortOrder').value    = row.SortOrder;
    document.getElementById('IsActive').checked   = (row.IsActive == 1);
    document.getElementById('modalFormTitle').innerHTML = '<i class="bi bi-pencil"></i> แก้ไข<?php echo $pageTitle; ?>';
    modalForm.show();
}

document.getElementById('formLookup').addEventListener('submit', function (e) {
    e.preventDefault();
    var formData = new FormData(this);
    formData.append('action', 'save');

    fetch('lookup_watersource.php', { method: 'POST', body: formData })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.success) {
                modalForm.hide();
                Swal.fire({ icon: 'success', title: data.message, timer: 1500, showConfirmButton: false, customClass: { popup: 'font-prompt' } })
                    .then(function () { location.reload(); });
            } else {
                Swal.fire({ icon: 'error', title: data.message, customClass: { popup: 'font-prompt' } });
            }
        });
});

/* ===== Delete ===== */
function deleteRow(typeID, typeName) {
    Swal.fire({
        title: 'ยืนยันการลบ?',
        html: 'ต้องการลบ <strong>' + typeName + '</strong> หรือไม่',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#E05050',
        cancelButtonColor: '#7AAAB8',
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true,
        customClass: { popup: 'font-prompt' }
    }).then(function (result) {
        if (result.isConfirmed) {
            var formData = new FormData();
            formData.append('action', 'delete');
            formData.append('SourceID', typeID);

            fetch('lookup_watersource.php', { method: 'POST', body: formData })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        Swal.fire({ icon: 'success', title: data.message, timer: 1500, showConfirmButton: false, customClass: { popup: 'font-prompt' } })
                            .then(function () { location.reload(); });
                    } else {
                        Swal.fire({ icon: 'error', title: data.message, customClass: { popup: 'font-prompt' } });
                    }
                });
        }
    });
}

/* ===== Import Excel ===== */
var selectedFile = null;

function openImportModal() {
    selectedFile = null;
    document.getElementById('importFile').value = '';
    document.getElementById('importFileName').innerHTML = '';
    document.getElementById('importSummary').style.display = 'none';
    document.getElementById('btnDoImport').disabled = true;
    modalImport.show();
}

function handleFileSelect(input) {
    if (input.files && input.files[0]) {
        selectedFile = input.files[0];
        document.getElementById('importFileName').innerHTML =
            '<i class="bi bi-file-earmark-check"></i> ' + selectedFile.name;
        document.getElementById('btnDoImport').disabled = false;
    }
}

function downloadTemplate() {
    /* TODO: ลิงก์ไปยังไฟล์ template จริง */
    window.location.href = '/carbonfootprint/templates/template_watersource.xlsx';
}

function doImport() {
    if (!selectedFile) { return; }

    var formData = new FormData();
    formData.append('action', 'import');
    formData.append('importFile', selectedFile);

    document.getElementById('btnDoImport').disabled = true;
    document.getElementById('btnDoImport').innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังนำเข้า...';

    fetch('lookup_watersource.php', { method: 'POST', body: formData })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            document.getElementById('btnDoImport').innerHTML = '<i class="bi bi-upload"></i> นำเข้าข้อมูล';
            if (data.success) {
                document.getElementById('importSummary').style.display = 'grid';
                document.getElementById('sumSuccess').textContent = data.successRows || 0;
                document.getElementById('sumFail').textContent    = data.failRows || 0;
                document.getElementById('sumSkip').textContent    = data.skipRows || 0;
                setTimeout(function () { location.reload(); }, 2000);
            } else {
                Swal.fire({ icon: 'error', title: data.message, customClass: { popup: 'font-prompt' } });
                document.getElementById('btnDoImport').disabled = false;
            }
        });
}

/* Drag & Drop */
var dropZone = document.getElementById('dropZone');
dropZone.addEventListener('dragover', function (e) { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', function () { dropZone.classList.remove('dragover'); });
dropZone.addEventListener('drop', function (e) {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    if (e.dataTransfer.files.length > 0) {
        document.getElementById('importFile').files = e.dataTransfer.files;
        handleFileSelect(document.getElementById('importFile'));
    }
});
</script>
</body>
</html>

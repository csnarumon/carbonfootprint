<?php
/* ==============================================
   master/unit.php
   จัดการหน่วยวัด (Unit) — Admin (4) เท่านั้น
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(4));

$conn = getConnection();

/* ===== Toast จาก redirect ===== */
$toastMsg  = '';
$toastType = '';
if (!empty($_SESSION['toast'])) {
    $toastMsg  = $_SESSION['toast']['msg'];
    $toastType = $_SESSION['toast']['type'];
    unset($_SESSION['toast']);
}

/* ===== ผลการ Import (ถ้ามี) ===== */
$importResult = null;
if (!empty($_SESSION['import_result'])) {
    $importResult = $_SESSION['import_result'];
    unset($_SESSION['import_result']);
}

/* ===== ดึงรายการหน่วยวัด (JOIN ประเภทหน่วย) ===== */
$res = sqlsrv_query($conn, "
    SELECT u.UnitID, u.UnitCode, u.UnitName, u.UnitTypeID, ut.TypeName, ut.TypeCode,
           u.Description, u.SortOrder, u.IsActive
    FROM CFP_Unit u
    LEFT JOIN CFP_UnitType ut ON ut.UnitTypeID = u.UnitTypeID
    ORDER BY u.SortOrder, u.UnitName");
$rows = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $rows[] = $r;
}

/* ===== ดึงรายการประเภทหน่วยที่ใช้งานอยู่ สำหรับ Dropdown Filter/Modal ===== */
$resTypes = sqlsrv_query($conn, "
    SELECT UnitTypeID, TypeCode, TypeName
    FROM CFP_UnitType
    WHERE IsActive = 1
    ORDER BY SortOrder, TypeName");
$unitTypes = array();
while ($t = sqlsrv_fetch_array($resTypes, SQLSRV_FETCH_ASSOC)) {
    $unitTypes[] = $t;
}

$total    = count($rows);
$active   = 0;
$inactive = 0;
foreach ($rows as $r) {
    if ($r['IsActive']) { $active++; } else { $inactive++; }
}

/* นับหน่วยวัดที่ถูกใช้งานจริงใน CFP_ActivityData หรือ CFP_ActivityItem */
$resUsed = @sqlsrv_query($conn, "
    SELECT COUNT(DISTINCT UnitID) AS Cnt
    FROM CFP_ActivityItem
    WHERE UnitID IS NOT NULL AND IsActive=1");
$usedCount = 0;
if ($resUsed !== false) {
    $rowUsed = sqlsrv_fetch_array($resUsed, SQLSRV_FETCH_ASSOC);
    $usedCount = $rowUsed ? (int)$rowUsed['Cnt'] : 0;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>หน่วยวัด — ระบบบริหารจัดการคาร์บอนองค์กร</title>

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
    $pageTitle = 'หน่วยวัด';
    $pageIcon  = 'rulers';
    include '../includes/topbar.php';
    ?>

    <div class="cfp-content">

      <!-- Page Header -->
      <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
          <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);">
            <i class="bi bi-rulers me-2" style="color:var(--cfp-green);"></i>จัดการหน่วยวัด
          </h5>
          <div style="font-size:0.78rem;color:var(--cfp-text-muted);margin-top:2px;">
            ข้อมูลอ้างอิง › หน่วยวัด
          </div>
        </div>
      </div>

      <!-- ===== KPI SUMMARY ===== -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#E3F2FD;">
              <i class="bi bi-rulers" style="color:#1565C0;"></i>
            </div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:var(--cfp-primary);line-height:1.1;">
                <?php echo $total; ?>
              </div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">หน่วยทั้งหมด</div>
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
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ถูกใช้ใน Activity</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ===== TABLE CARD ===== -->
      <div class="cfp-card">
        <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);margin-bottom:12px;">
          <i class="bi bi-rulers me-2"></i>รายการหน่วยวัด
        </div>

        <div class="cfp-page-toolbar mb-3">
          <div class="d-flex gap-2 flex-grow-1" style="max-width:580px;">
            <div class="cfp-search-wrap flex-grow-1" style="position:relative;">
            <input type="text" id="fltKeyword" class="form-control font-prompt" style="font-size:0.85rem;padding-right:28px;"
                   placeholder="ค้นหารหัส / ชื่อหน่วย / ประเภท...">
            <button type="button" class="cfp-search-clear" onclick="clearKeyword()" title="ล้างคำค้นหา" style="display:none;position:absolute;right:6px;top:50%;transform:translateY(-50%);border:none;background:none;padding:2px;line-height:1;color:var(--cfp-text-muted,#888);font-size:0.95rem;cursor:pointer;z-index:2;"><i class="bi bi-x-circle-fill"></i></button>
            </div>
            <select id="fltType" class="form-select font-prompt" style="font-size:0.85rem;max-width:160px;">
              <option value="">ประเภททั้งหมด</option>
              <?php foreach ($unitTypes as $t) { ?>
              <option value="<?php echo (int)$t['UnitTypeID']; ?>"><?php echo htmlspecialchars($t['TypeName']); ?></option>
              <?php } ?>
            </select>
            <select id="fltStatus" class="form-select font-prompt" style="font-size:0.85rem;max-width:140px;">
              <option value="">สถานะทั้งหมด</option>
              <option value="1">ใช้งาน</option>
              <option value="0">ปิด</option>
            </select>
            <button class="btn btn-outline-secondary btn-sm" style="font-size:0.85rem;white-space:nowrap;" onclick="clearFilter()">
              <i class="bi bi-x-circle me-1"></i>ล้าง
            </button>
          </div>
          <div class="cfp-page-toolbar-actions">
            <button class="btn-cfp-import" onclick="openImportModal()">
              <i class="bi bi-file-earmark-spreadsheet"></i>Import Excel
            </button>
            <button class="btn-cfp-add" onclick="openModal(0)">
              <i class="bi bi-plus-circle"></i>เพิ่มหน่วยวัด
            </button>
          </div>
        </div>

        <div class="table-responsive">
          <table id="tblUnit" class="table table-bordered table-hover align-middle" style="width:100%">
            <thead>
              <tr>
                <th class="cfp-th-expand"></th>
                <th class="cfp-th-num" style="width:40px;">#</th>
                <th>ชื่อหน่วย</th>
                <th class="cfp-col-hide" style="width:130px;">ประเภทหน่วย</th>
                <th class="cfp-col-hide">คำอธิบาย</th>
                <th class="cfp-col-hide text-center" style="width:70px;">ลำดับ</th>
                <th class="text-center" style="width:90px;">สถานะ</th>
                <th class="cfp-col-hide text-center" style="width:90px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $i => $r) { ?>
              <tr data-status="<?php echo $r['IsActive'] ? '1' : '0'; ?>"
                  data-type="<?php echo (int)($r['UnitTypeID'] ?? 0); ?>">
                <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
                <td class="cfp-td-num"><?php echo $i + 1; ?></td>
                <td>
                  <?php echo htmlspecialchars($r['UnitName']); ?>
                  <div><code style="font-size:0.7rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($r['UnitCode']); ?></code></div>
                </td>
                <td class="cfp-col-hide">
                  <?php if (!empty($r['TypeName'])) { ?>
                    <?php
                    /* Palette สีคงที่ — วนสีตาม UnitTypeID เพื่อให้แต่ละประเภทได้สีต่างกัน
                       และยังคงสีเดิมเสมอแม้เพิ่ม/ลบประเภทอื่นภายหลัง */
                    $badgePalette = array('#1565C0', '#00838F', '#E65100', '#4527A0', '#2E7D32', '#AD1457', '#546E7A', '#F9A825');
                    $badgeColor   = $badgePalette[((int)$r['UnitTypeID']) % count($badgePalette)];
                    ?>
                    <span class="badge" style="background:<?php echo $badgeColor; ?>;font-size:0.72rem;"><?php echo htmlspecialchars($r['TypeName']); ?></span>
                  <?php } else { ?>
                    —
                  <?php } ?>
                </td>
                <td class="cfp-col-hide" style="font-size:0.82rem;color:var(--cfp-text-muted);">
                  <?php echo htmlspecialchars($r['Description'] ?? '—'); ?>
                </td>
                <td class="cfp-col-hide text-center"><?php echo (int)$r['SortOrder']; ?></td>
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
                            onclick="openModal(<?php echo (int)$r['UnitID']; ?>)" title="แก้ไข">
                      <i class="bi bi-pencil-square"></i><span class="cfp-act-label">แก้ไข</span>
                    </button>
                    <div class="cfp-act-secondary">
                      <button class="btn btn-action <?php echo $r['IsActive'] ? 'btn-outline-danger' : 'btn-outline-success'; ?> me-1 cfp-act-toggle"
                              onclick="confirmToggle(<?php echo (int)$r['UnitID']; ?>, <?php echo $r['IsActive'] ? 1 : 0; ?>, '<?php echo htmlspecialchars(addslashes($r['UnitName'])); ?>')"
                              title="<?php echo $r['IsActive'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน'; ?>">
                        <i class="bi bi-<?php echo $r['IsActive'] ? 'toggle2-off' : 'toggle2-on'; ?>"></i><span class="cfp-act-label"><?php echo $r['IsActive'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน'; ?></span>
                      </button>
                      <button class="btn btn-outline-warning btn-action cfp-act-del"
                              onclick="confirmDelete(<?php echo (int)$r['UnitID']; ?>, '<?php echo htmlspecialchars(addslashes($r['UnitName'])); ?>')"
                              title="ลบ">
                        <i class="bi bi-trash"></i><span class="cfp-act-label">ลบ</span>
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
<div class="modal fade" id="modalUnit" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content font-prompt">
      <div class="modal-header">
        <h6 class="modal-title mb-0" id="modalTitle">
          <i class="bi bi-plus-circle me-2"></i>เพิ่มหน่วยวัด
        </h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formUnit" method="POST" action="unit_save.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" id="fAction" value="create">
        <input type="hidden" name="UnitID" id="fID" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label form-required">รหัสหน่วย</label>
              <input type="text" class="form-control font-prompt" name="UnitCode" id="fCodeDisplay"
                     placeholder="เช่น UN-1001" maxlength="30" required>
            </div>
            <div class="col-md-8">
              <label class="form-label form-required">ชื่อหน่วย</label>
              <input type="text" class="form-control font-prompt" name="UnitName" id="fName"
                     placeholder="เช่น กิโลกรัม, ลิตร, kWh" maxlength="100" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">ประเภทหน่วย</label>
              <select class="form-select font-prompt" name="UnitTypeID" id="fType">
                <option value="">— ไม่ระบุ —</option>
                <?php foreach ($unitTypes as $t) { ?>
                <option value="<?php echo (int)$t['UnitTypeID']; ?>"><?php echo htmlspecialchars($t['TypeName']); ?></option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">ลำดับแสดง</label>
              <input type="number" class="form-control font-prompt" name="SortOrder" id="fSort"
                     value="99" min="1" max="999">
            </div>
            <div class="col-12">
              <label class="form-label">คำอธิบาย</label>
              <textarea class="form-control font-prompt" name="Description" id="fDesc"
                        rows="2" maxlength="300"></textarea>
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
          <button type="button" class="btn btn-outline-secondary btn-sm w-25" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn-cfp-add btn-sm">
            <i class="bi bi-check-circle me-1"></i>บันทึก
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ===== Modal: Import Excel ===== -->
<div class="modal fade modal-cfp" id="modalImport" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content font-prompt">
      <div class="modal-header">
        <h6 class="modal-title"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Import หน่วยวัด จาก Excel</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="formImport" method="POST" action="unit_import.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div class="modal-body">
          <div class="mb-3 d-flex justify-content-between align-items-center">
            <label class="form-label mb-0">เลือกไฟล์ Excel <span style="color:var(--cfp-danger);">*</span></label>
            <a href="unit_template.php" class="cfp-import-template-link">
              <i class="bi bi-download"></i> ดาวน์โหลดไฟล์ตัวอย่าง (Template)
            </a>
          </div>
          <div class="cfp-import-dropzone" id="dropZone" onclick="document.getElementById('importFile').click()">
            <i class="bi bi-cloud-arrow-up-fill"></i>
            <p class="mb-1 mt-2" style="font-size:0.85rem;">คลิกหรือลากไฟล์ Excel มาวางที่นี่</p>
            <p style="font-size:0.72rem;color:var(--cfp-text-muted);">คอลัมน์: รหัสหน่วย*, ชื่อหน่วย*, ประเภท (TypeCode), คำอธิบาย, ลำดับ</p>
            <input type="file" id="importFile" name="import_file" accept=".xlsx" style="display:none;" onchange="handleFileSelect(this)">
          </div>
          <div id="importFileName" style="margin-top:10px;font-size:0.82rem;color:var(--cfp-primary);"></div>
          <div class="mt-2" style="font-size:0.78rem;color:var(--cfp-text-muted);">
            <strong style="color:var(--cfp-primary);">กฎการนำเข้า:</strong>
            <ul class="mb-0 ps-3">
                <li>รองรับไฟล์ .xlsx เท่านั้น ขนาดไม่เกิน 5 MB</li>
              <li>ต้องกรอกรหัสในไฟล์ (ห้ามเว้นว่าง) ระบบจะไม่สร้างรหัสให้อัตโนมัติ 
                <!-- Badge สีเหลือง -->
                <span class="badge badge-sm" style="background-color: #c0aa00ff; color: #fff; ">
                  Manual 
                </span></li>
              <li>ไม่กรอกรหัส หรือไม่กรอกชื่อ → ข้ามแถวนั้น</li>
              <li>รหัสหรือชื่อซ้ำกับข้อมูลเดิม หรือซ้ำกันเองในไฟล์ → ข้ามแถวนั้น</li>
              <li>รหัสที่ใช้งานได้: KG, KM, HR, DAY, etc.</li>
            </ul>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
          <button type="submit" class="btn-cfp-add">
            <i class="bi bi-upload me-1"></i>นำเข้าข้อมูล
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

<!-- Hidden Form สำหรับลบแบบ POST -->
<form id="formDelete" method="POST" action="unit_save.php" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="UnitID" id="fdID" value="0">
</form>

<!-- Hidden Form สำหรับ Toggle -->
<form id="formToggle" method="POST" action="unit_save.php" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="action" value="toggle">
  <input type="hidden" name="UnitID" id="ftID" value="0">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../assets/js/cfp-table-mobile.js"></script>

<script>
var unitData = <?php
    $map = array();
    foreach ($rows as $r) {
        $map[$r['UnitID']] = array(
            'code'   => $r['UnitCode'],
            'name'   => $r['UnitName'],
            'type'   => ($r['UnitTypeID'] !== null) ? (int)$r['UnitTypeID'] : '',
            'desc'   => $r['Description'] ?? '',
            'sort'   => (int)$r['SortOrder'],
            'active' => (int)$r['IsActive'],
        );
    }
    echo json_encode($map);
?>;

/* Custom filter: ประเภทหน่วย + สถานะ */
$.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
    var typeVal   = $('#fltType').val();
    var statusVal = $('#fltStatus').val();
    var rowNode   = $('#tblUnit').DataTable().row(dataIndex).node();
    if (typeVal   && $(rowNode).attr('data-type')   !== typeVal)   { return false; }
    if (statusVal && $(rowNode).attr('data-status') !== statusVal) { return false; }
    return true;
});

var tblApi;

$(document).ready(function () {
    tblApi = $('#tblUnit').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order:      [[6, 'asc'], [3, 'asc']],
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        dom: '<"row align-items-center mb-2"<"col-auto"l><"col">>rtip',
        columnDefs: [{ targets: 0, orderable: false, searchable: false }],
        drawCallback: function () { cfpInitMobileExpand('tblUnit'); }
    });

    $('#fltKeyword').on('keyup', function () {
        tblApi.search(this.value).draw();
    });
    $('#fltType, #fltStatus').on('change', function () {
        tblApi.draw();
    });

    cfpBindMobileExpand('tblUnit');
});

$('#fltKeyword').on('input', function () {
    $(this).closest('.cfp-search-wrap').find('.cfp-search-clear').toggle(this.value.length > 0);
});
function clearKeyword() {
    $('#fltKeyword').val('').trigger('keyup').trigger('input').focus();
}
function clearFilter() {
    $('#fltKeyword').val('');
    $('#fltType').val('');
    $('#fltStatus').val('');
    tblApi.search('').draw();
}

function openModal(id) {
    var modal = new bootstrap.Modal(document.getElementById('modalUnit'));

    document.getElementById('fName').value   = '';
    document.getElementById('fType').value   = '';
    document.getElementById('fDesc').value   = '';
    document.getElementById('fSort').value   = '99';
    document.getElementById('fActive').value = '1';
    document.getElementById('statusWrap').style.display = 'none';

    if (id === 0) {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>เพิ่มหน่วยวัด';
        document.getElementById('fAction').value = 'create';
        document.getElementById('fID').value     = '0';
        document.getElementById('fCodeDisplay').value = '';
        document.getElementById('fCodeDisplay').readOnly = false;
        document.getElementById('fCodeDisplay').style.background = '';
        document.getElementById('fCodeDisplay').style.color = '';
    } else {
        var d = unitData[id];
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขหน่วยวัด';
        document.getElementById('fAction').value = 'update';
        document.getElementById('fID').value     = id;
        document.getElementById('fCodeDisplay').value = d.code;
        document.getElementById('fCodeDisplay').readOnly = true;
        document.getElementById('fCodeDisplay').style.background = '#F0F0F0';
        document.getElementById('fCodeDisplay').style.color = 'var(--cfp-text-muted)';
        document.getElementById('fName').value   = d.name;
        document.getElementById('fType').value   = d.type;
        document.getElementById('fDesc').value   = d.desc;
        document.getElementById('fSort').value   = d.sort;
        document.getElementById('fActive').value = d.active;
        document.getElementById('statusWrap').style.display = '';
    }
    modal.show();
}

function confirmDelete(id, name) {
    Swal.fire({
        title: 'ยืนยันการลบ?',
        html: 'ต้องการลบหน่วยวัด "<b>' + name + '</b>" ใช่หรือไม่',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#2AABB8',
        cancelButtonColor: '#9E9E9E',
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
        customClass: { popup: 'font-prompt' }
    }).then(function (result) {
        if (result.isConfirmed) {
            document.getElementById('fdID').value = id;
            document.getElementById('formDelete').submit();
        }
    });
}

function confirmToggle(id, currentActive, name) {
    var action = currentActive ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    var icon   = currentActive ? 'warning' : 'question';
    var color  = currentActive ? '#DC3545' : '#4CAF50';
    Swal.fire({
        title: action + 'หน่วยวัด?',
        html: 'หน่วย: <b>' + name + '</b>',
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

function openImportModal() {
    document.getElementById('formImport').reset();
    document.getElementById('importFileName').textContent = '';
    new bootstrap.Modal(document.getElementById('modalImport')).show();
}

function handleFileSelect(input) {
    var nameBox = document.getElementById('importFileName');
    if (input.files && input.files.length > 0) {
        nameBox.innerHTML = '<i class="bi bi-file-earmark-check-fill me-1"></i>' + input.files[0].name;
    } else {
        nameBox.textContent = '';
    }
}

/* Drag & Drop dropzone */
(function () {
    var dropZone  = document.getElementById('dropZone');
    var fileInput = document.getElementById('importFile');
    if (!dropZone) { return; }
    ['dragenter', 'dragover'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
            e.preventDefault();
            dropZone.classList.remove('dragover');
        });
    });
    dropZone.addEventListener('drop', function (e) {
        if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            handleFileSelect(fileInput);
        }
    });
})();

/* Validate ก่อนส่งฟอร์ม Import */
document.getElementById('formImport').addEventListener('submit', function (e) {
    var fi = document.getElementById('importFile');
    if (!fi.files || fi.files.length === 0) {
        e.preventDefault();
        Swal.fire({
            title: 'กรุณาเลือกไฟล์',
            text: 'ยังไม่ได้เลือกไฟล์ Excel สำหรับนำเข้าข้อมูล',
            icon: 'warning',
            confirmButtonColor: '#2AABB8',
            confirmButtonText: 'ตกลง',
            customClass: { popup: 'font-prompt' }
        });
    }
});

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

<?php if ($importResult) { ?>
Swal.fire({
    title: 'ผลการนำเข้าข้อมูล',
    icon: <?php echo ($importResult['fail'] > 0) ? "'warning'" : "'success'"; ?>,
    html: '<div style="text-align:left;font-size:0.9rem;">'
        + '<div class="mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>นำเข้าสำเร็จ: <b><?php echo (int)$importResult['success']; ?></b> แถว</div>'
        + '<div class="mb-1"><i class="bi bi-skip-forward-fill text-warning me-2"></i>ข้าม (ข้อมูลซ้ำ): <b><?php echo (int)$importResult['skip']; ?></b> แถว</div>'
        + '<div class="mb-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>ผิดพลาด: <b><?php echo (int)$importResult['fail']; ?></b> แถว</div>'
        <?php if (!empty($importResult['errors'])) { ?>
        + '<hr><div style="font-size:0.78rem;color:#C62828;max-height:150px;overflow-y:auto;">'
        + '<?php echo implode('<br>', array_map(function ($e) { return htmlspecialchars(addslashes($e)); }, $importResult['errors'])); ?>'
        + '</div>'
        <?php } ?>
        + '</div>',
    confirmButtonColor: '#2AABB8',
    customClass: { popup: 'font-prompt' }
});
<?php } ?>
</script>

</body>
</html>

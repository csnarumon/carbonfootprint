<?php
/* master/employee.php — ทะเบียนพนักงาน (การเดินทาง) Scope 3 Cat.7
   แก้ไข: Filter ประเภทการเดินทาง ใช้ค่าคงที่ (ไม่ต้องมีข้อมูลใน DB)
*/
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4, 5));

$conn   = getConnection();
$userID = (int)$_SESSION['user_id'];

$toastMsg  = '';
$toastType = 'success';
if (!empty($_SESSION['toast_msg'])) {
    $toastMsg  = $_SESSION['toast_msg'];
    $toastType = $_SESSION['toast_type'] ?? 'success';
    unset($_SESSION['toast_msg'], $_SESSION['toast_type']);
}

/* ---- Lookups ---- */
$resSite = sqlsrv_query($conn, "SELECT SiteID, SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
$sites = array();
if ($resSite) {
    while ($r = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) {
        $sites[] = $r;
    }
}

$resDept = sqlsrv_query($conn, "SELECT DeptID, DeptName, DivisionID FROM CFP_Department WHERE IsActive=1 ORDER BY DeptName");
$depts = array();
if ($resDept) {
    while ($r = sqlsrv_fetch_array($resDept, SQLSRV_FETCH_ASSOC)) {
        $depts[] = $r;
    }
}

$resPos = sqlsrv_query($conn, "SELECT PositionID, PositionName FROM CFP_Position WHERE IsActive=1 ORDER BY PositionName");
$positions = array();
if ($resPos) {
    while ($r = sqlsrv_fetch_array($resPos, SQLSRV_FETCH_ASSOC)) {
        $positions[] = $r;
    }
}

$resVT = sqlsrv_query($conn, "SELECT TypeID, TypeName FROM CFP_VehicleType WHERE IsActive=1 ORDER BY TypeName");
$vehicleTypes = array();
if ($resVT) {
    while ($r = sqlsrv_fetch_array($resVT, SQLSRV_FETCH_ASSOC)) {
        $vehicleTypes[] = $r;
    }
}

/* ---- เพิ่ม Lookup: บริษัท และ ฝ่าย ---- */
$resCompany = sqlsrv_query($conn, "SELECT CompanyID, CompanyName FROM CFP_Company WHERE IsActive=1 ORDER BY CompanyName");
$companies = array();
if ($resCompany) {
    while ($r = sqlsrv_fetch_array($resCompany, SQLSRV_FETCH_ASSOC)) {
        $companies[] = $r;
    }
}

$resDivision = sqlsrv_query($conn, "SELECT DivisionID, DivisionName, CompanyID FROM CFP_Division WHERE IsActive=1 ORDER BY DivisionName");
$divisions = array();
if ($resDivision) {
    while ($r = sqlsrv_fetch_array($resDivision, SQLSRV_FETCH_ASSOC)) {
        $divisions[] = $r;
    }
}

/* ---- Main query (เพิ่ม JOIN ไปยัง Division และ Company) ---- */
$res = sqlsrv_query($conn, "
    SELECT e.EmployeeID, e.EmployeeCode, e.FullName,
           e.SiteID,     s.SiteName,
           e.DeptID,     d.DeptName,
           dv.DivisionID, dv.DivisionName,
           c.CompanyID,  c.CompanyName,
           e.PositionID, p.PositionName,
           e.CommuteType, e.CommuteDistKm, e.WorkDaysPerMonth,
           e.VehicleTypeID, vt.TypeName AS VehicleTypeName,
           e.Email, e.Phone, e.Remark, e.IsActive
    FROM CFP_Employee e
    LEFT JOIN CFP_Site       s  ON s.SiteID       = e.SiteID
    LEFT JOIN CFP_Department d  ON d.DeptID        = e.DeptID
    LEFT JOIN CFP_Division   dv ON dv.DivisionID   = d.DivisionID
    LEFT JOIN CFP_Company    c  ON c.CompanyID     = dv.CompanyID
    LEFT JOIN CFP_Position   p  ON p.PositionID    = e.PositionID
    LEFT JOIN CFP_VehicleType vt ON vt.TypeID      = e.VehicleTypeID
    ORDER BY e.EmployeeCode
");
$rows = array();
if ($res) {
    while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $r;
    }
}

/* ---- KPI ---- */
$total    = count($rows);
$active   = count(array_filter($rows, function ($r) { return (bool)$r['IsActive']; }));
$inactive = $total - $active;
$sites_used = count(array_unique(array_filter(array_column($rows, 'SiteID'))));

/* ---- Filter options ---- */
// Site: จาก Master
$siteFilterOptions = array();
$resSiteAll = sqlsrv_query($conn, "SELECT SiteID, SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
if ($resSiteAll) {
    while ($row = sqlsrv_fetch_array($resSiteAll, SQLSRV_FETCH_ASSOC)) {
        $siteFilterOptions[$row['SiteID']] = $row['SiteName'];
    }
}

// ประเภทการเดินทาง: ใช้ค่าคงที่ (ไม่ต้องมีข้อมูลใน DB)
$commuteFilterOptions = array(
    'รถยนต์ส่วนตัว' => 'รถยนต์ส่วนตัว',
    'มอเตอร์ไซค์'   => 'มอเตอร์ไซค์',
    'รถสาธารณะ'     => 'รถสาธารณะ',
    'เดินเท้า/จักรยาน' => 'เดินเท้า/จักรยาน',
    'รถตู้องค์กร'    => 'รถตู้องค์กร',
    'อื่นๆ'          => 'อื่นๆ',
);

/* ---- JS data map ---- */
$map = array();
foreach ($rows as $r) {
    $map[(int)$r['EmployeeID']] = array(
        'code'      => $r['EmployeeCode'],
        'name'      => $r['FullName'],
        'site'      => $r['SiteID']         ? (int)$r['SiteID']         : '',
        'company'   => $r['CompanyID']      ? (int)$r['CompanyID']      : '',
        'division'  => $r['DivisionID']     ? (int)$r['DivisionID']     : '',
        'dept'      => $r['DeptID']          ? (int)$r['DeptID']          : '',
        'pos'       => $r['PositionID']      ? (int)$r['PositionID']      : '',
        'commute'   => $r['CommuteType']     ?? '',
        'dist'      => $r['CommuteDistKm']   !== null ? (float)$r['CommuteDistKm'] : '',
        'workdays'  => $r['WorkDaysPerMonth'] !== null ? (int)$r['WorkDaysPerMonth']   : '',
        'vtype'     => $r['VehicleTypeID']   ? (int)$r['VehicleTypeID']   : '',
        'email'     => $r['Email']           ?? '',
        'phone'     => $r['Phone']           ?? '',
        'remark'    => $r['Remark']          ?? '',
    );
}

$pageTitle = 'ทะเบียนพนักงาน (การเดินทาง)';
$pageIcon  = 'people';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ทะเบียนพนักงาน (การเดินทาง) — CFP</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/css/fileinput.min.css" rel="stylesheet">
<style>
body { font-family: 'Prompt', sans-serif; }
.font-prompt { font-family: 'Prompt', sans-serif !important; }
.btn-action {
    width: 30px; height: 30px; padding: 0;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 6px; font-size: 0.8rem;
}
.status-dot {
    width: 8px; height: 8px; border-radius: 50%; display: inline-block;
}
.form-section-hd {
    font-size: 0.8rem; font-weight: 600; color: var(--cfp-primary);
    border-bottom: 2px solid var(--cfp-sky);
    padding-bottom: 4px; margin-bottom: 12px; margin-top: 4px;
    display: flex; align-items: center; gap: 6px;
}
.commute-chip {
    font-size: 0.72rem; padding: 2px 8px; border-radius: 10px; font-weight: 500;
}
.chip-car     { background: #E3F2FD; color: #1565C0; }
.chip-moto    { background: #FFF3E0; color: #E65100; }
.chip-public  { background: #E8F5E9; color: #2E7D32; }
.chip-walk    { background: #F3E5F5; color: #6A1B9A; }
.chip-other   { background: #ECEFF1; color: #37474F; }
.file-drop-zone{border:2px dashed #2AABB8!important;border-radius:10px!important;background:#EEF6F8!important;}
.file-actions .file-upload-button{display:none!important;}
.cfp-btn-del:hover{background:rgba(220,53,69,1)!important;transform:scale(1.1);}

/* ---- Toolbar 2 บรรทัด (อยู่ภายใน Card) ---- */
.cfp-page-toolbar {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 14px;
    width: 100%;
}
.toolbar-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    width: 100%;
}
.toolbar-row .toolbar-left {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    flex: 1 1 auto;
}
.toolbar-row .toolbar-left .form-control,
.toolbar-row .toolbar-left .form-select {
    font-size: 0.85rem;
    font-family: 'Prompt', sans-serif;
}
.toolbar-row .toolbar-left .form-control {
    min-width: 200px;
    max-width: 300px;
    flex: 0 1 auto;
}
.toolbar-row .toolbar-left .form-select {
    min-width: 130px;
    max-width: 180px;
    flex: 0 1 auto;
}
.toolbar-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-shrink: 0;
    margin-left: auto;
}
.toolbar-row-filters .toolbar-left {
    flex: 1 1 auto;
}
.toolbar-row-filters .toolbar-left .form-select {
    min-width: 140px;
    max-width: 200px;
}

@media (max-width: 768px) {
    .toolbar-row { flex-direction: column; align-items: stretch; }
    .toolbar-row .toolbar-left { flex-wrap: wrap; }
    .toolbar-row .toolbar-left .form-control,
    .toolbar-row .toolbar-left .form-select {
        min-width: 100%;
        max-width: 100%;
        flex: 1 1 100%;
    }
    .toolbar-actions {
        justify-content: stretch;
        margin-left: 0;
        width: 100%;
    }
    .toolbar-actions .btn { flex: 1; }
}
</style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="cfp-main">
<?php include '../includes/topbar.php'; ?>
<div class="cfp-content">

<!-- Page header -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);">
            <i class="bi bi-people me-2"></i>ทะเบียนพนักงาน (การเดินทาง)
        </h5>
        <div style="font-size:0.78rem;color:var(--cfp-text-muted);">
            ทะเบียนทรัพย์สิน › Scope 3 › Cat.7 Employee Commuting
        </div>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
            <div style="font-size:1.6rem;font-weight:700;color:var(--cfp-primary);"><?php echo $total; ?></div>
            <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ทั้งหมด</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
            <div style="font-size:1.6rem;font-weight:700;color:#43A047;"><?php echo $active; ?></div>
            <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ใช้งาน</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
            <div style="font-size:1.6rem;font-weight:700;color:#F57C00;"><?php echo $inactive; ?></div>
            <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ปิดใช้งาน</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
            <div style="font-size:1.6rem;font-weight:700;color:#7B1FA2;"><?php echo $sites_used; ?></div>
            <div style="font-size:0.75rem;color:var(--cfp-text-muted);">Site ที่มีพนักงาน</div>
        </div>
    </div>
</div>

<!-- ===== Table Card (พร้อม Toolbar 2 บรรทัด) ===== -->
<div class="cfp-card">

    <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);margin-bottom:12px;">
        <i class="bi bi-card-list me-2"></i>รายการพนักงาน
    </div>

    <!-- Toolbar 2 บรรทัด (เฉพาะ Site + ประเภทการเดินทาง) -->
    <div class="cfp-page-toolbar">
        <!-- บรรทัดที่ 1: ค้นหา + สถานะ + ล้าง + ปุ่มเพิ่ม -->
        <div class="toolbar-row">
            <div class="toolbar-left">
                <input type="text" id="fltKeyword" class="form-control font-prompt"
                       placeholder="ค้นหารหัส / ชื่อ-นามสกุล / แผนก...">
                <select id="fltStatus" class="form-select font-prompt">
                    <option value="">สถานะทั้งหมด</option>
                    <option value="ใช้งาน">ใช้งาน</option>
                    <option value="ปิด">ปิดใช้งาน</option>
                </select>
                <button class="btn btn-outline-secondary btn-sm" onclick="clearFilter()">
                    <i class="bi bi-x-circle me-1"></i>ล้าง
                </button>
            </div>
            <div class="toolbar-actions">
                <button class="btn-cfp-add btn-sm" onclick="openModal(0)">
                    <i class="bi bi-plus-circle me-1"></i>เพิ่มพนักงาน
                </button>
            </div>
        </div>

        <!-- บรรทัดที่ 2: Site + ประเภทการเดินทาง (เท่านั้น) -->
        <div class="toolbar-row toolbar-row-filters">
            <div class="toolbar-left">
                <select id="fltSite" class="form-select font-prompt">
                    <option value="">Site ทั้งหมด</option>
                    <?php foreach ($siteFilterOptions as $id => $name) { ?>
                    <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
                    <?php } ?>
                </select>
                <select id="fltCommute" class="form-select font-prompt">
                    <option value="">ประเภทการเดินทางทั้งหมด</option>
                    <?php foreach ($commuteFilterOptions as $key => $value) { ?>
                    <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($value); ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table id="tblEmployee" class="table table-bordered table-hover align-middle" style="width:100%;font-size:0.85rem;">
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th style="width:110px;">รหัส</th>
                    <th>ชื่อ-นามสกุล / แผนก</th>
                    <th style="width:120px;">ประเภทการเดินทาง</th>
                    <th style="width:90px;" class="text-center">ระยะทาง (km)</th>
                    <th style="width:90px;" class="text-center">วันทำงาน/เดือน</th>
                    <th style="width:100px;">Site</th>
                    <th class="text-center" style="width:80px;">สถานะ</th>
                    <th class="text-center" style="width:70px;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $r) { ?>
                <?php
                $ct    = $r['CommuteType'] ?? '';
                $chips = array(
                    'รถยนต์ส่วนตัว'  => 'chip-car',
                    'มอเตอร์ไซค์'    => 'chip-moto',
                    'รถสาธารณะ'      => 'chip-public',
                    'เดินเท้า/จักรยาน' => 'chip-walk',
                );
                $chipClass = $chips[$ct] ?? 'chip-other';
                ?>
                <tr data-status="<?php echo $r['IsActive'] ? 'ใช้งาน' : 'ปิด'; ?>"
                    data-site="<?php echo htmlspecialchars($r['SiteName'] ?? ''); ?>"
                    data-commute="<?php echo htmlspecialchars($r['CommuteType'] ?? ''); ?>">
                    <td><?php echo $i + 1; ?></td>
                    <td><code><?php echo htmlspecialchars($r['EmployeeCode']); ?></code></td>
                    <td>
                        <div class="fw-500"><?php echo htmlspecialchars($r['FullName']); ?></div>
                        <?php if (!empty($r['DeptName'])) { ?>
                        <div style="font-size:0.72rem;color:var(--cfp-text-muted);">
                            <i class="bi bi-building me-1"></i><?php echo htmlspecialchars($r['DeptName']); ?>
                            <?php if (!empty($r['PositionName'])) { echo ' · ' . htmlspecialchars($r['PositionName']); } ?>
                        </div>
                        <?php } ?>
                    </td>
                    <td>
                        <?php if (!empty($ct)) { ?>
                        <span class="commute-chip <?php echo $chipClass; ?>"><?php echo htmlspecialchars($ct); ?></span>
                        <?php if (!empty($r['VehicleTypeName'])) { ?>
                        <div style="font-size:0.7rem;color:var(--cfp-text-muted);margin-top:2px;"><?php echo htmlspecialchars($r['VehicleTypeName']); ?></div>
                        <?php } ?>
                        <?php } else { echo '—'; } ?>
                    </td>
                    <td class="text-center">
                        <?php echo $r['CommuteDistKm'] !== null ? number_format((float)$r['CommuteDistKm'], 1) . ' km' : '—'; ?>
                    </td>
                    <td class="text-center">
                        <?php echo $r['WorkDaysPerMonth'] !== null ? (int)$r['WorkDaysPerMonth'] . ' วัน' : '—'; ?>
                    </td>
                    <td><?php echo htmlspecialchars($r['SiteName'] ?? '—'); ?></td>
                    <td class="text-center">
                        <?php if ($r['IsActive']) { ?>
                            <span class="status-dot" style="background:#4CAF50;"></span>
                            <span style="font-size:0.78rem;color:#2E7D32;"> ใช้งาน</span>
                        <?php } else { ?>
                            <span class="status-dot" style="background:#ccc;"></span>
                            <span style="font-size:0.78rem;color:#9E9E9E;"> ปิด</span>
                        <?php } ?>
                    </td>
                    <td class="text-center">
                        <button class="btn btn-outline-primary btn-action me-1"
                                onclick="openModal(<?php echo (int)$r['EmployeeID']; ?>)"
                                title="แก้ไข">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                        <button class="btn btn-action <?php echo $r['IsActive'] ? 'btn-outline-danger' : 'btn-outline-success'; ?>"
                                onclick="confirmToggle(<?php echo (int)$r['EmployeeID']; ?>, <?php echo $r['IsActive'] ? 1 : 0; ?>, '<?php echo htmlspecialchars(addslashes($r['FullName'])); ?>')"
                                title="<?php echo $r['IsActive'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน'; ?>">
                            <i class="bi bi-<?php echo $r['IsActive'] ? 'toggle2-off' : 'toggle2-on'; ?>"></i>
                        </button>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ==================== MODAL ==================== -->
<div class="modal fade" id="modalEmployee" tabindex="-1" data-bs-backdrop="static">
<div class="modal-dialog modal-dialog-centered modal-xl">
<div class="modal-content" style="font-family:'Prompt',sans-serif;">

<div class="modal-header" style="background:var(--cfp-primary);color:#fff;">
    <h6 class="modal-title mb-0" id="modalTitle">
        <i class="bi bi-person-plus me-2"></i>เพิ่มพนักงาน
    </h6>
    <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<form id="formEmployee" method="POST" enctype="multipart/form-data">
<input type="hidden" name="action"     id="fAction" value="create">
<input type="hidden" name="id"         id="fID"     value="0">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

<div class="modal-body">
<div class="row g-3">

    <!-- ========== กลุ่ม 1: ข้อมูลพนักงาน ========== -->
    <div class="col-12">
        <div class="form-section-hd">
            <i class="bi bi-person-badge"></i> ข้อมูลพนักงาน
        </div>
    </div>

    <div class="col-md-3">
        <label class="form-label form-required">รหัสพนักงาน</label>
        <input type="text" class="form-control font-prompt" name="EmployeeCode" id="fCode"
               maxlength="50" required placeholder="เช่น T0069xxx1" style="text-transform:uppercase;">
    </div>
    <div class="col-md-9">
        <label class="form-label form-required">ชื่อ-นามสกุล</label>
        <input type="text" class="form-control font-prompt" name="FullName" id="fName"
               maxlength="200" required placeholder="ชื่อและนามสกุลพนักงาน">
    </div>

    <!-- บริษัท (Cascade) -->
    <div class="col-md-6">
        <label class="form-label">บริษัท</label>
        <select class="form-select font-prompt" id="fCompany" onchange="filterDivisionByCompany()">
            <option value="">— เลือกบริษัท —</option>
            <?php foreach ($companies as $c) { ?>
            <option value="<?php echo $c['CompanyID']; ?>"><?php echo htmlspecialchars($c['CompanyName']); ?></option>
            <?php } ?>
        </select>
    </div>

    <!-- ฝ่าย (Cascade) -->
    <div class="col-md-6">
        <label class="form-label">ฝ่าย</label>
        <select class="form-select font-prompt" id="fDivision" onchange="filterDeptByDivision()">
            <option value="">— เลือกฝ่าย —</option>
            <?php foreach ($divisions as $d) { ?>
            <option value="<?php echo $d['DivisionID']; ?>" data-company="<?php echo $d['CompanyID']; ?>"><?php echo htmlspecialchars($d['DivisionName']); ?></option>
            <?php } ?>
        </select>
    </div>

    <!-- แผนก (Cascade จากฝ่าย) -->
    <div class="col-md-6">
        <label class="form-label">แผนก</label>
        <select class="form-select font-prompt" name="DeptID" id="fDept">
            <option value="">— เลือกฝ่ายก่อน —</option>
            <?php foreach ($depts as $d) { ?>
            <option value="<?php echo $d['DeptID']; ?>" data-division="<?php echo $d['DivisionID']; ?>"><?php echo htmlspecialchars($d['DeptName']); ?></option>
            <?php } ?>
        </select>
    </div>

    <!-- ตำแหน่ง -->
    <div class="col-md-6">
        <label class="form-label">ตำแหน่ง</label>
        <select class="form-select font-prompt" name="PositionID" id="fPos">
            <option value="">— ไม่ระบุ —</option>
            <?php foreach ($positions as $p) { ?>
            <option value="<?php echo $p['PositionID']; ?>"><?php echo htmlspecialchars($p['PositionName']); ?></option>
            <?php } ?>
        </select>
    </div>

    <!-- Site / สาขา -->
    <div class="col-md-6">
        <label class="form-label">Site / สาขา</label>
        <select class="form-select font-prompt" name="SiteID" id="fSite">
            <option value="">— ไม่ระบุ —</option>
            <?php foreach ($sites as $s) { ?>
            <option value="<?php echo $s['SiteID']; ?>"><?php echo htmlspecialchars($s['SiteName']); ?></option>
            <?php } ?>
        </select>
    </div>

    <!-- ========== กลุ่ม 2: ข้อมูลการเดินทาง ========== -->
    <div class="col-12">
        <div class="form-section-hd">
            <i class="bi bi-car-front"></i> ข้อมูลการเดินทาง
            <span style="font-size:0.72rem;font-weight:400;color:var(--cfp-text-muted);">Scope 3 Cat.7 Employee Commuting</span>
        </div>
    </div>

    <div class="col-md-4">
        <label class="form-label">ประเภทการเดินทาง</label>
        <select class="form-select font-prompt" name="CommuteType" id="fCommuteType">
            <option value="">— ไม่ระบุ —</option>
            <option value="รถยนต์ส่วนตัว">รถยนต์ส่วนตัว</option>
            <option value="มอเตอร์ไซค์">มอเตอร์ไซค์</option>
            <option value="รถสาธารณะ">รถสาธารณะ (รถเมล์/BTS/MRT)</option>
            <option value="เดินเท้า/จักรยาน">เดินเท้า / จักรยาน</option>
            <option value="รถตู้องค์กร">รถตู้องค์กร (จัดหาให้)</option>
            <option value="อื่นๆ">อื่นๆ</option>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label">ประเภทยานพาหนะ</label>
        <select class="form-select font-prompt" name="VehicleTypeID" id="fVType">
            <option value="">— ไม่ระบุ —</option>
            <?php foreach ($vehicleTypes as $vt) { ?>
            <option value="<?php echo $vt['TypeID']; ?>"><?php echo htmlspecialchars($vt['TypeName']); ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label">วันทำงานต่อเดือน</label>
        <div class="input-group">
            <input type="number" class="form-control font-prompt" name="WorkDaysPerMonth" id="fWorkDays"
                   min="1" max="31" step="1" placeholder="เช่น 22">
            <span class="input-group-text font-prompt" style="font-size:0.82rem;">วัน</span>
        </div>
    </div>

    <div class="col-md-6">
        <label class="form-label">ระยะทางบ้านถึงที่ทำงาน (เที่ยวเดียว)</label>
        <div class="input-group">
            <input type="number" class="form-control font-prompt" name="CommuteDistKm" id="fDist"
                   min="0" step="0.1" placeholder="เช่น 15.5">
            <span class="input-group-text font-prompt" style="font-size:0.82rem;">km</span>
        </div>
        <div style="font-size:0.72rem;color:var(--cfp-text-muted);margin-top:3px;">
            <i class="bi bi-info-circle me-1"></i>ระบุระยะทางเที่ยวเดียว ระบบจะคำนวณไป-กลับ ×2 อัตโนมัติ
        </div>
    </div>
    <div class="col-md-6">
        <label class="form-label" style="color:var(--cfp-text-muted);font-size:0.8rem;">
            km รวมต่อเดือน (คำนวณอัตโนมัติ)
        </label>
        <div class="input-group">
            <input type="text" class="form-control font-prompt" id="fDistCalc" readonly
                   style="background:#EEF6F8;color:var(--cfp-primary);font-weight:600;"
                   placeholder="กรอกระยะทางและวันทำงานก่อน">
            <span class="input-group-text font-prompt" style="font-size:0.82rem;">km/เดือน</span>
        </div>
    </div>

    <!-- ========== กลุ่ม 3: ติดต่อ / หมายเหตุ ========== -->
    <div class="col-12">
        <div class="form-section-hd">
            <i class="bi bi-telephone"></i> ข้อมูลติดต่อ / หมายเหตุ
        </div>
    </div>

    <div class="col-md-6">
        <label class="form-label">อีเมล</label>
        <input type="email" class="form-control font-prompt" name="Email" id="fEmail"
               maxlength="200" placeholder="name@company.com">
    </div>
    <div class="col-md-6">
        <label class="form-label">เบอร์โทรศัพท์</label>
        <input type="text" class="form-control font-prompt" name="Phone" id="fPhone"
               maxlength="50" placeholder="เช่น 081-234-5678">
    </div>
    <div class="col-12">
        <label class="form-label">หมายเหตุ</label>
        <textarea class="form-control font-prompt" name="Remark" id="fRemark"
                  rows="2" maxlength="500" style="resize:none;"
                  placeholder="ข้อมูลเพิ่มเติม เช่น รถจักรยานยนต์ 2 จังหวะ, cc เครื่องยนต์"></textarea>
    </div>

    <!-- ========== กลุ่ม 4: Upload Panel ========== -->
    <div class="col-12">
        <label style="font-weight:600;color:var(--cfp-primary);font-size:0.85rem;">
            <i class="bi bi-images me-1"></i>รูปภาพ / เอกสารแนบ
            <span class="text-secondary small fw-normal"> .png .jpg .pdf | สูงสุด 10 ไฟล์ | รวมไม่เกิน 10 MB</span>
        </label>
        <div class="file-loading">
            <input id="file-1" name="file[]" type="file" class="file" multiple data-browse-on-zone-click="true">
        </div>
        <div id="cfp-file-error" class="mt-2 text-danger small" style="display:none;"></div>
    </div>

</div><!-- /row -->
</div><!-- /modal-body -->

<div class="modal-footer" style="background:#F9FAFB;">
    <button type="button" class="btn btn-outline-secondary btn-sm font-prompt" data-bs-dismiss="modal">
        <i class="bi bi-x-circle me-1"></i>ยกเลิก
    </button>
    <button type="button" class="btn-cfp-add btn-sm" id="btnSave">
        <i class="bi bi-check-circle me-1"></i>บันทึก
    </button>
</div>
</form>
</div><!-- /modal-content -->
</div><!-- /modal-dialog -->
</div><!-- /modal -->

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999;">
    <div id="toastSuccess" class="toast align-items-center text-bg-success border-0 font-prompt">
        <div class="d-flex">
            <div class="toast-body" id="toastMsg"></div>
            <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
    <div id="toastError" class="toast align-items-center text-bg-danger border-0 font-prompt">
        <div class="d-flex">
            <div class="toast-body" id="toastErrMsg"></div>
            <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/js/fileinput.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/themes/fas/theme.min.js"></script>

<script>
// ============================================================
// ตัวแปรหลัก
// ============================================================
var currentAssetID = 0;
var savedPreviewFiles = [];
var savedPreviewConfig = [];
var pendingFiles = [];
var pendingDeletes = [];
var _cfpDupTimer = null;

var employeeData = <?php echo json_encode($map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var csrfToken = <?php echo json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

// ============================================================
// Cascade Functions
// ============================================================
function filterDivisionByCompany() {
    var companyID = document.getElementById('fCompany').value;
    var divisionSelect = document.getElementById('fDivision');
    var deptSelect = document.getElementById('fDept');
    
    divisionSelect.value = '';
    Array.prototype.forEach.call(divisionSelect.options, function(opt) {
        if (!opt.value) return;
        opt.style.display = (!companyID || opt.getAttribute('data-company') === companyID) ? '' : 'none';
    });
    divisionSelect.options[0].text = companyID ? '— เลือกฝ่าย —' : '— เลือกฝ่าย —';
    
    deptSelect.value = '';
    Array.prototype.forEach.call(deptSelect.options, function(opt) {
        if (!opt.value) return;
        opt.style.display = 'none';
    });
    deptSelect.options[0].text = '— เลือกฝ่ายก่อน —';
}

function filterDeptByDivision() {
    var divisionID = document.getElementById('fDivision').value;
    var deptSelect = document.getElementById('fDept');
    
    deptSelect.value = '';
    Array.prototype.forEach.call(deptSelect.options, function(opt) {
        if (!opt.value) return;
        opt.style.display = (!divisionID || opt.getAttribute('data-division') === divisionID) ? '' : 'none';
    });
    deptSelect.options[0].text = divisionID ? '— เลือกแผนก —' : '— เลือกฝ่ายก่อน —';
}

// ============================================================
// loadPreviewFromDB
// ============================================================
function loadPreviewFromDB(assetID, callback) {
    if (assetID <= 0) {
        savedPreviewFiles = [];
        savedPreviewConfig = [];
        if (callback) callback([], []);
        return;
    }
    $.ajax({
        url: 'get_asset_images.php',
        method: 'POST',
        data: { asset_type: 'Employee', asset_id: assetID },
        dataType: 'json',
        async: false,
        success: function(res) {
            var pf = [], pc = [];
            if (res.success && res.images) {
                res.images.forEach(function(img) {
                    pf.push(img.url);
                    var fileType = 'file';
                    var urlLower = (img.url || '').toLowerCase();
                    if (urlLower.match(/\.(jpg|jpeg|png|gif|webp)$/)) {
                        fileType = 'image';
                    } else if (urlLower.match(/\.pdf$/)) {
                        fileType = 'pdf';
                    }
                    pc.push({
                        caption: img.caption,
                        size: img.size,
                        key: img.id,
                        type: fileType
                    });
                });
            }
            savedPreviewFiles = pf.slice();
            savedPreviewConfig = pc.slice();
        }
    });
    if (callback) callback(savedPreviewFiles, savedPreviewConfig);
}

// ============================================================
// _initFileInput
// ============================================================
function _initFileInput(previewFiles, previewConfig) {
    if ($('#file-1').data('fileinput')) {
        $('#file-1').fileinput('destroy');
    }

    var isEdit = (currentAssetID > 0);

    $('#file-1').fileinput({
        theme: 'fas',
        uploadUrl: 'employee_save.php',
        uploadAsync: false,
        showUpload: false,
        showRemove: false,
        autoUpload: false,
        overwriteInitial: false,
        append: true,
        deleteUrl: false,
        initialPreviewAsData: true,
        allowedFileExtensions: ['png', 'jpg', 'jpeg', 'pdf'],
        maxFileSize: 10240,
        maxFileCount: 10,
        previewFileType: 'any',
        fileActionSettings: {
            showUpload: false,
            showRemove: true,
            showZoom: true,
            showDrag: false
        },
        msgInvalidFileExtension: 'ไฟล์ "{name}" ไม่รองรับ กรุณาเลือกเฉพาะ .png .jpg .pdf',
        msgSizeTooLarge: 'ไฟล์ "{name}" ขนาด {size} KB ใหญ่เกินไป (สูงสุด {maxSize} KB)',
        msgFilesTooMany: 'เลือกไฟล์มากเกินไป ({n} ไฟล์) สูงสุด {m} ไฟล์รวมกัน',
        initialPreview: previewFiles,
        initialPreviewConfig: previewConfig,
        removeFromPreviewOnError: true,
        dropZoneEnabled: true,
        browseOnZoneClick: true,
        uploadExtraData: function() {
            var data = {};
            $('#formEmployee').serializeArray().forEach(function(item) {
                if (data[item.name] !== undefined) {
                    data[item.name] += ',' + item.value;
                } else {
                    data[item.name] = item.value;
                }
            });
            data['action'] = $('#fAction').val() || 'create';
            data['id'] = $('#fID').val() || '0';
            data['asset_type'] = 'Employee';
            data['asset_id'] = currentAssetID;
            data['csrf_token'] = $('input[name="csrf_token"]').val() || csrfToken;
            data['pending_deletes'] = pendingDeletes.join(',');
            return data;
        }
    })
    .on('fileshown', function() { injectDeleteButtons(); })
    .on('fileloaded', function(event, file, previewId, index) {
        pendingFiles.push(file.name.toLowerCase());
        setTimeout(function() { injectNewFileDeleteBtn(previewId, index); }, 100);
    })
    .on('filecleared filereset', function() {
        pendingFiles = [];
        $('#cfp-file-error').hide().text('');
    })
    .on('fileremoved', function(event, id, index) {
        if (index >= 0 && index < pendingFiles.length) {
            pendingFiles.splice(index, 1);
        }
        $('#cfp-file-error').hide().text('');
    })
    .on('fileerror', function(event, data, msg) {
        showUploadErrorSwal('ไม่สามารถเพิ่มไฟล์ได้', [msg], null);
    })
    .on('filebatchuploadsuccess', function(event, data) {
        var response = data.response;
        if (response && response.success) {
            var successTitle = isEdit ? 'บันทึกข้อมูลสำเร็จ!' : 'เพิ่มข้อมูลสำเร็จ!';
            var modalEl = document.getElementById('modalEmployee');
            var bsModal = bootstrap.Modal.getInstance(modalEl);

            // ✅ ป้องกัน Event ซ้ำ
            if (modalEl._cfpSaving) return;
            modalEl._cfpSaving = true;

            // ✅ ปิด Modal
            bsModal.hide();

            // ✅ ใช้ setTimeout เพื่อให้ Modal ปิดสนิท แล้วค่อยแสดง Swal
            setTimeout(function() {
                modalEl._cfpSaving = false;
                Swal.fire({
                    icon: 'success',
                    title: successTitle,
                    text: response.msg || '',
                    timer: 5000,
                    timerProgressBar: true,
                    showConfirmButton: true,
                    confirmButtonText: 'ตกลง',
                    confirmButtonColor: '#2AABB8',
                    allowOutsideClick: false,
                    customClass: { popup: 'font-prompt' }
                }).then(function() {
                    location.reload();
                });
            }, 400);
        } else {
            Swal.fire({
                icon: 'error', title: 'เกิดข้อผิดพลาด',
                text: response.msg || 'ไม่สามารถบันทึกข้อมูลได้',
                confirmButtonText: 'ตกลง', confirmButtonColor: '#DC3545',
                customClass: { popup: 'font-prompt' }
            });
            $('#btnSave').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>บันทึก');
        }
    })

    .on('filebatchuploaderror', function(event, data, msg) {
        var errorMsg = (data.response && data.response.msg) ? data.response.msg : (msg || 'อัปโหลดไฟล์ล้มเหลว');
        Swal.fire({
            icon: 'error', title: 'อัปโหลดไฟล์ไม่สำเร็จ',
            text: errorMsg, customClass: { popup: 'font-prompt' }
        });
        $('#btnSave').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>บันทึก');
    });

    setTimeout(injectDeleteButtons, 600);

    // ===== ดัก change =====
    var inputEl = document.getElementById('file-1');
    if (inputEl._cfpDupHandler) {
        inputEl.removeEventListener('change', inputEl._cfpDupHandler, true);
    }
    inputEl._cfpDupHandler = function() {
        var files = inputEl.files;
        if (!files || !files.length) return;

        var dupNames = [];
        var dt = new DataTransfer();

        for (var i = 0; i < files.length; i++) {
            var name = files[i].name.toLowerCase();
            var dupInDB = savedPreviewConfig.some(function(c) {
                return (c.caption || '').toLowerCase() === name;
            });
            var dupInPending = pendingFiles.some(function(f) { return f === name; });
            var dupInBatch = false;
            for (var j = 0; j < i; j++) {
                if (files[j].name.toLowerCase() === name) { dupInBatch = true; break; }
            }

            if (dupInDB || dupInPending || dupInBatch) {
                dupNames.push(files[i].name);
            } else {
                dt.items.add(files[i]);
            }
        }

        if (dupNames.length > 0) {
            try { inputEl.files = dt.files; } catch(ex) {}
            clearTimeout(_cfpDupTimer);
            _cfpDupTimer = setTimeout(function() {
                var names = dupNames.slice();
                showUploadErrorSwal(
                    names.length === 1 ? 'พบไฟล์ซ้ำ' : 'พบไฟล์ซ้ำ ' + names.length + ' ไฟล์',
                    names.map(function(n) { return '<i class="fa fa-file me-1"></i>' + n; }),
                    'ไฟล์เหล่านี้มีอยู่ในระบบแล้ว จึงไม่ถูกเพิ่มเข้าในรายการ'
                );
            }, 50);
        }
    };
    inputEl.addEventListener('change', inputEl._cfpDupHandler, true);
}

// ============================================================
// showUploadErrorSwal
// ============================================================
function showUploadErrorSwal(title, items, subtitle) {
    var itemsHtml = items.map(function(item) {
        return '<div class="cfp-err-item">' + item + '</div>';
    }).join('');
    var subtitleHtml = subtitle ? '<p class="cfp-err-subtitle">' + subtitle + '</p>' : '';

    Swal.fire({
        icon: 'warning',
        title: '<span class="cfp-err-title">' + title + '</span>',
        html: subtitleHtml + '<div class="cfp-err-list">' + itemsHtml + '</div>',
        confirmButtonText: 'ตกลง รับทราบ',
        confirmButtonColor: '#2AABB8',
        customClass: { popup: 'font-prompt cfp-upload-err-popup' },
        didOpen: function() {
            if (!document.getElementById('cfp-err-style')) {
                var s = document.createElement('style');
                s.id = 'cfp-err-style';
                s.textContent = [
                    '.cfp-upload-err-popup{max-width:420px!important;border-radius:14px!important;}',
                    '.cfp-err-title{font-size:1rem;font-weight:600;color:#E65100;}',
                    '.cfp-err-subtitle{font-size:0.78rem;color:#666;margin:4px 0 10px;}',
                    '.cfp-err-list{background:#FFF8F0;border:1px solid #FFE0B2;border-radius:8px;padding:8px 12px;text-align:left;max-height:160px;overflow-y:auto;margin-top:6px;}',
                    '.cfp-err-item{font-size:0.78rem;color:#BF360C;padding:3px 0;border-bottom:1px dashed #FFCC80;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
                    '.cfp-err-item:last-child{border-bottom:none;}'
                ].join(' ');
                document.head.appendChild(s);
            }
        }
    });
}

// ============================================================
// injectDeleteButtons
// ============================================================
function injectDeleteButtons() {
    var $wrap = $('#file-1').closest('.file-input');
    $wrap.find('.file-preview-frame').each(function() {
        var $frame = $(this);
        var frameId = $frame.attr('id') || '';
        if (frameId.indexOf('init') < 0) return;
        $frame.find('.kv-file-remove').hide();
        if ($frame.find('.cfp-btn-del').length) return;
        var match = frameId.match(/init-(\d+)$/);
        if (!match) return;
        var cfg = savedPreviewConfig[parseInt(match[1], 10)];
        if (!cfg || !cfg.key) return;
        appendDelBtn($frame, function() { confirmDeleteFromDB(cfg.key); });
    });
}

// ============================================================
// injectNewFileDeleteBtn
// ============================================================
function injectNewFileDeleteBtn(previewId, fileIndex) {
    var $frame = $('#' + previewId);
    if (!$frame.length || $frame.find('.cfp-btn-del').length) return;
    $frame.find('.kv-file-remove').hide();
    appendDelBtn($frame, function() {
        pendingFiles.splice(fileIndex, 1);
        $frame.remove();
        $('#cfp-file-error').hide().text('');
    });
}

// ============================================================
// appendDelBtn
// ============================================================
function appendDelBtn($frame, onConfirm) {
    var $btn = $(
        '<button type="button" class="cfp-btn-del" title="ลบไฟล์" ' +
        'style="position:absolute;top:3px;right:3px;z-index:9999;' +
        'background:rgba(220,53,69,0.85);border:none;border-radius:50%;' +
        'width:24px;height:24px;padding:0;cursor:pointer;' +
        'display:flex;align-items:center;justify-content:center;">' +
        '<i class="fa fa-times" style="color:#fff;font-size:11px;pointer-events:none;"></i>' +
        '</button>'
    );
    $frame.css('position', 'relative').append($btn);
    $btn.on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        onConfirm();
    });
}

// ============================================================
// confirmDeleteFromDB
// ============================================================
function confirmDeleteFromDB(key) {
    Swal.fire({
        title: 'ยืนยันการลบไฟล์?',
        text: 'ไฟล์จะถูกลบเมื่อกดบันทึก',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC3545',
        cancelButtonColor: '#6C757D',
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true,
        customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (!result.isConfirmed) return;

        pendingDeletes.push(key);

        var frameIdx = -1;
        for (var i = 0; i < savedPreviewConfig.length; i++) {
            if (savedPreviewConfig[i].key == key) { frameIdx = i; break; }
        }
        if (frameIdx >= 0) {
            $('#thumb-file-1-init-' + frameIdx).fadeOut(150, function() { $(this).remove(); });
            savedPreviewConfig.splice(frameIdx, 1);
            savedPreviewFiles.splice(frameIdx, 1);
        }
    });
}

// ============================================================
// Open Modal
// ============================================================
function openModal(id) {
    var form = document.getElementById('formEmployee');
    form.reset();
    pendingFiles = [];
    pendingDeletes = [];
    $('#cfp-file-error').hide().text('');
    $('#fDistCalc').val('');

    // Reset cascade dropdowns
    var deptSelect = document.getElementById('fDept');
    Array.prototype.forEach.call(deptSelect.options, function(opt) {
        if (!opt.value) return;
        opt.style.display = 'none';
    });
    deptSelect.options[0].text = '— เลือกฝ่ายก่อน —';

    if (id === 0) {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-person-plus me-2"></i>เพิ่มพนักงาน';
        document.getElementById('fAction').value = 'create';
        document.getElementById('fID').value = '0';
        currentAssetID = 0;
        savedPreviewFiles = [];
        savedPreviewConfig = [];
        _initFileInput([], []);
    } else {
        var d = employeeData[id];
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขพนักงาน';
        document.getElementById('fAction').value = 'update';
        document.getElementById('fID').value = id;
        currentAssetID = id;

        document.getElementById('fCode').value = d.code || '';
        document.getElementById('fName').value = d.name || '';
        document.getElementById('fSite').value = d.site || '';
        document.getElementById('fDept').value = d.dept || '';
        document.getElementById('fPos').value = d.pos || '';
        document.getElementById('fCommuteType').value = d.commute || '';
        document.getElementById('fVType').value = d.vtype || '';
        document.getElementById('fDist').value = d.dist || '';
        document.getElementById('fWorkDays').value = d.workdays || '';
        document.getElementById('fEmail').value = d.email || '';
        document.getElementById('fPhone').value = d.phone || '';
        document.getElementById('fRemark').value = d.remark || '';

        // ตั้งค่า Company และ Division จาก Department
        if (d.dept) {
            var deptOpt = document.querySelector('#fDept option[value="' + d.dept + '"]');
            if (deptOpt) {
                var divID = deptOpt.getAttribute('data-division');
                if (divID) {
                    var divOpt = document.querySelector('#fDivision option[value="' + divID + '"]');
                    if (divOpt) {
                        var companyID = divOpt.getAttribute('data-company');
                        if (companyID) {
                            document.getElementById('fCompany').value = companyID;
                            filterDivisionByCompany();
                            document.getElementById('fDivision').value = divID;
                            filterDeptByDivision();
                            document.getElementById('fDept').value = d.dept;
                        }
                    }
                }
            }
        }

        loadPreviewFromDB(id, function(files, config) {
            savedPreviewFiles = files.slice();
            savedPreviewConfig = config.slice();
            _initFileInput(files, config);
        });
    }

    $('#btnSave').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>บันทึก');
    new bootstrap.Modal(document.getElementById('modalEmployee')).show();
}

// ============================================================
// DOMContentLoaded
// ============================================================
document.addEventListener('DOMContentLoaded', function() {

    function calcDist() {
        var dist = parseFloat($('#fDist').val()) || 0;
        var workdays = parseInt($('#fWorkDays').val()) || 0;
        if (dist > 0 && workdays > 0) {
            var total = (dist * 2 * workdays).toFixed(1);
            $('#fDistCalc').val(total);
        } else {
            $('#fDistCalc').val('');
        }
    }
    $('#fDist, #fWorkDays').on('input', calcDist);

    document.getElementById('btnSave').addEventListener('click', function() {
    var btn = this;
    var code = $('#fCode').val().trim();
    var name = $('#fName').val().trim();

    if (!code || !name) {
        Swal.fire({
            icon: 'warning', title: 'ข้อมูลไม่ครบ',
            text: 'กรุณากรอกรหัสพนักงานและชื่อ-นามสกุล',
            confirmButtonText: 'ตกลง', confirmButtonColor: '#2AABB8',
            customClass: { popup: 'font-prompt' }
        });
        return;
    }

    // ✅ ตรวจสอบขนาดไฟล์
    var totalCount = savedPreviewConfig.length + pendingFiles.length;
    if (totalCount > 10) {
        Swal.fire({
            icon: 'error', title: 'ไฟล์มากเกินไป',
            text: 'จำนวนไฟล์รวมกันต้องไม่เกิน 10 ไฟล์ (มีอยู่แล้ว ' + savedPreviewConfig.length + ' ไฟล์)',
            confirmButtonText: 'ตกลง', customClass: { popup: 'font-prompt' }
        });
        return;
    }

    var rawFiles = document.getElementById('file-1').files;
    var totalSize = 0;
    if (rawFiles) {
        for (var i = 0; i < rawFiles.length; i++) {
            totalSize += rawFiles[i].size;
        }
    }
    if (totalSize > 10 * 1024 * 1024) {
        Swal.fire({
            icon: 'error', title: 'ไฟล์รวมใหญ่เกินไป',
            text: 'ขนาดไฟล์รวมกันต้องไม่เกิน 10 MB',
            confirmButtonText: 'ตกลง', customClass: { popup: 'font-prompt' }
        });
        return;
    }

    var modalEl = document.getElementById('modalEmployee');
    if (modalEl._cfpSaving) return;
    modalEl._cfpSaving = true;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>กำลังบันทึก...';

    // ✅ ใช้ AJAX แทนการ Upload ของ Krajee
    var formData = new FormData(document.getElementById('formEmployee'));
    formData.append('csrf_token', csrfToken);
    formData.append('action', $('#fAction').val() || 'create');
    formData.append('id', $('#fID').val() || '0');
    formData.append('asset_type', 'Employee');
    formData.append('asset_id', currentAssetID);
    formData.append('pending_deletes', pendingDeletes.join(','));

    // ✅ เพิ่มไฟล์
    var fileInput = document.getElementById('file-1');
    if (fileInput.files) {
        for (var i = 0; i < fileInput.files.length; i++) {
            formData.append('file[]', fileInput.files[i]);
        }
    }

    $.ajax({
        url: 'employee_save.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            var modalEl = document.getElementById('modalEmployee');
            var bsModal = bootstrap.Modal.getInstance(modalEl);

            if (res.success) {
                if (bsModal) {
                    bsModal.hide();
                }
                Swal.fire({
                    icon: 'success',
                    title: 'บันทึกข้อมูลสำเร็จ!',
                    text: res.msg || '',
                    timer: 5000,
                    timerProgressBar: true,
                    showConfirmButton: true,
                    confirmButtonText: 'ตกลง',
                    confirmButtonColor: '#2AABB8',
                    allowOutsideClick: false,
                    customClass: { popup: 'font-prompt' }
                }).then(function() {
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error', title: 'เกิดข้อผิดพลาด',
                    text: res.msg || 'ไม่สามารถบันทึกข้อมูลได้',
                    confirmButtonText: 'ตกลง', confirmButtonColor: '#DC3545',
                    customClass: { popup: 'font-prompt' }
                });
            }
            modalEl._cfpSaving = false;
            $('#btnSave').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>บันทึก');
        },
        error: function(xhr, status, error) {
            Swal.fire({
                icon: 'error', title: 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์',
                text: error || 'กรุณาลองใหม่',
                confirmButtonText: 'ตกลง', customClass: { popup: 'font-prompt' }
            });
            document.getElementById('modalEmployee')._cfpSaving = false;
            $('#btnSave').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>บันทึก');
        }
    });
});

    // ===== DataTable =====
    var table = $('#tblEmployee').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order: [[1, 'asc']],
        pageLength: 25,
        dom: 'lrtip',
        searching: true
    });

    // ✅ ซ่อน Search Box ของ DataTable (ใช้ custom แทน)
    $('#tblEmployee_filter').hide();

    // ===== Custom Search =====
    $('#fltKeyword').on('keyup', function() {
        table.search(this.value).draw();
    });

    // ===== Custom Filter: ใช้ data-attributes (เฉพาะ Site + Commute) =====
    $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
        var row = table.row(dataIndex).node();
        var status = $('#fltStatus').val();
        var site = $('#fltSite').val();
        var commute = $('#fltCommute').val();

        if (status !== '' && $(row).attr('data-status') !== status) return false;
        if (site !== '' && $(row).attr('data-site') !== site) return false;
        if (commute !== '' && $(row).attr('data-commute') !== commute) return false;
        return true;
    });

    // ===== Event listeners =====
    $('#fltStatus, #fltSite, #fltCommute').on('change', function() {
        table.draw();
    });

    // ===== Clear Filters =====
    window.clearFilter = function() {
        $('#fltKeyword').val('');
        $('#fltStatus').val('');
        $('#fltSite').val('');
        $('#fltCommute').val('');
        table.search('').draw();
    };

    var tm = <?php echo json_encode($toastMsg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var te = <?php echo json_encode($toastType === 'error'); ?>;
    if (tm) { showToast(tm, te); }
});

// ============================================================
// Toast
// ============================================================
function showToast(msg, isErr) {
    var id = isErr ? 'toastError' : 'toastSuccess';
    var mid = isErr ? 'toastErrMsg' : 'toastMsg';
    document.getElementById(mid).textContent = msg;
    new bootstrap.Toast(document.getElementById(id), { delay: 3000 }).show();
}

// ============================================================
// Toggle
// ============================================================
function confirmToggle(id, isActive, name) {
    var action = isActive ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    Swal.fire({
        title: action + '?',
        html: '<b>' + name + '</b>',
        icon: isActive ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: action,
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: isActive ? '#DC3545' : '#4CAF50',
        customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (result.isConfirmed) {
            var f = document.createElement('form');
            f.method = 'POST';
            f.action = 'employee_save.php';
            f.innerHTML =
                '<input name="action" value="toggle">' +
                '<input name="id" value="' + id + '">' +
                '<input name="is_active" value="' + isActive + '">';
            document.body.appendChild(f);
            f.submit();
        }
    });
}
</script>
</body>
</html>
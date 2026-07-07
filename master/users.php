<?php
/* ==============================================
   master/users.php
   จัดการผู้ใช้งาน — เฉพาะ Admin (4) และ SustainAdmin (5)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

/* เฉพาะ Admin และ SustainAdmin เท่านั้น */
requireRole(array(4, 5));

$conn       = getConnection();
$myRoleID   = getActualRole();
$myUserID   = (int)$_SESSION['user_id'];
$isSustain  = ($myRoleID === 5);   /* SustainAdmin — จำกัดสิทธิ์ */
$isDevAdmin = ($myRoleID === 4);   /* DevAdmin — ทุกอย่าง */

/* Role ที่ SustainAdmin กำหนดได้ (ไม่รวม ADMIN=4) */
$allowedAssignRoles = array(1, 2, 3, 5, 6);

/* ===== ดึง Master Dropdown ===== */

/* บริษัท */
$resCo  = sqlsrv_query($conn, "SELECT CompanyID, CompanyName FROM CFP_Company WHERE IsActive=1 ORDER BY CompanyName");
$companies = array();
while ($r = sqlsrv_fetch_array($resCo, SQLSRV_FETCH_ASSOC)) {
    $companies[] = $r;
}

/* ฝ่าย */
$resDiv = sqlsrv_query($conn, "SELECT DivisionID, CompanyID, DivisionName FROM CFP_Division WHERE IsActive=1 ORDER BY DivisionName");
$divisions = array();
while ($r = sqlsrv_fetch_array($resDiv, SQLSRV_FETCH_ASSOC)) {
    $divisions[] = $r;
}

/* แผนก */
$resDept = sqlsrv_query($conn, "SELECT DeptID, DivisionID, DeptName FROM CFP_Department WHERE IsActive=1 ORDER BY DeptName");
$departments = array();
while ($r = sqlsrv_fetch_array($resDept, SQLSRV_FETCH_ASSOC)) {
    $departments[] = $r;
}

/* หน่วยงาน */
$resSec = sqlsrv_query($conn, "SELECT SectionID, DeptID, SectionName FROM CFP_Section WHERE IsActive=1 ORDER BY SectionName");
$sections = array();
while ($r = sqlsrv_fetch_array($resSec, SQLSRV_FETCH_ASSOC)) {
    $sections[] = $r;
}

/* ตำแหน่ง */
$resPos = sqlsrv_query($conn, "SELECT PositionID, CompanyID, PositionName FROM CFP_Position WHERE IsActive=1 ORDER BY PositionName");
$positions = array();
while ($r = sqlsrv_fetch_array($resPos, SQLSRV_FETCH_ASSOC)) {
    $positions[] = $r;
}

/* Role ทั้งหมด */
$resRole = sqlsrv_query($conn, "SELECT RoleID, RoleName, RoleNameEN, Description FROM CFP_Role WHERE IsActive=1 ORDER BY SortOrder");
$roles = array();
while ($r = sqlsrv_fetch_array($resRole, SQLSRV_FETCH_ASSOC)) {
    $roles[] = $r;
}

/* Site (สำหรับ DataEntry) */
$resSite = sqlsrv_query($conn, "SELECT SiteID, SiteCode, SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
$sites = array();
while ($r = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) {
    $sites[] = $r;
}

/* ===== ดึงรายชื่อ Users ===== */
$sqlUsers = "
    SELECT
        u.UserID, u.Username, u.FullName, u.Email,
        u.RoleID, r.RoleName, r.RoleNameEN,
        u.CompanyID,  co.CompanyName,
        u.DivisionID, dv.DivisionName,
        u.DeptID,     dp.DeptName,
        u.SectionID,  sc.SectionName,
        u.PositionID, po.PositionName,
        u.SiteID,     si.SiteName,
        u.IsActive, u.CreatedDate
    FROM CFP_User u
    LEFT JOIN CFP_Role       r  ON u.RoleID     = r.RoleID
    LEFT JOIN CFP_Company    co ON u.CompanyID   = co.CompanyID
    LEFT JOIN CFP_Division   dv ON u.DivisionID  = dv.DivisionID
    LEFT JOIN CFP_Department dp ON u.DeptID      = dp.DeptID
    LEFT JOIN CFP_Section    sc ON u.SectionID   = sc.SectionID
    LEFT JOIN CFP_Position   po ON u.PositionID  = po.PositionID
    LEFT JOIN CFP_Site       si ON u.SiteID      = si.SiteID
    ORDER BY u.IsActive DESC, u.FullName
";
$resUsers = sqlsrv_query($conn, $sqlUsers);
$users = array();
while ($row = sqlsrv_fetch_array($resUsers, SQLSRV_FETCH_ASSOC)) {
    $users[] = $row;
}

/* ===== KPI Summary ===== */
$total    = count($users);
$active   = count(array_filter($users, function($u) { return $u['IsActive']; }));
$inactive = $total - $active;
$noRole   = count(array_filter($users, function($u) { return empty($u['RoleID']); }));

/* ===== จำนวนตาม Role (สำหรับ Badge ใน Tab) ===== */
$dataEntryCount = count(array_filter($users, function($u) { return (int)$u['RoleID'] === 1; }));
$reviewerCount  = count(array_filter($users, function($u) { return (int)$u['RoleID'] === 2; }));

/* ===== ดึงข้อมูล UserScopeAccess ทั้งหมด ===== */
$scopeAccesses = array();
$resScopeAll = sqlsrv_query($conn,
    "SELECT a.AccessID, a.UserID, a.ScopeNo, a.CategoryIDs, a.SiteID, a.IsActive,
            s.SiteName
     FROM CFP_UserScopeAccess a
     LEFT JOIN CFP_Site s ON a.SiteID = s.SiteID
     ORDER BY a.UserID, a.ScopeNo"
);
if ($resScopeAll) {
    while ($r = sqlsrv_fetch_array($resScopeAll, SQLSRV_FETCH_ASSOC)) {
        $scopeAccesses[(int)$r['UserID']][] = $r;
    }
}

/* นับ DATA_ENTRY ที่ยังไม่มีสิทธิ์ Scope */
$dataEntryUsers = array_filter($users, function($u) { return (int)$u['RoleID'] === 1; });
$noScopeCount   = count(array_filter($dataEntryUsers, function($u) use ($scopeAccesses) {
    return empty($scopeAccesses[(int)$u['UserID']]);
}));

/* นับ REVIEWER ที่ยังไม่มี Site */
$reviewerUsers = array_filter($users, function($u) { return (int)$u['RoleID'] === 2; });
$noReviewerSite = count(array_filter($reviewerUsers, function($u) use ($scopeAccesses) {
    $uid = (int)$u['UserID'];
    if (empty($scopeAccesses[$uid])) { return true; }
    $hasSite = false;
    foreach ($scopeAccesses[$uid] as $sc) {
        if (!empty($sc['SiteID'])) { $hasSite = true; break; }
    }
    return !$hasSite;
}));

$scopeLabels = array(1 => 'Scope 1 — Direct', 2 => 'Scope 2 — Energy', 3 => 'Scope 3 — Indirect');
$scopeColors = array(1 => '#2AABB8',            2 => '#F59E0B',          3 => '#8B5CF6');

/* ===== Toast จาก redirect ===== */
$toastMsg  = '';
$toastType = '';
if (!empty($_SESSION['toast'])) {
    $toastMsg  = $_SESSION['toast']['msg'];
    $toastType = $_SESSION['toast']['type'];
    unset($_SESSION['toast']);
}

/* ===== Helper: Role Badge ===== */
function getRoleBadge($roleID, $roleName) {
    $map = array(
        1 => array('bg' => '#FFF3E0', 'color' => '#E65100'),
        2 => array('bg' => '#F3E5F5', 'color' => '#6A1B9A'),
        3 => array('bg' => '#E0F2F1', 'color' => '#00695C'),
        4 => array('bg' => '#E3F2FD', 'color' => '#1565C0'),
        5 => array('bg' => '#E8F5E9', 'color' => '#2E7D32'),
        6 => array('bg' => '#ECEFF1', 'color' => '#546E7A'),
    );
    $style = $map[(int)$roleID] ?? array('bg' => '#F5F5F5', 'color' => '#333');
    $name  = htmlspecialchars($roleName ?? 'ไม่ระบุ');
    return '<span class="badge-role" style="background:' . $style['bg'] . ';color:' . $style['color'] . ';">'
         . $name . '</span>';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>จัดการผู้ใช้งาน/สิทธิ์ — ระบบบริหารจัดการคาร์บอนองค์กร</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body { font-family: 'Prompt', sans-serif; }
    .badge-role {
      display: inline-block;
      padding: 2px 10px;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 600;
    }
    .kpi-icon-box {
      width: 40px; height: 40px;
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.2rem;
    }
    .nav-tabs .nav-link {
      font-family: 'Prompt', sans-serif;
      font-size: 0.88rem;
      color: var(--cfp-text-muted);
    }
    .nav-tabs .nav-link.active {
      color: var(--cfp-primary);
      font-weight: 600;
      border-bottom: 2px solid var(--cfp-green);
    }
    .table thead th {
      font-size: 0.8rem;
      white-space: nowrap;
    }
    .btn-action {
      width: 30px; height: 30px;
      padding: 0;
      display: inline-flex; align-items: center; justify-content: center;
      border-radius: 6px;
      font-size: 0.8rem;
    }
    .avatar-circle {
      width: 34px; height: 34px;
      border-radius: 50%;
      background: var(--cfp-primary-light);
      color: #fff;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 0.72rem;
      font-weight: 600;
      flex-shrink: 0;
    }
    .status-dot {
      width: 8px; height: 8px;
      border-radius: 50%;
      display: inline-block;
    }
    .modal-section-title {
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--cfp-primary);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      border-bottom: 1px solid var(--cfp-gray-border);
      padding-bottom: 4px;
      margin-bottom: 12px;
    }
    .font-prompt { font-family: 'Prompt', sans-serif !important; }

    /* Badge Style */
    .tab-badge {
      display: inline-block;
      font-size: 0.65rem;
      font-weight: 600;
      padding: 1px 7px;
      border-radius: 10px;
      background: #E9ECEF;
      color: #495057;
      line-height: 1.5;
      margin-left: 4px;
      vertical-align: middle;
    }
    .tab-badge.warning {
      background: #FEE2E2;
      color: #B91C1C;
    }
    .tab-badge.primary {
      background: #E3F2FD;
      color: #1565C0;
    }
    .tab-badge.success {
      background: #E8F5E9;
      color: #2E7D32;
    }
  </style>
</head>
<body>

<div class="d-flex">

  <?php include '../includes/sidebar.php'; ?>

  <div class="cfp-main">

    <?php
    $pageTitle = 'จัดการผู้ใช้งาน/สิทธิ์';
    $pageIcon  = 'people';
    include '../includes/topbar.php';
    ?>

    <div class="cfp-content">

    <!-- Page Header -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <div>
        <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);">
          <i class="bi bi-gear-wide-connected me-2" style="color:var(--cfp-green);"></i>จัดการผู้ใช้งาน/สิทธิ์
        </h5>
        <div style="font-size:0.78rem;color:var(--cfp-text-muted);margin-top:2px;">
          จัดการ › ผู้ใช้งาน/สิทธิ์
        </div>
      </div>
      
    </div>


      <!-- ===== KPI SUMMARY ===== -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#E3F2FD;">
              <i class="bi bi-people-fill" style="color:#1565C0;"></i>
            </div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:var(--cfp-primary);line-height:1.1;">
                <?php echo $total; ?>
              </div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ผู้ใช้ทั้งหมด</div>
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
            <div class="kpi-icon-box" style="background:#FFF8E1;">
              <i class="bi bi-exclamation-circle-fill" style="color:#F57F17;"></i>
            </div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:#F57F17;line-height:1.1;">
                <?php echo $noRole; ?>
              </div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ยังไม่กำหนด Role</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#F5F5F5;">
              <i class="bi bi-person-slash" style="color:#9E9E9E;"></i>
            </div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:#9E9E9E;line-height:1.1;">
                <?php echo $inactive; ?>
              </div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ปิดใช้งาน</div>
            </div>
          </div>
        </div>
      </div>
      <!-- END KPI -->

      <!-- ===== TABS (พร้อม Badge แสดงจำนวน) ===== -->
      <div class="cfp-card">
        <ul class="nav nav-tabs mb-3" id="userTabs">
          <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabUsers">
              <i class="bi bi-people me-1"></i>ผู้ใช้งาน
              <span class="tab-badge"><?php echo $total; ?></span>
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabScope">
              <i class="bi bi-shield-shaded me-1"></i>สิทธิ์ Data Entry (Scope) 
              <span class="tab-badge <?php echo $noScopeCount > 0 ? 'warning' : 'primary'; ?>">
                <?php echo $dataEntryCount; ?>
                <?php if ($noScopeCount > 0) { ?>
                <span style="font-size:0.6rem;opacity:0.7;">(ขาด <?php echo $noScopeCount; ?>)</span>
                <?php } ?>
              </span>
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabReviewer">
              <i class="bi bi-clipboard-check me-1"></i>สิทธิ์ Reviewer
              <span class="tab-badge <?php echo $noReviewerSite > 0 ? 'warning' : 'primary'; ?>">
                <?php echo $reviewerCount; ?>
                <?php if ($noReviewerSite > 0) { ?>
                <span style="font-size:0.6rem;opacity:0.7;">(ขาด <?php echo $noReviewerSite; ?>)</span>
                <?php } ?>
              </span>
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabRoleGuide">
              <i class="bi bi-info-circle me-1"></i>สิทธิ์แต่ละ Role
            </button>
          </li>
        </ul>

        <div class="tab-content">

          <!-- ===== TAB: ผู้ใช้งาน ===== -->
          <div class="tab-pane fade show active" id="tabUsers">

            <!-- Filter Row -->
            <div class="row g-2 mb-3 align-items-end">
              <div class="col-6 col-md-2">
                <label class="form-label mb-1" style="font-size:0.78rem;">บริษัท</label>
                <select id="filterCompany" class="form-select form-select-sm" style="font-family:'Prompt',sans-serif;">
                  <option value="">ทั้งหมด</option>
                  <?php foreach ($companies as $c) { ?>
                  <option value="<?php echo $c['CompanyID']; ?>">
                    <?php echo htmlspecialchars($c['CompanyName']); ?>
                  </option>
                  <?php } ?>
                </select>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label mb-1" style="font-size:0.78rem;">ฝ่าย</label>
                <select id="filterDivision" class="form-select form-select-sm" style="font-family:'Prompt',sans-serif;">
                  <option value="">ทั้งหมด</option>
                  <?php foreach ($divisions as $d) { ?>
                  <option value="<?php echo $d['DivisionID']; ?>"
                          data-company="<?php echo $d['CompanyID']; ?>">
                    <?php echo htmlspecialchars($d['DivisionName']); ?>
                  </option>
                  <?php } ?>
                </select>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label mb-1" style="font-size:0.78rem;">Role</label>
                <select id="filterRole" class="form-select form-select-sm" style="font-family:'Prompt',sans-serif;">
                  <option value="">ทั้งหมด</option>
                  <?php foreach ($roles as $ro) { ?>
                  <option value="<?php echo $ro['RoleID']; ?>">
                    <?php echo htmlspecialchars($ro['RoleName']); ?>
                  </option>
                  <?php } ?>
                </select>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label mb-1" style="font-size:0.78rem;">สถานะ</label>
                <select id="filterStatus" class="form-select form-select-sm" style="font-family:'Prompt',sans-serif;">
                  <option value="">ทั้งหมด</option>
                  <option value="1">ใช้งาน</option>
                  <option value="0">ปิดใช้งาน</option>
                </select>
              </div>
              <div class="col-12 col-md-4 d-flex gap-2 justify-content-md-end">
                <button class="btn btn-outline-secondary btn-sm btn-sm w-25" onclick="clearFilters()">
                  <i class="bi bi-x-circle me-1"></i>ล้าง
                </button>
                <button class="btn-cfp-add btn-sm" onclick="openModalUser(0)">
                  <i class="bi bi-plus-circle me-1"></i>เพิ่มผู้ใช้
                </button>
              </div>
            </div>

            <!-- Table -->
            <div class="table-responsive">
              <table id="tblUsers" class="table table-bordered table-hover align-middle" style="width:100%;">
                <thead>
                  <tr>
                    <th style="width:40px;">#</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>ฝ่าย / แผนก</th>
                    <th>ตำแหน่ง</th>
                    <th class="text-center">Role</th>
                    <th class="text-center" style="width:80px;">สถานะ</th>
                    <th class="text-center" style="width:105px;">จัดการ</th>
                    <!-- hidden columns สำหรับ filter -->
                    <th class="d-none">company_id</th>
                    <th class="d-none">division_id</th>
                    <th class="d-none">role_id</th>
                    <th class="d-none">is_active</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($users as $i => $u) {
                    $initials = '';
                    $nameParts = explode(' ', trim($u['FullName'] ?? ''));
                    foreach ($nameParts as $p) {
                        if ($initials !== '' && strlen($initials) >= 2) { break; }
                        $initials .= mb_substr($p, 0, 1);
                    }
                    $isActive = (bool)$u['IsActive'];
                    /* DevAdmin ดู SustainAdmin ได้ แต่ SustainAdmin ดูได้ยกเว้น DevAdmin/SustainAdmin */
                    $canEdit  = $isDevAdmin || ($isSustain && !in_array((int)$u['RoleID'], array(4, 5)));
                    $canToggle = $canEdit && ($u['UserID'] !== $myUserID);
                  ?>
                  <tr data-company="<?php echo (int)$u['CompanyID']; ?>"
                      data-division="<?php echo (int)$u['DivisionID']; ?>"
                      data-role="<?php echo (int)$u['RoleID']; ?>"
                      data-active="<?php echo $isActive ? '1' : '0'; ?>">
                    <td><?php echo $i + 1; ?></td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <div class="avatar-circle"><?php echo htmlspecialchars($initials); ?></div>
                        <div>
                          <div style="font-weight:500;font-size:0.88rem;">
                            <?php echo htmlspecialchars($u['FullName']); ?>
                          </div>
                          <div style="font-size:0.75rem;color:var(--cfp-text-muted);">
                            <?php echo htmlspecialchars($u['Email'] ?? ''); ?>
                          </div>
                        </div>
                      </div>
                    </td>
                    <td style="font-size:0.82rem;">
                      <?php echo htmlspecialchars($u['DivisionName'] ?? '—'); ?>
                      <?php if (!empty($u['DeptName'])) { ?>
                        <span style="color:var(--cfp-text-muted);"> / <?php echo htmlspecialchars($u['DeptName']); ?></span>
                      <?php } ?>
                    </td>
                    <td style="font-size:0.82rem;">
                      <?php echo htmlspecialchars($u['PositionName'] ?? '—'); ?>
                    </td>
                    <td class="text-center">
                      <?php echo getRoleBadge($u['RoleID'], $u['RoleNameEN'] ?? $u['RoleName']); ?>
                    </td>
                    <td class="text-center">
                      <?php if ($isActive) { ?>
                        <span class="status-dot" style="background:#4CAF50;"></span>
                        <span style="font-size:0.78rem;color:#2E7D32;">ใช้งาน</span>
                      <?php } else { ?>
                        <span class="status-dot" style="background:#ccc;"></span>
                        <span style="font-size:0.78rem;color:#9E9E9E;">ปิด</span>
                      <?php } ?>
                    </td>
                    <td class="text-center" style="white-space:nowrap;">
                      <?php if ($canEdit) { ?>
                      <button class="btn btn-outline-primary btn-action me-1"
                              title="แก้ไข / กำหนด Role"
                              onclick="openModalUser(<?php echo $u['UserID']; ?>)">
                        <i class="bi bi-pencil-square"></i>
                      </button>
                      <button class="btn btn-outline-secondary btn-action me-1"
                              title="คัดลอกผู้ใช้งาน"
                              onclick="copyUser(<?php echo $u['UserID']; ?>)">
                        <i class="bi bi-copy"></i>
                      </button>
                      <?php } ?>
                      <?php if ($canToggle) { ?>
                      <button class="btn btn-action <?php echo $isActive ? 'btn-outline-danger' : 'btn-outline-success'; ?>"
                              title="<?php echo $isActive ? 'ปิดใช้งาน' : 'เปิดใช้งาน'; ?>"
                              onclick="confirmToggle(<?php echo $u['UserID']; ?>, <?php echo $isActive ? 1 : 0; ?>, '<?php echo htmlspecialchars(addslashes($u['FullName'])); ?>')">
                        <i class="bi bi-<?php echo $isActive ? 'person-slash' : 'person-check'; ?>"></i>
                      </button>
                      <?php } ?>
                    </td>
                    <!-- hidden filter data -->
                    <td class="d-none"><?php echo (int)$u['CompanyID']; ?></td>
                    <td class="d-none"><?php echo (int)$u['DivisionID']; ?></td>
                    <td class="d-none"><?php echo (int)$u['RoleID']; ?></td>
                    <td class="d-none"><?php echo $isActive ? '1' : '0'; ?></td>
                  </tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>
          </div>
          <!-- END TAB ผู้ใช้งาน -->

          <!-- ===== TAB: กำหนดสิทธิ์ Scope ===== -->
          <div class="tab-pane fade" id="tabScope">

            <div class="alert alert-light border mb-3 py-2" style="font-size:0.8rem;">
              <i class="bi bi-info-circle text-info me-1"></i>
              กำหนด <strong>Scope, หมวดหมู่ และ Site</strong> ที่ผู้บันทึกข้อมูลแต่ละคนสามารถกรอกข้อมูลได้
              <span style="display:inline-block;background:#FEF3C7;color:#B45309;border-radius:4px;font-size:0.7rem;font-weight:600;padding:1px 8px;margin-left:6px;">
                <?php echo $dataEntryCount; ?> คน
                <?php if ($noScopeCount > 0) { ?>
                <span style="color:#DC2626;">(ขาด <?php echo $noScopeCount; ?> คน)</span>
                <?php } ?>
              </span>
            </div>

            <div class="table-responsive">
              <table id="tblScope" class="table table-bordered table-hover align-middle" style="width:100%;font-size:0.85rem;">
                <thead>
                  <tr>
                    <th style="width:40px;">#</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>ฝ่าย / แผนก</th>
                    <th class="text-center" style="width:90px;">Scope 1</th>
                    <th class="text-center" style="width:90px;">Scope 2</th>
                    <th class="text-center" style="width:90px;">Scope 3</th>
                    <th class="text-center" style="width:80px;">จัดการ</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $si = 1;
                  foreach ($users as $u) {
                      if ((int)$u['RoleID'] !== 1) { continue; }
                      $uid     = (int)$u['UserID'];
                      $uScopes = $scopeAccesses[$uid] ?? array();
                      $scopeMap = array();
                      foreach ($uScopes as $sc) {
                          $scopeMap[(int)$sc['ScopeNo']] = $sc;
                      }
                  ?>
                  <tr>
                    <td><?php echo $si++; ?></td>
                    <td>
                      <div style="font-weight:500;"><?php echo htmlspecialchars($u['FullName']); ?></div>
                      <div style="font-size:0.72rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($u['Username']); ?></div>
                    </td>
                    <td style="font-size:0.8rem;">
                      <?php echo htmlspecialchars($u['DivisionName'] ?? '—'); ?>
                      <?php if (!empty($u['DeptName'])) { ?>
                      / <?php echo htmlspecialchars($u['DeptName']); ?>
                      <?php } ?>
                    </td>
                    <?php foreach (array(1,2,3) as $sNo) { ?>
                    <td class="text-center">
                      <?php if (!empty($scopeMap[$sNo]) && $scopeMap[$sNo]['IsActive']) { ?>
                        <i class="bi bi-check-circle-fill" style="color:<?php echo $scopeColors[$sNo]; ?>;font-size:1rem;"
                           title="มีสิทธิ์<?php echo $scopeMap[$sNo]['CategoryIDs'] ? ' Cat:'.$scopeMap[$sNo]['CategoryIDs'] : ''; ?>"></i>
                      <?php } else { ?>
                        <i class="bi bi-dash-circle" style="color:#ccc;font-size:1rem;"></i>
                      <?php } ?>
                    </td>
                    <?php } ?>
                    <td class="text-center">
                      <button class="btn btn-outline-primary btn-action"
                              title="กำหนดสิทธิ์ Scope"
                              onclick="openScopeModal(<?php echo $uid; ?>, '<?php echo htmlspecialchars(addslashes($u['FullName'])); ?>')">
                        <i class="bi bi-shield-check"></i>
                      </button>
                    </td>
                  </tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>
          </div>
          <!-- END TAB SCOPE -->

          <!-- ===== TAB: สิทธิ์ Reviewer ===== -->
          <div class="tab-pane fade" id="tabReviewer">

            <div class="alert alert-light border mb-3 py-2" style="font-size:0.8rem;">
              <i class="bi bi-info-circle text-info me-1"></i>
              กำหนด <strong>Site (โรงงาน)</strong> ที่ Reviewer แต่ละคนรับผิดชอบตรวจสอบ
              — ถ้าไม่กำหนด Reviewer จะไม่เห็นข้อมูลใดเลย
              <span style="display:inline-block;background:#FEF3C7;color:#B45309;border-radius:4px;font-size:0.7rem;font-weight:600;padding:1px 8px;margin-left:6px;">
                <?php echo $reviewerCount; ?> คน
                <?php if ($noReviewerSite > 0) { ?>
                <span style="color:#DC2626;">(ขาด <?php echo $noReviewerSite; ?> คน)</span>
                <?php } ?>
              </span>
            </div>

            <div class="table-responsive">
              <table id="tblReviewer" class="table table-bordered table-hover align-middle" style="width:100%;font-size:0.85rem;">
                <thead>
                  <tr>
                    <th style="width:40px;">#</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>ฝ่าย / แผนก</th>
                    <th>Site ที่รับผิดชอบ</th>
                    <th style="width:80px;" class="text-center">จัดการ</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $ri = 1;
                  foreach ($users as $u) {
                      if ((int)$u['RoleID'] !== 2) { continue; }
                      $uid = (int)$u['UserID'];
                      $uAccesses = $scopeAccesses[$uid] ?? array();
                      /* เก็บ SiteID ที่ unique จากทุก scope row */
                      $revSiteIDs = array();
                      foreach ($uAccesses as $ac) {
                          if (!empty($ac['SiteID'])) { $revSiteIDs[] = (int)$ac['SiteID']; }
                      }
                      $revSiteIDs = array_unique($revSiteIDs);
                  ?>
                  <tr>
                    <td><?php echo $ri++; ?></td>
                    <td>
                      <div style="font-weight:500;"><?php echo htmlspecialchars($u['FullName']); ?></div>
                      <div style="font-size:0.72rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($u['Username']); ?></div>
                    </td>
                    <td style="font-size:0.8rem;">
                      <?php echo htmlspecialchars($u['DivisionName'] ?? '—'); ?>
                      <?php if (!empty($u['DeptName'])) { ?>
                      <div style="font-size:0.72rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($u['DeptName']); ?></div>
                      <?php } ?>
                    </td>
                    <td>
                      <?php if (!empty($revSiteIDs)) { ?>
                      <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($sites as $s) {
                          if (!in_array((int)$s['SiteID'], $revSiteIDs)) { continue; } ?>
                        <span style="display:inline-block;background:#E0F4F7;color:#1B7A8A;border:1px solid #A8D8DF;
                                     border-radius:4px;font-size:0.72rem;font-weight:600;padding:1px 8px;"
                              title="<?php echo htmlspecialchars($s['SiteName']); ?>">
                          <?php echo htmlspecialchars($s['SiteCode']); ?>
                        </span>
                        <?php } ?>
                      </div>
                      <?php } else { ?>
                      <span style="display:inline-block;background:#FEE2E2;color:#B91C1C;border:1px solid #FCA5A5;
                                   border-radius:4px;font-size:0.72rem;font-weight:600;padding:1px 8px;">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>ยังไม่ได้กำหนด
                      </span>
                      <?php } ?>
                    </td>
                    <td class="text-center">
                      <button class="btn btn-outline-primary btn-action"
                              title="กำหนด Site"
                              onclick="openReviewerSiteModal(<?php echo $uid; ?>, '<?php echo htmlspecialchars(addslashes($u['FullName'])); ?>', [<?php echo implode(',', $revSiteIDs); ?>])">
                        <i class="bi bi-geo-alt"></i>
                      </button>
                    </td>
                  </tr>
                  <?php } ?>
                  <?php if (empty($reviewerUsers)) { ?>
                  <tr><td colspan="5" class="text-center py-3" style="color:var(--cfp-text-muted);font-size:0.85rem;">
                    ยังไม่มีผู้ใช้ที่มี Role Reviewer ในระบบ
                  </td></tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>
          </div>
          <!-- END TAB REVIEWER -->

          <!-- ===== TAB: สิทธิ์แต่ละ Role บนหน้าบันทึกข้อมูล ===== -->
          <div class="tab-pane fade" id="tabRoleGuide">

            <div class="alert alert-light border mb-3 py-2" style="font-size:0.8rem;">
              <i class="bi bi-info-circle text-info me-1"></i>
              ตารางนี้แสดงสิทธิ์ของแต่ละ Role บนหน้าบันทึกข้อมูล Scope 1, 2, 3
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle" style="font-size:0.82rem;">
                <thead>
                  <tr style="background:var(--cfp-bg);">
                    <th style="width:180px;">Role</th>
                    <th class="text-center">เห็นรายการ</th>
                    <th class="text-center">กรอกปริมาณ</th>
                    <th class="text-center">ส่งอนุมัติ</th>
                    <th>ขอบเขต</th>
                    <th>หน้าที่</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  /* ดึง Description จาก roles ที่โหลดไว้แล้ว */
                  $roleDescMap = array();
                  foreach ($roles as $ro) {
                      $roleDescMap[(int)$ro['RoleID']] = $ro['Description'] ?? '';
                  }
                  $chkY  = '<i class="bi bi-check-circle-fill text-success"></i>';
                  $chkN  = '<i class="bi bi-x-circle-fill" style="color:#ccc;"></i>';
                  $roleRows = array(
                      array(
                          'id'    => 1,
                          'label' => '1 — Data Entry',
                          'bg'    => '#FFF3E0', 'color' => '#E65100', 'border' => '#FED7AA',
                          'see'   => 'เฉพาะที่ได้รับสิทธิ์',
                          'fill'  => true, 'submit' => true,
                          'scope' => 'กรองตาม Scope, หมวดหมู่ และ Site ที่ได้รับมอบหมาย',
                      ),
                      array(
                          'id'    => 2,
                          'label' => '2 — Reviewer',
                          'bg'    => '#F3E5F5', 'color' => '#6A1B9A', 'border' => '#E9D5FF',
                          'see'   => 'เฉพาะ Site ที่รับผิดชอบ',
                          'fill'  => false, 'submit' => false,
                          'scope' => 'กรองตาม Site ที่กำหนดไว้ใน Tab "สิทธิ์ Reviewer"',
                      ),
                      array(
                          'id'    => 3,
                          'label' => '3 — Approver',
                          'bg'    => '#E0F2F1', 'color' => '#00695C', 'border' => '#99F6E4',
                          'see'   => 'ทุก Site / หมวดหมู่',
                          'fill'  => false, 'submit' => false,
                          'scope' => 'เห็นข้อมูลทุกโรงงาน (อ่านอย่างเดียว)',
                      ),
                      array(
                          'id'    => 4,
                          'label' => '4 — Admin',
                          'bg'    => '#E3F2FD', 'color' => '#1565C0', 'border' => '#BFDBFE',
                          'see'   => 'ทุก Site / หมวดหมู่',
                          'fill'  => true, 'submit' => true,
                          'scope' => 'เข้าถึงได้ทุกอย่างโดยไม่มีข้อจำกัด',
                      ),
                      array(
                          'id'    => 5,
                          'label' => '5 — Sustainability Admin',
                          'bg'    => '#E8F5E9', 'color' => '#2E7D32', 'border' => '#BBF7D0',
                          'see'   => 'ทุก Site / หมวดหมู่',
                          'fill'  => true, 'submit' => true,
                          'scope' => 'เข้าถึงได้ทุกอย่างโดยไม่มีข้อจำกัด',
                      ),
                      array(
                          'id'    => 6,
                          'label' => '6 — Viewer',
                          'bg'    => '#ECEFF1', 'color' => '#546E7A', 'border' => '#E2E8F0',
                          'see'   => 'ทุก Site / หมวดหมู่',
                          'fill'  => false, 'submit' => false,
                          'scope' => 'เห็นข้อมูลทุกโรงงาน (อ่านอย่างเดียว)',
                      ),
                  );
                  foreach ($roleRows as $rr) { ?>
                  <tr>
                    <td>
                      <span style="background:<?php echo $rr['bg']; ?>;color:<?php echo $rr['color']; ?>;
                                   border:1px solid <?php echo $rr['border']; ?>;
                                   padding:2px 9px;border-radius:9px;font-size:0.75rem;font-weight:600;display:inline-block;">
                        <?php echo $rr['label']; ?>
                      </span>
                    </td>
                    <td class="text-center"><?php echo $chkY; ?> <?php echo $rr['see']; ?></td>
                    <td class="text-center"><?php echo $rr['fill'] ? $chkY : $chkN; ?></td>
                    <td class="text-center"><?php echo $rr['submit'] ? $chkY : $chkN; ?></td>
                    <td style="font-size:0.78rem;color:var(--cfp-text-muted);"><?php echo $rr['scope']; ?></td>
                    <td style="font-size:0.78rem;"><?php echo htmlspecialchars($roleDescMap[$rr['id']] ?? ''); ?></td>
                  </tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>

          </div>
          <!-- END TAB ROLE GUIDE -->

        </div><!-- end tab-content -->
      </div><!-- end cfp-card -->

    </div><!-- end cfp-content -->
  </div><!-- end cfp-main -->
</div>

<!-- ===== MODAL: เพิ่ม / แก้ไข User ===== -->
<div class="modal fade" id="modalUser" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--cfp-primary-light);color:#fff;padding:0.75rem 1.25rem;">
        <h6 class="modal-title mb-0" id="modalUserTitle">
          <i class="bi bi-person-plus me-2"></i>เพิ่มผู้ใช้งานใหม่
        </h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formUser" method="POST" action="users_save.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" id="formAction" value="create">
        <input type="hidden" name="user_id" id="formUserID" value="0">

        <div class="modal-body" style="max-height:70vh;overflow-y:auto;">

          <!-- ข้อมูลบัญชี -->
          <div class="modal-section-title"><i class="bi bi-shield-lock me-1"></i>ข้อมูลบัญชี</div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label form-required">ชื่อผู้ใช้ (Username)</label>
              <input type="text" class="form-control" name="username" id="fUsername"
                     placeholder="ตัวอักษร/ตัวเลข ไม่มีช่องว่าง" autocomplete="off" required
                     style="font-family:'Prompt',sans-serif;">
            </div>
            <div class="col-md-6">
              <label class="form-label form-required">ชื่อ-นามสกุล</label>
              <input type="text" class="form-control" name="fullname" id="fFullname"
                     placeholder="ชื่อ นามสกุล" required
                     style="font-family:'Prompt',sans-serif;">
            </div>
            <div class="col-md-6">
              <label class="form-label">อีเมล</label>
              <input type="email" class="form-control" name="email" id="fEmail"
                     placeholder="email@company.th"
                     style="font-family:'Prompt',sans-serif;">
            </div>
            <div class="col-md-6">
              <label class="form-label" id="labelPassword">
                รหัสผ่าน <span class="text-danger">*</span>
              </label>
              <div class="input-group">
                <input type="password" class="form-control" name="password" id="fPassword"
                       placeholder="อย่างน้อย 8 ตัวอักษร"
                       style="font-family:'Prompt',sans-serif;">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        onclick="togglePassword()">
                  <i class="bi bi-eye" id="eyeIcon"></i>
                </button>
              </div>
              <div id="hintPassword" class="form-text" style="display:none;font-size:0.75rem;">
                ปล่อยว่างถ้าไม่ต้องการเปลี่ยนรหัสผ่าน
              </div>
            </div>
          </div>

          <!-- ข้อมูลองค์กร -->
          <div class="modal-section-title"><i class="bi bi-diagram-3 me-1"></i>ข้อมูลองค์กร</div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">บริษัท</label>
              <select class="form-select" name="company_id" id="fCompanyID"
                      style="font-family:'Prompt',sans-serif;" onchange="cascadeOrg('company')">
                <option value="">— เลือกบริษัท —</option>
                <?php foreach ($companies as $c) { ?>
                <option value="<?php echo $c['CompanyID']; ?>">
                  <?php echo htmlspecialchars($c['CompanyName']); ?>
                </option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">ฝ่าย</label>
              <select class="form-select" name="division_id" id="fDivisionID"
                      style="font-family:'Prompt',sans-serif;" onchange="cascadeOrg('division')">
                <option value="">— เลือกฝ่าย —</option>
                <?php foreach ($divisions as $d) { ?>
                <option value="<?php echo $d['DivisionID']; ?>"
                        data-company="<?php echo $d['CompanyID']; ?>">
                  <?php echo htmlspecialchars($d['DivisionName']); ?>
                </option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">แผนก</label>
              <select class="form-select" name="dept_id" id="fDeptID"
                      style="font-family:'Prompt',sans-serif;" onchange="cascadeOrg('dept')">
                <option value="">— เลือกแผนก —</option>
                <?php foreach ($departments as $d) { ?>
                <option value="<?php echo $d['DeptID']; ?>"
                        data-division="<?php echo $d['DivisionID']; ?>">
                  <?php echo htmlspecialchars($d['DeptName']); ?>
                </option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">หน่วยงาน <span style="font-size:0.72rem;color:var(--cfp-text-muted);">(เฉพาะโรงงาน)</span></label>
              <select class="form-select" name="section_id" id="fSectionID"
                      style="font-family:'Prompt',sans-serif;">
                <option value="">— เลือกหน่วยงาน —</option>
                <?php foreach ($sections as $s) { ?>
                <option value="<?php echo $s['SectionID']; ?>"
                        data-dept="<?php echo $s['DeptID']; ?>">
                  <?php echo htmlspecialchars($s['SectionName']); ?>
                </option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">ตำแหน่ง</label>
              <select class="form-select" name="position_id" id="fPositionID"
                      style="font-family:'Prompt',sans-serif;">
                <option value="">— เลือกตำแหน่ง —</option>
                <?php foreach ($positions as $p) { ?>
                <option value="<?php echo $p['PositionID']; ?>"
                        data-company="<?php echo $p['CompanyID']; ?>">
                  <?php echo htmlspecialchars($p['PositionName']); ?>
                </option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Site (Carbon Data)</label>
              <select class="form-select" name="site_id" id="fSiteID"
                      style="font-family:'Prompt',sans-serif;">
                <option value="">— เลือก Site —</option>
                <?php foreach ($sites as $s) { ?>
                <option value="<?php echo $s['SiteID']; ?>">
                  <?php echo htmlspecialchars($s['SiteName']); ?>
                </option>
                <?php } ?>
              </select>
            </div>
          </div>

          <!-- กำหนด Role -->
          <div class="modal-section-title"><i class="bi bi-shield-check me-1"></i>กำหนด Role & สิทธิ์</div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label form-required">Role</label>
              <select class="form-select" name="role_id" id="fRoleID" required
                      style="font-family:'Prompt',sans-serif;">
                <option value="">— เลือก Role —</option>
                <?php foreach ($roles as $ro) {
                  /* SustainAdmin กำหนดได้: Role 1,2,3,5,6 — ไม่รวม ADMIN=4 */
                  if ($isSustain && !in_array((int)$ro['RoleID'], $allowedAssignRoles)) { continue; }
                ?>
                <option value="<?php echo $ro['RoleID']; ?>">
                  <?php echo htmlspecialchars($ro['RoleName']); ?>
                  <?php if (!empty($ro['RoleNameEN'])) { ?>— <?php echo htmlspecialchars($ro['RoleNameEN']); ?><?php } ?>
                </option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <div class="alert alert-light border mb-0 w-100 py-2" style="font-size:0.78rem;">
                <i class="bi bi-info-circle text-info me-1"></i>
                <?php if ($isSustain) { ?>
                กำหนด Role ได้: DataEntry, Reviewer, Approver, SustainAdmin, Viewer
                <?php } else { ?>
                Admin กำหนด Role ได้ทุกระดับ
                <?php } ?>
              </div>
            </div>
          </div>

        </div><!-- end modal-body -->

        <div class="modal-footer" style="background:#F9FAFB;">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
            <i class="bi bi-x-circle me-1"></i>ยกเลิก
          </button>
          <button type="submit" class="btn-cfp-add">
            <i class="bi bi-check-circle me-1"></i>บันทึก
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<!-- END MODAL -->

<!-- ===== MODAL: กำหนดสิทธิ์ Scope ===== -->
<?php
/* Category Scope 3 ทั้ง 15 ตัว */
$scope3Categories = array(
    1  => 'Cat.1 — สินค้าและบริการที่จัดซื้อ (Purchased goods & services)',
    2  => 'Cat.2 — สินทรัพย์ทุน (Capital goods)',
    3  => 'Cat.3 — เชื้อเพลิงและพลังงาน (Fuel & energy related)',
    4  => 'Cat.4 — การขนส่งต้นน้ำ (Upstream transportation)',
    5  => 'Cat.5 — ของเสียจากการดำเนินงาน (Waste generated in operations)',
    6  => 'Cat.6 — การเดินทางเพื่อธุรกิจ (Business travel)',
    7  => 'Cat.7 — การเดินทางของพนักงาน (Employee commuting)',
    8  => 'Cat.8 — สินทรัพย์เช่าต้นน้ำ (Upstream leased assets)',
    9  => 'Cat.9 — การขนส่งปลายน้ำ (Downstream transportation)',
    10 => 'Cat.10 — การแปรรูปผลิตภัณฑ์ที่ขาย (Processing of sold products)',
    11 => 'Cat.11 — การใช้ผลิตภัณฑ์ที่ขาย (Use of sold products)',
    12 => 'Cat.12 — การกำจัดผลิตภัณฑ์หลังใช้งาน (End-of-life treatment)',
    13 => 'Cat.13 — สินทรัพย์เช่าปลายน้ำ (Downstream leased assets)',
    14 => 'Cat.14 — แฟรนไชส์ (Franchises)',
    15 => 'Cat.15 — การลงทุน (Investments)',
);
?>
<div class="modal fade" id="modalScope" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff;padding:0.75rem 1.25rem;">
        <h6 class="modal-title mb-0">
          <i class="bi bi-shield-shaded me-2"></i>กำหนดสิทธิ์ Scope
        </h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="max-height:75vh;overflow-y:auto;">

        <div class="mb-3 p-2 rounded" style="background:#EEF6F8;font-size:0.83rem;">
          <i class="bi bi-person-fill me-1" style="color:var(--cfp-primary);"></i>
          <span id="scopeModalName" style="font-weight:600;"></span>
        </div>

        <?php foreach (array(1,2,3) as $sNo) { ?>
        <div class="cfp-card mb-2 p-3">
          <!-- หัว Scope -->
          <div class="d-flex align-items-center gap-2 mb-0">
            <input class="form-check-input mt-0 scope-check" type="checkbox"
                   id="chkScope<?php echo $sNo; ?>"
                   value="<?php echo $sNo; ?>"
                   onchange="toggleScopeDetail(<?php echo $sNo; ?>)">
            <label for="chkScope<?php echo $sNo; ?>"
                   style="color:<?php echo $scopeColors[$sNo]; ?>;font-weight:600;font-size:0.9rem;cursor:pointer;">
              <?php echo $scopeLabels[$sNo]; ?>
            </label>
          </div>

          <!-- รายละเอียด (แสดงเมื่อ check) -->
          <div id="scopeDetail<?php echo $sNo; ?>" style="display:none;margin-top:12px;padding-top:10px;border-top:1px solid var(--cfp-border);">

            <?php if ($sNo === 3) { ?>
            <!-- Scope 3: แสดง checkbox 15 Category -->
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div style="font-size:0.78rem;color:var(--cfp-text-mid);font-weight:600;">
                เลือก Category <span style="font-weight:400;">(ไม่เลือก = ทุก Category)</span>
              </div>
              <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                        style="font-size:0.72rem;font-family:'Prompt',sans-serif;"
                        onclick="selectAllCat(true)">เลือกทั้งหมด</button>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                        style="font-size:0.72rem;font-family:'Prompt',sans-serif;"
                        onclick="selectAllCat(false)">ล้างทั้งหมด</button>
              </div>
            </div>
            <div class="row g-1" id="catCheckboxes">
              <?php foreach ($scope3Categories as $catNo => $catLabel) { ?>
              <div class="col-6">
                <div class="form-check mb-0" style="font-size:0.78rem;">
                  <input class="form-check-input cat3-check" type="checkbox"
                         id="cat3_<?php echo $catNo; ?>"
                         value="<?php echo $catNo; ?>">
                  <label class="form-check-label" for="cat3_<?php echo $catNo; ?>"
                         style="font-family:'Prompt',sans-serif;cursor:pointer;">
                    <?php echo htmlspecialchars($catLabel); ?>
                  </label>
                </div>
              </div>
              <?php } ?>
            </div>
            <?php } ?>

            <!-- Site (ทุก Scope) -->
            <div style="font-size:0.78rem;color:var(--cfp-text-mid);margin:10px 0 4px;">Site</div>
            <select class="form-select form-select-sm" id="siteScope<?php echo $sNo; ?>"
                    style="font-family:'Prompt',sans-serif;">
              <option value="">— ทุก Site —</option>
              <?php foreach ($sites as $s) { ?>
              <option value="<?php echo $s['SiteID']; ?>">
                <?php echo htmlspecialchars($s['SiteName']); ?>
              </option>
              <?php } ?>
            </select>

          </div>
        </div>
        <?php } ?>

      </div>
      <div class="modal-footer" style="background:#F9FAFB;">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
          <i class="bi bi-x-circle me-1"></i>ยกเลิก
        </button>
        <button type="button" class="btn-cfp-add" onclick="saveScopeAccess()">
          <i class="bi bi-check-circle me-1"></i>บันทึกสิทธิ์
        </button>
      </div>
    </div>
  </div>
</div>
<!-- ===== MODAL: กำหนด Site สำหรับ Reviewer ===== -->
<div class="modal fade" id="modalReviewerSite" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content font-prompt">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff;padding:0.75rem 1.25rem;">
        <h6 class="modal-title mb-0">
          <i class="bi bi-geo-alt-fill me-2"></i>กำหนด Site ที่รับผิดชอบ
        </h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3 p-2 rounded" style="background:#EEF6F8;font-size:0.83rem;">
          <i class="bi bi-person-fill me-1" style="color:var(--cfp-primary);"></i>
          <span id="reviewerSiteModalName" style="font-weight:600;"></span>
        </div>
        <div style="font-size:0.78rem;color:var(--cfp-text-muted);margin-bottom:8px;">
          เลือก Site ที่ Reviewer คนนี้รับผิดชอบตรวจสอบ
        </div>
        <div class="d-flex gap-2 mb-2">
          <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                  style="font-size:0.72rem;" onclick="selectAllRevSite(true)">เลือกทั้งหมด</button>
          <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                  style="font-size:0.72rem;" onclick="selectAllRevSite(false)">ล้างทั้งหมด</button>
        </div>
        <div id="reviewerSiteList">
          <?php foreach ($sites as $s) { ?>
          <div class="form-check mb-1" style="font-size:0.85rem;">
            <input class="form-check-input rev-site-chk" type="checkbox"
                   id="revSiteChk<?php echo $s['SiteID']; ?>"
                   value="<?php echo $s['SiteID']; ?>">
            <label class="form-check-label" for="revSiteChk<?php echo $s['SiteID']; ?>">
              <span style="font-weight:500;"><?php echo htmlspecialchars($s['SiteName']); ?></span>
            </label>
          </div>
          <?php } ?>
          <?php if (empty($sites)) { ?>
          <div style="color:var(--cfp-text-muted);font-size:0.82rem;">ยังไม่มี Site ในระบบค่ะ</div>
          <?php } ?>
        </div>
      </div>
      <div class="modal-footer" style="background:#F9FAFB;">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn-cfp-add" onclick="saveReviewerSite()">
          <i class="bi bi-check-circle me-1"></i>บันทึก
        </button>
      </div>
    </div>
  </div>
</div>
<!-- END MODAL REVIEWER SITE -->

<!-- Hidden form save reviewer site -->
<form id="formReviewerSite" method="POST" action="users_scope_save.php" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="action"   value="save_reviewer_site">
  <input type="hidden" name="user_id"  id="fRevSiteUserID" value="0">
  <input type="hidden" name="site_ids" id="fRevSiteIDs"    value="">
</form>

<!-- END MODAL SCOPE -->

<!-- Toast -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:9999;">
  <div id="toastSuccess" class="toast align-items-center text-white border-0"
       style="background:#4CAF50;" role="alert">
    <div class="d-flex">
      <div class="toast-body d-flex align-items-center gap-2">
        <i class="bi bi-check-circle-fill"></i>
        <span id="toastMsg">บันทึกข้อมูลเรียบร้อย</span>
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
  <div id="toastError" class="toast align-items-center text-white border-0"
       style="background:#DC3545;" role="alert">
    <div class="d-flex">
      <div class="toast-body d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-circle-fill"></i>
        <span id="toastErrMsg">เกิดข้อผิดพลาด</span>
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<!-- ===== SCRIPTS ===== -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
/* ===== DataTable ===== */
var table;
var FILTER_KEY = 'cfp_users_filters';

$(document).ready(function() {
    /* ── กู้คืนค่า filter ที่เลือกไว้ก่อนหน้า (ก่อน save/reload) จาก sessionStorage ── */
    var savedFlt = JSON.parse(sessionStorage.getItem(FILTER_KEY) || '{}');
    if (savedFlt.company)  { $('#filterCompany').val(savedFlt.company); }
    if (savedFlt.division) { $('#filterDivision').val(savedFlt.division); }
    if (savedFlt.role)     { $('#filterRole').val(savedFlt.role); }
    if (savedFlt.status)   { $('#filterStatus').val(savedFlt.status); }

    table = $('#tblUsers').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order: [[1, 'asc']],
        pageLength: 25,
        columnDefs: [
            { orderable: false, targets: [6] },
            { visible: false, targets: [7,8,9,10] }
        ],
        dom: '<"row align-items-center mb-2"<"col-md-6"l><"col-md-6 text-end"f>>rtip',
        stateSave: true,       /* จำ pagination/คำค้นหา/ลำดับ ให้อัตโนมัติ (DataTables built-in) */
        stateDuration: 3600    /* จำไว้ 1 ชั่วโมง */
    });

    /* Custom Filter */
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        var coCf  = $('#filterCompany').val();
        var divCf = $('#filterDivision').val();
        var roleCf= $('#filterRole').val();
        var staCf = $('#filterStatus').val();
        var row   = table.row(dataIndex).node();
        var co  = $(row).data('company')  + '';
        var dv  = $(row).data('division') + '';
        var rl  = $(row).data('role')     + '';
        var ac  = $(row).data('active')   + '';
        if (coCf  && co  !== coCf)  { return false; }
        if (divCf && dv  !== divCf) { return false; }
        if (roleCf && rl !== roleCf) { return false; }
        if (staCf && ac  !== staCf) { return false; }
        return true;
    });

    function saveFilterState() {
        sessionStorage.setItem(FILTER_KEY, JSON.stringify({
            company:  $('#filterCompany').val(),
            division: $('#filterDivision').val(),
            role:     $('#filterRole').val(),
            status:   $('#filterStatus').val()
        }));
    }

    $('#filterCompany, #filterDivision, #filterRole, #filterStatus').on('change', function() {
        /* cascade ฝ่ายตาม บริษัท */
        var coCf = $('#filterCompany').val();
        $('#filterDivision option').each(function() {
            var opt = $(this);
            if (!opt.val()) { opt.show(); return; }
            if (coCf && opt.data('company') + '' !== coCf) {
                opt.hide();
            } else {
                opt.show();
            }
        });
        saveFilterState();
        table.draw();
    });

    /* re-apply ค่า filter ที่กู้คืนมา (ต้อง draw หลัง DataTable พร้อมแล้ว) */
    if (savedFlt.company || savedFlt.division || savedFlt.role || savedFlt.status) {
        table.draw();
    }

    /* DataTable Scope Tab */
    $('#tblScope').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order: [[1, 'asc']],
        pageLength: 25,
        columnDefs: [{ orderable: false, targets: [3,4,5,6] }]
    });
});

function clearFilters() {
    $('#filterCompany, #filterDivision, #filterRole, #filterStatus').val('');
    sessionStorage.removeItem(FILTER_KEY);
    table.draw();
}

/* ===== Toast ===== */
function showToast(msg, isError) {
    if (isError === undefined) { isError = false; }
    var id  = isError ? 'toastError' : 'toastSuccess';
    var mid = isError ? 'toastErrMsg' : 'toastMsg';
    document.getElementById(mid).textContent = msg;
    var toast = new bootstrap.Toast(document.getElementById(id), { delay: 3000 });
    toast.show();
}

/* ===== จำ Tab ที่เลือกด้วย sessionStorage ===== */
(function() {
    var TAB_KEY = 'cfp_users_tab';

    /* อ่าน tab จาก URL ?tab= (หลัง redirect) หรือ sessionStorage */
    var urlParams = new URLSearchParams(window.location.search);
    var tabFromUrl = urlParams.get('tab');
    var activeTab  = tabFromUrl || sessionStorage.getItem(TAB_KEY) || 'tabUsers';

    /* activate tab ที่ถูกต้อง */
    var tabEl = document.querySelector('[data-bs-target="#' + activeTab + '"]');
    if (tabEl) { new bootstrap.Tab(tabEl).show(); }

    /* บันทึก tab เมื่อเปลี่ยน */
    document.querySelectorAll('#userTabs button[data-bs-toggle="tab"]').forEach(function(btn) {
        btn.addEventListener('shown.bs.tab', function(e) {
            var target = e.target.getAttribute('data-bs-target').replace('#', '');
            sessionStorage.setItem(TAB_KEY, target);
        });
    });
})();

/* ===== Auto Toast จาก session ===== */
<?php if ($toastMsg) { ?>
showToast(
    '<?php echo htmlspecialchars(addslashes($toastMsg)); ?>',
    <?php echo $toastType === 'error' ? 'true' : 'false'; ?>
);
<?php } ?>

/* ===== Modal User Data (JSON) ===== */
var usersData = <?php
    $map = array();
    foreach ($users as $u) {
        $map[$u['UserID']] = array(
            'username'    => $u['Username'],
            'fullname'    => $u['FullName'],
            'email'       => $u['Email'] ?? '',
            'role_id'     => (int)$u['RoleID'],
            'company_id'  => (int)$u['CompanyID'],
            'division_id' => (int)$u['DivisionID'],
            'dept_id'     => (int)$u['DeptID'],
            'section_id'  => (int)$u['SectionID'],
            'position_id' => (int)$u['PositionID'],
            'site_id'     => (int)$u['SiteID'],
        );
    }
    echo json_encode($map);
?>;

/* ===== Scope Access Data ===== */
var scopeAccessData = <?php
    $scopeJson = array();
    foreach ($scopeAccesses as $uid => $rows) {
        foreach ($rows as $r) {
            $scopeJson[$uid][(int)$r['ScopeNo']] = array(
                'active'      => (bool)$r['IsActive'],
                'categoryIDs' => $r['CategoryIDs'] ?? '',
                'siteID'      => (int)($r['SiteID'] ?? 0),
            );
        }
    }
    echo json_encode($scopeJson);
?>;

/* ✅ เพิ่ม csrfToken ที่นี่ */
var csrfToken = <?php echo json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function openModalUser(userID) {
    var modal = new bootstrap.Modal(document.getElementById('modalUser'));
    if (userID === 0) {
        /* เพิ่มใหม่ */
        document.getElementById('modalUserTitle').innerHTML = '<i class="bi bi-person-plus me-2"></i>เพิ่มผู้ใช้งานใหม่';
        document.getElementById('formAction').value = 'create';
        document.getElementById('formUserID').value = 0;
        document.getElementById('formUser').reset();
        document.getElementById('labelPassword').innerHTML = 'รหัสผ่าน <span class="text-danger">*</span>';
        document.getElementById('hintPassword').style.display = 'none';
        document.getElementById('fPassword').required = true;
    } else {
        /* แก้ไข */
        var u = usersData[userID];
        if (!u) { return; }
        document.getElementById('modalUserTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขผู้ใช้งาน';
        document.getElementById('formAction').value = 'update';
        document.getElementById('formUserID').value = userID;
        document.getElementById('fUsername').value  = u.username;
        document.getElementById('fFullname').value  = u.fullname;
        document.getElementById('fEmail').value     = u.email;
        document.getElementById('fRoleID').value    = u.role_id;
        document.getElementById('fCompanyID').value = u.company_id;
        document.getElementById('fSiteID').value    = u.site_id;
        document.getElementById('fPassword').value  = '';
        document.getElementById('fPassword').required = false;
        document.getElementById('labelPassword').innerHTML = 'รหัสผ่านใหม่ (ถ้าต้องการเปลี่ยน)';
        document.getElementById('hintPassword').style.display = 'block';
        /* Cascade Org */
        document.getElementById('fDivisionID').value = u.division_id;
        cascadeOrg('division');
        document.getElementById('fDeptID').value     = u.dept_id;
        cascadeOrg('dept');
        document.getElementById('fSectionID').value  = u.section_id;
        document.getElementById('fPositionID').value = u.position_id;
    }
    modal.show();
}

/* ===== Cascade Org Dropdown ===== */
function cascadeOrg(level) {
    if (level === 'company') {
        var coID = document.getElementById('fCompanyID').value;
        filterOptions('fDivisionID', 'data-company', coID);
        document.getElementById('fDivisionID').value = '';
        document.getElementById('fDeptID').value     = '';
        document.getElementById('fSectionID').value  = '';
        filterOptions('fPositionID', 'data-company', coID);
    }
    if (level === 'division') {
        var dvID = document.getElementById('fDivisionID').value;
        filterOptions('fDeptID', 'data-division', dvID);
        document.getElementById('fDeptID').value    = '';
        document.getElementById('fSectionID').value = '';
    }
    if (level === 'dept') {
        var dpID = document.getElementById('fDeptID').value;
        filterOptions('fSectionID', 'data-dept', dpID);
        document.getElementById('fSectionID').value = '';
    }
}

function filterOptions(selectID, dataAttr, filterVal) {
    var sel = document.getElementById(selectID);
    for (var i = 0; i < sel.options.length; i++) {
        var opt = sel.options[i];
        if (!opt.value) { continue; }
        if (filterVal && opt.getAttribute(dataAttr) !== filterVal) {
            opt.style.display = 'none';
        } else {
            opt.style.display = '';
        }
    }
}

/* ===== Toggle Active ===== */
function confirmToggle(userID, currentActive, name) {
    var action = currentActive ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    var icon   = currentActive ? 'warning' : 'question';
    var color  = currentActive ? '#DC3545' : '#4CAF50';
    Swal.fire({
        title: action + ' บัญชีผู้ใช้?',
        html: '<div style="font-family:\'Prompt\',sans-serif;font-size:0.9rem;">'
              + 'ผู้ใช้: <b>' + name + '</b></div>',
        icon: icon,
        showCancelButton: true,
        confirmButtonColor: color,
        cancelButtonColor: '#6C757D',
        confirmButtonText: action,
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true,
        customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (result.isConfirmed) {
            window.location.href = 'users_save.php?action=toggle&user_id=' + userID
                + '&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>';
        }
    });
}

/* ===== Toggle Password ===== */
function togglePassword() {
    var inp  = document.getElementById('fPassword');
    var icon = document.getElementById('eyeIcon');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

/* ===== Scope Access Data ===== */
var scopeAccessData = <?php
    $scopeJson = array();
    foreach ($scopeAccesses as $uid => $rows) {
        foreach ($rows as $r) {
            $scopeJson[$uid][(int)$r['ScopeNo']] = array(
                'active'      => (bool)$r['IsActive'],
                'categoryIDs' => $r['CategoryIDs'] ?? '',
                'siteID'      => (int)($r['SiteID'] ?? 0),
            );
        }
    }
    echo json_encode($scopeJson);
?>;

var currentScopeUserID = 0;

function openScopeModal(userID, userName) {
    currentScopeUserID = userID;
    document.getElementById('scopeModalName').textContent = userName;
    /* reset */
    [1,2,3].forEach(function(sNo) {
        document.getElementById('chkScope'    + sNo).checked = false;
        document.getElementById('siteScope'   + sNo).value   = '';
        document.getElementById('scopeDetail' + sNo).style.display = 'none';
    });
    /* reset checkbox cat3 */
    document.querySelectorAll('.cat3-check').forEach(function(c) { c.checked = false; });

    /* โหลดข้อมูลเดิม */
    var uData = scopeAccessData[userID] || {};
    Object.keys(uData).forEach(function(sNo) {
        var sc = uData[sNo];
        if (sc.active) {
            document.getElementById('chkScope'    + sNo).checked = true;
            document.getElementById('siteScope'   + sNo).value   = sc.siteID || '';
            document.getElementById('scopeDetail' + sNo).style.display = 'block';
            /* โหลด cat3 checkbox */
            if (parseInt(sNo) === 3 && sc.categoryIDs) {
                sc.categoryIDs.split(',').forEach(function(cid) {
                    var el = document.getElementById('cat3_' + cid.trim());
                    if (el) { el.checked = true; }
                });
            }
        }
    });
    new bootstrap.Modal(document.getElementById('modalScope')).show();
}

function toggleScopeDetail(sNo) {
    var chk    = document.getElementById('chkScope' + sNo);
    var detail = document.getElementById('scopeDetail' + sNo);
    detail.style.display = chk.checked ? 'block' : 'none';
    if (!chk.checked) {
        document.getElementById('siteScope' + sNo).value = '';
        if (sNo === 3) {
            document.querySelectorAll('.cat3-check').forEach(function(c) { c.checked = false; });
        }
    }
}

function selectAllCat(checked) {
    document.querySelectorAll('.cat3-check').forEach(function(c) { c.checked = checked; });
}
function saveScopeAccess() {
    var scopes = [];
    [1,2,3].forEach(function(sNo) {
        if (document.getElementById('chkScope' + sNo).checked) {
            var catIDs = '';
            if (sNo === 3) {
                var checked = [];
                document.querySelectorAll('.cat3-check:checked').forEach(function(c) {
                    checked.push(c.value);
                });
                catIDs = checked.join(',');
            }
            scopes.push({
                scopeNo:     sNo,
                categoryIDs: catIDs,
                siteID:      document.getElementById('siteScope' + sNo).value
            });
        }
    });

    if (scopes.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'กรุณาเลือก Scope อย่างน้อย 1 รายการ',
            confirmButtonText: 'ตกลง',
            customClass: { popup: 'font-prompt' }
        });
        return;
    }

    var fd = new FormData();
    fd.append('action',    'save_scope');
    fd.append('user_id',   currentScopeUserID);
    fd.append('scopes',    JSON.stringify(scopes));
    fd.append('csrf_token', csrfToken);  // ✅ ใช้ csrfToken ที่ประกาศไว้

    Swal.fire({
        title: 'กำลังบันทึก...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });

    fetch('users_scope_save.php', { method:'POST', body:fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        Swal.close();
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalScope')).hide();
            showToast(res.msg || 'บันทึกสิทธิ์เรียบร้อย');
            setTimeout(function() { location.reload(); }, 800);
        } else {
            showToast(res.msg || 'เกิดข้อผิดพลาด', true);
            console.error('Error response:', res);
        }
    })
    .catch(function(err) {
        Swal.close();
        console.error('Fetch error:', err);
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', true);
    });
}
function doSaveScope(scopes) {
    var fd = new FormData();
    fd.append('action',    'save_scope');
    fd.append('user_id',   currentScopeUserID);
    fd.append('scopes',    JSON.stringify(scopes));
    fd.append('csrf_token', csrfToken);

    Swal.fire({
        title: 'กำลังบันทึก...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });

    fetch('users_scope_save.php', { method:'POST', body:fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        Swal.close();
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalScope')).hide();
            showToast(res.msg || 'บันทึกสิทธิ์เรียบร้อย');
            setTimeout(function() { location.reload(); }, 800);
        } else {
            showToast(res.msg || 'เกิดข้อผิดพลาด', true);
            console.error('Error response:', res);
        }
    })
    .catch(function(err) {
        Swal.close();
        console.error('Fetch error:', err);
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', true);
    });
}
/* ===== Reviewer Site Modal ===== */
var currentReviewerUserID = 0;

function openReviewerSiteModal(userID, userName, assignedSiteIDs) {
    currentReviewerUserID = userID;
    document.getElementById('reviewerSiteModalName').textContent = userName;
    /* reset */
    document.querySelectorAll('.rev-site-chk').forEach(function(c) { c.checked = false; });
    /* โหลดค่าเดิม */
    assignedSiteIDs.forEach(function(sid) {
        var el = document.getElementById('revSiteChk' + sid);
        if (el) { el.checked = true; }
    });
    new bootstrap.Modal(document.getElementById('modalReviewerSite')).show();
}

function selectAllRevSite(checked) {
    document.querySelectorAll('.rev-site-chk').forEach(function(c) { c.checked = checked; });
}

function saveReviewerSite() {
    var selected = [];
    document.querySelectorAll('.rev-site-chk:checked').forEach(function(c) {
        selected.push(c.value);
    });

    if (selected.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'กรุณาเลือก Site อย่างน้อย 1 แห่ง',
            confirmButtonText: 'ตกลง',
            customClass: { popup: 'font-prompt' }
        });
        return;
    }

    var fd = new FormData();
    fd.append('action', 'save_reviewer_site');
    fd.append('user_id', currentReviewerUserID);
    fd.append('site_ids', selected.join(','));
    fd.append('csrf_token', csrfToken);

    Swal.fire({
        title: 'กำลังบันทึก...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });

    fetch('users_scope_save.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        Swal.close();
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalReviewerSite')).hide();
            showToast(res.msg || 'บันทึกสิทธิ์เรียบร้อย');
            setTimeout(function() { location.reload(); }, 800);
        } else {
            showToast(res.msg || 'เกิดข้อผิดพลาด', true);
            console.error('Error response:', res);
        }
    })
    .catch(function(err) {
        Swal.close();
        console.error('Fetch error:', err);
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', true);
    });
}
/* ===== Copy User ===== */
function copyUser(userID) {
    var u = usersData[userID];
    if (!u) { return; }

    /* เปิด Modal ในโหมด create */
    document.getElementById('modalUserTitle').innerHTML = '<i class="bi bi-copy me-2"></i>คัดลอกผู้ใช้งาน';
    document.getElementById('formAction').value  = 'create';
    document.getElementById('formUserID').value  = 0;

    /* ล้าง field ที่ต้องกรอกใหม่ */
    document.getElementById('fUsername').value  = '';
    document.getElementById('fFullname').value  = '';
    document.getElementById('fEmail').value     = '';
    document.getElementById('fPassword').value  = '';
    document.getElementById('fPassword').required = true;
    document.getElementById('labelPassword').innerHTML = 'รหัสผ่าน <span class="text-danger">*</span>';
    document.getElementById('hintPassword').style.display = 'none';

    /* prefill ข้อมูลองค์กรและ Role จากต้นฉบับ */
    document.getElementById('fRoleID').value    = u.role_id;
    document.getElementById('fCompanyID').value = u.company_id;
    document.getElementById('fSiteID').value    = u.site_id;
    document.getElementById('fDivisionID').value = u.division_id;
    cascadeOrg('division');
    document.getElementById('fDeptID').value    = u.dept_id;
    cascadeOrg('dept');
    document.getElementById('fSectionID').value = u.section_id;
    document.getElementById('fPositionID').value = u.position_id;

    new bootstrap.Modal(document.getElementById('modalUser')).show();
}
</script>

</body>
</html>
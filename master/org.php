<?php
/* ==============================================
   master/org.php
   จัดการโครงสร้างองค์กร — DevAdmin เท่านั้น
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4));

$conn = getConnection();

$validTabs = array('company','division','department','section','position');
$activeTab = in_array($_GET['tab'] ?? '', $validTabs) ? $_GET['tab'] : 'company';

$res = sqlsrv_query($conn,"SELECT CompanyID,CompanyCode,CompanyName,IsActive FROM CFP_Company ORDER BY CompanyName");
$companies = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $companies[] = $r; }

$res = sqlsrv_query($conn,"SELECT SiteID,SiteCode,SiteName,Department,ScopeGroup,IsActive FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
$sites = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $sites[] = $r; }

$res = sqlsrv_query($conn,"
    SELECT d.DivisionID,d.DivisionCode,d.DivisionName,d.DivisionType,
           d.CompanyID,d.SiteID,d.SortOrder,d.IsActive,
           c.CompanyName, s.SiteName
    FROM CFP_Division d
    LEFT JOIN CFP_Company c ON d.CompanyID=c.CompanyID
    LEFT JOIN CFP_Site    s ON d.SiteID=s.SiteID
    ORDER BY c.CompanyName,d.SortOrder,d.DivisionName");
$divisions = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $divisions[] = $r; }

$res = sqlsrv_query($conn,"
    SELECT dp.DeptID,dp.DeptCode,dp.DeptName,dp.DivisionID,dp.SortOrder,dp.IsActive,
           dv.DivisionName,c.CompanyName
    FROM CFP_Department dp
    LEFT JOIN CFP_Division dv ON dp.DivisionID=dv.DivisionID
    LEFT JOIN CFP_Company   c ON dv.CompanyID=c.CompanyID
    ORDER BY c.CompanyName,dv.DivisionName,dp.SortOrder,dp.DeptName");
$departments = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $departments[] = $r; }

$res = sqlsrv_query($conn,"
    SELECT sc.SectionID,sc.SectionCode,sc.SectionName,sc.DeptID,sc.IsActive,
           dp.DeptName,dv.DivisionName
    FROM CFP_Section sc
    LEFT JOIN CFP_Department dp ON sc.DeptID=dp.DeptID
    LEFT JOIN CFP_Division   dv ON dp.DivisionID=dv.DivisionID
    ORDER BY dv.DivisionName,dp.DeptName,sc.SectionName");
$sections = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $sections[] = $r; }

$res = sqlsrv_query($conn,"
    SELECT dp.DeptID, dp.DeptName, dp.DivisionID, dv.DivisionName
    FROM CFP_Department dp
    JOIN CFP_Division dv ON dp.DivisionID=dv.DivisionID
    WHERE dp.IsActive=1 AND dv.DivisionType='Factory'
    ORDER BY dv.DivisionName, dp.DeptName");
$factoryDepts = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $factoryDepts[] = $r; }

$res = sqlsrv_query($conn,"
    SELECT p.PositionID,p.PositionCode,p.PositionName,p.CompanyID,p.IsActive,
           c.CompanyName
    FROM CFP_Position p
    LEFT JOIN CFP_Company c ON p.CompanyID=c.CompanyID
    ORDER BY c.CompanyName,p.PositionName");
$positions = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $positions[] = $r; }

$siJS  = array(); foreach ($sites as $r) { $siJS[$r['SiteID']] = array('code'=>$r['SiteCode'],'name'=>$r['SiteName'],'group'=>$r['ScopeGroup']??''); }
$coJS  = array(); foreach ($companies  as $r) { $coJS[$r['CompanyID']]  = array('code'=>$r['CompanyCode'],  'name'=>$r['CompanyName']); }
$dvJS  = array(); foreach ($divisions  as $r) { $dvJS[$r['DivisionID']] = array('company'=>(int)$r['CompanyID'],'site'=>(int)$r['SiteID'],'code'=>$r['DivisionCode'],'name'=>$r['DivisionName'],'type'=>$r['DivisionType'],'sort'=>(int)$r['SortOrder']); }
$dpJS  = array(); foreach ($departments as $r) { $dpJS[$r['DeptID']]    = array('division'=>(int)$r['DivisionID'],'code'=>$r['DeptCode'],'name'=>$r['DeptName'],'sort'=>(int)$r['SortOrder']); }
$scJS  = array(); foreach ($sections   as $r) { $scJS[$r['SectionID']]  = array('dept'=>(int)$r['DeptID'],'code'=>$r['SectionCode'],'name'=>$r['SectionName']); }
$poJS  = array(); foreach ($positions  as $r) { $poJS[$r['PositionID']] = array('company'=>(int)$r['CompanyID'],'code'=>$r['PositionCode'],'name'=>$r['PositionName']); }

function activeBadge($v) {
    return $v ? '<span class="badge bg-success">ใช้งาน</span>' : '<span class="badge bg-secondary">ปิด</span>';
}
function actionBtns($entity, $id, $name, $isActive, $openFn) {
    $escaped = htmlspecialchars(addslashes($name));
    $edit = '<button class="btn btn-outline-primary btn-sm py-0 px-2 me-1" onclick="'.$openFn.'('.$id.')" title="แก้ไข"><i class="bi bi-pencil"></i></button>';
    $del  = $isActive ? '<button class="btn btn-outline-danger btn-sm py-0 px-2" onclick="orgDelete(\''.$entity.'\','.$id.',\''.$escaped.'\')" title="ลบ"><i class="bi bi-trash"></i></button>' : '';
    return $edit . $del;
}
/* ★ เพิ่ม 1: helper badge รหัสหน้าชื่อ (แสดงเฉพาะ mobile ผ่าน CSS) */
function nameTd($name, $code) {
    return '<td><span class="cfp-code-badge">'.htmlspecialchars($code).'</span>'.htmlspecialchars($name).'</td>';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>โครงสร้างองค์กร — ระบบบริหารจัดการคาร์บอนองค์กร</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">
  <style>
    body { font-family: 'Prompt', sans-serif; }
    .tab-nav-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 8px 16px; border-radius: 8px; border: none;
      background: transparent; color: var(--cfp-text-muted);
      font-family: 'Prompt', sans-serif; font-size: 0.85rem;
      cursor: pointer; transition: all 0.15s; white-space: nowrap;
    }
    .tab-nav-btn:hover { background: var(--cfp-hover); color: var(--cfp-primary); }
    .tab-nav-btn.active { background: var(--cfp-primary); color: #fff; font-weight: 500; }
    .font-prompt { font-family: 'Prompt', sans-serif !important; }
    .section-hint { font-size: 0.78rem; color: var(--cfp-text-muted); }

    /* expand button */
    .cfp-expand-btn {
      font-family: "bootstrap-icons";
      display: inline-flex; align-items: center; justify-content: center;
      width: 22px; height: 22px; border-radius: 50%;
      background: var(--cfp-hover); color: var(--cfp-primary);
      border: 1.5px solid var(--cfp-border);
      font-size: 0.75rem; cursor: pointer; vertical-align: middle;
      transition: transform 0.2s ease, background 0.15s;
      user-select: none; flex-shrink: 0;
    }
    .cfp-expand-btn.expanded {
      transform: rotate(90deg); background: var(--cfp-primary); color: #fff;
      border-color: var(--cfp-primary); box-shadow: 0 2px 6px rgba(42,171,184,0.3);
    }
    .cfp-detail-row > td { padding: 0 !important; border-top: none !important; }
    .cfp-detail-body { background: #F0FAFB; border-left: 3px solid var(--cfp-primary); padding: 8px 14px; }
    .cfp-detail-item { display: flex; gap: 8px; padding: 3px 0; border-bottom: 1px solid var(--cfp-border); font-size: 0.82rem; }
    .cfp-detail-item:last-child { border-bottom: none; }
    .cfp-detail-label { font-weight: 600; color: var(--cfp-text-mid); min-width: 80px; flex-shrink: 0; }
    .cfp-detail-value { color: var(--cfp-text); }

    /* dot indicator */
    .cfp-tab-dots { display: none; justify-content: center; align-items: center; gap: 4px; margin-top: 8px; }
    .cfp-tab-dot { height: 4px; border-radius: 2px; background: var(--cfp-border); transition: width 0.25s ease, background 0.25s ease; cursor: pointer; }
    .cfp-tab-dot.active { background: var(--cfp-primary); }

    /* ★ เพิ่ม 2: badge รหัสน้ำเงิน — mobile only */
    .cfp-code-badge { display: none; }

    /* ★ เพิ่ม 3: filter bar */
    .cfp-filter-bar {
      display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
      padding: 8px 12px; background: #F0FAFB;
      border: 1px solid var(--cfp-border); border-radius: 8px; margin-bottom: 12px;
    }
    .cfp-filter-bar select {
      font-family: 'Prompt', sans-serif; font-size: 0.82rem; padding: 4px 8px;
      border: 1px solid var(--cfp-border); border-radius: 6px;
      background: #fff; color: var(--cfp-text); min-width: 130px; max-width: 220px;
    }
    .cfp-filter-clear {
      font-family: 'Prompt', sans-serif; font-size: 0.78rem; padding: 4px 10px;
      border-radius: 6px; background: transparent; border: 1px solid #ccc;
      color: #888; cursor: pointer; white-space: nowrap;
    }
    .cfp-filter-clear:hover { background: #f5f5f5; }

    @media (min-width: 768px) {
      .cfp-expand-btn { display: none !important; }
      .cfp-detail-row { display: none !important; }
      .cfp-tab-dots   { display: none !important; }
      .cfp-dt-length { text-align: left; }
      .cfp-dt-filter { text-align: right; }
      /* column expand ใช้เฉพาะจอมือถือ (มีปุ่ม "ดูรายละเอียด") — จอใหญ่ไม่ต้องมี ไม่งั้นเหลือคอลัมน์ว่างเปล่า */
      .cfp-org-table th.cfp-th-expand,
      .cfp-org-table td.cfp-td-expand { display: none !important; }
    }
    @media (max-width: 767px) {
      /* tab scroll */
      .cfp-tab-scroll-inner {
        display: flex !important; flex-wrap: nowrap !important;
        overflow-x: auto; overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none; gap: 8px;
        padding-bottom: 2px; padding-right: 44px;
      }
      .cfp-tab-scroll-inner::-webkit-scrollbar { display: none; }
      .cfp-tab-scroll-wrap { position: relative; }
      .cfp-tab-scroll-wrap::after {
        content: ''; position: absolute;
        top: 0; right: 0; bottom: 12px; width: 44px;
        background: linear-gradient(to right, transparent, rgba(255,255,255,0.95));
        pointer-events: none; z-index: 1;
      }
      .cfp-tab-dots { display: flex !important; }
      .cfp-dt-length { text-align: left; }
      .cfp-dt-filter { text-align: right; }
      .cfp-dt-filter label { white-space: nowrap; display: flex; align-items: center; justify-content: flex-end; gap: 4px; }
      .cfp-dt-filter input { width: 90px !important; min-width: 0; }
      .dataTables_wrapper .dataTables_paginate { text-align: center; float: none; }
      .dataTables_wrapper .dataTables_info    { text-align: center; }
      /* ซ่อนเลขแถว # — mobile only (column ที่ 2 = td:nth-child(2)) */
      .cfp-org-table th.cfp-th-num,
      .cfp-org-table td.cfp-td-num { display: none !important; }
      /* column expand — mobile only แสดงได้ */
      .cfp-org-table th.cfp-th-expand { width: 32px; padding: 4px !important; text-align:center; }
      /* ★ badge รหัสน้ำเงิน */
      .cfp-code-badge {
        display: inline-block; font-size: 0.68rem; font-weight: 600;
        color: #1565C0; background: #E3F2FD;
        border: 1px solid #BBDEFB; border-radius: 4px;
        padding: 1px 5px; margin-right: 5px;
        vertical-align: middle; white-space: nowrap;
      }
    }
  </style>
</head>
<body>
<div class="d-flex">
  <?php include '../includes/sidebar.php'; ?>
  <div class="cfp-main">
    <?php
    $pageTitle = 'โครงสร้างองค์กร';
    $pageIcon  = 'diagram-3';
    include '../includes/topbar.php';
    ?>
    <div class="cfp-content">

      <!-- Tab Nav -->
      <div class="cfp-card mb-3" style="padding:0.75rem 1rem;">
        <div class="cfp-tab-scroll-wrap">
          <div class="cfp-tab-scroll-inner" id="tabScrollInner">
            <?php
            $tabs = array(
                'company'    => array('icon'=>'bi-building',       'label'=>'บริษัท'),
                'site'       => array('icon'=>'bi-geo-alt-fill',   'label'=>'Site / โรงงาน'),
                'division'   => array('icon'=>'bi-diagram-2',      'label'=>'ฝ่าย'),
                'department' => array('icon'=>'bi-diagram-3',      'label'=>'แผนก'),
                'section'    => array('icon'=>'bi-grid-3x3-gap',   'label'=>'หน่วยงาน'),
                'position'   => array('icon'=>'bi-person-badge',   'label'=>'ตำแหน่ง'),
            );
            $tabKeys = array_keys($tabs);
            foreach ($tabs as $key => $t) {
                $cls = ($key === $activeTab) ? ' active' : '';
                echo '<button class="tab-nav-btn'.$cls.'" data-tabkey="'.$key.'" onclick="switchTab(\''.$key.'\', this)">';
                echo '<i class="bi '.$t['icon'].'"></i>'.$t['label'];
                if ($key === 'section') { echo ' <span class="badge" style="background:rgba(27,58,74,0.15);color:var(--cfp-primary);font-size:0.65rem;">โรงงาน</span>'; }
                echo '</button>';
            }
            ?>
          </div>
          <div class="cfp-tab-dots" id="tabDots">
            <?php foreach ($tabKeys as $idx => $key) {
                $isActive = ($key === $activeTab);
                echo '<div class="cfp-tab-dot'.($isActive?' active':'').'" '
                   . 'style="width:'.($isActive?'18px':'6px').';" '
                   . 'data-idx="'.$idx.'" '
                   . 'onclick="switchTabByIndex('.$idx.')" '
                   . 'title="'.htmlspecialchars($tabs[$key]['label']).'"></div>';
            } ?>
          </div>
        </div>
      </div>

      <!-- Tab Panes -->
      <?php foreach (array_keys($tabs) as $key) {
          $show = ($key === $activeTab) ? '' : ' d-none';
      ?>
      <div id="tab-<?php echo $key; ?>" class="tab-pane-wrap<?php echo $show; ?>">
        <div class="cfp-card">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);">
              <i class="bi <?php echo $tabs[$key]['icon']; ?> me-2"></i><?php echo $tabs[$key]['label']; ?>
            </div>
            <button class="btn-cfp-add btn-sm" onclick="openModal('<?php echo $key; ?>',0)">
              <i class="bi bi-plus-circle me-1"></i>เพิ่ม<?php echo $tabs[$key]['label']; ?>
            </button>
          </div>

          <?php if ($key === 'section') { ?>
          <div class="alert alert-info py-2 mb-3" style="font-size:0.82rem;">
            <i class="bi bi-info-circle me-1"></i>หน่วยงานมีเฉพาะ<b>ฝ่ายโรงงาน</b>เท่านั้น
          </div>
          <?php } ?>

          <?php /* ★ เพิ่ม 4: filter bar สำหรับ division / department / section */
          if ($key === 'division') { ?>
          <div class="cfp-filter-bar">
            <i class="bi bi-funnel" style="color:var(--cfp-primary)"></i>
            <select id="fDivCo" onchange="cfpFilter('tbl-division',this,'f-co')">
              <option value="">— ทุกบริษัท —</option>
              <?php foreach ($companies as $c) { echo '<option value="'.htmlspecialchars($c['CompanyName']).'">'.htmlspecialchars($c['CompanyName']).'</option>'; } ?>
            </select>
            <button class="cfp-filter-clear" onclick="cfpClearFilter('tbl-division',['fDivCo'])"><i class="bi bi-x-circle me-1"></i>ล้าง</button>
          </div>
          <?php } elseif ($key === 'department') { ?>
          <div class="cfp-filter-bar">
            <i class="bi bi-funnel" style="color:var(--cfp-primary)"></i>
            <select id="fDeptCo" onchange="cfpFilter('tbl-department',this,'f-co')">
              <option value="">— ทุกบริษัท —</option>
              <?php foreach ($companies as $c) { echo '<option value="'.htmlspecialchars($c['CompanyName']).'">'.htmlspecialchars($c['CompanyName']).'</option>'; } ?>
            </select>
            <select id="fDeptDiv" onchange="cfpFilter('tbl-department',this,'f-div')">
              <option value="">— ทุกฝ่าย —</option>
              <?php foreach ($divisions as $d) { if (!$d['IsActive']) { continue; } echo '<option value="'.htmlspecialchars($d['DivisionName']).'">'.htmlspecialchars($d['DivisionName']).'</option>'; } ?>
            </select>
            <button class="cfp-filter-clear" onclick="cfpClearFilter('tbl-department',['fDeptCo','fDeptDiv'])"><i class="bi bi-x-circle me-1"></i>ล้าง</button>
          </div>
          <?php } elseif ($key === 'section') { ?>
          <div class="cfp-filter-bar">
            <i class="bi bi-funnel" style="color:var(--cfp-primary)"></i>
            <select id="fSecDiv" onchange="cfpFilter('tbl-section',this,'f-div')">
              <option value="">— ทุกฝ่าย (โรงงาน) —</option>
              <?php $fdSeen=array(); foreach($factoryDepts as $d){ if(!isset($fdSeen[$d['DivisionID']])){$fdSeen[$d['DivisionID']]=1; echo '<option value="'.htmlspecialchars($d['DivisionName']).'">'.htmlspecialchars($d['DivisionName']).'</option>';} } ?>
            </select>
            <select id="fSecDept" onchange="cfpFilter('tbl-section',this,'f-dept')">
              <option value="">— ทุกแผนก (โรงงาน) —</option>
              <?php foreach ($factoryDepts as $d) { echo '<option value="'.htmlspecialchars($d['DeptName']).'">'.htmlspecialchars($d['DeptName']).'</option>'; } ?>
            </select>
            <button class="cfp-filter-clear" onclick="cfpClearFilter('tbl-section',['fSecDiv','fSecDept'])"><i class="bi bi-x-circle me-1"></i>ล้าง</button>
          </div>
          <?php } ?>

          <div class="table-responsive">
            <table id="tbl-<?php echo $key; ?>" class="table table-bordered table-hover align-middle cfp-org-table" style="width:100%;">
              <thead>
                <?php if ($key === 'company') { ?>
                <tr>
                  <th class="cfp-th-expand"></th>
                  <th class="cfp-th-num" style="width:40px">#</th>
                  <th class="cfp-col-hide" style="width:110px">รหัส</th>
                  <th>ชื่อบริษัท</th>
                  <th class="cfp-col-hide text-center" style="width:90px">สถานะ</th>
                  <th class="text-center" style="width:90px">จัดการ</th>
                </tr>
                <?php } elseif ($key === 'site') { ?>
                <tr>
                  <th class="cfp-th-expand"></th>
                  <th class="cfp-th-num" style="width:40px">#</th>
                  <th class="cfp-col-hide" style="width:100px">รหัส Site</th>
                  <th>ชื่อ Site / โรงงาน</th>
                  <th class="cfp-col-hide text-center" style="width:100px">ScopeGroup</th>
                  <th class="cfp-col-hide text-center" style="width:80px">สถานะ</th>
                  <th class="text-center" style="width:80px">จัดการ</th>
                </tr>
                <?php } elseif ($key === 'division') { ?>
                <tr>
                  <th class="cfp-th-expand"></th>
                  <th class="cfp-th-num" style="width:40px">#</th>
                  <th class="cfp-col-hide" style="width:90px">รหัส</th>
                  <th>ชื่อฝ่าย</th>
                  <th class="cfp-col-hide f-co">บริษัท</th>
                  <th class="cfp-col-hide text-center" style="width:80px">ประเภท</th>
                  <th class="cfp-col-hide">Site</th>
                  <th class="cfp-col-hide text-center" style="width:80px">สถานะ</th>
                  <th class="text-center" style="width:80px">จัดการ</th>
                </tr>
                <?php } elseif ($key === 'department') { ?>
                <tr>
                  <th class="cfp-th-expand"></th>
                  <th class="cfp-th-num" style="width:40px">#</th>
                  <th>ชื่อแผนก</th>
                  <th class="cfp-col-hide f-div">ฝ่าย</th>
                  <th class="cfp-col-hide f-co">บริษัท</th>
                  <th class="cfp-col-hide text-center" style="width:80px">สถานะ</th>
                  <th class="text-center" style="width:80px">จัดการ</th>
                </tr>
                <?php } elseif ($key === 'section') { ?>
                <tr>
                  <th class="cfp-th-expand"></th>
                  <th class="cfp-th-num" style="width:40px">#</th>
                  <th>ชื่อหน่วยงาน</th>
                  <th class="cfp-col-hide f-dept">แผนก</th>
                  <th class="cfp-col-hide f-div">ฝ่าย</th>
                  <th class="cfp-col-hide text-center" style="width:80px">สถานะ</th>
                  <th class="text-center" style="width:80px">จัดการ</th>
                </tr>
                <?php } elseif ($key === 'position') { ?>
                <tr>
                  <th class="cfp-th-expand"></th>
                  <th class="cfp-th-num" style="width:40px">#</th>
                  <th>ชื่อตำแหน่ง</th>
                  <th class="cfp-col-hide">บริษัท</th>
                  <th class="cfp-col-hide text-center" style="width:80px">สถานะ</th>
                  <th class="text-center" style="width:80px">จัดการ</th>
                </tr>
                <?php } ?>
              </thead>
              <tbody>
                <?php if ($key === 'company') {
                  foreach ($companies as $i => $r) { ?>
                  <tr>
                    <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
                    <td class="cfp-td-num"><?php echo $i+1; ?></td>
                    <td class="cfp-col-hide"><code><?php echo htmlspecialchars($r['CompanyCode']); ?></code></td>
                    <td><?php echo htmlspecialchars($r['CompanyName']); ?></td>
                    <td class="cfp-col-hide text-center"><?php echo activeBadge($r['IsActive']); ?></td>
                    <td class="text-center"><?php echo actionBtns('company',$r['CompanyID'],$r['CompanyName'],$r['IsActive'],'openModalCompany'); ?></td>
                  </tr>
                  <?php }
                } elseif ($key === 'site') {
                  foreach ($sites as $i => $r) { ?>
                  <tr>
                    <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
                    <td class="cfp-td-num"><?php echo $i+1; ?></td>
                    <td class="cfp-col-hide"><code><?php echo htmlspecialchars($r['SiteCode']); ?></code></td>
                    <td style="font-weight:500;"><?php echo htmlspecialchars($r['SiteName']); ?></td>
                    <td class="cfp-col-hide text-center">
                      <?php if (!empty($r['ScopeGroup'])) { ?>
                      <span class="badge" style="background:#E4F7F9;color:#2AABB8;font-size:0.72rem;"><?php echo htmlspecialchars($r['ScopeGroup']); ?></span>
                      <?php } else { echo '<span style="color:#ccc;">—</span>'; } ?>
                    </td>
                    <td class="cfp-col-hide text-center"><?php echo activeBadge($r['IsActive']); ?></td>
                    <td class="text-center"><?php echo actionBtns('site',$r['SiteID'],$r['SiteName'],1,'openModalSite'); ?></td>
                  </tr>
                  <?php }
                } elseif ($key === 'division') {
                  foreach ($divisions as $i => $r) { ?>
                  <tr>
                    <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
                    <td class="cfp-td-num"><?php echo $i+1; ?></td>
                    <td class="cfp-col-hide"><code><?php echo htmlspecialchars($r['DivisionCode']); ?></code></td>
                    <?php echo nameTd($r['DivisionName'], $r['DivisionCode']); ?>
                    <td class="cfp-col-hide f-co" style="font-size:0.82rem"><?php echo htmlspecialchars($r['CompanyName'] ?? '—'); ?></td>
                    <td class="cfp-col-hide text-center">
                      <?php if ($r['DivisionType'] === 'Factory') { ?>
                        <span class="badge" style="background:#E8F5E9;color:#2E7D32;">Factory</span>
                      <?php } else { ?>
                        <span class="badge" style="background:#E3F2FD;color:#1565C0;">สำนักงานใหญ่</span>
                      <?php } ?>
                    </td>
                    <td class="cfp-col-hide" style="font-size:0.82rem"><?php echo htmlspecialchars($r['SiteName'] ?? '—'); ?></td>
                    <td class="cfp-col-hide text-center"><?php echo activeBadge($r['IsActive']); ?></td>
                    <td class="text-center"><?php echo actionBtns('division',$r['DivisionID'],$r['DivisionName'],$r['IsActive'],'openModalDivision'); ?></td>
                  </tr>
                  <?php }
                } elseif ($key === 'department') {
                  foreach ($departments as $i => $r) { ?>
                  <tr>
                    <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
                    <td class="cfp-td-num"><?php echo $i+1; ?></td>
                    <?php echo nameTd($r['DeptName'], $r['DeptCode']); ?>
                    <td class="cfp-col-hide f-div" style="font-size:0.82rem"><?php echo htmlspecialchars($r['DivisionName'] ?? '—'); ?></td>
                    <td class="cfp-col-hide f-co" style="font-size:0.82rem"><?php echo htmlspecialchars($r['CompanyName'] ?? '—'); ?></td>
                    <td class="cfp-col-hide text-center"><?php echo activeBadge($r['IsActive']); ?></td>
                    <td class="text-center"><?php echo actionBtns('department',$r['DeptID'],$r['DeptName'],$r['IsActive'],'openModalDept'); ?></td>
                  </tr>
                  <?php }
                } elseif ($key === 'section') {
                  foreach ($sections as $i => $r) { ?>
                  <tr>
                    <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
                    <td class="cfp-td-num"><?php echo $i+1; ?></td>
                    <?php echo nameTd($r['SectionName'], $r['SectionCode']); ?>
                    <td class="cfp-col-hide f-dept" style="font-size:0.82rem"><?php echo htmlspecialchars($r['DeptName'] ?? '—'); ?></td>
                    <td class="cfp-col-hide f-div" style="font-size:0.82rem"><?php echo htmlspecialchars($r['DivisionName'] ?? '—'); ?></td>
                    <td class="cfp-col-hide text-center"><?php echo activeBadge($r['IsActive']); ?></td>
                    <td class="text-center"><?php echo actionBtns('section',$r['SectionID'],$r['SectionName'],$r['IsActive'],'openModalSection'); ?></td>
                  </tr>
                  <?php }
                } elseif ($key === 'position') {
                  foreach ($positions as $i => $r) { ?>
                  <tr>
                    <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
                    <td class="cfp-td-num"><?php echo $i+1; ?></td>
                    <?php echo nameTd($r['PositionName'], $r['PositionCode']); ?>
                    <td class="cfp-col-hide" style="font-size:0.82rem"><?php echo htmlspecialchars($r['CompanyName'] ?? '—'); ?></td>
                    <td class="cfp-col-hide text-center"><?php echo activeBadge($r['IsActive']); ?></td>
                    <td class="text-center"><?php echo actionBtns('position',$r['PositionID'],$r['PositionName'],$r['IsActive'],'openModalPosition'); ?></td>
                  </tr>
                  <?php } } ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php } ?>

    </div>
  </div>
</div>

<!-- MODAL บริษัท -->
<div class="modal fade" id="modalCompany" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width:460px">
    <div class="modal-content" style="font-family:'Prompt',sans-serif">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff">
        <h6 class="modal-title mb-0" id="titleCompany"><i class="bi bi-building me-2"></i>เพิ่มบริษัท</h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formCompany" onsubmit="event.preventDefault(); doSave('formCompany','company')">
        <input type="hidden" name="action" id="coAction" value="create">
        <input type="hidden" name="entity" value="company">
        <input type="hidden" name="id"     id="coID"    value="0">
        <div class="modal-body"><div class="row g-3">
          <div class="col-md-4">
            <label class="form-label form-required">รหัสบริษัท</label>
            <input type="text" class="form-control" name="CompanyCode" id="coCode" maxlength="20" required placeholder="ABC" style="font-family:'Prompt',sans-serif;text-transform:uppercase">
          </div>
          <div class="col-md-8">
            <label class="form-label form-required">ชื่อบริษัท</label>
            <input type="text" class="form-control" name="CompanyName" id="coName" maxlength="200" required placeholder="บริษัท ... จำกัด" style="font-family:'Prompt',sans-serif">
          </div>
        </div></div>
        <div class="modal-footer" style="background:#F9FAFB">
          <button type="button" class="btn btn-outline-secondary btn-sm w-25" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn-cfp-add btn-sm"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL Site -->
<div class="modal fade" id="modalSite" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width:500px">
    <div class="modal-content" style="font-family:'Prompt',sans-serif">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff">
        <h6 class="modal-title mb-0" id="titleSite"><i class="bi bi-geo-alt-fill me-2"></i>เพิ่ม Site / โรงงาน</h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formSite" onsubmit="event.preventDefault(); doSave('formSite','site')">
        <input type="hidden" name="action" id="siAction" value="create">
        <input type="hidden" name="entity" value="site">
        <input type="hidden" name="id"     id="siID"    value="0">
        <div class="modal-body"><div class="row g-3">
          <div class="col-md-4">
            <label class="form-label form-required">รหัส Site</label>
            <input type="text" class="form-control" name="SiteCode" id="siCode" maxlength="20" required placeholder="เช่น HYT-01" style="font-family:'Prompt',sans-serif;text-transform:uppercase">
          </div>
          <div class="col-md-8">
            <label class="form-label form-required">ชื่อ Site / โรงงาน</label>
            <input type="text" class="form-control" name="SiteName" id="siName" maxlength="200" required placeholder="เช่น โรงงานหาดใหญ่" style="font-family:'Prompt',sans-serif">
          </div>
          <div class="col-md-6">
            <label class="form-label">ScopeGroup</label>
            <select class="form-select" name="ScopeGroup" id="siScopeGroup" style="font-family:'Prompt',sans-serif">
              <option value="">— ไม่ระบุ —</option>
              <option value="Factory">Factory</option>
              <option value="Office">Office</option>
              <option value="Warehouse">Warehouse</option>
              <option value="All">All</option>
            </select>
          </div>
        </div></div>
        <div class="modal-footer" style="background:#F9FAFB">
          <button type="button" class="btn btn-outline-secondary btn-sm w-25" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn-cfp-add btn-sm"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL ฝ่าย -->
<div class="modal fade" id="modalDivision" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="font-family:'Prompt',sans-serif">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff">
        <h6 class="modal-title mb-0" id="titleDivision"><i class="bi bi-diagram-2 me-2"></i>เพิ่มฝ่าย</h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formDivision" onsubmit="event.preventDefault(); doSave('formDivision','division')">
        <input type="hidden" name="action" id="dvAction" value="create">
        <input type="hidden" name="entity" value="division">
        <input type="hidden" name="id"     id="dvID"    value="0">
        <div class="modal-body"><div class="row g-3">
          <div class="col-md-6">
            <label class="form-label form-required">บริษัท</label>
            <select class="form-select" name="CompanyID" id="dvCompany" required style="font-family:'Prompt',sans-serif">
              <option value="">— เลือกบริษัท —</option>
              <?php foreach ($companies as $c) { echo '<option value="'.$c['CompanyID'].'">'.htmlspecialchars($c['CompanyName']).'</option>'; } ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label form-required">ประเภทฝ่าย</label>
            <select class="form-select" name="DivisionType" id="dvType" required onchange="toggleSite()" style="font-family:'Prompt',sans-serif">
              <option value="">— เลือกประเภท —</option>
              <option value="HQ">สำนักงานใหญ่ (HQ)</option>
              <option value="Factory">โรงงาน (Factory)</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label form-required">รหัสฝ่าย</label>
            <input type="text" class="form-control" name="DivisionCode" id="dvCode" maxlength="20" required style="font-family:'Prompt',sans-serif;text-transform:uppercase">
          </div>
          <div class="col-md-8">
            <label class="form-label form-required">ชื่อฝ่าย</label>
            <input type="text" class="form-control" name="DivisionName" id="dvName" maxlength="200" required style="font-family:'Prompt',sans-serif">
          </div>
          <div class="col-md-6" id="siteWrap" style="display:none">
            <label class="form-label">Site (Carbon Data) <span class="section-hint">เฉพาะโรงงาน</span></label>
            <select class="form-select" name="SiteID" id="dvSite" style="font-family:'Prompt',sans-serif">
              <option value="">— เลือก Site —</option>
              <?php foreach ($sites as $s) { echo '<option value="'.$s['SiteID'].'">['.htmlspecialchars($s['SiteCode']).'] '.htmlspecialchars($s['SiteName']).'</option>'; } ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">ลำดับแสดง</label>
            <input type="number" class="form-control" name="SortOrder" id="dvSort" value="99" min="1" max="999" style="font-family:'Prompt',sans-serif">
          </div>
        </div></div>
        <div class="modal-footer" style="background:#F9FAFB">
          <button type="button" class="btn btn-outline-secondary btn-sm w-25" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn-cfp-add btn-sm"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL แผนก -->
<div class="modal fade" id="modalDept" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width:520px">
    <div class="modal-content" style="font-family:'Prompt',sans-serif">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff">
        <h6 class="modal-title mb-0" id="titleDept"><i class="bi bi-diagram-3 me-2"></i>เพิ่มแผนก</h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formDept" onsubmit="event.preventDefault(); doSave('formDept','department')">
        <input type="hidden" name="action"   id="dpAction" value="create">
        <input type="hidden" name="entity"   value="department">
        <input type="hidden" name="id"       id="dpID"     value="0">
        <input type="hidden" name="DeptCode" id="dpCode"   value="">
        <div class="modal-body"><div class="row g-3">
          <div class="col-12">
            <label class="form-label form-required">ฝ่าย</label>
            <select class="form-select" name="DivisionID" id="dpDivision" required style="font-family:'Prompt',sans-serif">
              <option value="">— เลือกฝ่าย —</option>
              <?php foreach ($divisions as $d) { if (!$d['IsActive']) { continue; } echo '<option value="'.$d['DivisionID'].'">'.htmlspecialchars($d['DivisionName']).'</option>'; } ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label form-required">ชื่อแผนก</label>
            <input type="text" class="form-control" name="DeptName" id="dpName" maxlength="200" required style="font-family:'Prompt',sans-serif">
            <div class="form-text"><i class="bi bi-magic me-1 text-success"></i>รหัสแผนก: <strong id="dpCodeDisplay" class="text-success">สร้างอัตโนมัติ</strong></div>
          </div>
          <div class="col-md-3">
            <label class="form-label">ลำดับแสดง</label>
            <input type="number" class="form-control" name="SortOrder" id="dpSort" value="99" min="1" max="999" style="font-family:'Prompt',sans-serif">
          </div>
        </div></div>
        <div class="modal-footer" style="background:#F9FAFB">
          <button type="button" class="btn btn-outline-secondary btn-sm w-25" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn-cfp-add btn-sm"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL หน่วยงาน -->
<div class="modal fade" id="modalSection" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width:500px">
    <div class="modal-content" style="font-family:'Prompt',sans-serif">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff">
        <h6 class="modal-title mb-0" id="titleSection"><i class="bi bi-grid-3x3-gap me-2"></i>เพิ่มหน่วยงาน</h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formSection" onsubmit="event.preventDefault(); doSave('formSection','section')">
        <input type="hidden" name="action" id="scAction" value="create">
        <input type="hidden" name="entity" value="section">
        <input type="hidden" name="id"     id="scID"    value="0">
        <div class="modal-body"><div class="row g-3">
          <div class="col-12">
            <label class="form-label form-required">ฝ่าย <span class="section-hint">(เฉพาะโรงงาน)</span></label>
            <select class="form-select" id="scDivision" onchange="filterDeptByDivision()" style="font-family:'Prompt',sans-serif">
              <option value="">— เลือกฝ่ายก่อน —</option>
              <?php
              $factoryDivisions = array();
              foreach ($factoryDepts as $d) { if (!isset($factoryDivisions[$d['DivisionID']])) { $factoryDivisions[$d['DivisionID']] = $d['DivisionName']; } }
              foreach ($factoryDivisions as $divID => $divName) { echo '<option value="'.$divID.'">'.htmlspecialchars($divName).'</option>'; }
              ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label form-required">แผนก</label>
            <select class="form-select" name="DeptID" id="scDept" required style="font-family:'Prompt',sans-serif">
              <option value="">— เลือกฝ่ายก่อน —</option>
              <?php foreach ($factoryDepts as $d) { echo '<option value="'.$d['DeptID'].'" data-division="'.$d['DivisionID'].'" style="display:none;">'.htmlspecialchars($d['DeptName']).'</option>'; } ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">รหัสหน่วยงาน</label>
            <input type="text" class="form-control" id="scCodeDisplay" value="ระบบสร้างให้อัตโนมัติ" readonly style="font-family:'Prompt',sans-serif;background:#F0F0F0;color:#999;font-size:0.85rem;">
          </div>
          <div class="col-md-8">
            <label class="form-label form-required">ชื่อหน่วยงาน</label>
            <input type="text" class="form-control" name="SectionName" id="scName" maxlength="200" required style="font-family:'Prompt',sans-serif">
          </div>
        </div></div>
        <div class="modal-footer" style="background:#F9FAFB">
          <button type="button" class="btn btn-outline-secondary btn-sm w-25" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn-cfp-add btn-sm"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL ตำแหน่ง -->
<div class="modal fade" id="modalPosition" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
    <div class="modal-content" style="font-family:'Prompt',sans-serif">
      <div class="modal-header" style="background:var(--cfp-primary);color:#fff">
        <h6 class="modal-title mb-0" id="titlePosition"><i class="bi bi-person-badge me-2"></i>เพิ่มตำแหน่ง</h6>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="formPosition" onsubmit="event.preventDefault(); doSave('formPosition','position')">
        <input type="hidden" name="action" id="poAction" value="create">
        <input type="hidden" name="entity" value="position">
        <input type="hidden" name="id"     id="poID"    value="0">
        <div class="modal-body"><div class="row g-3">
          <div class="col-12">
            <label class="form-label form-required">บริษัท</label>
            <select class="form-select" name="CompanyID" id="poCompany" required style="font-family:'Prompt',sans-serif">
              <option value="">— เลือกบริษัท —</option>
              <?php foreach ($companies as $c) { if (!$c['IsActive']) { continue; } echo '<option value="'.$c['CompanyID'].'">'.htmlspecialchars($c['CompanyName']).'</option>'; } ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">รหัสตำแหน่ง</label>
            <input type="text" class="form-control" id="poCodeDisplay" value="ระบบสร้างให้อัตโนมัติ" readonly style="font-family:'Prompt',sans-serif;background:#F0F0F0;color:#999;font-size:0.85rem;">
          </div>
          <div class="col-md-8">
            <label class="form-label form-required">ชื่อตำแหน่ง</label>
            <input type="text" class="form-control" name="PositionName" id="poName" maxlength="200" required style="font-family:'Prompt',sans-serif">
          </div>
        </div></div>
        <div class="modal-footer" style="background:#F9FAFB">
          <button type="button" class="btn btn-outline-secondary btn-sm w-25" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn-cfp-add btn-sm"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
var csrfToken = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
var siData = <?php echo json_encode($siJS); ?>;
var coData = <?php echo json_encode($coJS); ?>;
var dvData = <?php echo json_encode($dvJS); ?>;
var dpData = <?php echo json_encode($dpJS); ?>;
var scData = <?php echo json_encode($scJS); ?>;
var poData = <?php echo json_encode($poJS); ?>;
var tabKeys = ['company','site','division','department','section','position'];

$(document).ready(function() {
    var savedTab = sessionStorage.getItem('cfp_org_tab');
    var urlTab   = '<?php echo $activeTab; ?>';
    var initTab  = (savedTab && tabKeys.indexOf(savedTab) !== -1) ? savedTab : urlTab;
    _applyTab(initTab, true);
    sessionStorage.setItem('cfp_org_tab', initTab);

    $('[id^="tbl-"]').each(function() {
        $(this).DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
            order: [[0,'asc']], pageLength: 25,
            dom: '<"cfp-dt-top row g-1 align-items-center mb-2"<"col-6 col-md-6 cfp-dt-length"l><"col-6 col-md-6 cfp-dt-filter"f>>rtip'
        });
    });

    initMobileExpand();
    $(window).on('resize', function() { initMobileExpand(); });

    var inner = document.getElementById('tabScrollInner');
    if (inner) { inner.addEventListener('scroll', function() { syncDotFromScroll(inner); }); }
});

function _applyTab(key, skipScroll) {
    document.querySelectorAll('.tab-pane-wrap').forEach(function(p) { p.classList.add('d-none'); });
    var pane = document.getElementById('tab-' + key);
    if (pane) { pane.classList.remove('d-none'); }
    document.querySelectorAll('.tab-nav-btn').forEach(function(b) {
        b.classList.remove('active');
        if (b.getAttribute('data-tabkey') === key) { b.classList.add('active'); }
    });
    updateDots(key);
    if (!skipScroll) {
        var btn = document.querySelector('.tab-nav-btn[data-tabkey="' + key + '"]');
        if (btn) { btn.scrollIntoView({ behavior:'smooth', block:'nearest', inline:'center' }); }
    }
}

function switchTab(key, btn) {
    _applyTab(key, false);
    sessionStorage.setItem('cfp_org_tab', key);
    if (window.innerWidth < 768) { initMobileExpand(); }
}
function switchTabByIndex(idx) {
    if (idx < 0 || idx >= tabKeys.length) { return; }
    var key = tabKeys[idx];
    _applyTab(key, false);
    sessionStorage.setItem('cfp_org_tab', key);
    if (window.innerWidth < 768) { initMobileExpand(); }
}
function updateDots(activeKey) {
    document.querySelectorAll('.cfp-tab-dot').forEach(function(dot) {
        var isAct = (tabKeys[parseInt(dot.getAttribute('data-idx'),10)] === activeKey);
        dot.classList.toggle('active', isAct);
        dot.style.width = isAct ? '18px' : '6px';
    });
}
function syncDotFromScroll(inner) {
    var btns = inner.querySelectorAll('.tab-nav-btn');
    var center = inner.scrollLeft + inner.offsetWidth / 2;
    var closest = 0; var minDist = Infinity;
    btns.forEach(function(b, i) {
        var dist = Math.abs((b.offsetLeft + b.offsetWidth / 2) - center);
        if (dist < minDist) { minDist = dist; closest = i; }
    });
    updateDots(tabKeys[closest]);
}

function initMobileExpand() {
    var isMobile = window.innerWidth < 768;
    document.querySelectorAll('.cfp-expand-btn').forEach(function(b) { b.remove(); });
    document.querySelectorAll('.cfp-detail-row').forEach(function(r) { r.remove(); });
    if (!isMobile) {
        document.querySelectorAll('.cfp-col-hide').forEach(function(el) { el.style.display = ''; });
        document.querySelectorAll('.cfp-td-num').forEach(function(el) { el.style.display = ''; });
        return;
    }
    /* จอเล็ก: ซ่อน column รองและเลขแถว */
    document.querySelectorAll('.cfp-col-hide').forEach(function(el) { el.style.display = 'none'; });
    document.querySelectorAll('.cfp-td-num').forEach(function(el) { el.style.display = 'none'; });

    /* วาง expand btn ใน td.cfp-td-expand */
    document.querySelectorAll('.cfp-org-table tbody tr').forEach(function(tr) {
        if (tr.classList.contains('cfp-detail-row')) { return; }
        var expandTd = tr.querySelector('td.cfp-td-expand');
        if (!expandTd) { return; }
        expandTd.innerHTML = '<span class="cfp-expand-btn" title="ดูรายละเอียด">&#xF285;</span>';
        var btn = expandTd.querySelector('.cfp-expand-btn');
        btn.addEventListener('click', function(e) { e.stopPropagation(); accordionToggle(tr, btn); });
    });
}

function accordionToggle(tr, btn) {
    var table  = tr.closest('table');
    var isOpen = (tr.nextElementSibling && tr.nextElementSibling.classList.contains('cfp-detail-row'));
    table.querySelectorAll('.cfp-detail-row').forEach(function(r) { r.remove(); });
    table.querySelectorAll('.cfp-expand-btn.expanded').forEach(function(b) { b.classList.remove('expanded'); });
    if (isOpen) { return; }
    var hiddenCells = tr.querySelectorAll('.cfp-col-hide');
    var headers     = table.querySelectorAll('thead th');
    var html = '<tr class="cfp-detail-row"><td colspan="99"><div class="cfp-detail-body">';
    hiddenCells.forEach(function(cell) {
        var colIdx = Array.prototype.indexOf.call(cell.parentNode.children, cell);
        var label  = headers[colIdx] ? headers[colIdx].textContent.trim() : '';
        if (!label) { return; }
        html += '<div class="cfp-detail-item"><span class="cfp-detail-label">'+label+'</span><span class="cfp-detail-value">'+cell.innerHTML+'</span></div>';
    });
    html += '</div></td></tr>';
    tr.insertAdjacentHTML('afterend', html);
    btn.classList.add('expanded');
}

/* ★ เพิ่ม 5: filter functions */
function cfpFilter(tableId, selectEl, colClass) {
    var value = selectEl.value;
    var tbl   = document.getElementById(tableId);
    if (!tbl) { return; }
    var ths    = tbl.querySelectorAll('thead th');
    var colIdx = -1;
    ths.forEach(function(th, i) { if (th.classList.contains(colClass)) { colIdx = i; } });
    if (colIdx < 0) { return; }
    tbl.querySelectorAll('tbody tr').forEach(function(tr) {
        var tds = tr.querySelectorAll('td');
        if (!tds[colIdx]) { return; }
        tr.style.display = (!value || tds[colIdx].textContent.trim() === value) ? '' : 'none';
    });
}
function cfpClearFilter(tableId, selectIds) {
    selectIds.forEach(function(id) { var el=document.getElementById(id); if(el){el.value='';} });
    var tbl = document.getElementById(tableId);
    if (tbl) { tbl.querySelectorAll('tbody tr').forEach(function(tr) { tr.style.display=''; }); }
}

function openModal(entity, id) {
    var fn = { company:openModalCompany, site:openModalSite, division:openModalDivision, department:openModalDept, section:openModalSection, position:openModalPosition };
    if (fn[entity]) { fn[entity](id); }
}
function openModalSite(id) {
    document.getElementById('formSite').reset();
    if (id == 0) {
        document.getElementById('titleSite').innerHTML = '<i class="bi bi-geo-alt-fill me-2"></i>เพิ่ม Site / โรงงาน';
        document.getElementById('siAction').value = 'create'; document.getElementById('siID').value = '0';
    } else {
        var d = siData[id]; if (!d) { return; }
        document.getElementById('titleSite').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไข Site';
        document.getElementById('siAction').value = 'update'; document.getElementById('siID').value = id;
        document.getElementById('siCode').value = d.code; document.getElementById('siName').value = d.name;
        document.getElementById('siScopeGroup').value = d.group || '';
    }
    new bootstrap.Modal(document.getElementById('modalSite')).show();
}
function openModalCompany(id) {
    document.getElementById('formCompany').reset();
    if (id == 0) {
        document.getElementById('titleCompany').innerHTML = '<i class="bi bi-building me-2"></i>เพิ่มบริษัท';
        document.getElementById('coAction').value = 'create'; document.getElementById('coID').value = '0';
    } else {
        var d = coData[id]; if (!d) return;
        document.getElementById('titleCompany').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขบริษัท';
        document.getElementById('coAction').value = 'update'; document.getElementById('coID').value = id;
        document.getElementById('coCode').value = d.code; document.getElementById('coName').value = d.name;
    }
    new bootstrap.Modal(document.getElementById('modalCompany')).show();
}
function toggleSite() {
    var t = document.getElementById('dvType').value;
    document.getElementById('siteWrap').style.display = (t === 'Factory') ? '' : 'none';
    if (t !== 'Factory') { document.getElementById('dvSite').value = ''; }
}
function openModalDivision(id) {
    document.getElementById('formDivision').reset();
    document.getElementById('siteWrap').style.display = 'none';
    if (id == 0) {
        document.getElementById('titleDivision').innerHTML = '<i class="bi bi-diagram-2 me-2"></i>เพิ่มฝ่าย';
        document.getElementById('dvAction').value = 'create'; document.getElementById('dvID').value = '0';
        var maxSort = 0; Object.values(dvData).forEach(function(d) { if (d.sort > maxSort) { maxSort = d.sort; } });
        document.getElementById('dvSort').value = maxSort + 1;
    } else {
        var d = dvData[id]; if (!d) return;
        document.getElementById('titleDivision').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขฝ่าย';
        document.getElementById('dvAction').value = 'update'; document.getElementById('dvID').value = id;
        document.getElementById('dvCompany').value = d.company; document.getElementById('dvType').value = d.type;
        toggleSite();
        if (d.site) { document.getElementById('dvSite').value = d.site; }
        document.getElementById('dvCode').value = d.code; document.getElementById('dvName').value = d.name;
        document.getElementById('dvSort').value = d.sort;
    }
    new bootstrap.Modal(document.getElementById('modalDivision')).show();
}
function openModalDept(id) {
    document.getElementById('dpDivision').value = ''; document.getElementById('dpName').value = '';
    var maxSort = 0; Object.values(dpData).forEach(function(d) { if (d.sort > maxSort) { maxSort = d.sort; } });
    document.getElementById('dpSort').value = maxSort + 1;
    if (id == 0) {
        document.getElementById('titleDept').innerHTML = '<i class="bi bi-diagram-3 me-2"></i>เพิ่มแผนก';
        document.getElementById('dpAction').value = 'create'; document.getElementById('dpID').value = '0';
        document.getElementById('dpCode').value = '';
        document.getElementById('dpCodeDisplay').textContent = 'สร้างอัตโนมัติ'; document.getElementById('dpCodeDisplay').style.color = '#4CAF50';
    } else {
        var d = dpData[id]; if (!d) return;
        document.getElementById('titleDept').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขแผนก';
        document.getElementById('dpAction').value = 'update'; document.getElementById('dpID').value = id;
        document.getElementById('dpDivision').value = d.division; document.getElementById('dpCode').value = d.code;
        document.getElementById('dpName').value = d.name; document.getElementById('dpSort').value = d.sort;
        document.getElementById('dpCodeDisplay').textContent = d.code; document.getElementById('dpCodeDisplay').style.color = '#1B3A4A';
    }
    new bootstrap.Modal(document.getElementById('modalDept')).show();
}
function filterDeptByDivision() {
    var divID = document.getElementById('scDivision').value;
    var deptSel = document.getElementById('scDept'); deptSel.value = '';
    Array.prototype.forEach.call(deptSel.options, function(opt) {
        if (!opt.value) { return; }
        opt.style.display = (!divID || opt.getAttribute('data-division') === divID) ? '' : 'none';
    });
    deptSel.options[0].text = divID ? '— เลือกแผนก —' : '— เลือกฝ่ายก่อน —';
}
function openModalSection(id) {
    document.getElementById('formSection').reset();
    document.getElementById('scDivision').value = '';
    Array.prototype.forEach.call(document.getElementById('scDept').options, function(opt) { if (opt.value) { opt.style.display = 'none'; } });
    document.getElementById('scDept').options[0].text = '— เลือกฝ่ายก่อน —';
    if (id == 0) {
        document.getElementById('titleSection').innerHTML = '<i class="bi bi-grid-3x3-gap me-2"></i>เพิ่มหน่วยงาน';
        document.getElementById('scAction').value = 'create'; document.getElementById('scID').value = '0';
    } else {
        var d = scData[id]; if (!d) { return; }
        document.getElementById('titleSection').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขหน่วยงาน';
        document.getElementById('scAction').value = 'update'; document.getElementById('scID').value = id;
        var deptOpt = document.querySelector('#scDept option[value="' + d.dept + '"]');
        if (deptOpt) { document.getElementById('scDivision').value = deptOpt.getAttribute('data-division'); filterDeptByDivision(); }
        document.getElementById('scDept').value = d.dept;
        document.getElementById('scCodeDisplay').value = d.code || 'ระบบสร้างให้อัตโนมัติ';
        document.getElementById('scName').value = d.name;
    }
    new bootstrap.Modal(document.getElementById('modalSection')).show();
}
function openModalPosition(id) {
    document.getElementById('formPosition').reset();
    if (id == 0) {
        document.getElementById('titlePosition').innerHTML = '<i class="bi bi-person-badge me-2"></i>เพิ่มตำแหน่ง';
        document.getElementById('poAction').value = 'create'; document.getElementById('poID').value = '0';
    } else {
        var d = poData[id]; if (!d) return;
        document.getElementById('titlePosition').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขตำแหน่ง';
        document.getElementById('poAction').value = 'update'; document.getElementById('poID').value = id;
        document.getElementById('poCompany').value = d.company;
        document.getElementById('poCodeDisplay').value = d.code || 'ระบบสร้างให้อัตโนมัติ';
        document.getElementById('poName').value = d.name;
    }
    new bootstrap.Modal(document.getElementById('modalPosition')).show();
}
function doSave(formID, tab) {
    var form = document.getElementById(formID);
    var data = new FormData(form);
    data.append('csrf_token', csrfToken);
    var btn = form.querySelector('[type="submit"]');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>กำลังบันทึก...';
    fetch('/carbonfootprint/master/ajax/org_save.php', { method:'POST', body:data })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            document.querySelectorAll('.modal.show').forEach(function(m) { bootstrap.Modal.getInstance(m).hide(); });
            showToast(res.msg || 'บันทึกเรียบร้อยแล้ว');
            setTimeout(function() { window.location.href = '/carbonfootprint/master/org.php?tab=' + tab; }, 800);
        } else { showToast(res.msg || 'เกิดข้อผิดพลาด', true); }
    })
    .catch(function() { showToast('เชื่อมต่อ server ไม่ได้', true); })
    .finally(function() { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>บันทึก'; });
}
function orgDelete(entity, id, name) {
    Swal.fire({
        title: 'ลบ "' + name + '" ?', text: 'ข้อมูลจะถูกปิดใช้งาน (ไม่ได้ลบถาวร)', icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#DC3545', cancelButtonColor: '#6C757D',
        confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก', reverseButtons: true,
        customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (!result.isConfirmed) { return; }
        var fd = new FormData();
        fd.append('action','delete'); fd.append('entity',entity); fd.append('id',id); fd.append('csrf_token',csrfToken);
        fetch('/carbonfootprint/master/ajax/org_save.php', { method:'POST', body:fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            showToast(res.msg, !res.success);
            if (res.success) { setTimeout(function() { window.location.href = '/carbonfootprint/master/org.php?tab=' + entity; }, 800); }
        });
    });
}
function showToast(msg, isError) {
    var bg = isError ? '#DC3545' : '#4CAF50';
    var ico = isError ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill';
    var el = document.getElementById('orgToast');
    if (!el) {
        el = document.createElement('div');
        el.id = 'orgToast';
        el.className = 'toast align-items-center text-white border-0 position-fixed top-0 end-0 m-3';
        el.style.zIndex = '9999';
        el.innerHTML = '<div class="d-flex"><div class="toast-body d-flex align-items-center gap-2"><i id="orgToastIcon"></i><span id="orgToastMsg"></span></div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
        document.body.appendChild(el);
    }
    el.style.background = bg;
    document.getElementById('orgToastIcon').className = 'bi ' + ico;
    document.getElementById('orgToastMsg').textContent = msg;
    new bootstrap.Toast(el, { delay: 2500 }).show();
}
</script>
</body>
</html>

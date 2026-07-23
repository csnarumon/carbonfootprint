<?php
/* master/watermeter.php — ทะเบียนมิเตอร์น้ำ (Krajee FileInput)
   แก้ไข: PDF Thumbnail, pending_deletes, ไม่มีหน้าแว๊บ, Filter 2 บรรทัด (แบบ vehicle.php)
*/
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4, 5));
$conn = getConnection();
$myUserID = (int)$_SESSION['user_id'];

$toastMsg = ''; $toastType = 'success';
if (!empty($_SESSION['toast_msg'])) {
    $toastMsg = $_SESSION['toast_msg'];
    $toastType = $_SESSION['toast_type'] ?? 'success';
    unset($_SESSION['toast_msg'], $_SESSION['toast_type']);
}

$resSite = sqlsrv_query($conn,"SELECT SiteID,SiteCode,SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
$sites = array();
while ($r = sqlsrv_fetch_array($resSite,SQLSRV_FETCH_ASSOC)) { $sites[] = $r; }

$siteCodeMap = array();
foreach ($sites as $s) { $siteCodeMap[(int)$s['SiteID']] = $s['SiteCode']; }

$resAllWMCodes = sqlsrv_query($conn, "SELECT MeterCode, SiteID FROM CFP_WaterMeter");
$maxNumBySite  = array();
if ($resAllWMCodes) {
    while ($rx = sqlsrv_fetch_array($resAllWMCodes, SQLSRV_FETCH_ASSOC)) {
        $sid = (int)$rx['SiteID'];
        $sc  = $siteCodeMap[$sid] ?? '';
        if (!$sc) continue;
        if (preg_match('/^WM-' . preg_quote($sc, '/') . '-(\d+)$/i', $rx['MeterCode'], $m)) {
            $num = (int)$m[1];
            if (!isset($maxNumBySite[$sid]) || $num > $maxNumBySite[$sid]) { $maxNumBySite[$sid] = $num; }
        }
    }
}

$resMT = @sqlsrv_query($conn,"SELECT TypeID,TypeCode,TypeName FROM CFP_WaterMeterType WHERE IsActive=1 ORDER BY TypeName");
$meterTypes = array();
if ($resMT) { while ($r = sqlsrv_fetch_array($resMT,SQLSRV_FETCH_ASSOC)) { $meterTypes[] = $r; } }

$resWS = @sqlsrv_query($conn,"SELECT SourceID,SourceCode,SourceName FROM CFP_WaterSourceType WHERE IsActive=1 ORDER BY SourceName");
$wSources = array();
if ($resWS) { while ($r = sqlsrv_fetch_array($resWS,SQLSRV_FETCH_ASSOC)) { $wSources[] = $r; } }

$res = sqlsrv_query($conn,"
    SELECT m.MeterID,m.MeterCode,m.MeterName,m.SiteID,s.SiteName,
           m.WaterMeterTypeID,mt.TypeName AS MeterTypeName,
           m.WaterSourceID,ws.SourceName AS WaterSourceName,
           m.MeterNo,m.InstallDate,m.Location,m.Remark,m.IsActive
    FROM CFP_WaterMeter m
    LEFT JOIN CFP_Site s ON s.SiteID=m.SiteID
    LEFT JOIN CFP_WaterMeterType mt ON mt.TypeID=m.WaterMeterTypeID
    LEFT JOIN CFP_WaterSourceType ws ON ws.SourceID=m.WaterSourceID
    ORDER BY m.MeterCode");
$rows = array();
while ($r = sqlsrv_fetch_array($res,SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }

$total  = count($rows);
$active = count(array_filter($rows,function($r){ return (bool)$r['IsActive']; }));

/* ---- Filter options (จาก Master) ---- */
$siteFilterOptions = array();
$resSiteAll = sqlsrv_query($conn, "SELECT SiteID, SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
if ($resSiteAll) {
    while ($row = sqlsrv_fetch_array($resSiteAll, SQLSRV_FETCH_ASSOC)) {
        $siteFilterOptions[$row['SiteID']] = $row['SiteName'];
    }
}

$meterTypeFilterOptions = array();
$resMTypeAll = sqlsrv_query($conn, "SELECT TypeID, TypeName FROM CFP_WaterMeterType WHERE IsActive=1 ORDER BY TypeName");
if ($resMTypeAll) {
    while ($row = sqlsrv_fetch_array($resMTypeAll, SQLSRV_FETCH_ASSOC)) {
        $meterTypeFilterOptions[$row['TypeID']] = $row['TypeName'];
    }
}

$waterSourceFilterOptions = array();
$resWSourceAll = sqlsrv_query($conn, "SELECT SourceID, SourceName FROM CFP_WaterSourceType WHERE IsActive=1 ORDER BY SourceName");
if ($resWSourceAll) {
    while ($row = sqlsrv_fetch_array($resWSourceAll, SQLSRV_FETCH_ASSOC)) {
        $waterSourceFilterOptions[$row['SourceID']] = $row['SourceName'];
    }
}

$map = array();
foreach ($rows as $r) {
    $map[$r['MeterID']] = array(
        'code'   => $r['MeterCode'],  'name'    => $r['MeterName'],
        'site'   => $r['SiteID']?(int)$r['SiteID']:'',
        'mtype'  => $r['WaterMeterTypeID']?(int)$r['WaterMeterTypeID']:'',
        'wsrc'   => $r['WaterSourceID']?(int)$r['WaterSourceID']:'',
        'mno'    => $r['MeterNo']??'',
        'install'=> $r['InstallDate']?date_format($r['InstallDate'],'Y-m-d'):'',
        'loc'    => $r['Location']??'', 'remark' => $r['Remark']??'',
    );
}
$pageTitle = 'มิเตอร์น้ำ';
$pageIcon  = 'droplet-half';

?>
<!DOCTYPE html><html lang="th"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>มิเตอร์น้ำ — CFP</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="/carbonfootprint/assets/css/cfp-theme.css?v=<?php echo filemtime('../assets/css/cfp-theme.css'); ?>" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/css/fileinput.min.css" rel="stylesheet">
<style>
body{font-family:'Prompt',sans-serif;}
.font-prompt{font-family:'Prompt',sans-serif!important;}
.btn-action{width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;font-size:0.8rem;}
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;}
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
</head><body>
<?php include '../includes/sidebar.php'; ?>
<div class="cfp-main">
<?php include '../includes/topbar.php'; ?>
<div class="cfp-content">

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);"><i class="bi bi-droplet-half me-2"></i>จัดการมิเตอร์น้ำ</h5>
    <div style="font-size:0.78rem;color:var(--cfp-text-muted)">ทะเบียนทรัพย์สิน (Scope 1) › มิเตอร์น้ำ</div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="cfp-card py-2 px-3 text-center"><div style="font-size:1.6rem;font-weight:700;color:var(--cfp-primary)"><?php echo $total; ?></div><div style="font-size:0.75rem;color:var(--cfp-text-muted)">ทั้งหมด</div></div></div>
  <div class="col-6 col-md-3"><div class="cfp-card py-2 px-3 text-center"><div style="font-size:1.6rem;font-weight:700;color:#43A047"><?php echo $active; ?></div><div style="font-size:0.75rem;color:var(--cfp-text-muted)">ใช้งาน</div></div></div>
  <div class="col-6 col-md-3"><div class="cfp-card py-2 px-3 text-center"><div style="font-size:1.6rem;font-weight:700;color:#F57C00"><?php echo $total-$active; ?></div><div style="font-size:0.75rem;color:var(--cfp-text-muted)">ปิดใช้งาน</div></div></div>
  <div class="col-6 col-md-3"><div class="cfp-card py-2 px-3 text-center"><div style="font-size:1.6rem;font-weight:700;color:#7B1FA2"><?php echo count(array_unique(array_filter(array_column($rows,'SiteID')))); ?></div><div style="font-size:0.75rem;color:var(--cfp-text-muted)">Site ที่มีมิเตอร์</div></div></div>
</div>

<!-- ===== Table Card (พร้อม Toolbar 2 บรรทัด) ===== -->
<div class="cfp-card">

    <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);margin-bottom:12px;">
        <i class="bi bi-card-list me-2"></i>รายการมิเตอร์น้ำ
    </div>

    <!-- Toolbar 2 บรรทัด -->
    <div class="cfp-page-toolbar">
        <!-- บรรทัดที่ 1: ค้นหา + สถานะ + ล้าง + ปุ่มเพิ่ม -->
        <div class="toolbar-row">
            <div class="toolbar-left">
                <input type="text" id="fltKeyword" class="form-control font-prompt"
                       placeholder="ค้นหารหัส / ชื่อมิเตอร์ / เลขมิเตอร์...">
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
                    <i class="bi bi-plus-circle me-1"></i>เพิ่มมิเตอร์น้ำ
                </button>
            </div>
        </div>

        <!-- บรรทัดที่ 2: Site + ประเภทมิเตอร์ + แหล่งน้ำ -->
        <div class="toolbar-row toolbar-row-filters">
            <div class="toolbar-left">
                <select id="fltSite" class="form-select font-prompt">
                    <option value="">Site ทั้งหมด</option>
                    <?php foreach ($siteFilterOptions as $id => $name) { ?>
                    <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
                    <?php } ?>
                </select>
                <select id="fltMType" class="form-select font-prompt">
                    <option value="">ประเภทมิเตอร์ทั้งหมด</option>
                    <?php foreach ($meterTypeFilterOptions as $id => $name) { ?>
                    <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
                    <?php } ?>
                </select>
                <select id="fltWSrc" class="form-select font-prompt">
                    <option value="">แหล่งน้ำทั้งหมด</option>
                    <?php foreach ($waterSourceFilterOptions as $id => $name) { ?>
                    <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table id="tblWM" class="table table-bordered table-hover align-middle" style="width:100%;font-size:0.85rem">
            <thead>
                <tr>
                    <th class="cfp-th-expand"></th>
                    <th class="cfp-th-num" style="width:40px">#</th>
                    <th>ชื่อมิเตอร์</th>
                    <th class="cfp-col-hide" style="width:130px">ประเภทมิเตอร์</th>
                    <th class="cfp-col-hide" style="width:120px">แหล่งน้ำ</th>
                    <th class="cfp-col-hide" style="width:110px">Site</th>
                    <th class="text-center" style="width:80px">สถานะ</th>
                    <th class="cfp-col-hide text-center" style="width:70px">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $r) { ?>
                <tr data-status="<?php echo $r['IsActive'] ? 'ใช้งาน' : 'ปิด'; ?>"
                    data-site="<?php echo htmlspecialchars($r['SiteName'] ?? ''); ?>"
                    data-type="<?php echo htmlspecialchars($r['MeterTypeName'] ?? ''); ?>"
                    data-source="<?php echo htmlspecialchars($r['WaterSourceName'] ?? ''); ?>">
                    <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
                    <td class="cfp-td-num"><?php echo $i+1; ?></td>
                    <td>
                        <div class="fw-500"><?php echo htmlspecialchars($r['MeterName']); ?></div>
                        <div><code style="font-size:0.7rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($r['MeterCode']); ?></code></div>
                        <?php if (!empty($r['MeterNo'])) { ?><div style="font-size:0.72rem;color:var(--cfp-text-muted)">เลขมิเตอร์: <?php echo htmlspecialchars($r['MeterNo']); ?></div><?php } ?>
                    </td>
                    <td class="cfp-col-hide"><?php echo htmlspecialchars($r['MeterTypeName']??'—'); ?></td>
                    <td class="cfp-col-hide"><?php echo htmlspecialchars($r['WaterSourceName']??'—'); ?></td>
                    <td class="cfp-col-hide"><?php echo htmlspecialchars($r['SiteName']??'—'); ?></td>
                    <td class="text-center">
                        <?php if ($r['IsActive']) { ?>
                            <span class="status-dot" style="background:#4CAF50;"></span><span style="font-size:0.78rem;color:#2E7D32;"> ใช้งาน</span>
                        <?php } else { ?>
                            <span class="status-dot" style="background:#ccc;"></span><span style="font-size:0.78rem;color:#9E9E9E;"> ปิด</span>
                        <?php } ?>
                    </td>
                    <td class="cfp-col-hide text-center">
                        <div class="cfp-action-group">
                            <button class="btn btn-outline-primary btn-action me-1 cfp-act-primary" onclick="openModal(<?php echo (int)$r['MeterID']; ?>)" title="แก้ไข"><i class="bi bi-pencil-square"></i><span class="cfp-act-label">แก้ไข</span></button>
                            <div class="cfp-act-secondary">
                                <button class="btn btn-action <?php echo $r['IsActive']?'btn-outline-danger':'btn-outline-success'; ?> cfp-act-toggle"
                                        onclick="confirmToggle(<?php echo (int)$r['MeterID']; ?>,<?php echo $r['IsActive']?1:0; ?>,'<?php echo htmlspecialchars(addslashes($r['MeterName'])); ?>')"
                                        title="<?php echo $r['IsActive']?'ปิด':'เปิด'; ?>">
                                    <i class="bi bi-<?php echo $r['IsActive']?'toggle2-off':'toggle2-on'; ?>"></i><span class="cfp-act-label"><?php echo $r['IsActive']?'ปิด':'เปิด'; ?></span>
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
</div></div>

<!-- Modal -->
<div class="modal fade" id="modalWM" tabindex="-1" data-bs-backdrop="static">
<div class="modal-dialog modal-dialog-centered modal-xl">
<div class="modal-content" style="font-family:'Prompt',sans-serif">
<div class="modal-header" style="background:var(--cfp-primary);color:#fff">
  <h6 class="modal-title mb-0" id="modalTitle"><i class="bi bi-droplet-half me-2"></i>เพิ่มมิเตอร์น้ำ</h6>
  <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form id="formWM" method="POST" enctype="multipart/form-data">
<input type="hidden" name="action" id="fAction" value="create">
<input type="hidden" name="id" id="fID" value="0">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
<div class="modal-body"><div class="row g-3">
  <div class="col-md-4"><label class="form-label">รหัส (Auto)</label>
    <input type="text" class="form-control" name="MeterCode" id="fCode" maxlength="50" readonly
           style="font-family:'Prompt',sans-serif;background:#f8f9fa;"></div>
  <div class="col-md-4"><label class="form-label form-required">ชื่อมิเตอร์</label>
    <input type="text" class="form-control" name="MeterName" id="fName" maxlength="300" required style="font-family:'Prompt',sans-serif"></div>
  <div class="col-md-4"><label class="form-label form-required">Site</label>
    <select class="form-select" name="SiteID" id="fSite" required
            onchange="updateAutoCode()" style="font-family:'Prompt',sans-serif">
      <option value="">— เลือก Site —</option>
      <?php foreach ($sites as $s) { ?><option value="<?php echo $s['SiteID']; ?>"><?php echo htmlspecialchars($s['SiteName']); ?></option><?php } ?>
    </select></div>
  <div class="col-md-6"><label class="form-label">ประเภทมิเตอร์</label>
    <select class="form-select" name="WaterMeterTypeID" id="fMType" style="font-family:'Prompt',sans-serif">
      <option value="">— ไม่ระบุ —</option>
      <?php foreach ($meterTypes as $t) { ?><option value="<?php echo $t['TypeID']; ?>"><?php echo htmlspecialchars($t['TypeCode'].' — '.$t['TypeName']); ?></option><?php } ?>
    </select></div>
  <div class="col-md-6"><label class="form-label">แหล่งน้ำ</label>
    <select class="form-select" name="WaterSourceID" id="fWSrc" style="font-family:'Prompt',sans-serif">
      <option value="">— ไม่ระบุ —</option>
      <?php foreach ($wSources as $ws) { ?><option value="<?php echo $ws['SourceID']; ?>"><?php echo htmlspecialchars($ws['SourceCode'].' — '.$ws['SourceName']); ?></option><?php } ?>
    </select></div>
  <div class="col-md-6"><label class="form-label">เลขมิเตอร์</label>
    <input type="text" class="form-control" name="MeterNo" id="fMNo" maxlength="100" style="font-family:'Prompt',sans-serif"></div>
  <div class="col-md-6"><label class="form-label">วันติดตั้ง</label>
    <input type="date" class="form-control" name="InstallDate" id="fInstall" style="font-family:'Prompt',sans-serif"></div>
  <div class="col-md-6"><label class="form-label">ตำแหน่งติดตั้ง</label>
    <input type="text" class="form-control" name="Location" id="fLoc" maxlength="300" style="font-family:'Prompt',sans-serif"></div>
  <div class="col-12"><label class="form-label">หมายเหตุ</label>
    <textarea class="form-control" name="Remark" id="fRemark" rows="2" maxlength="500" style="font-family:'Prompt',sans-serif;resize:none"></textarea></div>
  <div class="col-12">
    <label style="font-weight:600;color:var(--cfp-primary);font-size:0.85rem;"><i class="bi bi-images me-1"></i>รูปภาพ / เอกสารแนบ
      <span class="text-secondary small fw-normal"> .png .jpg .pdf | สูงสุด 10 ไฟล์ | รวมไม่เกิน 10 MB</span>
    </label>
    <div class="file-loading">
      <input id="file-1" name="file[]" type="file" class="file" multiple data-browse-on-zone-click="true">
    </div>
    <div id="cfp-file-error" class="mt-2 text-danger small" style="display:none;"></div>
  </div>
</div></div>
<div class="modal-footer" style="background:#F9FAFB">
  <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>ยกเลิก</button>
  <button type="button" class="btn-cfp-add btn-sm" id="btnSave"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
</div>
</form></div></div></div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999">
  <div id="toastSuccess" class="toast align-items-center text-bg-success border-0" style="font-family:'Prompt',sans-serif"><div class="d-flex"><div class="toast-body" id="toastMsg"></div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>
  <div id="toastError" class="toast align-items-center text-bg-danger border-0" style="font-family:'Prompt',sans-serif"><div class="d-flex"><div class="toast-body" id="toastErrMsg"></div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/js/fileinput.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/themes/fas/theme.min.js"></script>
<script src="../assets/js/cfp-table-mobile.js"></script>
<script>
var currentAssetID = 0;
var savedPreviewFiles = [];
var savedPreviewConfig = [];
var pendingFiles = [];
var pendingDeletes = [];
var _cfpDupTimer = null;

var wmData    = <?php echo json_encode($map, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var csrfToken = <?php echo json_encode($_SESSION['csrf_token']??'', JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var SITE_CODES = <?php echo json_encode($siteCodeMap, JSON_UNESCAPED_UNICODE); ?>;
var MAX_NUMS   = <?php echo json_encode($maxNumBySite, JSON_UNESCAPED_UNICODE); ?>;

function updateAutoCode() {
    var isEdit = (parseInt(document.getElementById('fID').value) || 0) > 0;
    if (isEdit) { return; }
    var siteID = parseInt(document.getElementById('fSite').value) || 0;
    var codeEl = document.getElementById('fCode');
    if (!siteID) { codeEl.value = ''; return; }
    var sc      = SITE_CODES[siteID] || 'SITE';
    var nextNum = (MAX_NUMS[siteID] || 0) + 1;
    codeEl.value = 'WM-' + sc + '-' + String(nextNum).padStart(3, '0');
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
        data: { asset_type: 'WaterMeter', asset_id: assetID },
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
        uploadUrl: 'watermeter_save.php',
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
            $('#formWM').serializeArray().forEach(function(item) {
                if (data[item.name] !== undefined) {
                    data[item.name] += ',' + item.value;
                } else {
                    data[item.name] = item.value;
                }
            });
            data['action'] = $('#fAction').val() || 'create';
            data['id'] = $('#fID').val() || '0';
            data['asset_type'] = 'WaterMeter';
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
            var modalEl = document.getElementById('modalWM');
            var bsModal = bootstrap.Modal.getInstance(modalEl);

            if (modalEl._cfpSaving) return;
            modalEl._cfpSaving = true;

            bsModal.hide();

            modalEl.addEventListener('hidden.bs.modal', function onHidden() {
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                modalEl._cfpSaving = false;

                Swal.fire({
                    icon: 'success',
                    title: successTitle,
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
            });
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
// openModal
// ============================================================
function openModal(id) {
    var form = document.getElementById('formWM');
    form.reset();
    pendingFiles = [];
    pendingDeletes = [];
    $('#cfp-file-error').hide().text('');

    if (id === 0) {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-droplet-half me-2"></i>เพิ่มมิเตอร์น้ำ';
        document.getElementById('fAction').value = 'create';
        document.getElementById('fID').value = '0';
        document.getElementById('fCode').value = '';
        currentAssetID = 0;
        savedPreviewFiles = [];
        savedPreviewConfig = [];
        _initFileInput([], []);
    } else {
        var d = wmData[id];
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขมิเตอร์น้ำ';
        document.getElementById('fAction').value = 'update';
        document.getElementById('fID').value = id;
        currentAssetID = id;

        document.getElementById('fCode').value = d.code || '';
        document.getElementById('fName').value = d.name || '';
        document.getElementById('fSite').value = d.site || '';
        document.getElementById('fMType').value = d.mtype || '';
        document.getElementById('fWSrc').value = d.wsrc || '';
        document.getElementById('fMNo').value = d.mno || '';
        document.getElementById('fInstall').value = d.install || '';
        document.getElementById('fLoc').value = d.loc || '';
        document.getElementById('fRemark').value = d.remark || '';

        loadPreviewFromDB(id, function(files, config) {
            savedPreviewFiles = files.slice();
            savedPreviewConfig = config.slice();
            _initFileInput(files, config);
        });
    }

    $('#btnSave').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>บันทึก');
    new bootstrap.Modal(document.getElementById('modalWM')).show();
}

// ============================================================
// Pre-fill จากคำขอเพิ่มทรัพย์สิน (master/asset_requests.php ส่ง query string มา)
// ============================================================
function cfpSelectByText(selectEl, text) {
    if (!selectEl || !text) { return; }
    text = text.trim();
    for (var i = 0; i < selectEl.options.length; i++) {
        if (selectEl.options[i].text.indexOf(text) !== -1) { selectEl.selectedIndex = i; return; }
    }
}

function cfpApplyPrefillFromRequest() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('prefill') !== '1') { return; }

    openModal(0);

    var name = params.get('name');
    var site = params.get('site');
    var mtype = params.get('watermetertype');
    var wsrc = params.get('watersourcetype');
    var meterNo = params.get('meterno');
    var install = params.get('installdate');
    var location = params.get('location');
    var remark = params.get('remark');

    if (name) { document.getElementById('fName').value = name; }
    if (site) { document.getElementById('fSite').value = site; }
    if (mtype) { cfpSelectByText(document.getElementById('fMType'), mtype); }
    if (wsrc) { cfpSelectByText(document.getElementById('fWSrc'), wsrc); }
    if (meterNo) { document.getElementById('fMNo').value = meterNo; }
    if (install) { document.getElementById('fInstall').value = install; }
    if (location) { document.getElementById('fLoc').value = location; }
    if (remark) { document.getElementById('fRemark').value = remark; }
}

// ============================================================
// DOMContentLoaded
// ============================================================
document.addEventListener('DOMContentLoaded', function() {

    cfpApplyPrefillFromRequest();

    document.getElementById('btnSave').addEventListener('click', function() {
        var btn = this;

        if (!$('#fName').val().trim()) {
            Swal.fire({
                icon: 'warning', title: 'ข้อมูลไม่ครบ',
                text: 'กรุณากรอกชื่อมิเตอร์น้ำ',
                confirmButtonText: 'ตกลง', customClass: { popup: 'font-prompt' }
            });
            return;
        }

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

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>กำลังบันทึก...';
        $('#file-1').fileinput('upload');
    });

    // ===== DataTable (ปรับให้ search ทำงาน) =====
    var table = $('#tblWM').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order: [[1, 'asc']],
        pageLength: 25,
        dom: 'lrtip',
        searching: true,
        columnDefs: [{ targets: 0, orderable: false, searchable: false }],
        drawCallback: function () { cfpInitMobileExpand('tblWM'); }
    });

    // ✅ ซ่อน Search Box ของ DataTable (ใช้ custom แทน)
    $('#tblWM_filter').hide();

    // ===== Custom Search =====
    $('#fltKeyword').on('keyup', function() {
        table.search(this.value).draw();
    });

    // ===== Custom Filter: ใช้ data-attributes =====
    $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
        var row = table.row(dataIndex).node();
        var status = $('#fltStatus').val();
        var site = $('#fltSite').val();
        var type = $('#fltMType').val();
        var source = $('#fltWSrc').val();

        if (status !== '' && $(row).attr('data-status') !== status) return false;
        if (site !== '' && $(row).attr('data-site') !== site) return false;
        if (type !== '' && $(row).attr('data-type') !== type) return false;
        if (source !== '' && $(row).attr('data-source') !== source) return false;
        return true;
    });

    // ===== Event listeners: เมื่อเลือก filter ให้ redraw =====
    $('#fltStatus, #fltSite, #fltMType, #fltWSrc').on('change', function() {
        table.draw();
    });

    // ===== Clear Filters =====
    window.clearFilter = function() {
        $('#fltKeyword').val('');
        $('#fltStatus').val('');
        $('#fltSite').val('');
        $('#fltMType').val('');
        $('#fltWSrc').val('');
        table.search('').draw();
    };

    var toastMsg = <?php echo json_encode($toastMsg, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
    var toastErr = <?php echo json_encode($toastType === 'error', JSON_HEX_TAG); ?>;
    if (toastMsg) showToast(toastMsg, toastErr);

    cfpBindMobileExpand('tblWM');
});

// ============================================================
// Toast
// ============================================================
function showToast(msg, isError) {
    if (!msg || msg === '') return;
    var id = isError ? 'toastError' : 'toastSuccess';
    var mid = isError ? 'toastErrMsg' : 'toastMsg';
    document.getElementById(mid).textContent = msg;
    new bootstrap.Toast(document.getElementById(id), { delay: 3000 }).show();
}

// ============================================================
// confirmToggle
// ============================================================
function confirmToggle(id, isActive, name) {
    var action = isActive ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    Swal.fire({
        title: action + 'มิเตอร์?',
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
            f.action = 'watermeter_save.php';
            f.innerHTML = '<input name="action" value="toggle"><input name="id" value="' + id + '"><input name="is_active" value="' + isActive + '">';
            document.body.appendChild(f);
            f.submit();
        }
    });
}
</script>
</body></html>
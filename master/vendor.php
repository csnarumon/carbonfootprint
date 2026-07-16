<?php
/* master/vendor.php — ทะเบียนชาวสวน (Scope 3 CAT4)
   แก้ไข: ใช้ตารางจริง (VendorType, ProductType, TaxID, TransportDist)
   + PDF Thumbnail, pending_deletes, ไม่มีหน้าแว๊บ, Filter 2 บรรทัด
*/
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4, 5));
$conn     = getConnection();
$userID   = (int)$_SESSION['user_id'];
$toastMsg = ''; $toastType = 'success';
if (!empty($_SESSION['toast_msg'])) {
    $toastMsg  = $_SESSION['toast_msg'];
    $toastType = $_SESSION['toast_type'] ?? 'success';
    unset($_SESSION['toast_msg'], $_SESSION['toast_type']);
}

/* ===== ดึงรายชื่อ Site ===== */
$resSite = sqlsrv_query($conn, "SELECT SiteID, SiteName FROM CFP_Site WHERE IsActive=1 ORDER BY SiteName");
$sites = array();
while ($s = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) { $sites[] = $s; }

/* ===== ดึงข้อมูลชาวสวน ===== */
$res = @sqlsrv_query($conn,"
    SELECT v.VendorID, v.VendorCode, v.VendorName, v.VendorType,
           v.ContactName, v.Phone, v.Address, v.Province,
           v.TaxID, v.ProductType, v.TransportDist,
           v.Remark, v.IsActive, v.SiteID, s.SiteName
    FROM CFP_Vendor v
    LEFT JOIN CFP_Site s ON s.SiteID = v.SiteID
    ORDER BY v.VendorCode");
$rows = array(); 
if ($res) { 
    while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { 
        $rows[] = $r; 
    } 
}
$total  = count($rows);
$active = count(array_filter($rows, function($r){ return (bool)$r['IsActive']; }));

/* ---- Filter options (จาก Master + ข้อมูลที่มี) ---- */
$provinceFilterOptions = array();
foreach ($rows as $r) {
    if (!empty($r['Province'])) {
        $provinceFilterOptions[$r['Province']] = $r['Province'];
    }
}
$provinceFilterOptions = array_unique($provinceFilterOptions);

$vendorTypeFilterOptions = array();
$resVendorType = sqlsrv_query($conn, "SELECT DISTINCT VendorType FROM CFP_Vendor WHERE VendorType IS NOT NULL AND VendorType != '' ORDER BY VendorType");
if ($resVendorType) {
    while ($row = sqlsrv_fetch_array($resVendorType, SQLSRV_FETCH_ASSOC)) {
        $vendorTypeFilterOptions[$row['VendorType']] = $row['VendorType'];
    }
}

$productTypeFilterOptions = array();
$resProductType = sqlsrv_query($conn, "SELECT DISTINCT ProductType FROM CFP_Vendor WHERE ProductType IS NOT NULL AND ProductType != '' ORDER BY ProductType");
if ($resProductType) {
    while ($row = sqlsrv_fetch_array($resProductType, SQLSRV_FETCH_ASSOC)) {
        $productTypeFilterOptions[$row['ProductType']] = $row['ProductType'];
    }
}
/* ===== สร้าง $map สำหรับ JavaScript ===== */
$map = array();
foreach ($rows as $r) {
    $map[$r['VendorID']] = array(
        'code'          => $r['VendorCode'],
        'name'          => $r['VendorName'],
        'vendorType'    => $r['VendorType'] ?? '',
        'contact'       => $r['ContactName'] ?? '',
        'phone'         => $r['Phone'] ?? '',
        'address'       => $r['Address'] ?? '',
        'province'      => $r['Province'] ?? '',
        'taxID'         => $r['TaxID'] ?? '',
        'productType'   => $r['ProductType'] ?? '',
        'transportDist' => $r['TransportDist'] ? (float)$r['TransportDist'] : '',
        'remark'        => $r['Remark'] ?? '',
        'siteID'        => $r['SiteID'] ? (int)$r['SiteID'] : '',
    );
}

$pageTitle = 'ทะเบียนชาวสวน';
$pageIcon  = 'tree';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ทะเบียนชาวสวน — CFP</title>
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
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="cfp-main">
<?php include '../includes/topbar.php'; ?>
<div class="cfp-content">

<!-- Page Header -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);">
            <i class="bi bi-tree me-2"></i>ทะเบียนชาวสวน
        </h5>
        <div style="font-size:0.78rem;color:var(--cfp-text-muted)">
            ทะเบียนทรัพย์สิน (Scope 3) › ทะเบียนชาวสวน
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
            <div style="font-size:1.6rem;font-weight:700;color:var(--cfp-primary)"><?php echo $total; ?></div>
            <div style="font-size:0.75rem;color:var(--cfp-text-muted)">ทั้งหมด</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
            <div style="font-size:1.6rem;font-weight:700;color:#43A047"><?php echo $active; ?></div>
            <div style="font-size:0.75rem;color:var(--cfp-text-muted)">ใช้งาน</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
            <div style="font-size:1.6rem;font-weight:700;color:#F57C00"><?php echo $total - $active; ?></div>
            <div style="font-size:0.75rem;color:var(--cfp-text-muted)">ปิดใช้งาน</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="cfp-card py-2 px-3 text-center">
            <div style="font-size:1.6rem;font-weight:700;color:#7B1FA2"><?php echo count(array_unique(array_filter(array_column($rows, 'Province')))); ?></div>
            <div style="font-size:0.75rem;color:var(--cfp-text-muted)">จังหวัด</div>
        </div>
    </div>
</div>

<!-- ===== Table Card (พร้อม Toolbar 2 บรรทัด) ===== -->
<div class="cfp-card">

    <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);margin-bottom:12px;">
        <i class="bi bi-card-list me-2"></i>รายการชาวสวน
    </div>

    <!-- Toolbar 2 บรรทัด -->
    <div class="cfp-page-toolbar">
        <!-- บรรทัดที่ 1: ค้นหา + สถานะ + ล้าง + ปุ่มเพิ่ม -->
        <div class="toolbar-row">
            <div class="toolbar-left">
                <input type="text" id="fltKeyword" class="form-control font-prompt"
                       placeholder="ค้นหารหัส / ชื่อชาวสวน / ที่อยู่...">
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
                    <i class="bi bi-plus-circle me-1"></i>เพิ่มชาวสวน
                </button>
            </div>
        </div>

        <!-- บรรทัดที่ 2: จังหวัด + ประเภทชาวสวน + ประเภทสินค้า -->
        <div class="toolbar-row toolbar-row-filters">
            <div class="toolbar-left">
                <select id="fltSite" class="form-select font-prompt">
                    <option value="">Site ทั้งหมด</option>
                    <?php foreach ($sites as $s) { ?>
                    <option value="<?php echo (int)$s['SiteID']; ?>"><?php echo htmlspecialchars($s['SiteName']); ?></option>
                    <?php } ?>
                </select>
                <select id="fltProvince" class="form-select font-prompt">
                    <option value="">จังหวัดทั้งหมด</option>
                    <?php foreach ($provinceFilterOptions as $province) { ?>
                    <option value="<?php echo htmlspecialchars($province); ?>"><?php echo htmlspecialchars($province); ?></option>
                    <?php } ?>
                </select>
                <select id="fltVendorType" class="form-select font-prompt">
                    <option value="">ประเภทชาวสวนทั้งหมด</option>
                    <?php foreach ($vendorTypeFilterOptions as $type) { ?>
                    <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                    <?php } ?>
                </select>
                <select id="fltProductType" class="form-select font-prompt">
                    <option value="">ประเภทสินค้าทั้งหมด</option>
                    <?php foreach ($productTypeFilterOptions as $product) { ?>
                    <option value="<?php echo htmlspecialchars($product); ?>"><?php echo htmlspecialchars($product); ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table id="tblVendor" class="table table-bordered table-hover align-middle" style="width:100%;font-size:0.85rem">
            <thead>
                <tr>
                    <th class="cfp-th-expand"></th>
                    <th class="cfp-th-num" style="width:40px">#</th>
                    <th>ชื่อ / ที่อยู่</th>
                    <th style="width:130px" class="cfp-col-hide">Site</th>
                    <th style="width:120px" class="cfp-col-hide">ผู้ติดต่อ</th>
                    <th style="width:120px" class="cfp-col-hide">ประเภท</th>
                    <th style="width:100px" class="cfp-col-hide">ระยะทาง (km)</th>
                    <th class="text-center" style="width:80px">สถานะ</th>
                    <th class="text-center cfp-col-hide" style="width:70px">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $r) { ?>
                <tr data-status="<?php echo $r['IsActive'] ? 'ใช้งาน' : 'ปิด'; ?>"
                    data-site="<?php echo (int)($r['SiteID'] ?? 0); ?>"
                    data-province="<?php echo htmlspecialchars($r['Province'] ?? ''); ?>"
                    data-vendortype="<?php echo htmlspecialchars($r['VendorType'] ?? ''); ?>"
                    data-product="<?php echo htmlspecialchars($r['ProductType'] ?? ''); ?>">
                    <td class="cfp-td-expand text-center" style="padding:4px;width:32px;"></td>
                    <td class="cfp-td-num"><?php echo $i + 1; ?></td>
                    <td>
                        <div class="fw-500"><?php echo htmlspecialchars($r['VendorName']); ?></div>
                        <div><code style="font-size:0.7rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($r['VendorCode']); ?></code></div>
                        <?php if (!empty($r['Province'])) { ?>
                        <div style="font-size:0.72rem;color:var(--cfp-text-muted)">
                            <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($r['Province']); ?>
                        </div>
                        <?php } ?>
                        <?php if (!empty($r['ProductType'])) { ?>
                        <div style="font-size:0.72rem;color:var(--cfp-text-muted)">
                            <i class="bi bi-tag me-1"></i><?php echo htmlspecialchars($r['ProductType']); ?>
                        </div>
                        <?php } ?>
                    </td>
                    <td class="small text-muted cfp-col-hide"><?php echo htmlspecialchars($r['SiteName'] ?? '—'); ?></td>
                    <td class="cfp-col-hide">
                        <div><?php echo htmlspecialchars($r['ContactName'] ?? '—'); ?></div>
                        <?php if (!empty($r['Phone'])) { ?>
                        <div style="font-size:0.72rem;color:var(--cfp-text-muted)"><?php echo htmlspecialchars($r['Phone']); ?></div>
                        <?php } ?>
                    </td>
                    <td class="cfp-col-hide"><?php echo htmlspecialchars($r['VendorType'] ?? '—'); ?></td>
                    <td class="text-center cfp-col-hide">
                        <?php echo $r['TransportDist'] ? number_format((float)$r['TransportDist'], 1) . ' km' : '—'; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($r['IsActive']) { ?>
                            <span class="status-dot" style="background:#4CAF50;"></span>
                            <span style="font-size:0.78rem;color:#2E7D32;"> ใช้งาน</span>
                        <?php } else { ?>
                            <span class="status-dot" style="background:#ccc;"></span>
                            <span style="font-size:0.78rem;color:#9E9E9E;"> ปิด</span>
                        <?php } ?>
                    </td>
                    <td class="text-center cfp-col-hide">
                        <div class="cfp-action-group">
                            <button class="btn btn-outline-primary btn-action me-1 cfp-act-primary"
                                    onclick="openModal(<?php echo (int)$r['VendorID']; ?>)"
                                    title="แก้ไข">
                                <i class="bi bi-pencil-square"></i><span class="cfp-act-label">แก้ไข</span>
                            </button>
                            <div class="cfp-act-secondary">
                                <button class="btn btn-action <?php echo $r['IsActive'] ? 'btn-outline-danger' : 'btn-outline-success'; ?> cfp-act-toggle"
                                        onclick="confirmToggle(<?php echo (int)$r['VendorID']; ?>, <?php echo $r['IsActive'] ? 1 : 0; ?>, '<?php echo htmlspecialchars(addslashes($r['VendorName'])); ?>')"
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

</div><!-- /.cfp-content -->
</div><!-- /.cfp-main -->

<!-- ===== Modal ===== -->
<div class="modal fade" id="modalVendor" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content" style="font-family:'Prompt',sans-serif">
            <div class="modal-header" style="background:var(--cfp-primary);color:#fff">
                <h6 class="modal-title mb-0" id="modalTitle">
                    <i class="bi bi-tree me-2"></i>เพิ่มชาวสวน
                </h6>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formVendor" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" id="fAction" value="create">
                <input type="hidden" name="id" id="fID" value="0">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- รหัส + ชื่อ -->
                        <div class="col-md-3">
                            <label class="form-label form-required">รหัสชาวสวน</label>
                            <input type="text" class="form-control" name="VendorCode" id="fCode" 
                                   maxlength="50" required style="font-family:'Prompt',sans-serif;text-transform:uppercase">
                        </div>
                        <div class="col-md-9">
                            <label class="form-label form-required">ชื่อชาวสวน / ฟาร์ม</label>
                            <input type="text" class="form-control" name="VendorName" id="fName"
                                   maxlength="300" required style="font-family:'Prompt',sans-serif">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-required">Site</label>
                            <select class="form-select" name="SiteID" id="fSite" required style="font-family:'Prompt',sans-serif">
                                <option value="">— เลือก Site —</option>
                                <?php foreach ($sites as $s) { ?>
                                <option value="<?php echo (int)$s['SiteID']; ?>"><?php echo htmlspecialchars($s['SiteName']); ?></option>
                                <?php } ?>
                            </select>
                        </div>

                        <!-- ประเภทชาวสวน + Tax ID -->
                        <div class="col-md-3">
                            <label class="form-label">ประเภทชาวสวน</label>
                            <input type="text" class="form-control" name="VendorType" id="fVendorType" 
                                   maxlength="100" placeholder="เช่น เกษตรกร, ผู้รวบรวม" style="font-family:'Prompt',sans-serif">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tax ID / เลขผู้เสียภาษี</label>
                            <input type="text" class="form-control" name="TaxID" id="fTaxID" 
                                   maxlength="20" style="font-family:'Prompt',sans-serif">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">ประเภทสินค้า</label>
                            <input type="text" class="form-control" name="ProductType" id="fProductType" 
                                   maxlength="200" placeholder="เช่น พืชผล, ผลไม้" style="font-family:'Prompt',sans-serif">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">ระยะทาง (km)</label>
                            <input type="number" class="form-control" name="TransportDist" id="fTransportDist" 
                                   step="0.1" min="0" style="font-family:'Prompt',sans-serif">
                        </div>

                        <!-- ผู้ติดต่อ + โทร -->
                        <div class="col-md-6">
                            <label class="form-label">ชื่อผู้ติดต่อ</label>
                            <input type="text" class="form-control" name="ContactName" id="fContact" 
                                   maxlength="200" style="font-family:'Prompt',sans-serif">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">เบอร์โทร</label>
                            <input type="text" class="form-control" name="Phone" id="fPhone" 
                                   maxlength="50" style="font-family:'Prompt',sans-serif">
                        </div>

                        <!-- ที่อยู่ + จังหวัด -->
                        <div class="col-12">
                            <label class="form-label">ที่อยู่</label>
                            <input type="text" class="form-control" name="Address" id="fAddress" 
                                   maxlength="500" style="font-family:'Prompt',sans-serif">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">จังหวัด</label>
                            <input type="text" class="form-control" name="Province" id="fProvince" 
                                   maxlength="100" style="font-family:'Prompt',sans-serif">
                        </div>

                        <!-- หมายเหตุ -->
                        <div class="col-12">
                            <label class="form-label">หมายเหตุ</label>
                            <textarea class="form-control" name="Remark" id="fRemark" 
                                      rows="2" maxlength="500" style="font-family:'Prompt',sans-serif;resize:none"></textarea>
                        </div>

                        <!-- Upload Panel -->
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
                    </div>
                </div>
                <div class="modal-footer" style="background:#F9FAFB">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>ยกเลิก
                    </button>
                    <button type="button" class="btn-cfp-add btn-sm" id="btnSave">
                        <i class="bi bi-check-circle me-1"></i>บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999">
    <div id="toastSuccess" class="toast align-items-center text-bg-success border-0" style="font-family:'Prompt',sans-serif">
        <div class="d-flex">
            <div class="toast-body" id="toastMsg"></div>
            <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
    <div id="toastError" class="toast align-items-center text-bg-danger border-0" style="font-family:'Prompt',sans-serif">
        <div class="d-flex">
            <div class="toast-body" id="toastErrMsg"></div>
            <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<!-- ===== JavaScript ===== -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/js/fileinput.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/kartik-v/bootstrap-fileinput@5.5.0/themes/fas/theme.min.js"></script>
<script src="../assets/js/cfp-table-mobile.js"></script>

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

var vendorData = <?php echo json_encode($map, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var csrfToken = <?php echo json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

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
        data: { asset_type: 'Vendor', asset_id: assetID },
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
        uploadUrl: 'vendor_save.php',
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
            $('#formVendor').serializeArray().forEach(function(item) {
                if (data[item.name] !== undefined) {
                    data[item.name] += ',' + item.value;
                } else {
                    data[item.name] = item.value;
                }
            });
            data['action'] = $('#fAction').val() || 'create';
            data['id'] = $('#fID').val() || '0';
            data['asset_type'] = 'Vendor';
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
            var modalEl = document.getElementById('modalVendor');
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
    var form = document.getElementById('formVendor');
    form.reset();
    pendingFiles = [];
    pendingDeletes = [];
    $('#cfp-file-error').hide().text('');

    if (id === 0) {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-tree me-2"></i>เพิ่มชาวสวน';
        document.getElementById('fAction').value = 'create';
        document.getElementById('fID').value = '0';
        document.getElementById('fSite').value = '';
        currentAssetID = 0;
        savedPreviewFiles = [];
        savedPreviewConfig = [];
        _initFileInput([], []);
    } else {
        var d = vendorData[id];
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>แก้ไขชาวสวน';
        document.getElementById('fAction').value = 'update';
        document.getElementById('fID').value = id;
        currentAssetID = id;

        document.getElementById('fCode').value = d.code || '';
        document.getElementById('fName').value = d.name || '';
        document.getElementById('fSite').value = d.siteID || '';
        document.getElementById('fVendorType').value = d.vendorType || '';
        document.getElementById('fTaxID').value = d.taxID || '';
        document.getElementById('fProductType').value = d.productType || '';
        document.getElementById('fTransportDist').value = d.transportDist || '';
        document.getElementById('fContact').value = d.contact || '';
        document.getElementById('fPhone').value = d.phone || '';
        document.getElementById('fAddress').value = d.address || '';
        document.getElementById('fProvince').value = d.province || '';
        document.getElementById('fRemark').value = d.remark || '';

        loadPreviewFromDB(id, function(files, config) {
            savedPreviewFiles = files.slice();
            savedPreviewConfig = config.slice();
            _initFileInput(files, config);
        });
    }

    $('#btnSave').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>บันทึก');
    new bootstrap.Modal(document.getElementById('modalVendor')).show();
}

// ============================================================
// Pre-fill จากคำขอเพิ่มทรัพย์สิน (master/asset_requests.php ส่ง query string มา)
// ============================================================
function cfpApplyPrefillFromRequest() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('prefill') !== '1') { return; }

    openModal(0);

    var name = params.get('name');
    var vendorType = params.get('vendortype');
    var taxID = params.get('taxid');
    var productType = params.get('producttype');
    var transportDist = params.get('transportdist');
    var contactName = params.get('contactname');
    var phone = params.get('phone');
    var address = params.get('address');
    var province = params.get('province');
    var remark = params.get('remark');

    if (name) { document.getElementById('fName').value = name; }
    if (vendorType) { document.getElementById('fVendorType').value = vendorType; }
    if (taxID) { document.getElementById('fTaxID').value = taxID; }
    if (productType) { document.getElementById('fProductType').value = productType; }
    if (transportDist) { document.getElementById('fTransportDist').value = transportDist; }
    if (contactName) { document.getElementById('fContact').value = contactName; }
    if (phone) { document.getElementById('fPhone').value = phone; }
    if (address) { document.getElementById('fAddress').value = address; }
    if (province) { document.getElementById('fProvince').value = province; }
    if (remark) { document.getElementById('fRemark').value = remark; }
}

// ============================================================
// DOMContentLoaded
// ============================================================
document.addEventListener('DOMContentLoaded', function() {

    cfpApplyPrefillFromRequest();

    document.getElementById('btnSave').addEventListener('click', function() {
        var btn = this;

        if (!$('#fCode').val().trim() || !$('#fName').val().trim()) {
            Swal.fire({
                icon: 'warning', title: 'ข้อมูลไม่ครบ',
                text: 'กรุณากรอกรหัสและชื่อชาวสวน',
                confirmButtonText: 'ตกลง', customClass: { popup: 'font-prompt' }
            });
            return;
        }

        if (!$('#fSite').val()) {
            Swal.fire({
                icon: 'warning', title: 'ข้อมูลไม่ครบ',
                text: 'กรุณาเลือก Site',
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
    var table = $('#tblVendor').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' },
        order: [[1, 'asc']],
        pageLength: 25,
        dom: 'lrtip',
        searching: true,
        columnDefs: [{ targets: 0, orderable: false, searchable: false }],
        drawCallback: function () { cfpInitMobileExpand('tblVendor'); }
    });

    // ✅ ซ่อน Search Box ของ DataTable (ใช้ custom แทน)
    $('#tblVendor_filter').hide();

    // ===== Custom Search =====
    $('#fltKeyword').on('keyup', function() {
        table.search(this.value).draw();
    });

    // ===== Custom Filter: ใช้ data-attributes =====
    $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
        var row = table.row(dataIndex).node();
        var status = $('#fltStatus').val();
        var site = $('#fltSite').val();
        var province = $('#fltProvince').val();
        var vendorType = $('#fltVendorType').val();
        var productType = $('#fltProductType').val();

        if (status !== '' && $(row).attr('data-status') !== status) return false;
        if (site !== '' && $(row).attr('data-site') !== site) return false;
        if (province !== '' && $(row).attr('data-province') !== province) return false;
        if (vendorType !== '' && $(row).attr('data-vendortype') !== vendorType) return false;
        if (productType !== '' && $(row).attr('data-product') !== productType) return false;
        return true;
    });

    // ===== Event listeners: เมื่อเลือก filter ให้ redraw =====
    $('#fltStatus, #fltSite, #fltProvince, #fltVendorType, #fltProductType').on('change', function() {
        table.draw();
    });

    // ===== Clear Filters =====
    window.clearFilter = function() {
        $('#fltKeyword').val('');
        $('#fltStatus').val('');
        $('#fltSite').val('');
        $('#fltProvince').val('');
        $('#fltVendorType').val('');
        $('#fltProductType').val('');
        table.search('').draw();
    };

    var toastMsg = <?php echo json_encode($toastMsg, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
    var toastErr = <?php echo json_encode($toastType === 'error', JSON_HEX_TAG); ?>;
    if (toastMsg) showToast(toastMsg, toastErr);

    cfpBindMobileExpand('tblVendor');
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
        title: action + 'ชาวสวน?',
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
            f.action = 'vendor_save.php';
            f.innerHTML = '<input name="action" value="toggle"><input name="id" value="' + id + '"><input name="is_active" value="' + isActive + '">';
            document.body.appendChild(f);
            f.submit();
        }
    });
}
</script>
</body>
</html>
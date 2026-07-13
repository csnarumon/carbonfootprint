<?php
/* =============================================================
   data_entry/scope2.php
   บันทึกข้อมูล Scope 2 — Energy Indirect GHG Emission
   - Role 1 (DataEntry)   : เห็น+กรอกเฉพาะสิทธิ์ใน CFP_UserScopeAccess
   - Role 2,3,6           : read-only ทุก item
   - Role 4,5 (Admin)     : bypass — เห็น+กรอกทุกอย่าง
   ============================================================= */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(1, 2, 3, 4, 5, 6));

$conn      = getConnection();
/* ── ใช้ Effective UserID เสมอ — ถ้า Admin กำลัง View-as จะเห็น/กรองข้อมูลตรงกับ user ที่สวมอยู่จริง ── */
$userID    = getEffectiveUserID();

/* ── Auto-fill ผู้รับผิดชอบข้อมูล — FullName เอาจาก session ตรงๆ (ไม่ query ซ้ำ) ── */
$myFullName = isViewingAs() ? ($_SESSION['view_as_name'] ?? '') : ($_SESSION['fullname'] ?? '');
$myDivID    = 0;

/* ลองหา DivisionID ของ user จากโครงสร้างใหม่ก่อน */
$resMe = sqlsrv_query($conn, "SELECT DivisionID FROM CFP_User WHERE UserID=?", array($userID));
if ($resMe) {
    $rMe = sqlsrv_fetch_array($resMe, SQLSRV_FETCH_ASSOC);
    if ($rMe && !empty($rMe['DivisionID'])) { $myDivID = (int)$rMe['DivisionID']; }
}
$myDeptID = $myDivID;
$roleID    = getActualRole();
$effRole   = getEffectiveRole();
/* View-as ต้อง bypass ไม่ได้ — บังคับดูตามสิทธิ์จริงของ user ที่สวมอยู่เสมอ (ไม่ใช่สิทธิ์ Admin ตัวจริง) */
$isSuperAdmin = isSuperAdmin() && !isViewingAs();
/* Admin/SustainAdmin เข้าตรงๆ (ไม่ผ่าน Elevate เป็น Data Entry) = ดูได้อย่างเดียว
   ให้กรอกข้อมูลจริงต้อง Elevate Role เป็น Data Entry หรือ View-as เท่านั้น */
$canEdit   = ($effRole === 1) && !isViewingAs();

/* ===== filter เดือน/ปี + Site ===== */
$filterYM   = $_GET['ym']     ?? date('Ym');
$filterSite = (int)($_GET['site'] ?? 0);
$filterYear  = (int)substr($filterYM, 0, 4);
$filterMonth = (int)substr($filterYM, 4, 2);
$ymLabel     = $filterYear . '-' . str_pad($filterMonth, 2, '0', STR_PAD_LEFT);

/* ===== ดึงสิทธิ์ Data Entry (Scope) — ต้องทำก่อนดึง Site dropdown เพื่อกรอง Site ที่ไม่มีสิทธิ์ออก ===== */
$allowedCats  = null; /* null = ทุก category */
$allowedSites = null; /* null = ทุก Site */
if (!$isSuperAdmin && $effRole === 1) {
    $resAcc = sqlsrv_query($conn,
        "SELECT CategoryIDs, SiteID FROM CFP_UserScopeAccess
         WHERE UserID=? AND ScopeNo=2 AND IsActive=1",
        array($userID));
    if ($resAcc) {
        $allowedCats  = array();
        $allowedSites = array();
        while ($r = sqlsrv_fetch_array($resAcc, SQLSRV_FETCH_ASSOC)) {
            if (!empty($r['CategoryIDs'])) {
                foreach (explode(',', $r['CategoryIDs']) as $c) { $allowedCats[] = (int)trim($c); }
            } else {
                $allowedCats = null; /* null = ทุก */
            }
            if ($r['SiteID']) { $allowedSites[] = (int)$r['SiteID']; }
            else              { $allowedSites = null; }
        }
    }
}

/* ===== ดึง Site dropdown — กรองตาม $allowedSites ถ้ามีสิทธิ์จำกัด ===== */
$sqlSite = "SELECT SiteID,SiteName FROM CFP_Site WHERE IsActive=1";
$paramSite = array();
if ($allowedSites !== null) {
    if (empty($allowedSites)) {
        $sqlSite .= " AND 1=0";
    } else {
        $ph = implode(',', array_fill(0, count($allowedSites), '?'));
        $sqlSite .= " AND SiteID IN ($ph)";
        $paramSite = $allowedSites;
    }
}
$sqlSite .= " ORDER BY CASE WHEN SiteName LIKE N'%สำนักงานใหญ่%' THEN 0 ELSE 1 END, SiteName";
$resSite = sqlsrv_query($conn, $sqlSite, $paramSite);
$sites   = array(); while ($r = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) { $sites[] = $r; }
if ($allowedSites !== null && $filterSite && !in_array($filterSite, $allowedSites)) { $filterSite = 0; }
if (!$filterSite && !empty($sites)) { $filterSite = (int)$sites[0]['SiteID']; }

/* ดึงฝ่าย/หน่วยงานทั้งหมด (HQ+Factory) มาพร้อมกัน ไม่กรองตาม Site — เผื่อกรณีคนอื่นคีย์แทน */
$deptListHQ = array();
$deptListFactory = array();
$resDeptHQ = sqlsrv_query($conn,
    "SELECT DivisionID AS DeptID, DivisionName AS DeptName FROM CFP_Division
     WHERE IsActive=1 AND DivisionType='HQ' ORDER BY SortOrder, DivisionName");
if ($resDeptHQ) { while ($rD = sqlsrv_fetch_array($resDeptHQ, SQLSRV_FETCH_ASSOC)) { $deptListHQ[] = $rD; } }
$resDeptFa = sqlsrv_query($conn,
    "SELECT sc.SectionID AS DeptID, sc.SectionName AS DeptName
     FROM CFP_Section sc
     JOIN CFP_Department dp ON dp.DeptID = sc.DeptID
     JOIN CFP_Division dv ON dv.DivisionID = dp.DivisionID
     WHERE sc.IsActive=1 AND dv.DivisionType='Factory' AND dv.SiteID=?
     ORDER BY sc.SectionName", array($filterSite));
if ($resDeptFa) { while ($rD = sqlsrv_fetch_array($resDeptFa, SQLSRV_FETCH_ASSOC)) { $deptListFactory[] = $rD; } }

/* ===== ดึง ActivityItem Scope 1 ===== */
/* กรอง Item ตาม: ScopeNo + Site (CFP_ActivityItemSite) + สิทธิ์ Category */
$sqlItem = "
    SELECT i.ItemID, i.ItemCode, i.ItemName, i.CategoryNo, i.SortOrder,
           u.UnitName, u.UnitCode
    FROM CFP_ActivityItem i
    LEFT JOIN CFP_Unit u ON u.UnitID = i.UnitID
    INNER JOIN CFP_ActivityItemSite ais ON ais.ItemID = i.ItemID AND ais.SiteID = ? AND ais.IsActive = 1
    WHERE i.ScopeNo = 2 AND i.IsActive = 1
";
$paramItems = array($filterSite);
if ($allowedCats !== null && count($allowedCats) > 0) {
    $placeholders = implode(',', array_fill(0, count($allowedCats), '?'));
    $sqlItem .= " AND i.CategoryNo IN ($placeholders)";
    $paramItems = array_merge($paramItems, $allowedCats);
}
$sqlItem .= " ORDER BY i.CategoryNo, i.SortOrder, i.ItemName";
$resItem = sqlsrv_query($conn, $sqlItem, $paramItems);
$items   = array(); if ($resItem) { while ($r = sqlsrv_fetch_array($resItem, SQLSRV_FETCH_ASSOC)) { $items[] = $r; } }

/* ===== ดึง EF ล่าสุดของแต่ละ Item ===== */
$efMap = array(); /* ItemID → EFValue, Unit, GasType */
if (!empty($items)) {
    $itemIDs = array_column($items, 'ItemID');
    $ph      = implode(',', array_fill(0, count($itemIDs), '?'));
    $resEF   = sqlsrv_query($conn,
        "SELECT e.RefID, e.EFValue, e.Unit, e.GasType, e.YearApply, e.SourceID,
                s.SourceCode
         FROM CFP_EFValue e
         LEFT JOIN CFP_EFSource s ON s.SourceID = e.SourceID
         WHERE e.RefID IN ($ph) AND e.RefTable='CFP_ActivityItem' AND e.IsActive=1
         ORDER BY e.YearApply DESC",
        $itemIDs);
    if ($resEF) {
        while ($r = sqlsrv_fetch_array($resEF, SQLSRV_FETCH_ASSOC)) {
            $id = (int)$r['RefID'];
            if (!isset($efMap[$id])) { $efMap[$id] = $r; } /* เก็บแค่ปีล่าสุด */
        }
    }
}


/* ===== ดึงรายการทรัพย์สิน (Asset) สำหรับ dropdown ผูกกิจกรรม Scope 2 ===== */
/* Mapping: CAT1=Grid Mix, CAT2=Solar/REC -> ใช้ CFP_ElectricMeter ชุดเดียวกัน
   (ไม่มีตารางมิเตอร์แยกสำหรับ Solar — แยกประเภทแหล่งพลังงานด้วย ElectricSourceID ในตัวมาสเตอร์มิเตอร์เอง)
   CAT3-5 (ไอน้ำ/ความร้อน/District Cooling) -> ไม่เก็บข้อมูล asset */
$assetMap = array();

$resMeter = sqlsrv_query($conn,
    "SELECT MeterID AS AssetID, MeterCode AS AssetCode, MeterName AS AssetName
     FROM CFP_ElectricMeter
     WHERE SiteID = ? AND IsActive = 1
     ORDER BY MeterName",
    array($filterSite));
$meterList = array();
if ($resMeter) {
    while ($r = sqlsrv_fetch_array($resMeter, SQLSRV_FETCH_ASSOC)) {
        $r['AssetType'] = 'ElectricMeter';
        $meterList[]    = $r;
    }
}
$assetMap[1] = $meterList; /* Grid Mix (MEA/PEA) */
$assetMap[2] = $meterList; /* Solar PV / REC — ใช้รายชื่อมิเตอร์ชุดเดียวกับ CAT1 */

/* ลิงก์ไปหน้าทะเบียนทรัพย์สิน ใช้ตอนแสดง empty-state (ยังไม่มีทรัพย์สินให้เลือก) */
$assetPageMap = array(
    1 => array('url' => '/carbonfootprint/master/electricmeter.php', 'label' => 'ทะเบียนมิเตอร์ไฟฟ้า', 'type' => 'ElectricMeter', 'typeLabel' => 'มิเตอร์ไฟฟ้า'),
    2 => array('url' => '/carbonfootprint/master/electricmeter.php', 'label' => 'ทะเบียนมิเตอร์ไฟฟ้า', 'type' => 'ElectricMeter', 'typeLabel' => 'มิเตอร์ไฟฟ้า'),
);

$SCOPE_STR = 'Scope2'; /* ใช้ match CFP_MonthlyHeader.Scope */
$hdrID     = 0;
$hdrStatus = -1;

    /* ── prefill: ดึงข้อมูลที่บันทึกแล้วเดือนนั้น ── */
    /* JOIN ผ่าน MonthlyHeader เพราะ ActivityData ไม่มี SiteID+YearMonth ตรงๆ */
    $dataMap = array();
    if (!empty($items) && $filterSite) {
        $itemIDs = array_column($items, 'ItemID');
        $ph      = implode(',', array_fill(0, count($itemIDs), '?'));
        /* ดึง HeaderID ของ Site+Month+Scope ก่อน */
        $resHdr = @sqlsrv_query($conn,
            "SELECT HeaderID, Status, ResponsibleName, ResponsibleDeptID FROM CFP_MonthlyHeader
             WHERE SiteID=? AND YearMonth=? AND Scope=?",
            array($filterSite, $filterYM, $SCOPE_STR));
        $hdrRow = $resHdr ? sqlsrv_fetch_array($resHdr, SQLSRV_FETCH_ASSOC) : null;
        if ($hdrRow) {
            $hdrID  = (int)$hdrRow['HeaderID'];
            $hdrStatus = (int)$hdrRow['Status']; /* 0=Draft 1=Submitted 2=Approved */
            /* ถ้า Header นี้เคยบันทึกผู้รับผิดชอบไว้แล้ว ใช้ค่านั้นแทนค่า auto-fill จาก session ปัจจุบัน */
            if (!empty($hdrRow['ResponsibleName'])) { $myFullName = $hdrRow['ResponsibleName']; }
            if (!empty($hdrRow['ResponsibleDeptID'])) { $myDeptID = (int)$hdrRow['ResponsibleDeptID']; }
            $params = array_merge(array($hdrID), $itemIDs);
            $resData = @sqlsrv_query($conn,
                "SELECT ActivityID AS DataID, ItemID, Quantity, Remark, EvidenceFile, AssetID, AssetType
                 FROM CFP_ActivityData
                 WHERE HeaderID=? AND ItemID IN ($ph) AND IsActive=1",
                $params);
            if ($resData) {
                while ($r = sqlsrv_fetch_array($resData, SQLSRV_FETCH_ASSOC)) {
                    /* map Status จาก Header ลงแต่ละ row */
                    $statusMap = array(0=>'Draft', 1=>'Submitted', 2=>'Approved');
                    $r['Status'] = $statusMap[$hdrStatus] ?? 'Draft';
                    $dataMap[(int)$r['ItemID']] = $r;
                }
            }
        }
    }


/* ===== จัดกลุ่มตาม category =====
   หมายเหตุ: เหลือแค่ 2 Category ที่มีหลักฐานยืนยันจาก CFO_application_V.1.pdf
   (ไอน้ำ/ความร้อน/District Cooling เอาออกก่อน — เพิ่มกลับได้ทีหลังถ้ามีข้อมูลใช้งานจริง) */
$catLabels = array(
    1 => array('label' => 'ไฟฟ้า Grid Mix (MEA/PEA)',  'icon' => 'bi-lightning-charge', 'color' => '#7C3AED'),
    2 => array('label' => 'ไฟฟ้า Solar PV / REC',      'icon' => 'bi-sun',              'color' => '#D97706'),
);
$grouped = array();
foreach ($items as $item) {
    $cat = (int)$item['CategoryNo'];
    $grouped[$cat][] = $item;
}

/* ===== KPI counts ===== */
$totalItems   = count($items);
$filledItems  = count(array_filter($items, function($i) use ($dataMap) {
    $d = $dataMap[(int)$i['ItemID']] ?? null;
    return $d && $d['Quantity'] !== null;
}));
$submittedItems = count(array_filter($dataMap, function($d) { return ($d['Status'] ?? '') === 'Submitted'; }));
$totalCO2 = 0.0;
foreach ($dataMap as $d) {
    if ($d['Quantity'] !== null) {
        $iid = (int)$d['ItemID'];
        $ef  = isset($efMap[$iid]) ? (float)$efMap[$iid]['EFValue'] : 0;
        $totalCO2 += (float)$d['Quantity'] * $ef;
    }
}

$toastMsg = ''; $toastType = 'success';
if (!empty($_SESSION['toast_msg'])) {
    $toastMsg  = $_SESSION['toast_msg'];
    $toastType = $_SESSION['toast_type'] ?? 'success';
    unset($_SESSION['toast_msg'], $_SESSION['toast_type']);
}
?>
<!DOCTYPE html><html lang="th"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>บันทึกข้อมูล Scope 2 — ระบบบริหารจัดการคาร์บอนองค์กร</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">
<style>
body { font-family:'Prompt',sans-serif; }
.font-prompt { font-family:'Prompt',sans-serif !important; }

/* ── KPI ── */
.kpi-card { background:#fff; border:1px solid var(--cfp-border); border-radius:10px; padding:12px 16px; text-align:center; }
.kpi-num  { font-size:1.7rem; font-weight:700; line-height:1; }
.kpi-lbl  { font-size:0.72rem; color:var(--cfp-text-muted); margin-top:4px; }

/* ── Filter bar ── */
.filter-bar { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; padding:12px 16px; background:#fff; border:1px solid var(--cfp-border); border-radius:10px; margin-bottom:16px; }
.filter-bar select { padding:5px 10px; border:1px solid var(--cfp-border); border-radius:6px; font-family:'Prompt',sans-serif; font-size:0.82rem; color:var(--cfp-text); background:#fff; }
.filter-bar .input-group .input-group-text { background:#fff; border-color:var(--cfp-border); color:var(--cfp-text-muted); font-size:0.82rem; padding:4px 8px; }
.filter-bar .input-group .form-control { border-color:var(--cfp-border); font-family:'Prompt',sans-serif; font-size:0.82rem; color:var(--cfp-text); padding:4px 8px; }
.filter-bar .input-group .form-control:focus { border-color:var(--cfp-primary); box-shadow:0 0 0 3px rgba(42,171,184,0.12); }
.filter-bar .input-group .form-control:disabled { background:var(--cfp-bg); color:var(--cfp-text-muted); }
.filter-bar .fbar-r { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; }

/* ── Progress bar ── */
.prog-wrap { margin-bottom:16px; }
.prog-label { display:flex; justify-content:space-between; font-size:0.72rem; color:var(--cfp-text-muted); margin-bottom:4px; }
.prog-bg { background:var(--cfp-bg); border:1px solid var(--cfp-border); border-radius:4px; height:6px; overflow:hidden; }
.prog-fill { background:var(--cfp-primary); height:100%; border-radius:4px; transition:width .4s ease; }

/* ── Category tabs ── */
.cat-tabs { display:flex; gap:0; border-bottom:1px solid var(--cfp-border); margin-bottom:0; overflow-x:auto; scrollbar-width:none; }
.cat-tabs::-webkit-scrollbar { display:none; }
.cat-tab { padding:9px 14px; font-size:0.8rem; color:var(--cfp-text-muted); cursor:pointer; border-bottom:2px solid transparent; white-space:nowrap; display:flex; align-items:center; gap:6px; flex-shrink:0; background:none; border-top:none; border-left:none; border-right:none; }
.cat-tab.active { color:var(--cfp-primary); border-bottom-color:var(--cfp-primary); font-weight:500; }
.cat-tab .tab-cnt { font-size:0.68rem; padding:1px 6px; border-radius:9px; font-weight:600; }
.cat-tab.active .tab-cnt { background:var(--cfp-primary); color:#fff; }
.cat-tab:not(.active) .tab-cnt { background:var(--cfp-bg); color:var(--cfp-text-muted); }

/* ── Desktop: Data Table ── */
.entry-table { width:100%; border-collapse:collapse; font-size:0.82rem; }
.entry-table th { background:var(--cfp-bg); color:var(--cfp-text-muted); font-weight:500; padding:7px 10px; border-bottom:1px solid var(--cfp-border); font-size:0.72rem; letter-spacing:.4px; text-transform:uppercase; white-space:nowrap; }
.entry-table td { padding:8px 10px; border-bottom:1px solid var(--cfp-border); vertical-align:middle; }
.entry-table tr:last-child td { border-bottom:none; }
.entry-table tr:hover td { background:#F9FDFE; }
.qty-input { width:100px; padding:5px 8px; border:1px solid var(--cfp-primary); border-radius:5px; font-size:0.85rem; font-weight:500; text-align:right; background:#fff; color:var(--cfp-text); font-family:'Prompt',sans-serif; }
.qty-input:disabled { border-color:var(--cfp-border); background:var(--cfp-bg); color:var(--cfp-text-muted); cursor:not-allowed; }
.qty-input.empty { border-color:var(--cfp-border); }
.ef-badge { background:#EEF6F8; border:1px solid #B8E4EC; color:#1A5060; padding:2px 7px; border-radius:9px; font-size:0.72rem; white-space:nowrap; }
.co2-val { font-size:0.85rem; font-weight:600; color:#059669; }
.co2-empty { color:var(--cfp-text-muted); }

/* ── Status pills ── */
.sp { display:inline-flex; align-items:center; gap:3px; padding:2px 8px; border-radius:9px; font-size:0.72rem; font-weight:500; white-space:nowrap; }
.sp-draft     { background:#EEF6F8; color:#1A5060; border:1px solid #B8E4EC; }
.sp-submitted { background:#FEF3C7; color:#92400E; border:1px solid #FCD34D; }
.sp-approved  { background:#D1FAE5; color:#065F46; border:1px solid #6EE7B7; }
.sp-empty     { background:var(--cfp-bg); color:var(--cfp-text-muted); border:1px solid var(--cfp-border); }

/* ── Footer total ── */
.entry-footer { padding:10px 16px; border-top:1px solid var(--cfp-border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.total-co2 { font-size:1.1rem; font-weight:700; color:#059669; }
.total-lbl { font-size:0.72rem; color:var(--cfp-text-muted); }

/* ── Mobile Cards (≤ 640px) ── */
@media (max-width:640px) {
    .desktop-only { display:none !important; }
    .mobile-only  { display:block !important; }
    .filter-bar   { padding:10px 12px; }
    .filter-bar select { font-size:0.78rem; padding:4px 8px; }
}
@media (min-width:641px) {
    .mobile-only { display:none !important; }
}

/* Mobile card */
.m-card { background:#fff; border:1px solid var(--cfp-border); border-radius:8px; padding:10px 12px; margin-bottom:8px; }
.m-card-hd { display:flex; align-items:center; justify-content:space-between; margin-bottom:4px; }
.m-card-name { font-size:0.85rem; font-weight:500; color:var(--cfp-text); }
.m-card-sub  { font-size:0.72rem; color:var(--cfp-text-muted); margin-bottom:7px; }
.m-card-row  { display:flex; align-items:center; gap:6px; }
.m-qty  { flex:1; padding:5px 8px; border:1px solid var(--cfp-primary); border-radius:5px; font-size:0.9rem; font-weight:600; text-align:right; background:#fff; color:var(--cfp-text); font-family:'Prompt',sans-serif; }
.m-qty:disabled { border-color:var(--cfp-border); background:var(--cfp-bg); color:var(--cfp-text-muted); }
.m-qty.empty { border-color:var(--cfp-border); }
.m-unit   { font-size:0.75rem; color:var(--cfp-text-muted); white-space:nowrap; flex-shrink:0; }
.m-co2    { font-size:0.82rem; font-weight:600; color:#059669; white-space:nowrap; flex-shrink:0; }
.m-co2.na { color:var(--cfp-text-muted); }
.m-footer { display:flex; align-items:center; justify-content:space-between; margin-top:7px; padding-top:7px; border-top:1px solid var(--cfp-border); }

/* ── Sticky footer mobile ── */
.sticky-bar { position:sticky; bottom:0; background:#fff; border-top:1px solid var(--cfp-border); padding:8px 14px; display:flex; align-items:center; justify-content:space-between; z-index:100; }
@media (min-width:641px) { .sticky-bar { position:static; border-top:1px solid var(--cfp-border); padding:10px 16px; } }

/* ── Action buttons ── */
.btn-draft  { padding:5px 14px; border-radius:6px; font-size:0.8rem; background:#fff; color:var(--cfp-text-mid); border:1px solid var(--cfp-border); font-family:'Prompt',sans-serif; cursor:pointer; }
.btn-submit { padding:5px 14px; border-radius:6px; font-size:0.8rem; background:var(--cfp-primary); color:#fff; border:none; font-family:'Prompt',sans-serif; cursor:pointer; }
.btn-draft:hover  { background:var(--cfp-bg); }
.btn-submit:hover { background:var(--cfp-primary-dark); }


/* ── Asset dropdown ── */
.asset-select { width:100%; padding:4px 7px; border:1px solid var(--cfp-border); border-radius:5px;
    font-size:0.78rem; color:var(--cfp-text); background:#fff; font-family:'Prompt',sans-serif; }
.asset-select:disabled { background:var(--cfp-bg); color:var(--cfp-text-muted); cursor:not-allowed; }
.asset-select.selected { border-color:var(--cfp-primary); }

/* ── Attach link ── */
.attach-link { font-size:0.72rem; color:var(--cfp-primary); display:inline-flex; align-items:center; gap:2px; text-decoration:none; cursor:pointer; }
.attach-link:hover { text-decoration:underline; }
.attach-done { color:#059669; }

/* ── Remark input ── */
.remark-input { border:1px solid var(--cfp-border); border-radius:5px; font-size:0.78rem; padding:3px 7px; color:var(--cfp-text); font-family:'Prompt',sans-serif; width:100%; background:#fff; }
.remark-input:disabled { background:var(--cfp-bg); color:var(--cfp-text-muted); cursor:not-allowed; }

/* ── Lock overlay ── */
.row-locked td { opacity:.72; }
</style>
</head><body>
<?php include '../includes/sidebar.php'; ?>
<div class="cfp-main">
<?php include '../includes/topbar.php'; ?>
<div class="cfp-content">

<!-- Page header -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold" style="color:var(--cfp-primary);">
      <i class="bi bi-lightning-charge me-2" style="color:#7C3AED;"></i>บันทึกข้อมูล Scope 2
    </h5>
    <div style="font-size:0.72rem;color:var(--cfp-text-muted);">
      Data Entry › Scope 2 — Energy Indirect GHG Emission
      <?php if (!$canEdit) { ?><span class="ms-2 badge" style="background:#EDE9FE;color:#4C1D95;font-size:0.68rem;">Read-only</span><?php } ?>
    </div>
  </div>
</div>

<!-- KPI -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="kpi-card">
      <div class="kpi-num" style="color:var(--cfp-primary);"><?php echo $totalItems; ?></div>
      <div class="kpi-lbl">รายการทั้งหมด</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card">
      <div class="kpi-num" style="color:#059669;"><?php echo $filledItems; ?></div>
      <div class="kpi-lbl">กรอกแล้ว</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card">
      <div class="kpi-num" style="color:#F59E0B;"><?php echo $submittedItems; ?></div>
      <div class="kpi-lbl">รออนุมัติ</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card">
      <div class="kpi-num" style="color:#059669;"><?php echo number_format($totalCO2 / 1000, 2); ?></div>
      <div class="kpi-lbl">รวม tCO₂e เดือนนี้</div>
    </div>
  </div>
</div>

<!-- Filter bar -->
<div class="filter-bar">
  <div>
    <div style="font-size:0.72rem;color:var(--cfp-text-muted);margin-bottom:3px;">เดือน/ปี</div>
    <select id="selYM" onchange="applyFilter()" style="min-width:140px;">
      <?php
      for ($m = 0; $m < 12; $m++) {
          $ts  = mktime(0,0,0, date('m') - $m, 1, date('Y'));
          $val = date('Ym', $ts);
          $lbl = date('M Y', $ts);
          $sel = ($val === $filterYM) ? 'selected' : '';
          echo "<option value=\"$val\" $sel>$lbl</option>";
      }
      ?>
    </select>
  </div>
  <div>
    <div style="font-size:0.72rem;color:var(--cfp-text-muted);margin-bottom:3px;">Site / โรงงาน</div>
    <select id="selSite" onchange="applyFilter()">
      <?php foreach ($sites as $s) {
          $sel = ($s['SiteID'] == $filterSite) ? 'selected' : '';
          echo "<option value=\"{$s['SiteID']}\" $sel>" . htmlspecialchars($s['SiteName']) . "</option>";
      } ?>
    </select>
  </div>
  <div>
    <div style="font-size:0.72rem;color:var(--cfp-text-muted);margin-bottom:3px;">ผู้รับผิดชอบข้อมูล</div>
    <div class="input-group" style="min-width:190px;">
      <span class="input-group-text"><i class="bi bi-person"></i></span>
      <input type="text" id="responsibleName" class="form-control"
             value="<?php echo htmlspecialchars($myFullName); ?>"
             placeholder="ชื่อผู้รับผิดชอบ"
             <?php echo $canEdit ? '' : 'disabled'; ?>>
    </div>
  </div>
  <div>
    <div style="font-size:0.72rem;color:var(--cfp-text-muted);margin-bottom:3px;">ฝ่าย/หน่วยงาน</div>
    <select id="responsibleDept" style="min-width:200px;" <?php echo $canEdit ? '' : 'disabled'; ?>>
      <option value="">— ไม่ระบุ —</option>
      <?php if (!empty($deptListHQ)) { ?>
      <optgroup label="ฝ่าย (สำนักงานใหญ่)">
        <?php foreach ($deptListHQ as $dp) {
            $sel = ($dp['DeptID'] == $myDeptID) ? 'selected' : '';
            echo "<option value=\"{$dp['DeptID']}\" $sel>" . htmlspecialchars($dp['DeptName']) . "</option>";
        } ?>
      </optgroup>
      <?php } ?>
      <?php if (!empty($deptListFactory)) { ?>
      <optgroup label="หน่วยงาน (โรงงาน)">
        <?php foreach ($deptListFactory as $dp) {
            $sel = ($dp['DeptID'] == $myDeptID) ? 'selected' : '';
            $displayName = preg_replace('/^ฝ่าย/u', '', $dp['DeptName']);
            echo "<option value=\"{$dp['DeptID']}\" $sel>" . htmlspecialchars($displayName) . "</option>";
        } ?>
      </optgroup>
      <?php } ?>
    </select>
  </div>
  <div class="align-self-end" style="font-size:0.78rem;color:var(--cfp-text-muted);">
    <span id="progressText"><?php echo $filledItems; ?> / <?php echo $totalItems; ?> รายการ</span>
  </div>
  <?php if ($canEdit) { ?>
  <div class="fbar-r">
    <?php if ($hdrID > 0 && $hdrStatus === 0 && $filledItems > 0) { ?>
    <button class="btn btn-sm btn-outline-danger font-prompt" onclick="confirmCancelDraft()" style="font-size:0.8rem;">
      <i class="bi bi-trash me-1"></i>ยกเลิก Draft
    </button>
    <?php } ?>
    <button class="btn-draft" onclick="saveDraft()"><i class="bi bi-floppy me-1"></i>บันทึกร่าง</button>
    <button class="btn-submit" onclick="confirmSubmit()"><i class="bi bi-send me-1"></i>ส่งอนุมัติ</button>
  </div>
  <?php } ?>
</div>

<!-- Progress bar -->
<div class="prog-wrap">
  <div class="prog-label">
    <span>ความคืบหน้า</span>
    <span id="progPct"><?php echo $totalItems > 0 ? round($filledItems / $totalItems * 100) : 0; ?>%</span>
  </div>
  <div class="prog-bg">
    <div class="prog-fill" id="progBar"
         style="width:<?php echo $totalItems > 0 ? round($filledItems / $totalItems * 100) : 0; ?>%;"></div>
  </div>
</div>

<!-- Category content -->
<?php if (empty($grouped)) { ?>
<div class="cfp-card text-center py-5" style="color:var(--cfp-text-muted);">
  <i class="bi bi-inbox" style="font-size:2.5rem;display:block;margin-bottom:8px;opacity:.4;"></i>
  ไม่มีรายการ Scope 2 ที่ได้รับสิทธิ์ในช่วงนี้
</div>
<?php } else { ?>

<!-- Category Tabs -->
<div class="cfp-card" style="padding:0;">
  <div class="cat-tabs" id="catTabs">
    <?php
    $firstCat = true;
    foreach ($grouped as $catNo => $catItems) {
        $catInfo  = $catLabels[$catNo] ?? array('label'=>'อื่นๆ','icon'=>'bi-circle','color'=>'#888');
        $catFilled = count(array_filter($catItems, function($i) use ($dataMap) {
            $d = $dataMap[(int)$i['ItemID']] ?? null; return $d && $d['Quantity'] !== null;
        }));
        $activeClass = $firstCat ? 'active' : '';
    ?>
    <button class="cat-tab <?php echo $activeClass; ?>"
            onclick="switchCat(<?php echo $catNo; ?>)"
            id="catTab<?php echo $catNo; ?>">
      <i class="<?php echo $catInfo['icon']; ?>" style="font-size:0.85rem;color:<?php echo $catInfo['color']; ?>;"></i>
      <?php echo htmlspecialchars($catInfo['label']); ?>
      <span class="tab-cnt"><?php echo $catFilled; ?>/<?php echo count($catItems); ?></span>
    </button>
    <?php $firstCat = false; } ?>
  </div>

  <!-- Tab contents -->
  <?php
  $firstCat = true;
  foreach ($grouped as $catNo => $catItems) {
      $catInfo = $catLabels[$catNo] ?? array('label'=>'อื่นๆ','icon'=>'bi-circle','color'=>'#888');
      $dispStyle = $firstCat ? '' : 'display:none;';
  ?>
  <div class="cat-content" id="cat<?php echo $catNo; ?>" style="<?php echo $dispStyle; ?>">

    <!-- ══ DESKTOP TABLE ══ -->
    <div class="table-responsive desktop-only">
    <table class="entry-table">
      <thead>
        <tr>
          <th style="width:30px;">#</th>
          <th>รายการกิจกรรม</th>
          <th style="width:150px;">ทรัพย์สิน</th>
          <th style="width:130px;">ปริมาณ</th>
          <th style="width:60px;">หน่วย</th>
          <th style="width:140px;">ค่า EF</th>
          <th style="width:120px;">kgCO₂e</th>
          <th style="width:120px;">หมายเหตุ</th>
          <th style="width:70px;">แนบ</th>
          <th style="width:90px;">สถานะ</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($catItems as $idx => $item) {
          $iid    = (int)$item['ItemID'];
          $d      = $dataMap[$iid] ?? null;
          $ef     = $efMap[$iid]   ?? null;
          $status = $d ? $d['Status'] : '';
          $qty    = ($d && $d['Quantity'] !== null) ? (float)$d['Quantity'] : null;
          $co2    = ($qty !== null && $ef) ? $qty * (float)$ef['EFValue'] : null;
          $locked = ($status === 'Submitted' || $status === 'Approved');
          $rowLocked = $locked ? 'row-locked' : '';
          $inputDisabled = (!$canEdit || $locked) ? 'disabled' : '';
          $qtyClass = ($qty === null) ? 'qty-input empty' : 'qty-input';
          $co2Class = ($co2 !== null) ? 'co2-val' : 'co2-empty';
          $dataID   = $d ? (int)$d['DataID'] : 0;
      ?>
      <?php
          $catAssets    = $assetMap[$catNo] ?? array();
          $savedAssetID = $d ? (int)($d['AssetID'] ?? 0) : 0;
          $savedAssetTp = $d ? ($d['AssetType'] ?? '') : '';
          $assetSelClass = $savedAssetID ? ' selected' : '';
      ?>
      <tr class="<?php echo $rowLocked; ?>" data-item="<?php echo $iid; ?>" data-dataid="<?php echo $dataID; ?>"
          data-assetid="<?php echo $savedAssetID; ?>" data-assettype="<?php echo htmlspecialchars($savedAssetTp); ?>">
        <td style="color:var(--cfp-text-muted);font-size:0.75rem;"><?php echo $idx+1; ?></td>
        <td>
          <div style="font-weight:500;"><?php echo htmlspecialchars($item['ItemName']); ?></div>
          <div style="font-size:0.72rem;color:var(--cfp-text-muted);"><?php echo $item['ItemCode']; ?></div>
        </td>
        <td>
          <?php if (!empty($catAssets)) { ?>
          <select class="asset-select<?php echo $assetSelClass; ?>"
                  id="asset_<?php echo $iid; ?>"
                  <?php echo $inputDisabled; ?>
                  onchange="this.classList.toggle('selected',this.value!='')">
            <option value="">— เลือกทรัพย์สิน —</option>
            <?php foreach ($catAssets as $a) { ?>
            <option value="<?php echo $a['AssetID']; ?>"
                    data-type="<?php echo $a['AssetType']; ?>"
                    <?php echo ($a['AssetID'] == $savedAssetID) ? 'selected' : ''; ?>>
              <?php echo /*htmlspecialchars($a['AssetCode'] . ' — ' . */$a['AssetName']; ?>
            </option>
            <?php } ?>
          </select>
          <?php } else { ?>
          <?php $apInfo = $assetPageMap[$catNo] ?? null; ?>
          <span style="font-size:0.72rem;color:var(--cfp-text-muted);">ไม่ได้ระบุทรัพย์สิน</span>
          <?php if ($isSuperAdmin && $apInfo) { ?>
          <br><a href="<?php echo $apInfo['url']; ?>" target="_blank" style="font-size:0.68rem;color:var(--cfp-text-muted);">
            + เพิ่ม<?php echo htmlspecialchars($apInfo['label']); ?>
          </a>
          <?php } ?>
          <?php if ($canEdit && $apInfo) { ?>
          <br><a href="#" onclick="requestAsset(2,'<?php echo $apInfo['type']; ?>','<?php echo htmlspecialchars($apInfo['typeLabel']); ?>');return false;" style="font-size:0.68rem;color:var(--cfp-primary);">
            <i class="bi bi-plus-circle"></i> ขอเพิ่มทรัพย์สินใหม่
          </a>
          <?php } ?>
          <?php } ?>
        </td>
        <td>
          <input type="number"
                 class="<?php echo $qtyClass; ?>"
                 id="qty_<?php echo $iid; ?>"
                 value="<?php echo ($qty !== null) ? $qty : ''; ?>"
                 placeholder="0.00"
                 step="0.001" min="0"
                 <?php echo $inputDisabled; ?>
                 onchange="calcCO2(<?php echo $iid; ?>, <?php echo $ef ? $ef['EFValue'] : 0; ?>)"
                 oninput="calcCO2(<?php echo $iid; ?>, <?php echo $ef ? $ef['EFValue'] : 0; ?>)">
        </td>
        <td style="font-size:0.78rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($item['UnitName'] ?? ''); ?></td>
        <td>
          <?php if ($ef) { ?>
          <span class="ef-badge"><?php echo number_format((float)$ef['EFValue'], 4); ?> kgCO₂e/<?php echo htmlspecialchars($item['UnitName'] ?? ''); ?></span>
          <div style="font-size:0.68rem;color:var(--cfp-text-muted);margin-top:2px;"><?php echo htmlspecialchars($ef['SourceCode'] ?? ''); ?> <?php echo $ef['YearApply'] ?? ''; ?></div>
          <?php } else { ?><span style="font-size:0.75rem;color:var(--cfp-text-muted);">ไม่มี EF</span><?php } ?>
        </td>
        <td>
          <div class="<?php echo $co2Class; ?>" id="co2_<?php echo $iid; ?>">
            <?php echo ($co2 !== null) ? number_format($co2, 2) : '—'; ?>
          </div>
          <?php if ($co2 !== null) { ?>
          <div style="font-size:0.68rem;color:var(--cfp-text-muted);">= <?php echo number_format($co2/1000, 4); ?> tCO₂e</div>
          <?php } ?>
        </td>
        <td>
          <input type="text" class="remark-input"
                 id="rmk_<?php echo $iid; ?>"
                 value="<?php echo htmlspecialchars($d ? ($d['Remark'] ?? '') : ''); ?>"
                 placeholder="หมายเหตุ"
                 <?php echo $inputDisabled; ?>>
        </td>
        <td>
          <?php if ($d && !empty($d['EvidenceFile'])) { ?>
          <a class="attach-link attach-done" href="#" title="<?php echo htmlspecialchars($d['EvidenceFile']); ?>">
            <i class="bi bi-paperclip"></i>มีไฟล์
          </a>
          <?php } elseif ($canEdit && !$locked) { ?>
          <a class="attach-link" href="#" onclick="openAttach(<?php echo $iid; ?>);return false;">
            <i class="bi bi-paperclip"></i>แนบ
          </a>
          <?php } else { ?>
          <span style="font-size:0.72rem;color:var(--cfp-text-muted);">—</span>
          <?php } ?>
        </td>
        <td>
          <?php
          if ($status === 'Approved') {
              echo '<span class="sp sp-approved"><i class="bi bi-check2-circle"></i>อนุมัติ</span>';
          } elseif ($status === 'Submitted') {
              echo '<span class="sp sp-submitted"><i class="bi bi-hourglass-split"></i>รออนุมัติ</span>';
          } elseif ($status === 'Draft' || ($d && $d['Quantity'] !== null)) {
              echo '<span class="sp sp-draft"><i class="bi bi-pencil"></i>ร่าง</span>';
          } else {
              echo '<span class="sp sp-empty">ยังไม่กรอก</span>';
          }
          ?>
        </td>
      </tr>
      <?php } ?>
      </tbody>
    </table>
    </div><!-- /desktop -->

    <!-- ══ MOBILE CARDS ══ -->
    <div class="mobile-only" style="padding:10px 12px;">
    <?php foreach ($catItems as $idx => $item) {
        $iid    = (int)$item['ItemID'];
        $d      = $dataMap[$iid] ?? null;
        $ef     = $efMap[$iid]   ?? null;
        $status = $d ? $d['Status'] : '';
        $qty    = ($d && $d['Quantity'] !== null) ? (float)$d['Quantity'] : null;
        $co2    = ($qty !== null && $ef) ? $qty * (float)$ef['EFValue'] : null;
        $locked = ($status === 'Submitted' || $status === 'Approved');
        $inputDisabled = (!$canEdit || $locked) ? 'disabled' : '';
        $dataID = $d ? (int)$d['DataID'] : 0;
        $cardOpacity = $locked ? 'opacity:.8;' : '';
    ?>
    <div class="m-card" style="<?php echo $cardOpacity; ?>" data-item="<?php echo $iid; ?>" data-dataid="<?php echo $dataID; ?>">
      <div class="m-card-hd">
        <span class="m-card-name"><?php echo htmlspecialchars($item['ItemName']); ?></span>
        <?php
        if ($status === 'Approved') {
            echo '<span class="sp sp-approved"><i class="bi bi-check2-circle"></i>อนุมัติ</span>';
        } elseif ($status === 'Submitted') {
            echo '<span class="sp sp-submitted"><i class="bi bi-hourglass-split"></i>รออนุมัติ</span>';
        } elseif ($status === 'Draft' || ($d && $d['Quantity'] !== null)) {
            echo '<span class="sp sp-draft"><i class="bi bi-pencil"></i>ร่าง</span>';
        } else {
            echo '<span class="sp sp-empty">ยังไม่กรอก</span>';
        }
        ?>
      </div>
      <div class="m-card-sub">
        <?php echo $item['ItemCode']; ?>
        <?php if ($ef) { echo ' · EF ' . number_format((float)$ef['EFValue'], 3); } ?>
        <?php if ($locked) { echo ' 🔒'; } ?>
      </div>
      <div class="m-card-row">
        <input type="number"
               class="m-qty<?php echo ($qty === null) ? ' empty' : ''; ?>"
               id="mqty_<?php echo $iid; ?>"
               value="<?php echo ($qty !== null) ? $qty : ''; ?>"
               placeholder="0.00" step="0.001" min="0"
               <?php echo $inputDisabled; ?>
               oninput="calcCO2M(<?php echo $iid; ?>, <?php echo $ef ? $ef['EFValue'] : 0; ?>)"
               onchange="calcCO2M(<?php echo $iid; ?>, <?php echo $ef ? $ef['EFValue'] : 0; ?>)">
        <span class="m-unit"><?php echo htmlspecialchars($item['UnitName'] ?? ''); ?></span>
        <span class="m-co2<?php echo ($co2 === null) ? ' na' : ''; ?>" id="mco2_<?php echo $iid; ?>">
          <?php echo ($co2 !== null) ? number_format($co2, 1) : '—'; ?>
        </span>
        <span class="m-unit" style="color:var(--cfp-text-muted);font-size:0.68rem;">kg</span>
      </div>
      <div class="m-footer">
        <?php if ($canEdit && !$locked) { ?>
        <a class="attach-link" href="#" onclick="openAttach(<?php echo $iid; ?>);return false;">
          <i class="bi bi-paperclip"></i>แนบไฟล์
        </a>
        <?php } else { ?><span></span><?php } ?>
        <?php if ($ef) { ?>
        <span style="font-size:0.68rem;color:#2AABB8;"><?php echo htmlspecialchars($ef['SourceCode'] ?? ''); ?></span>
        <?php } ?>
      </div>
    </div>
    <?php } ?>
    </div><!-- /mobile -->

    <!-- Footer summary per category -->
    <div class="entry-footer">
      <div>
        <div class="total-lbl">รวม <?php echo htmlspecialchars($catInfo['label']); ?></div>
        <div class="total-co2" id="catTotal<?php echo $catNo; ?>">
          <?php
          $catCO2 = 0;
          foreach ($catItems as $ci) {
              $cid = (int)$ci['ItemID'];
              $cd  = $dataMap[$cid] ?? null;
              $cef = $efMap[$cid]   ?? null;
              if ($cd && $cd['Quantity'] !== null && $cef) {
                  $catCO2 += (float)$cd['Quantity'] * (float)$cef['EFValue'];
              }
          }
          echo number_format($catCO2, 2) . ' kgCO₂e';
          ?>
        </div>
      </div>
      <?php if ($canEdit) { ?>
      <div class="desktop-only d-flex gap-2">
        <?php if ($hdrID > 0 && $hdrStatus === 0 && $filledItems > 0) { ?>
        <button class="btn btn-sm btn-outline-danger font-prompt" onclick="confirmCancelDraft()" style="font-size:0.8rem;">
          <i class="bi bi-trash me-1"></i>ยกเลิก Draft
        </button>
        <?php } ?>
        <button class="btn-draft" onclick="saveDraft()"><i class="bi bi-floppy me-1"></i>บันทึกร่าง</button>
        <button class="btn-submit" onclick="confirmSubmit()"><i class="bi bi-send me-1"></i>ส่งอนุมัติ</button>
      </div>
      <?php } ?>
    </div>

  </div><!-- /cat-content -->
  <?php $firstCat = false; } ?>

</div><!-- /cfp-card -->
<?php } ?>

<!-- Sticky footer (mobile only) -->
<?php if ($canEdit) { ?>
<div class="sticky-bar mobile-only">
  <div>
    <div class="total-lbl">รวม Scope 1</div>
    <div class="total-co2" id="grandTotalM"><?php echo number_format($totalCO2, 2); ?> kg</div>
  </div>
  <div style="display:flex;gap:6px;">
    <button class="btn-draft" onclick="saveDraft()"><i class="bi bi-floppy"></i></button>
    <button class="btn-submit" onclick="confirmSubmit()"><i class="bi bi-send me-1"></i>ส่ง</button>
  </div>
</div>
<?php } ?>

</div><!-- /cfp-content -->
</div><!-- /cfp-main -->

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999;">
  <div id="toastOK" class="toast align-items-center text-bg-success border-0" style="font-family:'Prompt',sans-serif;">
    <div class="d-flex"><div class="toast-body" id="toastOKMsg"></div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
  <div id="toastErr" class="toast align-items-center text-bg-danger border-0" style="font-family:'Prompt',sans-serif;">
    <div class="d-flex"><div class="toast-body" id="toastErrMsg"></div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
var CSRF        = <?php echo json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var SITE_ID     = <?php echo (int)$filterSite; ?>;
var YM          = <?php echo json_encode($filterYM); ?>;
var CAN_EDIT    = <?php echo $canEdit ? 'true' : 'false'; ?>;
var HDR_STATUS  = <?php echo $hdrStatus; ?>;
var TOTAL_ITEMS = <?php echo $totalItems; ?>;

/* ── ค่า EF map จาก PHP ── */
var EF_MAP = <?php
$jsEF = array();
foreach ($efMap as $iid => $ef) { $jsEF[$iid] = (float)$ef['EFValue']; }
echo json_encode($jsEF, JSON_HEX_TAG|JSON_HEX_AMP);
?>;

/* ── category tab switch ── */
function switchCat(catNo) {
    document.querySelectorAll('.cat-content').forEach(function(el) { el.style.display = 'none'; });
    document.querySelectorAll('.cat-tab').forEach(function(el) { el.classList.remove('active'); });
    var el = document.getElementById('cat' + catNo);
    var tab = document.getElementById('catTab' + catNo);
    if (el)  { el.style.display = ''; }
    if (tab) { tab.classList.add('active'); }
}

/* ── calcCO2 (desktop) ── */
function calcCO2(iid, ef) {
    var input = document.getElementById('qty_' + iid);
    var co2el = document.getElementById('co2_' + iid);
    if (!input || !co2el) { return; }
    var qty = parseFloat(input.value);
    if (isNaN(qty) || qty < 0) { co2el.innerHTML = '—'; co2el.className = 'co2-empty'; return; }
    var co2 = qty * ef;
    co2el.innerHTML = co2.toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2})
                    + '<div style="font-size:0.68rem;color:var(--cfp-text-muted);">= ' + (co2/1000).toFixed(4) + ' tCO₂e</div>';
    co2el.className = 'co2-val';
    input.classList.remove('empty');
    updateProgress();
}

/* ── calcCO2M (mobile) ── */
function calcCO2M(iid, ef) {
    var input = document.getElementById('mqty_' + iid);
    var co2el = document.getElementById('mco2_' + iid);
    if (!input || !co2el) { return; }
    var qty = parseFloat(input.value);
    if (isNaN(qty) || qty < 0) { co2el.textContent = '—'; co2el.className = 'm-co2 na'; return; }
    co2el.textContent = (qty * ef).toLocaleString('th-TH', {minimumFractionDigits:1, maximumFractionDigits:1});
    co2el.className = 'm-co2';
    input.classList.remove('empty');
    updateProgress();
}

/* ── update progress bar ── */
function updateProgress() {
    var filled = document.querySelectorAll('input[id^="qty_"]:not(:disabled)');
    var cnt = 0;
    filled.forEach(function(el) { if (el.value && parseFloat(el.value) >= 0) { cnt++; } });
    var pct = TOTAL_ITEMS > 0 ? Math.round(cnt / TOTAL_ITEMS * 100) : 0;
    var bar = document.getElementById('progBar');
    var pctEl = document.getElementById('progPct');
    var txtEl = document.getElementById('progressText');
    if (bar) { bar.style.width = pct + '%'; }
    if (pctEl) { pctEl.textContent = pct + '%'; }
    if (txtEl) { txtEl.textContent = cnt + ' / ' + TOTAL_ITEMS + ' รายการ'; }
}

/* ── collect form data ── */
function collectData() {
    var rows = [];
    document.querySelectorAll('[data-item]').forEach(function(tr) {
        /* กันเก็บซ้ำ: desktop <tr> กับ mobile <div class="m-card"> มี data-item เดียวกัน
           เช็ค offsetParent === null เพื่อข้าม element ที่ถูกซ่อนอยู่ตาม responsive layout */
        if (tr.offsetParent === null) { return; }
        var iid    = parseInt(tr.dataset.item);
        var dataID = parseInt(tr.dataset.dataid) || 0;
        var qtyEl  = document.getElementById('qty_' + iid)  || document.getElementById('mqty_' + iid);
        var rmkEl  = document.getElementById('rmk_' + iid);
        if (!qtyEl || qtyEl.disabled) { return; }
        var qty = qtyEl.value.trim();
        if (qty === '') { return; }
        /* ดึง AssetID จาก dropdown desktop หรือ mobile */
        var assetEl = document.getElementById('asset_' + iid) || document.getElementById('masset_' + iid);
        var assetID = assetEl ? parseInt(assetEl.value) || 0 : 0;
        var assetOpt = assetEl && assetEl.selectedIndex > 0 ? assetEl.options[assetEl.selectedIndex] : null;
        var assetType = assetOpt ? (assetOpt.dataset.type || '') : '';
        rows.push({
            DataID:    dataID,
            ItemID:    iid,
            Quantity:  parseFloat(qty),
            Remark:    rmkEl ? rmkEl.value.trim() : '',
            AssetID:   assetID,
            AssetType: assetType
        });
    });
    return rows;
}

/* ── save draft ── */
/* ── Lock กัน race condition: ห้ามกด บันทึกร่าง/ส่งอนุมัติ ซ้อนกันจนกว่า request แรกจะเสร็จ ── */
var isSavingScope2 = false;

function saveDraft() {
    if (isSavingScope2) { return; }
    var rows = collectData();
    if (!rows.length) {
        Swal.fire({ icon:'warning', title:'ไม่มีข้อมูลที่จะบันทึก', confirmButtonText:'ตกลง', confirmButtonColor:'#2AABB8', customClass:{popup:'font-prompt'} });
        return;
    }
    isSavingScope2 = true;
    Swal.fire({ title:'กำลังบันทึก...', allowOutsideClick:false, didOpen:function(){Swal.showLoading();} });
    fetch('/carbonfootprint/data_entry/scope2_save.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'draft', siteID:SITE_ID, yearMonth:YM, csrf_token:CSRF, rows:rows,
                                responsibleName: document.getElementById('responsibleName').value.trim(),
                                responsibleDeptID: document.getElementById('responsibleDept').value })
    })
    .then(function(r){ return r.json(); })
    .then(function(res) {
        isSavingScope2 = false;
        if (res.success) {
            Swal.fire({ icon:'success', title:'บันทึกร่างสำเร็จ', timer:1500, showConfirmButton:false, customClass:{popup:'font-prompt'} })
            .then(function(){ location.reload(); });
        } else {
            Swal.fire({ icon:'error', title:'บันทึกไม่สำเร็จ', text:res.msg, confirmButtonText:'ตกลง', customClass:{popup:'font-prompt'} });
        }
    })
    .catch(function() {
        isSavingScope2 = false;
        Swal.fire({ icon:'error', title:'เชื่อมต่อ server ไม่ได้', confirmButtonText:'ตกลง', customClass:{popup:'font-prompt'} });
    });
}

/* ── confirm submit ── */
function confirmSubmit() {
    if (isSavingScope2) { return; }
    var rows = collectData();
    if (!rows.length) {
        Swal.fire({ icon:'warning', title:'กรุณากรอกข้อมูลก่อนส่ง', confirmButtonText:'ตกลง', confirmButtonColor:'#2AABB8', customClass:{popup:'font-prompt'} });
        return;
    }
    Swal.fire({
        title: 'ยืนยันการส่งอนุมัติ?',
        html:  '<b>' + rows.length + ' รายการ</b> จะถูกส่งรออนุมัติ<br>ไม่สามารถแก้ไขได้อีกจนกว่าจะได้รับการอนุมัติ',
        icon:  'question',
        showCancelButton:true,
        confirmButtonText:'<i class="bi bi-send me-1"></i>ส่งอนุมัติ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor:'#2AABB8',
        reverseButtons:true,
        customClass:{popup:'font-prompt'}
    }).then(function(result) {
        if (!result.isConfirmed) { return; }
        if (isSavingScope2) { return; }
        isSavingScope2 = true;
        Swal.fire({ title:'กำลังส่ง...', allowOutsideClick:false, didOpen:function(){Swal.showLoading();} });
        fetch('/carbonfootprint/data_entry/scope2_save.php', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ action:'submit', siteID:SITE_ID, yearMonth:YM, csrf_token:CSRF, rows:rows,
                                    responsibleName: document.getElementById('responsibleName').value.trim(),
                                    responsibleDeptID: document.getElementById('responsibleDept').value })
        })
        .then(function(r){ return r.json(); })
        .then(function(res) {
            isSavingScope2 = false;
            if (res.success) {
                Swal.fire({ icon:'success', title:'ส่งอนุมัติเรียบร้อย!', text:res.msg, timer:3000, timerProgressBar:true, showConfirmButton:true, confirmButtonText:'ตกลง', confirmButtonColor:'#2AABB8', customClass:{popup:'font-prompt'} })
                .then(function(){ location.reload(); });
            } else {
                Swal.fire({ icon:'error', title:'ส่งไม่สำเร็จ', text:res.msg, confirmButtonText:'ตกลง', customClass:{popup:'font-prompt'} });
            }
        })
        .catch(function(){
            isSavingScope2 = false;
            Swal.fire({ icon:'error', title:'เชื่อมต่อ server ไม่ได้', confirmButtonText:'ตกลง', customClass:{popup:'font-prompt'} });
        });
    });
}

/* ── attach file ── */
function openAttach(iid) {
    Swal.fire({ icon:'info', title:'แนบไฟล์', text:'ยังใช้งานไม่ได้', confirmButtonText:'ตกลง', customClass:{popup:'font-prompt'} });
}

/* ── ขอเพิ่มทรัพย์สินใหม่ (Data Entry ส่งคำขอให้ Admin ไปสร้างทะเบียนจริง) ── */
var ASSET_FIELD_SPECS = {
    Equipment: {
        primaryLabel: 'ชื่อเครื่องจักร', fields: [
            { key: 'equipmentType', label: 'ประเภทเครื่องจักร', type: 'select', group: 'equipmentType' },
            { key: 'fuelType', label: 'ประเภทเชื้อเพลิง', type: 'select', group: 'fuelType' }
        ]
    },
    Vehicle: {
        primaryLabel: 'ทะเบียนรถ', fields: [
            { key: 'vehicleType', label: 'ประเภทพาหนะ', type: 'select', group: 'vehicleType' },
            { key: 'fuelType', label: 'ประเภทเชื้อเพลิง', type: 'select', group: 'fuelType' }
        ]
    },
    Refrigerant: {
        primaryLabel: 'ชื่ออุปกรณ์', fields: [
            { key: 'refrigerantType', label: 'ประเภทสารทำความเย็น', type: 'select', group: 'refrigerantType' },
            { key: 'capacity', label: 'ขนาด (Btu/hr)', type: 'number' },
            { key: 'chargeKg', label: 'ปริมาณสารทำความเย็น (kg)', type: 'number' }
        ]
    },
    WaterMeter: {
        primaryLabel: 'ชื่อมิเตอร์', fields: [
            { key: 'waterMeterType', label: 'ประเภทมิเตอร์', type: 'select', group: 'waterMeterType' },
            { key: 'waterSourceType', label: 'แหล่งน้ำ', type: 'select', group: 'waterSourceType' },
            { key: 'location', label: 'ตำแหน่งติดตั้ง', type: 'text' }
        ]
    },
    ElectricMeter: {
        primaryLabel: 'ชื่อมิเตอร์', fields: [
            { key: 'electricMeterType', label: 'ประเภทมิเตอร์', type: 'select', group: 'electricMeterType' },
            { key: 'electricSourceType', label: 'แหล่งไฟฟ้า', type: 'select', group: 'electricSourceType' },
            { key: 'meterNo', label: 'เลขมิเตอร์', type: 'text' },
            { key: 'voltage', label: 'แรงดัน (V)', type: 'number' },
            { key: 'phase', label: 'Phase', type: 'select', staticOptions: ['1 Phase', '3 Phase'] },
            { key: 'maxLoad', label: 'Max Load (kW)', type: 'number' },
            { key: 'installDate', label: 'วันติดตั้ง', type: 'date' },
            { key: 'location', label: 'ตำแหน่งติดตั้ง', type: 'text' }
        ]
    },
    Vendor: {
        primaryLabel: 'ชื่อชาวสวน/ฟาร์ม', fields: [
            { key: 'productType', label: 'ประเภทสินค้า', type: 'text' },
            { key: 'contactName', label: 'ชื่อผู้ติดต่อ', type: 'text' },
            { key: 'phone', label: 'เบอร์โทร', type: 'text' },
            { key: 'province', label: 'จังหวัด', type: 'text' }
        ]
    },
    Waste: {
        primaryLabel: 'ชื่อขยะ/ของเสีย', fields: [
            { key: 'wasteType', label: 'ประเภทขยะ', type: 'select', group: 'wasteType' },
            { key: 'disposalMethod', label: 'วิธีกำจัด', type: 'select', group: 'disposalMethod' }
        ]
    },
    Employee: {
        primaryLabel: 'ชื่อ-นามสกุล', fields: [
            { key: 'department', label: 'แผนก', type: 'text' },
            { key: 'commuteType', label: 'ประเภทการเดินทาง', type: 'select', staticOptions: [
                'รถยนต์ส่วนตัว', 'รถจักรยานยนต์', 'ขนส่งสาธารณะ', 'เดิน/จักรยาน', 'รถรับส่งบริษัท', 'อื่นๆ'
            ] },
            { key: 'commuteDist', label: 'ระยะทาง (กม./เที่ยว)', type: 'number' }
        ]
    }
};

function requestAsset(scopeNo, assetType, typeLabel) {
    var spec = ASSET_FIELD_SPECS[assetType] || { primaryLabel: 'ชื่อ/รายละเอียด', fields: [] };
    var needsOptions = spec.fields.some(function (f) { return f.type === 'select' && f.group; });

    var buildAndShow = function (optionGroups) {
        var html = '<div class="text-start">';
        html += '<label class="form-label" style="font-size:0.8rem;font-weight:600;">' + spec.primaryLabel + ' <span class="text-danger">*</span></label>';
        html += '<input id="reqf_primary" type="text" class="form-control mb-2" style="font-family:\'Prompt\',sans-serif;">';
        spec.fields.forEach(function (f) {
            html += '<label class="form-label" style="font-size:0.8rem;font-weight:600;">' + f.label + '</label>';
            if (f.type === 'select') {
                var opts = f.staticOptions
                    ? f.staticOptions.map(function (o) { return { id: o, name: o }; })
                    : ((optionGroups && optionGroups[f.group]) || []);
                html += '<select id="reqf_' + f.key + '" class="form-select mb-2" style="font-family:\'Prompt\',sans-serif;"><option value="">— ไม่ระบุ —</option>';
                opts.forEach(function (o) { html += '<option value="' + o.name.replace(/"/g, '&quot;') + '">' + o.name + '</option>'; });
                html += '</select>';
            } else {
                var inputType = (f.type === 'number') ? 'number' : (f.type === 'date' ? 'date' : 'text');
                html += '<input id="reqf_' + f.key + '" type="' + inputType + '" class="form-control mb-2" style="font-family:\'Prompt\',sans-serif;">';
            }
        });
        html += '<label class="form-label" style="font-size:0.8rem;font-weight:600;">หมายเหตุเพิ่มเติม</label>';
        html += '<textarea id="reqf_extra" class="form-control" rows="2" style="font-family:\'Prompt\',sans-serif;"></textarea>';
        html += '</div>';

        Swal.fire({
            title: 'ขอเพิ่ม' + typeLabel + 'ใหม่',
            html: html,
            showCancelButton: true,
            confirmButtonText: 'ส่งคำขอ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#2AABB8',
            customClass: { popup: 'font-prompt' },
            width: 480,
            preConfirm: function () {
                var primary = document.getElementById('reqf_primary').value.trim();
                if (!primary) { Swal.showValidationMessage('กรุณาระบุ' + spec.primaryLabel); return false; }
                var lines = [];
                var details = { primary: primary };
                spec.fields.forEach(function (f) {
                    var el = document.getElementById('reqf_' + f.key);
                    var val = el ? el.value.trim() : '';
                    if (val) { lines.push(f.label + ': ' + val); details[f.key] = val; }
                });
                var extra = document.getElementById('reqf_extra').value.trim();
                if (extra) { lines.push('หมายเหตุเพิ่มเติม: ' + extra); details.remark = extra; }
                return { name: primary, remark: lines.join(' | '), details: details };
            }
        }).then(function (result) {
            if (!result.isConfirmed) { return; }
            fetch('/carbonfootprint/data_entry/asset_request_save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    scopeNo: scopeNo, assetType: assetType, siteID: SITE_ID,
                    requestedName: result.value.name, remark: result.value.remark, details: result.value.details, csrf_token: CSRF
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                Swal.fire({
                    icon: data.success ? 'success' : 'error',
                    title: data.success ? 'ส่งคำขอแล้ว' : 'เกิดข้อผิดพลาด',
                    text: data.msg, confirmButtonColor: '#2AABB8', customClass: { popup: 'font-prompt' }
                });
            })
            .catch(function () {
                Swal.fire({ icon: 'error', title: 'เชื่อมต่อ server ไม่ได้', confirmButtonColor: '#2AABB8', customClass: { popup: 'font-prompt' } });
            });
        });
    };

    if (needsOptions) {
        Swal.fire({ title: 'กำลังโหลด...', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
        fetch('/carbonfootprint/data_entry/asset_request_options.php?assetType=' + encodeURIComponent(assetType))
            .then(function (r) { return r.json(); })
            .then(function (data) { buildAndShow(data.groups || {}); })
            .catch(function () { buildAndShow({}); });
    } else {
        buildAndShow({});
    }
}

/* ── filter ── */
function applyFilter() {
    var ym   = document.getElementById('selYM').value;
    var site = document.getElementById('selSite').value;
    window.location.href = window.location.pathname + '?ym=' + ym + '&site=' + site;
}

/* ── init toast ── */
document.addEventListener('DOMContentLoaded', function() {
    var tm = <?php echo json_encode($toastMsg, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
    var te = <?php echo json_encode($toastType === 'error'); ?>;
    if (tm) {
        var id  = te ? 'toastErr'    : 'toastOK';
        var mid = te ? 'toastErrMsg' : 'toastOKMsg';
        document.getElementById(mid).textContent = tm;
        new bootstrap.Toast(document.getElementById(id), {delay:3000}).show();
    }
});

function confirmCancelDraft() {
    Swal.fire({
        icon: 'warning',
        title: 'ยกเลิก Draft ทั้งหมด?',
        html: 'ข้อมูล Scope 2 เดือนนี้จะถูกล้างทั้งหมด<br><small style="color:#6B7280;">ไม่สามารถกู้คืนได้</small>',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-trash me-1"></i>ยืนยัน ยกเลิก Draft',
        cancelButtonText: 'ปิด',
        confirmButtonColor: '#DC3545',
        cancelButtonColor: '#6B7280',
        customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (!result.isConfirmed) { return; }
        fetch('scope2_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF, action: 'cancel_draft', siteID: SITE_ID, yearMonth: YM })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                Swal.fire({
                    icon: 'success', title: 'ยกเลิก Draft เรียบร้อย',
                    timer: 1500, showConfirmButton: false,
                    customClass: { popup: 'font-prompt' }
                }).then(function() { location.reload(); });
            } else {
                Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: data.msg, confirmButtonColor: '#1B3A4A', customClass: { popup: 'font-prompt' } });
            }
        })
        .catch(function(e) {
            Swal.fire({ icon: 'error', title: 'เชื่อมต่อไม่ได้', text: e.message, confirmButtonColor: '#1B3A4A', customClass: { popup: 'font-prompt' } });
        });
    });
}
</script>
</body></html>
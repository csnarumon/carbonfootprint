<?php
/* =============================================================
   data_entry/scope1.php
   บันทึกข้อมูล Scope 1 — Direct GHG Emission
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
$myDeptFallback = $_SESSION['dept'] ?? ''; /* ฟิลด์เก่า ใช้ fallback ถ้าไม่มี DivisionID */

/* ลองหา DivisionID ของ user จากโครงสร้างใหม่ก่อน */
$resMe = sqlsrv_query($conn, "SELECT DivisionID FROM CFP_User WHERE UserID=?", array($userID));
if ($resMe) {
    $rMe = sqlsrv_fetch_array($resMe, SQLSRV_FETCH_ASSOC);
    if ($rMe && !empty($rMe['DivisionID'])) { $myDivID = (int)$rMe['DivisionID']; }
}
$myDeptID = $myDivID; /* ตัวแปรใช้ร่วมกับส่วน render ด้านล่าง (ชื่อเดิม myDeptID) */
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
         WHERE UserID=? AND ScopeNo=1 AND IsActive=1",
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
$sqlSite = "SELECT SiteID,SiteName,SiteCode FROM CFP_Site WHERE IsActive=1";
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

/* ดึงฝ่าย/หน่วยงานทั้งหมด (HQ+Factory) มาพร้อมกัน ไม่กรองตาม Site — เผื่อกรณีคนอื่นคีย์แทน
   แยกกลุ่มด้วย optgroup ตอน render แทนการทำ 2 dropdown แยก (ลด JS logic ที่ต้องเคลียร์ค่ากันเอง) */
$currentSiteCode = '';
foreach ($sites as $s) { if ($s['SiteID'] == $filterSite) { $currentSiteCode = $s['SiteCode']; break; } }
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
    SELECT i.ItemID, i.ItemCode, i.ItemName, i.CategoryNo, i.Scope1Type, i.SortOrder,
           u.UnitName, u.UnitCode
    FROM CFP_ActivityItem i
    LEFT JOIN CFP_Unit u ON u.UnitID = i.UnitID
    INNER JOIN CFP_ActivityItemSite ais ON ais.ItemID = i.ItemID AND ais.SiteID = ? AND ais.IsActive = 1
    WHERE i.ScopeNo = 1 AND i.IsActive = 1
";
$paramItems = array($filterSite);
/* Bug fix: สิทธิ์ CategoryIDs ใน CFP_UserScopeAccess เก็บเป็นเลข 1-4 (Stationary/Mobile/Fugitive/Process)
   แต่คอลัมน์ CategoryNo ในตารางจริงเป็น NULL เสมอสำหรับ Scope1 (ใช้ Scope1Type แทน)
   จึงต้องแปลงเลข CategoryID เป็น Scope1Type string ก่อน filter */
if ($allowedCats !== null && count($allowedCats) > 0) {
    $catNoToScope1Type = array(1 => 'Stationary', 2 => 'Mobile', 3 => 'Fugitive', 4 => 'Process');
    $allowedTypes = array();
    foreach ($allowedCats as $c) {
        if (isset($catNoToScope1Type[$c])) { $allowedTypes[] = $catNoToScope1Type[$c]; }
    }
    if (!empty($allowedTypes)) {
        $placeholders = implode(',', array_fill(0, count($allowedTypes), '?'));
        $sqlItem .= " AND i.Scope1Type IN ($placeholders)";
        $paramItems = array_merge($paramItems, $allowedTypes);
    }
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


/* ===== ดึงรายการทรัพย์สิน (Asset) สำหรับ dropdown ผูกกิจกรรม Scope 1 ===== */
/* Mapping: CAT1=Stationary->Equipment, CAT2=Mobile->Vehicle, CAT3=Fugitive->Cooling, CAT4=Process->ไม่มี asset */
$assetMap = array();

/* CAT1: Stationary Combustion -> CFP_Equipment */
$resAssetEq = sqlsrv_query($conn,
    "SELECT EquipmentID AS AssetID, EquipmentCode AS AssetCode, EquipmentName AS AssetName
     FROM CFP_Equipment
     WHERE SiteID = ? AND IsActive = 1
     ORDER BY EquipmentName",
    array($filterSite));
if ($resAssetEq) {
    while ($r = sqlsrv_fetch_array($resAssetEq, SQLSRV_FETCH_ASSOC)) {
        $r['AssetType'] = 'Equipment';
        $assetMap[1][]  = $r;
    }
} else {
    error_log('[CFP assetMap] CFP_Equipment query failed: ' . print_r(sqlsrv_errors(), true));
}

/* CAT2: Mobile Combustion -> CFP_Vehicle */
$resAssetVh = sqlsrv_query($conn,
    "SELECT VehicleID AS AssetID, VehicleCode AS AssetCode, VehicleName AS AssetName
     FROM CFP_Vehicle
     WHERE SiteID = ? AND IsActive = 1
     ORDER BY VehicleName",
    array($filterSite));
if ($resAssetVh) {
    while ($r = sqlsrv_fetch_array($resAssetVh, SQLSRV_FETCH_ASSOC)) {
        $r['AssetType'] = 'Vehicle';
        $assetMap[2][]  = $r;
    }
} else {
    error_log('[CFP assetMap] CFP_Vehicle query failed: ' . print_r(sqlsrv_errors(), true));
}

/* CAT3: Fugitive Emissions -> CFP_Cooling
   หมายเหตุ: ชื่อตารางคือ CFP_Cooling แต่บันทึก AssetType='Refrigerant'
   เพื่อให้ตรงกับ convention เดิมที่ refrigerant_save.php ใช้กับ CFP_AssetImage */
$resAssetRf = sqlsrv_query($conn,
    "SELECT c.CoolingID AS AssetID, c.CoolingCode AS AssetCode,
            c.CoolingName
            + ISNULL(' (' + CAST(CAST(c.Capacity AS BIGINT) AS VARCHAR) + ' ' + c.CapacityUnit + ')', '')
            + ISNULL(' — ' + c.Location, '')
            AS AssetName,
            ISNULL(rt.GWP100, 0) AS GWP100, rt.TypeName AS RefrigerantName
     FROM CFP_Cooling c
     LEFT JOIN CFP_RefrigerantType rt ON rt.TypeID = c.RefrigerantTypeID
     WHERE c.SiteID = ? AND c.IsActive = 1
     ORDER BY c.CoolingName",
    array($filterSite));
if ($resAssetRf) {
    while ($r = sqlsrv_fetch_array($resAssetRf, SQLSRV_FETCH_ASSOC)) {
        $r['AssetType'] = 'Refrigerant';
        $assetMap[3][]  = $r;
    }
} else {
    error_log('[CFP assetMap] CFP_Cooling query failed: ' . print_r(sqlsrv_errors(), true));
}
/* GWP map: CoolingID → GWP100 — ใช้คำนวณ CO2e ของ Fugitive Emissions แทน EFValue */
$assetGWPMap = array();
foreach ($assetMap[3] ?? array() as $a) {
    $assetGWPMap[(int)$a['AssetID']] = (float)($a['GWP100'] ?? 0);
}
/* CAT4: Process Emissions -> ไม่เก็บข้อมูล asset (ตามเอกสาร Architecture) */

/* ลิงก์ไปหน้าทะเบียนทรัพย์สิน ใช้ตอนแสดง empty-state (ยังไม่มีทรัพย์สินให้เลือก) */
$assetPageMap = array(
    1 => array('url' => '/carbonfootprint/master/equipment.php',   'label' => 'ทะเบียนเครื่องจักร',        'type' => 'Equipment',   'typeLabel' => 'เครื่องจักร'),
    2 => array('url' => '/carbonfootprint/master/vehicle.php',     'label' => 'ทะเบียนยานพาหนะ',          'type' => 'Vehicle',     'typeLabel' => 'ยานพาหนะ'),
    3 => array('url' => '/carbonfootprint/master/refrigerant.php', 'label' => 'ทะเบียนอุปกรณ์ทำความเย็น',  'type' => 'Refrigerant', 'typeLabel' => 'อุปกรณ์ทำความเย็น'),
);

/* DEBUG ชั่วคราว: แสดงจำนวนทรัพย์สินที่ดึงได้ต่อ CAT บนหน้าเว็บ (Admin เห็นเท่านั้น) — ลบออกทีหลังเมื่อยืนยันว่าทำงานถูกต้อง */
if (isSuperAdmin() && isset($_GET['debug_asset'])) {
    echo '<pre style="background:#fff3cd;padding:10px;font-size:12px;">DEBUG assetMap: '
        . 'SiteID=' . htmlspecialchars($filterSite) . ' | '
        . 'CAT1(Equipment)=' . count($assetMap[1] ?? array()) . ' | '
        . 'CAT2(Vehicle)=' . count($assetMap[2] ?? array()) . ' | '
        . 'CAT3(Cooling)=' . count($assetMap[3] ?? array())
        . '</pre>';
}

$SCOPE_STR = 'Scope1'; /* ใช้ match CFP_MonthlyHeader.Scope */
$hdrID     = 0;
$hdrStatus = -1; /* -1 = ยังไม่มี Header */

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
                "SELECT ActivityID AS DataID, ItemID, Quantity, Remark, EvidenceFile, AssetID, AssetType, Cost
                 FROM CFP_ActivityData
                 WHERE HeaderID=? AND ItemID IN ($ph) AND IsActive=1",
                $params);
            if ($resData) {
                while ($r = sqlsrv_fetch_array($resData, SQLSRV_FETCH_ASSOC)) {
                    /* map Status จาก Header ลงแต่ละ row */
                    $statusMap = array(0=>'Draft', 1=>'Submitted', 2=>'Approved');
                    $r['Status'] = $statusMap[$hdrStatus] ?? 'Draft';
                    /* เก็บเป็น array เพราะ 1 Item อาจมีหลายทรัพย์สิน (หลายแถว) ได้ */
                    $dataMap[(int)$r['ItemID']][] = $r;
                }
            }
        }
    }


/* ===== จัดกลุ่มตาม category =====
   หมายเหตุ (Bug fix): รายการ Scope 1 ใช้ฟิลด์ Scope1Type (string) เป็นตัวแยกประเภทจริง
   ไม่ใช่ CategoryNo (ซึ่งออกแบบไว้ใช้กับ Scope 3 เท่านั้น และเป็น NULL เสมอสำหรับ Scope1)
   เดิมโค้ด group ด้วย CategoryNo ทำให้ทุกรายการตกไปอยู่ tab fallback "อื่นๆ" หมด */
$catLabels = array(
    1 => array('label' => 'Stationary Combustion',  'icon' => 'bi-fire',        'color' => '#D97706'),
    2 => array('label' => 'Mobile Combustion',       'icon' => 'bi-truck',       'color' => '#7C3AED'),
    3 => array('label' => 'Fugitive Emissions',      'icon' => 'bi-thermometer', 'color' => '#2AABB8'),
    4 => array('label' => 'Process Emissions',       'icon' => 'bi-gear',        'color' => '#059669'),
);
$scope1TypeToCatNo = array(
    'Stationary' => 1,
    'Mobile'     => 2,
    'Fugitive'   => 3,
    'Process'    => 4,
);
$grouped = array();
foreach ($items as $item) {
    $cat = $scope1TypeToCatNo[$item['Scope1Type'] ?? ''] ?? 0;
    $grouped[$cat][] = $item;
}

/* ===== KPI counts ===== */
$totalItems   = count($items);
$filledItems  = count(array_filter($items, function($i) use ($dataMap) {
    $rowsForItem = $dataMap[(int)$i['ItemID']] ?? array();
    foreach ($rowsForItem as $d) { if ($d['Quantity'] !== null) { return true; } }
    return false;
}));
$submittedItemsCount = 0;
$totalCO2 = 0.0;
/* map ItemID → catNo สำหรับแยก CAT3 (Fugitive) ออกจากประเภทอื่น */
$itemCatMap = array();
foreach ($items as $_it) { $itemCatMap[(int)$_it['ItemID']] = $scope1TypeToCatNo[$_it['Scope1Type'] ?? ''] ?? 0; }
foreach ($dataMap as $rowsForItem) {
    foreach ($rowsForItem as $d) {
        if (($d['Status'] ?? '') === 'Submitted') { $submittedItemsCount++; }
        if ($d['Quantity'] !== null) {
            $iid    = (int)$d['ItemID'];
            $dCatNo = $itemCatMap[$iid] ?? 0;
            if ($dCatNo === 3) {
                $dAsset = (int)($d['AssetID'] ?? 0);
                $dGWP   = $dAsset > 0 ? ($assetGWPMap[$dAsset] ?? 0) : 0;
                $totalCO2 += (float)$d['Quantity'] * $dGWP;
            } else {
                $ef = isset($efMap[$iid]) ? (float)$efMap[$iid]['EFValue'] : 0;
                $totalCO2 += (float)$d['Quantity'] * $ef;
            }
        }
    }
}
$submittedItems = $submittedItemsCount;

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
<title>บันทึกข้อมูล Scope 1 — ระบบบริหารจัดการคาร์บอนองค์กร</title>
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
.cat-tab.cat-tab-empty { color:#B0BEC5; }
.cat-tab.cat-tab-empty:not(.active) .tab-cnt { background:#F5F5F5; color:#B0BEC5; }

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
      <i class="bi bi-fire me-2" style="color:#D97706;"></i>บันทึกข้อมูล Scope 1
    </h5>
    <div style="font-size:0.72rem;color:var(--cfp-text-muted);">
      Data Entry › Scope 1 — Direct GHG Emission
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
            /* ตัดคำนำหน้า "ฝ่าย" ออกตอนแสดงผล เพราะกลุ่มนี้คือ "หน่วยงาน" ไม่ใช่ "ฝ่าย" (ข้อมูลจริงใน DB ไม่เปลี่ยน) */
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
<!-- Category Tabs — แสดงครบทุก Category เสมอ (Category ที่ไม่มีรายการจะเป็นสีเทา พร้อมข้อความอธิบาย) -->
<div class="cfp-card" style="padding:0;">
  <div class="cat-tabs" id="catTabs">
    <?php
    $firstCat = true;
    foreach ($catLabels as $catNo => $catInfo) {
        $catItems  = $grouped[$catNo] ?? array();
        $isEmptyCat = empty($catItems);
        $catFilled = count(array_filter($catItems, function($i) use ($dataMap) {
            $rowsForItem = $dataMap[(int)$i['ItemID']] ?? array();
            foreach ($rowsForItem as $d) { if ($d['Quantity'] !== null) { return true; } }
            return false;
        }));
        $activeClass = $firstCat ? 'active' : '';
    ?>
    <button class="cat-tab <?php echo $activeClass; ?><?php echo $isEmptyCat ? ' cat-tab-empty' : ''; ?>"
            onclick="switchCat(<?php echo $catNo; ?>)"
            id="catTab<?php echo $catNo; ?>">
      <i class="<?php echo $catInfo['icon']; ?>" style="font-size:0.85rem;color:<?php echo $isEmptyCat ? '#B0BEC5' : $catInfo['color']; ?>;"></i>
      <?php echo htmlspecialchars($catInfo['label']); ?>
      <span class="tab-cnt"><?php echo $catFilled; ?>/<?php echo count($catItems); ?></span>
    </button>
    <?php $firstCat = false; } ?>
  </div>

  <!-- Tab contents -->
  <?php
  $firstCat = true;
  foreach ($catLabels as $catNo => $catInfo) {
      $catItems  = $grouped[$catNo] ?? array();
      $dispStyle = $firstCat ? '' : 'display:none;';
  ?>
  <div class="cat-content" id="cat<?php echo $catNo; ?>" style="<?php echo $dispStyle; ?>">
  <?php if (empty($catItems)) { ?>
    <div class="text-center py-5" style="color:var(--cfp-text-muted);">
      <i class="<?php echo $catInfo['icon']; ?>" style="font-size:2.5rem;display:block;margin-bottom:8px;opacity:.3;"></i>
      ยังไม่มีรายการ <?php echo htmlspecialchars($catInfo['label']); ?> ที่ลงทะเบียนไว้ที่ Site นี้<br>
      <span style="font-size:0.78rem;">
        กรุณา <a href="/carbonfootprint/data_entry/my_asset_requests.php" style="color:var(--cfp-primary);font-weight:500;">ส่งคำขอเพิ่มทรัพย์สิน</a> เพื่อให้ Admin เพิ่มให้ Site นี้
      </span>
    </div>
  <?php } else { ?>

    <!-- ══ DESKTOP TABLE ══ -->
    <div class="table-responsive desktop-only">
    <table class="entry-table">
      <thead>
        <tr>
          <th style="width:30px;">#</th>
          <th style="min-width:260px;">รายการกิจกรรม</th>
          <th style="width:220px;">ทรัพย์สิน</th>
          <th style="width:130px;">ปริมาณ</th>
          <th style="width:60px;">หน่วย</th>
          <th style="width:140px;">ค่า EF</th>
          <th style="width:120px;">kgCO₂e</th>
          <th style="width:45px;">ค่าใช้จ่าย (บาท)</th>
          <th style="width:120px;">หมายเหตุ</th>
          <th style="width:70px;">แนบ</th>
          <th style="width:90px;">สถานะ</th>
        </tr>
      </thead>
      <tbody>
      <?php
      foreach ($catItems as $idx => $item) {
          $iid         = (int)$item['ItemID'];
          $rowsForItem = $dataMap[$iid] ?? array();
          $ef          = $efMap[$iid]   ?? null;
          $catAssets   = $assetMap[$catNo] ?? array();
          $hasAssets   = !empty($catAssets);

          /* สถานะรวมของ Item (ทุกแถวย่อยของ Item เดียวกันอยู่ Header เดียวกัน สถานะเดียวกันเสมอ) */
          $itemStatus = !empty($rowsForItem) ? ($rowsForItem[0]['Status'] ?? '') : '';
          $itemLocked = ($itemStatus === 'Submitted' || $itemStatus === 'Approved');
          $itemInputDisabled = (!$canEdit || $itemLocked) ? 'disabled' : '';
          $anyFilled = false;
          foreach ($rowsForItem as $rr) { if ($rr['Quantity'] !== null) { $anyFilled = true; break; } }

          if (!$hasAssets) {
              /* ===== ไม่มีทรัพย์สินให้เลือก -> แถวเดียวแบบเดิม ===== */
              $d      = $rowsForItem[0] ?? null;
              $qty    = ($d && $d['Quantity'] !== null) ? (float)$d['Quantity'] : null;
              $co2    = ($qty !== null && $ef) ? $qty * (float)$ef['EFValue'] : null;
              $dataID = $d ? (int)$d['DataID'] : 0;
              $rowKey = $iid . '_0';
          ?>
          <tr class="<?php echo $itemLocked ? 'row-locked' : ''; ?>" data-item="<?php echo $iid; ?>" data-row="<?php echo $rowKey; ?>" data-dataid="<?php echo $dataID; ?>">
            <td style="color:var(--cfp-text-muted);font-size:0.75rem;"><?php echo $idx+1; ?></td>
            <td>
              <div style="font-weight:500;"><?php echo htmlspecialchars($item['ItemName']); ?></div>
              <div style="font-size:0.72rem;color:var(--cfp-text-muted);"><?php echo $item['ItemCode']; ?></div>
            </td>
            <td>
              <?php $apInfo = $assetPageMap[$catNo] ?? null; ?>
              <?php if ($catNo === 3) { ?>
              <div style="font-size:0.7rem;color:#D97706;line-height:1.4;">
                <i class="bi bi-exclamation-triangle"></i> ต้องระบุสารทำความเย็น
              </div>
              <?php if ($isSuperAdmin && $apInfo) { ?>
              <a href="<?php echo $apInfo['url']; ?>" target="_blank" style="font-size:0.68rem;color:#D97706;">
                + เพิ่ม<?php echo htmlspecialchars($apInfo['label']); ?>
              </a>
              <?php } ?>
              <?php if ($canEdit && $apInfo) { ?>
              <br><a href="#" onclick="requestAsset(1,'<?php echo $apInfo['type']; ?>','<?php echo htmlspecialchars($apInfo['typeLabel']); ?>');return false;" style="font-size:0.68rem;color:var(--cfp-primary);">
                <i class="bi bi-plus-circle"></i> ขอเพิ่มทรัพย์สินใหม่
              </a>
              <?php } ?>
              <?php } else { ?>
              <span style="font-size:0.72rem;color:var(--cfp-text-muted);">ไม่ได้ระบุทรัพย์สิน</span>
              <?php if ($isSuperAdmin && $apInfo) { ?>
              <br><a href="<?php echo $apInfo['url']; ?>" target="_blank" style="font-size:0.68rem;color:var(--cfp-text-muted);">
                + เพิ่ม<?php echo htmlspecialchars($apInfo['label']); ?>
              </a>
              <?php } ?>
              <?php if ($canEdit && $apInfo) { ?>
              <br><a href="#" onclick="requestAsset(1,'<?php echo $apInfo['type']; ?>','<?php echo htmlspecialchars($apInfo['typeLabel']); ?>');return false;" style="font-size:0.68rem;color:var(--cfp-primary);">
                <i class="bi bi-plus-circle"></i> ขอเพิ่มทรัพย์สินใหม่
              </a>
              <?php } ?>
              <?php } ?>
            </td>
            <td>
              <input type="text" inputmode="decimal" class="<?php echo ($qty === null) ? 'qty-input empty' : 'qty-input'; ?>"
                     id="qty_<?php echo $rowKey; ?>" value="<?php echo ($qty !== null) ? $qty : ''; ?>"
                     placeholder="0.00" <?php echo $itemInputDisabled; ?>
                     onchange="cfpDecOnly(this);calcCO2('<?php echo $rowKey; ?>', <?php echo $ef ? $ef['EFValue'] : 0; ?>)"
                     oninput="cfpDecOnly(this);calcCO2('<?php echo $rowKey; ?>', <?php echo $ef ? $ef['EFValue'] : 0; ?>)">
            </td>
            <td style="font-size:0.78rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($item['UnitName'] ?? ''); ?></td>
            <td>
              <?php if ($ef) { ?>
              <span class="ef-badge"><?php echo number_format((float)$ef['EFValue'], 4); ?> kgCO₂e</span>
              <div style="font-size:0.68rem;color:var(--cfp-text-muted);margin-top:2px;"><?php echo htmlspecialchars($ef['SourceCode'] ?? ''); ?> <?php echo $ef['YearApply'] ?? ''; ?></div>
              <?php } else { ?><span style="font-size:0.75rem;color:var(--cfp-text-muted);">ไม่มี EF</span><?php } ?>
            </td>
            <td>
              <div class="<?php echo ($co2 !== null) ? 'co2-val' : 'co2-empty'; ?>" id="co2_<?php echo $rowKey; ?>">
                <?php echo ($co2 !== null) ? number_format($co2, 2) : '—'; ?>
              </div>
              <?php if ($co2 !== null) { ?>
              <div style="font-size:0.68rem;color:var(--cfp-text-muted);">= <?php echo number_format($co2/1000, 4); ?> tCO₂e</div>
              <?php } ?>
            </td>
            <td>
              <input type="text" inputmode="decimal" class="qty-input" style="width:100%;" id="cost_<?php echo $rowKey; ?>" oninput="cfpDecOnly(this);"
                     value="<?php echo ($d && $d['Cost'] !== null) ? (float)$d['Cost'] : ''; ?>"
                     placeholder="0.00" <?php echo $itemInputDisabled; ?>>
            </td>
            <td>
              <input type="text" class="remark-input" id="rmk_<?php echo $rowKey; ?>"
                     value="<?php echo htmlspecialchars($d ? ($d['Remark'] ?? '') : ''); ?>"
                     placeholder="หมายเหตุ" <?php echo $itemInputDisabled; ?>>
            </td>
            <td>
              <?php if ($d && !empty($d['EvidenceFile'])) { ?>
              <a class="attach-link attach-done" href="#" title="<?php echo htmlspecialchars($d['EvidenceFile']); ?>"><i class="bi bi-paperclip"></i>มีไฟล์</a>
              <?php } elseif ($canEdit && !$itemLocked) { ?>
              <a class="attach-link" href="#" onclick="openAttach('<?php echo $rowKey; ?>');return false;"><i class="bi bi-paperclip"></i>แนบ</a>
              <?php } else { ?><span style="font-size:0.72rem;color:var(--cfp-text-muted);">—</span><?php } ?>
            </td>
            <td>
              <?php
              if ($itemStatus === 'Approved') { echo '<span class="sp sp-approved"><i class="bi bi-check2-circle"></i>อนุมัติ</span>'; }
              elseif ($itemStatus === 'Submitted') { echo '<span class="sp sp-submitted"><i class="bi bi-hourglass-split"></i>รออนุมัติ</span>'; }
              elseif ($itemStatus === 'Draft' || $qty !== null) { echo '<span class="sp sp-draft"><i class="bi bi-pencil"></i>ร่าง</span>'; }
              else { echo '<span class="sp sp-empty">ยังไม่กรอก</span>'; }
              ?>
            </td>
          </tr>
          <?php
          } else {
              /* ===== มีทรัพย์สินให้เลือก -> รองรับหลายแถว (เพิ่มแบบ dynamic ไม่จำกัดจำนวน) ===== */
              $rowCount = max(count($rowsForItem), 1); /* อย่างน้อย 1 แถวว่างให้เริ่มกรอก */
              for ($n = 0; $n < $rowCount; $n++) {
                  $d          = $rowsForItem[$n] ?? null;
                  $rowKey     = $iid . '_' . $n;
                  $qty        = ($d && $d['Quantity'] !== null) ? (float)$d['Quantity'] : null;
                  $dataID     = $d ? (int)$d['DataID'] : 0;
                  $savedAssetID = $d ? (int)($d['AssetID'] ?? 0) : 0;
                  /* CAT3 ใช้ GWP ของสารทำความเย็นแทน EFValue */
                  if ($catNo === 3) {
                      $rowGWP = $savedAssetID > 0 ? ($assetGWPMap[$savedAssetID] ?? 0) : 0;
                      $co2    = ($qty !== null && $rowGWP > 0) ? $qty * $rowGWP : null;
                  } else {
                      $co2 = ($qty !== null && $ef) ? $qty * (float)$ef['EFValue'] : null;
                  }
              ?>
              <tr class="<?php echo $itemLocked ? 'row-locked' : ''; ?> asset-subrow" data-item="<?php echo $iid; ?>"
                  data-row="<?php echo $rowKey; ?>" data-dataid="<?php echo $dataID; ?>">
                <td style="color:var(--cfp-text-muted);font-size:0.75rem;"><?php echo $n===0 ? ($idx+1) : ''; ?></td>
                <td>
                  <?php if ($n === 0) { ?>
                  <div style="font-weight:500;"><?php echo htmlspecialchars($item['ItemName']); ?></div>
                  <div style="font-size:0.72rem;color:var(--cfp-text-muted);"><?php echo $item['ItemCode']; ?></div>
                  <?php } else { ?>
                  <div style="font-size:0.72rem;color:var(--cfp-text-muted);padding-left:14px;">
                    ↳ เพิ่มเติม
                    <?php if ($canEdit && !$itemLocked) { ?>
                    <a href="#" onclick="removeAssetRow('<?php echo $rowKey; ?>');return false;" style="color:#E05050;margin-left:4px;" title="ลบแถวนี้"><i class="bi bi-trash3"></i></a>
                    <?php } ?>
                  </div>
                  <?php } ?>
                </td>
                <td>
                  <select class="asset-select<?php echo $savedAssetID ? ' selected' : ''; ?>" id="asset_<?php echo $rowKey; ?>"
                          <?php echo $itemInputDisabled; ?>
                          onchange="this.classList.toggle('selected',this.value!='')<?php echo $catNo===3 ? ";calcFugitiveCO2('{$rowKey}')" : ''; ?>">
                    <option value="">— เลือกทรัพย์สิน —</option>
                    <?php foreach ($catAssets as $a) { ?>
                    <option value="<?php echo $a['AssetID']; ?>" data-type="<?php echo $a['AssetType']; ?>"
                            data-gwp="<?php echo number_format((float)($a['GWP100'] ?? 0), 2, '.', ''); ?>"
                            <?php echo ($a['AssetID'] == $savedAssetID) ? 'selected' : ''; ?>>
                      <?php echo $a['AssetName']; ?>
                    </option>
                    <?php } ?>
                  </select>
                </td>
                <td>
                  <?php
                  if ($catNo === 3) {
                      $qCalc = "calcFugitiveCO2('{$rowKey}')";
                  } else {
                      $efV   = $ef ? $ef['EFValue'] : 0;
                      $qCalc = "calcCO2('{$rowKey}', {$efV})";
                  }
                  ?>
                  <input type="text" inputmode="decimal" class="<?php echo ($qty === null) ? 'qty-input empty' : 'qty-input'; ?>"
                         id="qty_<?php echo $rowKey; ?>" value="<?php echo ($qty !== null) ? $qty : ''; ?>"
                         placeholder="0.00" <?php echo $itemInputDisabled; ?>
                         onchange="cfpDecOnly(this);<?php echo $qCalc; ?>"
                         oninput="cfpDecOnly(this);<?php echo $qCalc; ?>">
                </td>
                <td style="font-size:0.78rem;color:var(--cfp-text-muted);"><?php echo $n===0 ? htmlspecialchars($item['UnitName'] ?? '') : ''; ?></td>
                <td>
                  <?php if ($n === 0) { ?>
                    <?php if ($catNo === 3) { ?>
                    <span style="font-size:0.75rem;color:var(--cfp-text-muted);">GWP × ปริมาณ kg</span>
                    <?php } elseif ($ef) { ?>
                    <span class="ef-badge"><?php echo number_format((float)$ef['EFValue'], 4); ?> kgCO₂e</span>
                    <div style="font-size:0.68rem;color:var(--cfp-text-muted);margin-top:2px;"><?php echo htmlspecialchars($ef['SourceCode'] ?? ''); ?> <?php echo $ef['YearApply'] ?? ''; ?></div>
                    <?php } else { ?><span style="font-size:0.75rem;color:var(--cfp-text-muted);">ไม่มี EF</span><?php } ?>
                  <?php } ?>
                </td>
                <td>
                  <div class="<?php echo ($co2 !== null) ? 'co2-val' : 'co2-empty'; ?>" id="co2_<?php echo $rowKey; ?>">
                    <?php echo ($co2 !== null) ? number_format($co2, 2) : '—'; ?>
                  </div>
                </td>
                <td>
                  <input type="text" inputmode="decimal" class="qty-input" style="width:100%;" id="cost_<?php echo $rowKey; ?>" oninput="cfpDecOnly(this);"
                         value="<?php echo ($d && $d['Cost'] !== null) ? (float)$d['Cost'] : ''; ?>"
                         placeholder="0.00" <?php echo $itemInputDisabled; ?>>
                </td>
                <td>
                  <input type="text" class="remark-input" id="rmk_<?php echo $rowKey; ?>"
                         value="<?php echo htmlspecialchars($d ? ($d['Remark'] ?? '') : ''); ?>"
                         placeholder="หมายเหตุ" <?php echo $itemInputDisabled; ?>>
                </td>
                <td>
                  <?php if ($d && !empty($d['EvidenceFile'])) { ?>
                  <a class="attach-link attach-done" href="#" title="<?php echo htmlspecialchars($d['EvidenceFile']); ?>"><i class="bi bi-paperclip"></i>มีไฟล์</a>
                  <?php } elseif ($canEdit && !$itemLocked) { ?>
                  <a class="attach-link" href="#" onclick="openAttach('<?php echo $rowKey; ?>');return false;"><i class="bi bi-paperclip"></i>แนบ</a>
                  <?php } else { ?><span style="font-size:0.72rem;color:var(--cfp-text-muted);">—</span><?php } ?>
                </td>
                <td>
                  <?php if ($n === 0) {
                  if ($itemStatus === 'Approved') { echo '<span class="sp sp-approved"><i class="bi bi-check2-circle"></i>อนุมัติ</span>'; }
                  elseif ($itemStatus === 'Submitted') { echo '<span class="sp sp-submitted"><i class="bi bi-hourglass-split"></i>รออนุมัติ</span>'; }
                  elseif ($itemStatus === 'Draft' || $anyFilled) { echo '<span class="sp sp-draft"><i class="bi bi-pencil"></i>ร่าง</span>'; }
                  else { echo '<span class="sp sp-empty">ยังไม่กรอก</span>'; }
                  } ?>
                </td>
              </tr>
              <?php } /* end for $n */
              if (!$itemLocked && $canEdit) { ?>
              <tr class="add-row-tr" id="addrow_<?php echo $iid; ?>" data-additem="<?php echo $iid; ?>" data-nextidx="<?php echo $rowCount; ?>">
                <td></td>
                <td colspan="10">
                  <button type="button" onclick="addAssetRow(<?php echo $iid; ?>)"
                          style="background:none;border:1px dashed var(--cfp-border);border-radius:6px;
                                 color:var(--cfp-primary);font-size:0.75rem;padding:4px 10px;cursor:pointer;">
                    <i class="bi bi-plus-circle"></i> เพิ่มทรัพย์สินอีก 1 รายการ
                  </button>
                </td>
              </tr>
              <?php } ?>
          <?php } /* end if hasAssets */ } /* end foreach catItems */ ?>
      </tbody>
    </table>
    </div><!-- /desktop -->

    <!-- ข้อมูลสำหรับ JS สร้างแถวทรัพย์สินเพิ่มแบบ dynamic (เฉพาะ tab นี้) -->
    <script>
    (function() {
        window.scope1AssetOptions = window.scope1AssetOptions || {};
        window.scope1ItemMeta     = window.scope1ItemMeta || {};
        window.scope1AssetOptions[<?php echo $catNo; ?>] = <?php echo json_encode(array_map(function($a) {
            return array(
                'id'   => $a['AssetID'],
                'type' => $a['AssetType'],
                'label'=> $a['AssetName'],
                'gwp'  => (float)($a['GWP100'] ?? 0),
            );
        }, $assetMap[$catNo] ?? array()), JSON_UNESCAPED_UNICODE); ?>;
        <?php foreach ($catItems as $mi) {
            $miid = (int)$mi['ItemID'];
            if (empty($assetMap[$catNo])) { continue; } /* เฉพาะ item ที่มีทรัพย์สินให้เลือก */
            $mef = $efMap[$miid] ?? null;
            $mRows = $dataMap[$miid] ?? array();
            $mStatus = !empty($mRows) ? ($mRows[0]['Status'] ?? '') : '';
            $mLocked = ($mStatus === 'Submitted' || $mStatus === 'Approved');
            $mCanEdit = $canEdit && !$mLocked;
        ?>
        window.scope1ItemMeta[<?php echo $miid; ?>] = {
            catNo: <?php echo $catNo; ?>,
            efValue: <?php echo $mef ? (float)$mef['EFValue'] : 0; ?>,
            canEdit: <?php echo $mCanEdit ? 'true' : 'false'; ?>
        };
        <?php } ?>
    })();
    </script>


    <!-- ══ MOBILE CARDS ══ -->
    <div class="mobile-only" style="padding:10px 12px;">
    <?php foreach ($catItems as $idx => $item) {
        $iid    = (int)$item['ItemID'];
        $rowsForItem = $dataMap[$iid] ?? array();
        $d      = $rowsForItem[0] ?? null;
        $ef     = $efMap[$iid]   ?? null;
        $status = $d ? $d['Status'] : '';
        $qty    = ($d && $d['Quantity'] !== null) ? (float)$d['Quantity'] : null;
        /* CAT3 mobile: ใช้ GWP ของ asset แถวแรก */
        if ($catNo === 3) {
            $mAsset = $d ? (int)($d['AssetID'] ?? 0) : 0;
            $mGWP   = $mAsset > 0 ? ($assetGWPMap[$mAsset] ?? 0) : 0;
            $co2    = ($qty !== null && $mGWP > 0) ? $qty * $mGWP : null;
        } else {
            $co2    = ($qty !== null && $ef) ? $qty * (float)$ef['EFValue'] : null;
        }
        $locked = ($status === 'Submitted' || $status === 'Approved');
        $inputDisabled = (!$canEdit || $locked) ? 'disabled' : '';
        $dataID = $d ? (int)$d['DataID'] : 0;
        $cardOpacity = $locked ? 'opacity:.8;' : '';
        $multiNote = count($rowsForItem) > 1;
    ?>
    <div class="m-card" style="<?php echo $cardOpacity; ?>" data-item="<?php echo $iid; ?>" data-row="<?php echo $iid; ?>_0" data-dataid="<?php echo $dataID; ?>">
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
      <?php if ($multiNote) { ?>
      <div style="font-size:0.68rem;color:#D97706;padding:4px 0;">
        <i class="bi bi-info-circle"></i> รายการนี้มีหลายทรัพย์สิน (<?php echo count($rowsForItem); ?> รายการ) — แก้ไข/เพิ่มทรัพย์สินได้ที่หน้าคอมพิวเตอร์
      </div>
      <?php } ?>
      <div class="m-card-row">
        <input type="text" inputmode="decimal"
               class="m-qty<?php echo ($qty === null) ? ' empty' : ''; ?>"
               id="mqty_<?php echo $iid; ?>"
               value="<?php echo ($qty !== null) ? $qty : ''; ?>"
               placeholder="0.00"
               <?php echo $inputDisabled; ?>
               oninput="cfpDecOnly(this);calcCO2M(<?php echo $iid; ?>, <?php echo $catNo===3 ? ($mGWP??0) : ($ef ? $ef['EFValue'] : 0); ?>)"
               onchange="cfpDecOnly(this);calcCO2M(<?php echo $iid; ?>, <?php echo $catNo===3 ? ($mGWP??0) : ($ef ? $ef['EFValue'] : 0); ?>)">
        <span class="m-unit"><?php echo htmlspecialchars($item['UnitName'] ?? ''); ?></span>
        <span class="m-co2<?php echo ($co2 === null) ? ' na' : ''; ?>" id="mco2_<?php echo $iid; ?>">
          <?php echo ($co2 !== null) ? number_format($co2, 1) : '—'; ?>
        </span>
        <span class="m-unit" style="color:var(--cfp-text-muted);font-size:0.68rem;">kg</span>
      </div>
      <div class="m-footer">
        <?php if ($canEdit && !$locked) { ?>
        <a class="attach-link" href="#" onclick="openAttach('<?php echo $iid; ?>_0');return false;">
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
              $cRows = $dataMap[$cid] ?? array();
              $cef = $efMap[$cid] ?? null;
              foreach ($cRows as $cd) {
                  if ($cd['Quantity'] !== null) {
                      if ($catNo === 3) {
                          $cAsset = (int)($cd['AssetID'] ?? 0);
                          $cGWP   = $cAsset > 0 ? ($assetGWPMap[$cAsset] ?? 0) : 0;
                          $catCO2 += (float)$cd['Quantity'] * $cGWP;
                      } elseif ($cef) {
                          $catCO2 += (float)$cd['Quantity'] * (float)$cef['EFValue'];
                      }
                  }
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
  <?php } ?>
  </div><!-- /cat-content -->
  <?php $firstCat = false; } ?>

</div><!-- /cfp-card -->

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
var TOTAL_ITEMS = <?php echo $totalItems; ?>;
var HDR_STATUS  = <?php echo $hdrStatus; ?>;

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

/* ── อนุญาตเฉพาะตัวเลข+จุดทศนิยม (ไม่ใช้ type=number กันปุ่ม scale ขึ้น-ลง) ── */
function cfpDecOnly(el) {
    var v = el.value.replace(/[^0-9.]/g, '');
    var parts = v.split('.');
    if (parts.length > 2) { v = parts[0] + '.' + parts.slice(1).join(''); }
    el.value = v;
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

/* ── calcFugitiveCO2 (desktop, CAT3) — ใช้ GWP จาก data-gwp ของ option ที่เลือก ── */
function calcFugitiveCO2(rowKey) {
    var qtyEl   = document.getElementById('qty_' + rowKey);
    var assetEl = document.getElementById('asset_' + rowKey);
    var co2el   = document.getElementById('co2_' + rowKey);
    if (!qtyEl || !co2el) { return; }
    var qty = parseFloat(qtyEl.value);
    var gwp = (assetEl && assetEl.selectedIndex > 0)
              ? parseFloat(assetEl.options[assetEl.selectedIndex].dataset.gwp || 0)
              : 0;
    if (isNaN(qty) || qty < 0 || gwp <= 0) {
        co2el.innerHTML = '—'; co2el.className = 'co2-empty'; return;
    }
    var co2 = qty * gwp;
    co2el.innerHTML = co2.toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2})
                    + '<div style="font-size:0.68rem;color:var(--cfp-text-muted);">= ' + (co2/1000).toFixed(4) + ' tCO₂e</div>';
    co2el.className = 'co2-val';
    qtyEl.classList.remove('empty');
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
/* ── เก็บ DataID ของแถวที่ถูกลบไว้ ส่งไปให้ backend ลบจริงตอน save ── */
window.scope1DeletedIds = window.scope1DeletedIds || [];

function collectData() {
    var rows = [];
    document.querySelectorAll('[data-row]').forEach(function(el) {
        /* กันเก็บซ้ำ: element ที่ถูกซ่อนด้วย CSS (responsive mobile/desktop คู่กัน หรือแถวเสริมที่ยังไม่เปิดใช้) */
        if (el.offsetParent === null) { return; }
        var rowKey = el.dataset.row;
        var iid    = parseInt(el.dataset.item);
        var dataID = parseInt(el.dataset.dataid) || 0;
        var qtyEl  = document.getElementById('qty_' + rowKey) || document.getElementById('mqty_' + iid);
        var rmkEl  = document.getElementById('rmk_' + rowKey);
        var costEl = document.getElementById('cost_' + rowKey);
        if (!qtyEl || qtyEl.disabled) { return; }
        var qty = qtyEl.value.trim();
        if (qty === '') { return; }
        /* ดึง AssetID จาก dropdown (ถ้ามี) */
        var assetEl = document.getElementById('asset_' + rowKey);
        var assetID = assetEl ? parseInt(assetEl.value) || 0 : 0;
        var assetOpt = assetEl && assetEl.selectedIndex > 0 ? assetEl.options[assetEl.selectedIndex] : null;
        var assetType = assetOpt ? (assetOpt.dataset.type || '') : '';
        rows.push({
            DataID:    dataID,
            RowKey:    rowKey,
            ItemID:    iid,
            Quantity:  parseFloat(qty),
            Remark:    rmkEl ? rmkEl.value.trim() : '',
            Cost:      costEl && costEl.value.trim() !== '' ? parseFloat(costEl.value) : null,
            AssetID:   assetID,
            AssetType: assetType
        });
    });
    return rows;
}

/* ── เพิ่มแถวทรัพย์สินใหม่แบบ dynamic (ไม่จำกัดจำนวน) ── */
function addAssetRow(itemID) {
    var meta = window.scope1ItemMeta ? window.scope1ItemMeta[itemID] : null;
    var addRowTr = document.getElementById('addrow_' + itemID);
    if (!meta || !addRowTr) { return; }
    var options = (window.scope1AssetOptions && window.scope1AssetOptions[meta.catNo]) || [];
    var idx = parseInt(addRowTr.dataset.nextidx || '1');
    var rowKey = itemID + '_' + idx;

    var isFugitive = meta.catNo === 3;
    var optHtml = '<option value="">— เลือกทรัพย์สิน —</option>';
    options.forEach(function(o) {
        optHtml += '<option value="' + o.id + '" data-type="' + o.type + '" data-gwp="' + (o.gwp || 0) + '">' + o.label + '</option>';
    });
    var calcCall      = isFugitive ? 'calcFugitiveCO2(\'' + rowKey + '\')' : 'calcCO2(\'' + rowKey + '\', ' + meta.efValue + ')';
    var assetOnChange = 'this.classList.toggle(\'selected\',this.value!=\'\')' + (isFugitive ? ';calcFugitiveCO2(\'' + rowKey + '\')' : '');

    var tr = document.createElement('tr');
    tr.className = 'asset-subrow';
    tr.setAttribute('data-item', itemID);
    tr.setAttribute('data-row', rowKey);
    tr.setAttribute('data-dataid', '0');
    tr.innerHTML =
        '<td></td>' +
        '<td><div style="font-size:0.72rem;color:var(--cfp-text-muted);padding-left:14px;">↳ เพิ่มเติม ' +
            '<a href="#" onclick="removeAssetRow(\'' + rowKey + '\');return false;" style="color:#E05050;margin-left:4px;" title="ลบแถวนี้"><i class="bi bi-trash3"></i></a></div></td>' +
        '<td><select class="asset-select" id="asset_' + rowKey + '" onchange="' + assetOnChange + '">' + optHtml + '</select></td>' +
        '<td><input type="text" inputmode="decimal" class="qty-input empty" id="qty_' + rowKey + '" placeholder="0.00" ' +
            'onchange="cfpDecOnly(this);' + calcCall + '" oninput="cfpDecOnly(this);' + calcCall + '"></td>' +
        '<td></td>' +
        '<td></td>' +
        '<td><div class="co2-empty" id="co2_' + rowKey + '">—</div></td>' +
        '<td><input type="text" inputmode="decimal" class="qty-input" style="width:100%;" id="cost_' + rowKey + '" placeholder="0.00" oninput="cfpDecOnly(this);"></td>' +
        '<td><input type="text" class="remark-input" id="rmk_' + rowKey + '" placeholder="หมายเหตุ"></td>' +
        '<td><a class="attach-link" href="#" onclick="openAttach(\'' + rowKey + '\');return false;"><i class="bi bi-paperclip"></i>แนบ</a></td>' +
        '<td></td>';

    addRowTr.parentNode.insertBefore(tr, addRowTr);
    addRowTr.dataset.nextidx = idx + 1;
}

/* ── ลบแถวทรัพย์สิน (ทั้งแถวเดิมที่โหลดมา และแถวที่เพิ่งเพิ่มใหม่) ── */
function removeAssetRow(rowKey) {
    var tr = document.querySelector('tr[data-row="' + rowKey + '"]');
    if (!tr) { return; }
    Swal.fire({
        title: 'ลบแถวนี้?',
        text: 'ถ้าเคยบันทึกไว้แล้ว ต้องกด "บันทึกร่าง" อีกครั้งเพื่อให้มีผลจริง',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#E05050',
        customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (result.isConfirmed) {
            var did = parseInt(tr.dataset.dataid) || 0;
            if (did > 0) { window.scope1DeletedIds.push(did); }
            tr.remove();
            updateProgress();
        }
    });
}

/* ── save draft ── */
/* ── Lock กัน race condition: ห้ามกด บันทึกร่าง/ส่งอนุมัติ ซ้อนกันจนกว่า request แรกจะเสร็จ
   (ป้องกันปัญหา Status ถูกทับกลับเป็น Draft หลัง Submit ถ้ามีการกดปุ่มไล่ๆ กันจากคนละ tab) ── */
var isSavingScope1 = false;

function saveDraft() {
    if (isSavingScope1) { return; }
    var rows = collectData();
    if (!rows.length) {
        Swal.fire({ icon:'warning', title:'ไม่มีข้อมูลที่จะบันทึก', confirmButtonText:'ตกลง', confirmButtonColor:'#2AABB8', customClass:{popup:'font-prompt'} });
        return;
    }
    isSavingScope1 = true;
    Swal.fire({ title:'กำลังบันทึก...', allowOutsideClick:false, didOpen:function(){Swal.showLoading();} });
    fetch('/carbonfootprint/data_entry/scope1_save.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ action:'draft', siteID:SITE_ID, yearMonth:YM, csrf_token:CSRF, rows:rows, deletedIds:window.scope1DeletedIds,
                                responsibleName: document.getElementById('responsibleName').value.trim(),
                                responsibleDeptID: document.getElementById('responsibleDept').value })
    })
    .then(function(r){ return r.json(); })
    .then(function(res) {
        isSavingScope1 = false;
        if (res.success) {
            Swal.fire({ icon:'success', title:'บันทึกร่างสำเร็จ', timer:1500, showConfirmButton:false, customClass:{popup:'font-prompt'} })
            .then(function(){ uploadPendingEvidenceThenReload(res.savedRows); });
        } else {
            Swal.fire({ icon:'error', title:'บันทึกไม่สำเร็จ', text:res.msg, confirmButtonText:'ตกลง', customClass:{popup:'font-prompt'} });
        }
    })
    .catch(function() {
        isSavingScope1 = false;
        Swal.fire({ icon:'error', title:'เชื่อมต่อ server ไม่ได้', confirmButtonText:'ตกลง', customClass:{popup:'font-prompt'} });
    });
}

/* ── confirm submit ── */
function confirmSubmit() {
    if (isSavingScope1) { return; }
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
        if (isSavingScope1) { return; }
        isSavingScope1 = true;
        Swal.fire({ title:'กำลังส่ง...', allowOutsideClick:false, didOpen:function(){Swal.showLoading();} });
        fetch('/carbonfootprint/data_entry/scope1_save.php', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ action:'submit', siteID:SITE_ID, yearMonth:YM, csrf_token:CSRF, rows:rows, deletedIds:window.scope1DeletedIds,
                                    responsibleName: document.getElementById('responsibleName').value.trim(),
                                    responsibleDeptID: document.getElementById('responsibleDept').value })
        })
        .then(function(r){ return r.json(); })
        .then(function(res) {
            isSavingScope1 = false;
            if (res.success) {
                Swal.fire({ icon:'success', title:'ส่งอนุมัติเรียบร้อย!', text:res.msg, timer:3000, timerProgressBar:true, showConfirmButton:true, confirmButtonText:'ตกลง', confirmButtonColor:'#2AABB8', customClass:{popup:'font-prompt'} })
                .then(function(){ uploadPendingEvidenceThenReload(res.savedRows); });
            } else {
                Swal.fire({ icon:'error', title:'ส่งไม่สำเร็จ', text:res.msg, confirmButtonText:'ตกลง', customClass:{popup:'font-prompt'} });
            }
        })
        .catch(function(){
            isSavingScope1 = false;
            Swal.fire({ icon:'error', title:'เชื่อมต่อ server ไม่ได้', confirmButtonText:'ตกลง', customClass:{popup:'font-prompt'} });
        });
    });
}

/* ── attach file ──
   ถ้าแถวยังไม่เคยบันทึก (dataID<=0) ให้ "แนบไฟล์รอไว้" ก่อน แล้วอัปโหลดจริงตอนกด บันทึกร่าง/ส่งอนุมัติ
   ถ้าแถวบันทึกแล้ว (dataID>0) อัปโหลด/ลบไฟล์ได้ทันที */
window.pendingEvidence = window.pendingEvidence || {};

/* แสดงชื่อไฟล์ที่แนบรอไว้ + ปุ่มลบ (เลือกไฟล์ใหม่ได้โดยไม่ต้องรอบันทึกก่อน) */
function renderPendingEvidenceLink(tr, rowKey, fileName) {
    var link = tr.querySelector('.attach-link');
    if (!link) { return; }
    link.classList.add('attach-pending');
    link.innerHTML =
        '<i class="bi bi-paperclip"></i>' + fileName +
        ' <span onclick="event.stopPropagation();removePendingEvidence(\'' + rowKey + '\');" ' +
        'style="color:#E05050;margin-left:4px;cursor:pointer;" title="ลบไฟล์ที่เลือกไว้"><i class="bi bi-x-circle"></i></span>';
}
function removePendingEvidence(rowKey) {
    delete window.pendingEvidence[rowKey];
    var tr = document.querySelector('tr[data-row="' + rowKey + '"]') || document.querySelector('.m-card[data-row="' + rowKey + '"]')
        || document.querySelector('tr[data-item="' + rowKey + '"]') || document.querySelector('.m-card[data-item="' + rowKey + '"]');
    if (!tr) { return; }
    var link = tr.querySelector('.attach-link');
    if (link) {
        link.classList.remove('attach-pending');
        link.innerHTML = '<i class="bi bi-paperclip"></i>แนบ';
    }
}

function openAttach(rowKey) {
    var tr = document.querySelector('tr[data-row="' + rowKey + '"]') || document.querySelector('.m-card[data-row="' + rowKey + '"]');
    var dataID = tr ? (parseInt(tr.dataset.dataid) || 0) : 0;

    if (dataID <= 0) {
        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = '.jpg,.jpeg,.png,.pdf';
        fileInput.style.display = 'none';
        document.body.appendChild(fileInput);
        fileInput.addEventListener('change', function() {
            var f = fileInput.files[0];
            document.body.removeChild(fileInput);
            if (!f) { return; }
            window.pendingEvidence[rowKey] = f;
            renderPendingEvidenceLink(tr, rowKey, f.name);
        });
        fileInput.click();
        return;
    }

    var hasFile = tr.querySelector('.attach-done') !== null;
    Swal.fire({
        title: hasFile ? 'จัดการไฟล์แนบ' : 'แนบไฟล์หลักฐาน',
        html:
            '<input type="file" id="evdFileInput" class="form-control" accept=".jpg,.jpeg,.png,.pdf" style="margin-top:8px;">' +
            '<div style="font-size:0.72rem;color:#888;margin-top:4px;">รองรับ .jpg .png .pdf ไม่เกิน 10 MB (1 ไฟล์ต่อแถว ไฟล์ใหม่จะแทนที่ไฟล์เดิม)</div>',
        showCancelButton: true,
        showDenyButton: hasFile,
        confirmButtonText: 'อัปโหลด',
        denyButtonText: 'ลบไฟล์เดิม',
        cancelButtonText: 'ยกเลิก',
        customClass: { popup: 'font-prompt' },
        preConfirm: function() {
            var f = document.getElementById('evdFileInput').files[0];
            if (!f) { Swal.showValidationMessage('กรุณาเลือกไฟล์'); return false; }
            return f;
        }
    }).then(function(result) {
        if (result.isConfirmed) {
            var fd = new FormData();
            fd.append('action', 'upload');
            fd.append('activity_id', dataID);
            fd.append('scope', 'scope1');
            fd.append('csrf_token', CSRF);
            fd.append('file', result.value);
            uploadEvidence(fd);
        } else if (result.isDenied) {
            var fd2 = new FormData();
            fd2.append('action', 'delete');
            fd2.append('activity_id', dataID);
            fd2.append('scope', 'scope1');
            fd2.append('csrf_token', CSRF);
            uploadEvidence(fd2);
        }
    });
}
function uploadEvidence(fd) {
    fetch('evidence_save.php', { method:'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(res){
            if (res.success) {
                Swal.fire({ icon:'success', title: res.msg, timer:1500, showConfirmButton:false, customClass:{popup:'font-prompt'} })
                    .then(function(){ location.reload(); });
            } else {
                Swal.fire({ icon:'error', title:'ไม่สำเร็จ', text: res.msg, customClass:{popup:'font-prompt'} });
            }
        })
        .catch(function(){
            Swal.fire({ icon:'error', title:'เชื่อมต่อ server ไม่ได้', customClass:{popup:'font-prompt'} });
        });
}

/* อัปโหลดไฟล์ที่แนบรอไว้ (pendingEvidence) หลังบันทึกสำเร็จ โดยจับคู่ rowKey กับ ActivityID ใหม่จาก savedRows */
function uploadPendingEvidenceThenReload(savedRows) {
    var pending = window.pendingEvidence || {};
    var keys = Object.keys(pending);
    if (!keys.length) { location.reload(); return; }

    var rowKeyToId = {};
    (savedRows || []).forEach(function(sr) { rowKeyToId[sr.rowKey] = sr.activityID; });

    var uploads = keys.map(function(rowKey) {
        var activityID = rowKeyToId[rowKey];
        if (!activityID) { return Promise.resolve(); }
        var fd = new FormData();
        fd.append('action', 'upload');
        fd.append('activity_id', activityID);
        fd.append('scope', 'scope1');
        fd.append('csrf_token', CSRF);
        fd.append('file', pending[rowKey]);
        return fetch('evidence_save.php', { method:'POST', body: fd });
    });

    Promise.all(uploads).then(function() {
        window.pendingEvidence = {};
        location.reload();
    }).catch(function() {
        location.reload();
    });
}

/* ── ขอเพิ่มทรัพย์สินใหม่ (Data Entry ส่งคำขอให้ Admin ไปสร้างทะเบียนจริง) ── */
/* ── ฟิลด์ต่อประเภททรัพย์สิน — ตรงกับฟอร์มจริงของ Admin เพื่อไม่ต้องเดา ──
   type: 'text' | 'number' | 'select'
   group: ชื่อ option group จาก asset_request_options.php (เฉพาะ select ที่ดึงจาก DB)
   staticOptions: ตัวเลือกคงที่ (ไม่ต้องดึง DB) */
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
            { key: 'powerKW', label: 'กำลังไฟฟ้า (kW)', type: 'number' },
            { key: 'chargeKg', label: 'ปริมาณสารทำความเย็น (kg)', type: 'number' }
        ]
    },
    WaterMeter: {
        primaryLabel: 'ชื่อมิเตอร์', fields: [
            { key: 'waterMeterType', label: 'ประเภทมิเตอร์', type: 'select', group: 'waterMeterType' },
            { key: 'waterSourceType', label: 'แหล่งน้ำ', type: 'select', group: 'waterSourceType' },
            { key: 'meterNo', label: 'เลขมิเตอร์', type: 'text' },
            { key: 'installDate', label: 'วันติดตั้ง', type: 'date' },
            { key: 'location', label: 'ตำแหน่งติดตั้ง', type: 'text' }
        ]
    },
    ElectricMeter: {
        primaryLabel: 'ชื่อมิเตอร์', fields: [
            { key: 'electricMeterType', label: 'ประเภทมิเตอร์', type: 'select', group: 'electricMeterType' },
            { key: 'electricSourceType', label: 'แหล่งไฟฟ้า', type: 'select', group: 'electricSourceType' },
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
                html += '<input id="reqf_' + f.key + '" type="' + (f.type === 'number' ? 'number' : 'text') + '" class="form-control mb-2" style="font-family:\'Prompt\',sans-serif;">';
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
        html: 'ข้อมูล Scope 1 เดือนนี้จะถูกล้างทั้งหมด<br><small style="color:#6B7280;">ไม่สามารถกู้คืนได้</small>',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-trash me-1"></i>ยืนยัน ยกเลิก Draft',
        cancelButtonText: 'ปิด',
        confirmButtonColor: '#DC3545',
        cancelButtonColor: '#6B7280',
        customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (!result.isConfirmed) { return; }
        fetch('scope1_save.php', {
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
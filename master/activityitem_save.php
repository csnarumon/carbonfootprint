<?php
/* ==============================================
   master/activityitem_save.php
   รับ POST จาก activityitem.php
   actions: create | update | delete | toggle
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

define('CODE_PREFIX', 'ACT');

requireRole(array(4, 5));
$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: activityitem.php');
    exit;
}

/* สร้างรหัสอัตโนมัติ เช่น S1-001, S3C1-001 */
function generateItemCode($conn, $scopeNo, $categoryNo) {
    if ((int)$scopeNo === 3 && $categoryNo !== null && $categoryNo > 0) {
        $prefix = 'S3C' . (int)$categoryNo;
    } else {
        $prefix = 'S' . (int)$scopeNo;
    }
    $res = sqlsrv_query($conn,
        "SELECT MAX(CAST(SUBSTRING(ItemCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
         FROM CFP_ActivityItem
         WHERE ItemCode LIKE ? + '-%'",
        array($prefix, $prefix));
    $row     = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    $nextNum = ($row && $row['MaxNum'] !== null) ? ((int)$row['MaxNum'] + 1) : 1;
    return $prefix . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
}

verifyCsrf();
$action = $_POST['action'] ?? '';

/* ===== assign_site ===== */
if ($action === 'assign_site') {
    $itemID   = (int)($_POST['ItemID'] ?? 0);
    $siteIDs  = trim($_POST['SiteIDs'] ?? '');
    if (!$itemID) { redirectWithToast('ไม่พบรายการ', 'error'); }

    /* แปลง SiteIDs string → array int */
    $newSites = array();
    if ($siteIDs !== '') {
        foreach (explode(',', $siteIDs) as $sid) {
            $sid = (int)trim($sid);
            if ($sid > 0) { $newSites[] = $sid; }
        }
    }

    /* ปิด Site เดิมทั้งหมดก่อน */
    $rDel = sqlsrv_query($conn,
        "UPDATE CFP_ActivityItemSite SET IsActive=0 WHERE ItemID=?",
        array($itemID));
    if ($rDel === false) { redirectWithToast('เกิดข้อผิดพลาด', 'error'); }

    /* Upsert Site ใหม่ทีละ row */
    foreach ($newSites as $sid) {
        /* ตรวจว่ามี row อยู่แล้วไหม */
        $rEx = sqlsrv_query($conn,
            "SELECT ID FROM CFP_ActivityItemSite WHERE ItemID=? AND SiteID=?",
            array($itemID, $sid));
        $ex  = sqlsrv_fetch_array($rEx, SQLSRV_FETCH_ASSOC);
        if ($ex) {
            sqlsrv_query($conn,
                "UPDATE CFP_ActivityItemSite SET IsActive=1 WHERE ID=?",
                array((int)$ex['ID']));
        } else {
            sqlsrv_query($conn,
                "INSERT INTO CFP_ActivityItemSite (ItemID,SiteID,IsActive,CreatedBy) VALUES (?,?,1,?)",
                array($itemID, $sid, (int)$_SESSION['user_id']));
        }
    }

    $siteCount = count($newSites);
    logAction($conn, 'DATA_UPDATE', 'CFP_ActivityItemSite', $itemID, null, null, null,
        'กำหนด Site ' . $siteCount . ' โรงงาน ItemID=' . $itemID);
    redirectWithToast('บันทึกการกำหนด Site เรียบร้อย (' . $siteCount . ' โรงงาน)');
}

/* ===== toggle ===== */
if ($action === 'toggle') {
    $id = (int)($_POST['ItemID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูล', 'error'); }

    $res = sqlsrv_query($conn, "SELECT IsActive, ItemName FROM CFP_ActivityItem WHERE ItemID=?", array($id));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบรายการ', 'error'); }

    $newStatus = $row['IsActive'] ? 0 : 1;
    sqlsrv_query($conn,
        "UPDATE CFP_ActivityItem SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE ItemID=?",
        array($newStatus, (int)$_SESSION['user_id'], $id));

    logAction($conn, 'DATA_UPDATE', 'CFP_ActivityItem', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['ItemName']);
    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อย' : 'ปิดใช้งานเรียบร้อย');
}

/* ===== delete ===== */
if ($action === 'delete') {
    $id = (int)($_POST['ItemID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูล', 'error'); }

    /* ตรวจว่ามีการใช้งานใน ActivityData หรือไม่ */
    $res = sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_ActivityData WHERE ItemID=?", array($id));
    $chk = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if ($chk && $chk['Cnt'] > 0) {
        /* Soft delete — ปิดใช้งานแทน */
        sqlsrv_query($conn,
            "UPDATE CFP_ActivityItem SET IsActive=0, UpdatedBy=?, UpdatedDate=GETDATE() WHERE ItemID=?",
            array((int)$_SESSION['user_id'], $id));
        logAction($conn, 'DATA_UPDATE', 'CFP_ActivityItem', $id, null, null, null, 'ปิดใช้งาน (มีการบันทึกแล้ว)');
        redirectWithToast('มีข้อมูลบันทึกใช้รายการนี้แล้ว ระบบปิดใช้งานให้แทนการลบ');
    }

    /* ลบการกำหนด Site ที่ผูกกับรายการนี้ก่อน (CFP_ActivityItemSite มี FK ไป ItemID)
       ไม่งั้น DELETE FROM CFP_ActivityItem จะชน FK constraint แล้วลบไม่ได้ */
    $rSite = sqlsrv_query($conn, "DELETE FROM CFP_ActivityItemSite WHERE ItemID=?", array($id));
    if ($rSite === false) {
        $err = sqlsrv_errors();
        redirectWithToast('ไม่สามารถลบข้อมูล Site ที่ผูกไว้ได้: ' . ($err[0]['message'] ?? ''), 'error');
    }

    $rDel = sqlsrv_query($conn, "DELETE FROM CFP_ActivityItem WHERE ItemID=?", array($id));
    if ($rDel === false) {
        $err = sqlsrv_errors();
        redirectWithToast('ไม่สามารถลบรายการได้: ' . ($err[0]['message'] ?? 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'), 'error');
    }

    logAction($conn, 'DATA_DELETE', 'CFP_ActivityItem', $id);
    redirectWithToast('ลบรายการเรียบร้อยแล้ว');
}

/* ===== create / update ===== */
$name        = trim($_POST['ItemName']    ?? '');
$nameEN      = trim($_POST['ItemNameEN']  ?? '');
$scopeNo     = (int)($_POST['ScopeNo']    ?? 0);
$categoryNo  = isset($_POST['CategoryNo']) && $_POST['CategoryNo'] !== '' ? (int)$_POST['CategoryNo'] : null;
$scope1Type  = trim($_POST['Scope1Type']  ?? '');
$mobileRoadType = trim($_POST['MobileRoadType'] ?? '');
$unitID      = isset($_POST['UnitID'])    && $_POST['UnitID'] !== ''    ? (int)$_POST['UnitID']    : null;
$inputMethod = (int)($_POST['InputMethod'] ?? 1);
$sort        = (int)($_POST['SortOrder']  ?? 99);
$isActive    = isset($_POST['IsActive'])  ? (int)$_POST['IsActive'] : 1;
$desc        = trim($_POST['Description'] ?? '');

/* Validate */
if ($name === '') { redirectWithToast('กรุณากรอกชื่อรายการ', 'error'); }
if (!in_array($scopeNo, array(1,2,3))) { redirectWithToast('กรุณาเลือก Scope', 'error'); }
if ($scopeNo === 3 && ($categoryNo === null || $categoryNo < 1 || $categoryNo > 15)) {
    redirectWithToast('Scope 3 ต้องระบุ Category', 'error');
}
if ($scopeNo === 1 && !in_array($scope1Type, array('Stationary', 'Mobile', 'Fugitive', 'Process'))) {
    redirectWithToast('Scope 1 ต้องระบุประเภท (Stationary/Mobile/Fugitive/Process)', 'error');
}
if ($scopeNo === 1 && $scope1Type === 'Mobile' && !in_array($mobileRoadType, array('OnRoad', 'OffRoad'))) {
    redirectWithToast('ประเภท Mobile ต้องระบุ On-road หรือ Off-road ด้วย', 'error');
}
$scope1TypeVal = ($scopeNo === 1 && $scope1Type !== '') ? $scope1Type : null;
$mobileRoadTypeVal = ($scope1TypeVal === 'Mobile' && $mobileRoadType !== '') ? $mobileRoadType : null;

if ($action === 'create') {
    $code = generateItemCode($conn, $scopeNo, $categoryNo);
    $sql  = "INSERT INTO CFP_ActivityItem
             (ItemCode, ItemName, ItemNameEN, ScopeNo, CategoryNo, Scope1Type, MobileRoadType, UnitID,
              InputMethod, Description, SortOrder, IsActive, CreatedBy, CreatedDate)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,GETDATE())";
    $params = array(
        $code, $name, ($nameEN !== '' ? $nameEN : null),
        $scopeNo, $categoryNo, $scope1TypeVal, $mobileRoadTypeVal, $unitID,
        $inputMethod, ($desc !== '' ? $desc : null), $sort,
        (int)$_SESSION['user_id']
    );
    $r = sqlsrv_query($conn, $sql, $params);
    /* retry race condition */
    if ($r === false) {
        $code = generateItemCode($conn, $scopeNo, $categoryNo);
        $params[0] = $code;
        $r = sqlsrv_query($conn, $sql, $params);
    }
    if ($r === false) { redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error'); }

    logAction($conn, 'DATA_CREATE', 'CFP_ActivityItem', null, null, null, null,
        'เพิ่มรายการ: ' . $code . ' - ' . $name);
    redirectWithToast('เพิ่มรายการ "' . $name . '" เรียบร้อย (รหัส ' . $code . ')');

} elseif ($action === 'update') {
    $id = (int)($_POST['ItemID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }

    $sql = "UPDATE CFP_ActivityItem
            SET ItemName=?, ItemNameEN=?, ScopeNo=?, CategoryNo=?, Scope1Type=?, MobileRoadType=?, UnitID=?,
                InputMethod=?, Description=?, SortOrder=?, IsActive=?,
                UpdatedBy=?, UpdatedDate=GETDATE()
            WHERE ItemID=?";
    $r = sqlsrv_query($conn, $sql, array(
        $name, ($nameEN !== '' ? $nameEN : null),
        $scopeNo, $categoryNo, $scope1TypeVal, $mobileRoadTypeVal, $unitID,
        $inputMethod, ($desc !== '' ? $desc : null), $sort, $isActive,
        (int)$_SESSION['user_id'], $id
    ));
    if ($r === false) { redirectWithToast('เกิดข้อผิดพลาดในการบันทึก', 'error'); }

    logAction($conn, 'DATA_UPDATE', 'CFP_ActivityItem', $id, null, null, null,
        'แก้ไขรายการ: ' . $name);
    redirectWithToast('บันทึกการแก้ไขเรียบร้อย');

} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}
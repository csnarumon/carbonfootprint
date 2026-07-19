<?php
/* ==============================================
   master/ef_value_save.php
   actions: create | update | toggle
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(4, 5));
$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: ef_value.php');
    exit;
}

/* สร้างรหัส EF อัตโนมัติ เช่น EF-S1-001, EF-S2-001, EF-S3-001 */
function generateEFCode($conn, $scope, $gasType) {
    $scopeShort = str_replace('Scope', 'S', $scope ?? 'S1');
    $prefix     = 'EF-' . $scopeShort;
    $res = sqlsrv_query($conn,
        "SELECT MAX(CAST(SUBSTRING(EFCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
         FROM CFP_EFValue WHERE EFCode LIKE ? + '-%'",
        array($prefix, $prefix));
    $row     = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    $nextNum = ($row && $row['MaxNum'] !== null) ? ((int)$row['MaxNum'] + 1) : 1;
    return $prefix . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
}

verifyCsrf();
$action = $_POST['action'] ?? '';

/* ===== toggle ===== */
if ($action === 'toggle') {
    $id  = (int)($_POST['EFID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูล', 'error'); }

    $res = sqlsrv_query($conn, "SELECT IsActive, EFName FROM CFP_EFValue WHERE EFID=?", array($id));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) { redirectWithToast('ไม่พบค่า EF', 'error'); }

    $newStatus = $row['IsActive'] ? 0 : 1;
    sqlsrv_query($conn,
        "UPDATE CFP_EFValue SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE EFID=?",
        array($newStatus, (int)$_SESSION['user_id'], $id));

    logAction($conn, 'DATA_UPDATE', 'CFP_EFValue', $id, null, null, null,
        ($newStatus ? 'เปิดใช้งาน: ' : 'ปิดใช้งาน: ') . $row['EFName']);
    redirectWithToast($newStatus ? 'เปิดใช้งานเรียบร้อย' : 'ปิดใช้งานเรียบร้อย');
}

/* ===== create / update ===== */
$name     = trim($_POST['EFName']    ?? '');
$scope    = trim($_POST['Scope']     ?? '');
$category = trim($_POST['Category']  ?? '');
$gasType  = trim($_POST['GasType']   ?? '');
$efVal    = trim($_POST['EFValue']   ?? '');
$gwp      = trim($_POST['GWP']       ?? '1');
$unit     = trim($_POST['Unit']      ?? '');
$year     = (int)($_POST['YearApply'] ?? 0);
$sourceID = isset($_POST['SourceID']) && $_POST['SourceID'] !== '' ? (int)$_POST['SourceID'] : null;
$isActive = isset($_POST['IsActive'])  ? (int)$_POST['IsActive'] : 1;
/* หมายเหตุ: ไม่มีการผูก Activity Item จากหน้านี้อีกต่อไป — ย้ายไปจัดการที่ master/ef_link.php
   ทั้งหมด (แบบ Item หลายตัวชี้เข้าหา EF เดียวกันได้ ผ่าน CFP_ActivityItem.EFID) */

/* ValidUntil — รับ date string YYYY-MM-DD หรือว่าง */
$validUntilRaw = trim($_POST['ValidUntil'] ?? '');
$validUntil    = ($validUntilRaw !== '' && strtotime($validUntilRaw)) ? $validUntilRaw : null;

/* EffectiveDate — วันที่ค่า EF นี้เริ่มมีผลใช้จริง (ถ้าไม่กรอก ใช้วันนี้) */
$effectiveDateRaw = trim($_POST['EffectiveDate'] ?? '');
$effectiveDate     = ($effectiveDateRaw !== '' && strtotime($effectiveDateRaw)) ? $effectiveDateRaw : date('Y-m-d');

/* Validate */
if ($name    === '') { redirectWithToast('กรุณากรอกชื่อ EF', 'error'); }
if ($scope   === '') { redirectWithToast('กรุณาเลือก Scope', 'error'); }
if ($category === '') { redirectWithToast('กรุณาเลือกหมวด', 'error'); }
if ($gasType === '') { redirectWithToast('กรุณาเลือก Gas Type', 'error'); }
if ($efVal   === '' || !is_numeric($efVal)) { redirectWithToast('กรุณากรอกค่า EF', 'error'); }
if ($year    < 2000) { redirectWithToast('กรุณากรอกปีที่ถูกต้อง', 'error'); }

if ($action === 'create') {
    $code = generateEFCode($conn, $scope, $gasType);
    $sql  = "INSERT INTO CFP_EFValue
             (EFCode, EFName, EFValue, GWP, Unit, Scope, Category, GasType,
              YearApply, ValidUntil, EffectiveDate, SourceID, IsActive, CreatedBy, CreatedDate)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,GETDATE())";
    $r = sqlsrv_query($conn, $sql, array(
        $code, $name, (float)$efVal, (float)$gwp,
        ($unit !== '' ? $unit : null), $scope, $category, $gasType,
        $year, $validUntil, $effectiveDate, $sourceID,
        (int)$_SESSION['user_id']
    ));
    if ($r === false) {
        /* retry race condition */
        $code = generateEFCode($conn, $scope, $gasType);
        sqlsrv_query($conn, $sql, array(
            $code, $name, (float)$efVal, (float)$gwp,
            ($unit !== '' ? $unit : null), $scope, $category, $gasType,
            $year, $validUntil, $effectiveDate, $sourceID,
            (int)$_SESSION['user_id']
        ));
    }
    logAction($conn, 'DATA_CREATE', 'CFP_EFValue', null, null, null, null,
        'เพิ่ม EF: '.$code.' - '.$name.' ('.$year.')');
    redirectWithToast('เพิ่มค่า EF "'.$name.'" เรียบร้อย (รหัส '.$code.')');

} elseif ($action === 'update') {
    /* "แก้ไข" = สร้างเวอร์ชันใหม่ (revision) แทนการ UPDATE ทับของเดิม
       เพื่อรักษา audit trail — ค่าที่เคยใช้คำนวณ CO2e ในเดือนก่อนหน้ายังอ้างอิงถึง EFID เดิมได้เสมอ
       แถวเดิมถูกปิดใช้งาน (IsActive=0) และมี PreviousEFID ชี้ไปหาแถวใหม่เป็น chain ประวัติ */
    $id = (int)($_POST['EFID'] ?? 0);
    if (!$id) { redirectWithToast('ไม่พบข้อมูลที่ต้องการแก้ไข', 'error'); }

    $resOld = sqlsrv_query($conn, "SELECT EFCode FROM CFP_EFValue WHERE EFID=?", array($id));
    $oldRow = $resOld ? sqlsrv_fetch_array($resOld, SQLSRV_FETCH_ASSOC) : null;
    if (!$oldRow) { redirectWithToast('ไม่พบค่า EF เดิม', 'error'); }
    $code = $oldRow['EFCode'];

    $resIns = sqlsrv_query($conn,
        "INSERT INTO CFP_EFValue
         (EFCode, EFName, EFValue, GWP, Unit, Scope, Category, GasType,
          YearApply, ValidUntil, EffectiveDate, SourceID, IsActive,
          PreviousEFID, CreatedBy, CreatedDate)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,GETDATE())",
        array(
            $code, $name, (float)$efVal, (float)$gwp,
            ($unit !== '' ? $unit : null), $scope, $category, $gasType,
            $year, $validUntil, $effectiveDate, $sourceID, $isActive,
            $id, (int)$_SESSION['user_id']
        ));
    if ($resIns === false) {
        $e = sqlsrv_errors();
        redirectWithToast('บันทึกไม่สำเร็จ: '.($e[0]['message']??''), 'error');
    }
    $rIdent = sqlsrv_query($conn, "SELECT @@IDENTITY AS NewID");
    $rwIdent = $rIdent ? sqlsrv_fetch_array($rIdent, SQLSRV_FETCH_ASSOC) : null;
    $newID = $rwIdent ? (int)$rwIdent['NewID'] : 0;

    /* ปิดใช้งานแถวเดิม (ไม่ลบ — เก็บไว้เป็นประวัติ) */
    sqlsrv_query($conn,
        "UPDATE CFP_EFValue SET IsActive=0, UpdatedBy=?, UpdatedDate=GETDATE() WHERE EFID=?",
        array((int)$_SESSION['user_id'], $id));

    /* ย้ายทุก Activity Item ที่เคยใช้ EF ตัวเก่า ไปใช้เวอร์ชันใหม่โดยอัตโนมัติ
       (many-to-one: อาจมีหลาย Item ใช้ EF ตัวนี้ร่วมกันอยู่) */
    $resCascade = sqlsrv_query($conn,
        "UPDATE CFP_ActivityItem SET EFID=? WHERE EFID=?",
        array($newID, $id));
    $affectedCount = 0;
    if ($resCascade !== false) { $affectedCount = sqlsrv_rows_affected($resCascade); }

    logAction($conn, 'DATA_UPDATE', 'CFP_EFValue', $newID, null, null, null,
        'แก้ไข EF (revision): '.$name.' ('.$year.') — EFID เดิม='.$id.' → EFID ใหม่='.$newID.
        ' (ย้าย Item ที่ใช้ร่วมกัน '.$affectedCount.' รายการ)');
    redirectWithToast('บันทึกการแก้ไขเรียบร้อย (สร้างเวอร์ชันใหม่ เก็บประวัติเดิมไว้'.
        ($affectedCount > 0 ? ' และย้าย Item ที่ใช้ EF นี้ทั้ง '.$affectedCount.' รายการไปเวอร์ชันใหม่แล้ว' : '').')');

} else {
    redirectWithToast('คำขอไม่ถูกต้อง', 'error');
}

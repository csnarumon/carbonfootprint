<?php
/* =============================================================
   workflow/month_close_handler.php
   รับ POST จาก month_close.php
   action: close_month
   1. ตรวจสิทธิ์ Role 3 (Approver) หรือ Role 5 (SustainAdmin)
   2. ตรวจว่าทุก Site × Scope Status = 2 (Approved)
   3. INSERT CFP_MonthlySnapshot (Snapshot ณ วันปิดเดือน)
   4. UPDATE CFP_MonthlyHeader SET Status=3, ClosedBy, ClosedDate
   ============================================================= */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(3, 5));

$conn   = getConnection();
$userID = (int)$_SESSION['user_id'];

function redirectWithToast($msg, $type = 'success', $ym = '') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    $url = 'month_close.php' . ($ym ? '?ym=' . urlencode($ym) : '');
    header('Location: ' . $url);
    exit;
}

/* CSRF */
verifyCsrf();

$action = $_POST['action'] ?? '';
$ym     = trim($_POST['ym'] ?? '');

if ($action !== 'close_month') { redirectWithToast('คำขอไม่ถูกต้อง', 'error', $ym); }
if (!preg_match('/^\d{6}$/', $ym)) { redirectWithToast('เดือน/ปีไม่ถูกต้อง', 'error', $ym); }

/* ===== ดึง Sites ทั้งหมด ===== */
$resSite = sqlsrv_query($conn,
    "SELECT SiteID, SiteName, SiteCode FROM CFP_Site WHERE IsActive=1");
$sites = array();
if ($resSite) {
    while ($r = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) { $sites[] = $r; }
}
if (empty($sites)) { redirectWithToast('ไม่พบข้อมูล Site', 'error', $ym); }

/* ===== ตรวจว่าทุก Header Approved (Status=2) ครบ ===== */
$scopes   = array('Scope1', 'Scope2', 'Scope3');
$notReady = array();

foreach ($sites as $s) {
    foreach ($scopes as $scope) {
        $res = sqlsrv_query($conn,
            "SELECT HeaderID, Status FROM CFP_MonthlyHeader
             WHERE SiteID=? AND YearMonth=? AND Scope=?",
            array((int)$s['SiteID'], $ym, $scope));
        $row = $res ? sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC) : null;
        if (!$row) {
            $notReady[] = $s['SiteName'] . ' ' . $scope . ' (ไม่มีข้อมูล)';
        } elseif ((int)$row['Status'] !== 2) {
            $stMap = array(0=>'Draft', 1=>'Submitted', 2=>'Approved', 3=>'Closed');
            $notReady[] = $s['SiteName'] . ' ' . $scope . ' (' . ($stMap[(int)$row['Status']] ?? '') . ')';
        }
    }
}
if (!empty($notReady)) {
    redirectWithToast('ยังมีรายการที่ไม่ Approved (' . count($notReady) . ' รายการ) — ไม่สามารถปิดเดือนได้', 'error', $ym);
}

/* ===== ตรวจว่าไม่ถูกปิดไปแล้ว ===== */
$resChk = sqlsrv_query($conn,
    "SELECT COUNT(*) AS C FROM CFP_MonthlyHeader WHERE YearMonth=? AND Status=3",
    array($ym));
if ($resChk) {
    $chk = sqlsrv_fetch_array($resChk, SQLSRV_FETCH_ASSOC);
    if ($chk && (int)$chk['C'] > 0) {
        redirectWithToast('เดือนนี้ถูกปิดไปแล้ว', 'error', $ym);
    }
}

/* ===== เริ่ม Transaction ===== */
sqlsrv_begin_transaction($conn);

/* ===== STEP 1: ดึงข้อมูล ActivityData ทั้งหมดของเดือนนี้ ===== */
$resAct = sqlsrv_query($conn,
    "SELECT
        a.ActivityID, a.HeaderID, a.SiteID, a.Scope,
        a.Category, a.ActivityName, a.Quantity, a.CategoryNo,
        a.UnitID, a.EFID, a.CO2e, a.ItemID,
        u.UnitName,
        i.ItemCode, i.ItemName AS ItemNameMaster,
        h.YearMonth,
        s.SiteName, s.SiteCode,
        ef.EFValue, ef.Unit AS EFUnit, ef.GasType AS EFGasType,
        ef.YearApply AS EFYearApply,
        src.SourceCode AS EFSource
     FROM CFP_ActivityData a
     JOIN CFP_MonthlyHeader h ON h.HeaderID = a.HeaderID
     JOIN CFP_Site s          ON s.SiteID   = a.SiteID
     LEFT JOIN CFP_Unit u         ON u.UnitID   = a.UnitID
     LEFT JOIN CFP_ActivityItem i ON i.ItemID   = a.ItemID
     LEFT JOIN CFP_EFValue ef     ON ef.EFID    = a.EFID
     LEFT JOIN CFP_EFSource src   ON src.SourceID = ef.SourceID
     WHERE h.YearMonth = ? AND h.Status = 2 AND a.IsActive = 1",
    array($ym));

if ($resAct === false) {
    sqlsrv_rollback($conn);
    $e = sqlsrv_errors();
    redirectWithToast('ดึงข้อมูล Activity ไม่ได้: ' . ($e[0]['message'] ?? ''), 'error', $ym);
}

$actRows = array();
while ($r = sqlsrv_fetch_array($resAct, SQLSRV_FETCH_ASSOC)) { $actRows[] = $r; }

/* ===== STEP 2: ลบ Snapshot เก่า (ถ้ามี) แล้ว INSERT ใหม่ ===== */
$resDel = sqlsrv_query($conn,
    "DELETE FROM CFP_MonthlySnapshot WHERE YearMonth=?",
    array($ym));
if ($resDel === false) {
    sqlsrv_rollback($conn);
    $e = sqlsrv_errors();
    redirectWithToast('ลบ Snapshot เก่าไม่ได้: ' . ($e[0]['message'] ?? ''), 'error', $ym);
}

/* INSERT ทีละ row */
$snapCount = 0;
$sqlSnap = "INSERT INTO CFP_MonthlySnapshot
    (YearMonth, SiteID, SiteName, SiteCode, Scope, CategoryNo,
     HeaderID, ActivityID, ItemID, ItemCode, ItemName, ActivityName,
     Quantity, UnitID, UnitName,
     EFID, EFValue, EFUnit, EFGasType, EFSource, EFYearApply,
     CO2e, SnapshotBy, SnapshotDate)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,GETDATE())";

foreach ($actRows as $a) {
    $res = sqlsrv_query($conn, $sqlSnap, array(
        $ym,
        (int)$a['SiteID'],
        $a['SiteName'],
        $a['SiteCode'],
        $a['Scope'],
        $a['CategoryNo'] !== null ? (int)$a['CategoryNo'] : null,
        (int)$a['HeaderID'],
        (int)$a['ActivityID'],
        $a['ItemID'] !== null ? (int)$a['ItemID'] : null,
        $a['ItemCode'],
        $a['ItemNameMaster'] ?? $a['ActivityName'],
        $a['ActivityName'],
        (float)$a['Quantity'],
        $a['UnitID'] !== null ? (int)$a['UnitID'] : null,
        $a['UnitName'],
        $a['EFID'] !== null ? (int)$a['EFID'] : null,
        $a['EFValue'] !== null ? (float)$a['EFValue'] : null,
        $a['EFUnit'],
        $a['EFGasType'],
        $a['EFSource'],
        $a['EFYearApply'] !== null ? (int)$a['EFYearApply'] : null,
        $a['CO2e'] !== null ? (float)$a['CO2e'] : null,
        $userID,
    ));
    if ($res === false) {
        sqlsrv_rollback($conn);
        $e = sqlsrv_errors();
        redirectWithToast('บันทึก Snapshot ไม่ได้: ' . ($e[0]['message'] ?? ''), 'error', $ym);
    }
    $snapCount++;
}

/* ===== STEP 3: UPDATE MonthlyHeader Status=3 ===== */
$resUpd = sqlsrv_query($conn,
    "UPDATE CFP_MonthlyHeader
     SET Status=3, ClosedBy=?, ClosedDate=GETDATE(), UpdatedBy=?, UpdatedDate=GETDATE()
     WHERE YearMonth=? AND Status=2",
    array($userID, $userID, $ym));

if ($resUpd === false) {
    sqlsrv_rollback($conn);
    $e = sqlsrv_errors();
    redirectWithToast('อัปเดต Header ไม่ได้: ' . ($e[0]['message'] ?? ''), 'error', $ym);
}

/* ===== Commit ===== */
sqlsrv_commit($conn);

/* ===== Log ===== */
logAction($conn, 'MONTH_CLOSE', 'CFP_MonthlyHeader', null,
          null, $ym, null,
          'ปิดเดือน YearMonth=' . $ym . ' Snapshot ' . $snapCount . ' rows UserID=' . $userID);

/* แปลงเดือนเป็นภาษาไทย */
$thMonths = array(
    '01'=>'มกราคม','02'=>'กุมภาพันธ์','03'=>'มีนาคม','04'=>'เมษายน',
    '05'=>'พฤษภาคม','06'=>'มิถุนายน','07'=>'กรกฎาคม','08'=>'สิงหาคม',
    '09'=>'กันยายน','10'=>'ตุลาคม','11'=>'พฤศจิกายน','12'=>'ธันวาคม',
);
$mo    = substr($ym, 4, 2);
$yr    = (int)substr($ym, 0, 4) + 543;
$label = ($thMonths[$mo] ?? $mo) . ' ' . $yr;

redirectWithToast(
    'ปิดเดือน ' . $label . ' เรียบร้อย — บันทึก Snapshot ' . $snapCount . ' รายการ',
    'success',
    $ym
);

<?php
/* =============================================================
   users_scope_save.php
   รับ POST จาก modalScope ใน users.php
   action: save_scope — upsert CFP_UserScopeAccess
   หลักการ: 
     - ถ้า checkbox Scope ถูกติ๊ก → INSERT/UPDATE ด้วย IsActive = 1
     - ถ้าไม่ติ๊ก Scope ใด → UPDATE ให้ IsActive = 0 (หรือ DELETE)
   ============================================================= */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

header('Content-Type: application/json; charset=utf-8');

/* เฉพาะ Admin และ SustainAdmin */
requireRole(array(4, 5));

$conn     = getConnection();
$myRoleID = getActualRole();
$myUserID = (int)$_SESSION['user_id'];

/* ── Helper response ── */
function jsonOut($success, $msg, $debug = null) {
    $response = array('success' => $success, 'msg' => $msg);
    if ($debug !== null) {
        $response['debug'] = $debug;
    }
    echo json_encode($response);
    exit;
}

/* ── CSRF ── */
$csrfToken = $_POST['csrf_token'] ?? '';
if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    jsonOut(false, 'คำขอไม่ถูกต้อง (CSRF)');
}

$action   = $_POST['action'] ?? '';
$targetID = (int)($_POST['user_id'] ?? 0);

if (!$targetID) { jsonOut(false, 'ไม่พบข้อมูลผู้ใช้'); }

/* ── ตรวจสอบว่า target เป็น DATA_ENTRY เท่านั้น ── */
$resCheck = sqlsrv_query($conn,
    "SELECT RoleID, FullName FROM CFP_User WHERE UserID=? AND IsActive=1",
    array($targetID)
);
$uCheck = sqlsrv_fetch_array($resCheck, SQLSRV_FETCH_ASSOC);
if (!$uCheck) { jsonOut(false, 'ไม่พบผู้ใช้งาน'); }

/* ── SustainAdmin ห้ามแตะ Admin/SustainAdmin ── */
if ($myRoleID === 5 && in_array((int)$uCheck['RoleID'], array(4, 5))) {
    jsonOut(false, 'ไม่มีสิทธิ์จัดการผู้ใช้ Admin');
}

$targetRoleID = (int)$uCheck['RoleID'];

/* ===========================
   action: save_reviewer_site
   =========================== */
if ($action === 'save_reviewer_site') {

    if ($targetRoleID !== 2) {
        jsonOut(false, 'กำหนด Site Reviewer ได้เฉพาะผู้ใช้ Role Reviewer เท่านั้น');
    }

    /* แปลง site_ids string → array int */
    $siteIDsRaw = trim($_POST['site_ids'] ?? '');
    $newSiteIDs = array();
    if ($siteIDsRaw !== '') {
        foreach (explode(',', $siteIDsRaw) as $sid) {
            $sid = (int)trim($sid);
            if ($sid > 0) { $newSiteIDs[] = $sid; }
        }
    }

    sqlsrv_begin_transaction($conn);

    /* DELETE ทั้งหมดแล้ว INSERT ใหม่ — หลีกเลี่ยงปัญหา upsert + sqlsrv */
    $resDel = sqlsrv_query($conn,
        "DELETE FROM CFP_UserScopeAccess WHERE UserID=?",
        array($targetID));
    if ($resDel === false) {
        sqlsrv_rollback($conn);
        jsonOut(false, 'เกิดข้อผิดพลาดในการรีเซ็ตสิทธิ์');
    }

    /* INSERT Site x Scope 1,2,3 ที่เลือก */
    foreach ($newSiteIDs as $siteID) {
        foreach (array(1, 2, 3) as $sNo) {
            $resIn = sqlsrv_query($conn,
                "INSERT INTO CFP_UserScopeAccess
                 (UserID, ScopeNo, CategoryIDs, SiteID, IsActive, CreatedBy, CreatedDate)
                 VALUES (?, ?, NULL, ?, 1, ?, GETDATE())",
                array($targetID, $sNo, $siteID, $myUserID));
            if ($resIn === false) {
                $err = sqlsrv_errors();
                sqlsrv_rollback($conn);
                jsonOut(false, 'เกิดข้อผิดพลาดในการบันทึก Site ' . $siteID . ': ' . ($err[0]['message'] ?? ''));
            }
        }
    }

    sqlsrv_commit($conn);

    logAction($conn, 'USER_SCOPE_SAVE', 'CFP_UserScopeAccess', $targetID,
              null, null, null,
              'กำหนด Site Reviewer [' . implode(',', $newSiteIDs) . '] UserID=' . $targetID);

    // ✅ คืน JSON แล้วให้ JS จัดการ reload แทน redirect
    jsonOut(true, 'บันทึกสิทธิ์ Reviewer เรียบร้อยแล้ว');
}

/* ===========================
   action: save_approver_site
   =========================== */
if ($action === 'save_approver_site') {

    if ($targetRoleID !== 3) {
        jsonOut(false, 'กำหนด Site Approver ได้เฉพาะผู้ใช้ Role Approver เท่านั้น');
    }

    /* แปลง site_ids string → array int */
    $siteIDsRaw = trim($_POST['site_ids'] ?? '');
    $newSiteIDs = array();
    if ($siteIDsRaw !== '') {
        foreach (explode(',', $siteIDsRaw) as $sid) {
            $sid = (int)trim($sid);
            if ($sid > 0) { $newSiteIDs[] = $sid; }
        }
    }

    sqlsrv_begin_transaction($conn);

    /* DELETE ทั้งหมดแล้ว INSERT ใหม่ — หลีกเลี่ยงปัญหา upsert + sqlsrv */
    $resDel = sqlsrv_query($conn,
        "DELETE FROM CFP_UserScopeAccess WHERE UserID=?",
        array($targetID));
    if ($resDel === false) {
        sqlsrv_rollback($conn);
        jsonOut(false, 'เกิดข้อผิดพลาดในการรีเซ็ตสิทธิ์');
    }

    /* INSERT Site x Scope 1,2,3 ที่เลือก */
    foreach ($newSiteIDs as $siteID) {
        foreach (array(1, 2, 3) as $sNo) {
            $resIn = sqlsrv_query($conn,
                "INSERT INTO CFP_UserScopeAccess
                 (UserID, ScopeNo, CategoryIDs, SiteID, IsActive, CreatedBy, CreatedDate)
                 VALUES (?, ?, NULL, ?, 1, ?, GETDATE())",
                array($targetID, $sNo, $siteID, $myUserID));
            if ($resIn === false) {
                $err = sqlsrv_errors();
                sqlsrv_rollback($conn);
                jsonOut(false, 'เกิดข้อผิดพลาดในการบันทึก Site ' . $siteID . ': ' . ($err[0]['message'] ?? ''));
            }
        }
    }

    sqlsrv_commit($conn);

    logAction($conn, 'USER_SCOPE_SAVE', 'CFP_UserScopeAccess', $targetID,
              null, null, null,
              'กำหนด Site Approver [' . implode(',', $newSiteIDs) . '] UserID=' . $targetID);

    jsonOut(true, 'บันทึกสิทธิ์ Approver เรียบร้อยแล้ว');
}

/* ===========================
   action: save_scope (สำหรับ Data Entry)
   =========================== */
if ($action !== 'save_scope') { 
    jsonOut(false, 'คำขอไม่ถูกต้อง (action=' . $action . ')');
}

/* ── ตรวจสอบว่า target เป็น DATA_ENTRY เท่านั้น ── */
if ($targetRoleID !== 1) {
    jsonOut(false, 'กำหนด Scope ได้เฉพาะผู้ใช้ Role DATA_ENTRY เท่านั้น (RoleID=' . $targetRoleID . ')');
}

$scopesJson = $_POST['scopes'] ?? '[]';
error_log("[users_scope_save] scopes raw: " . $scopesJson);

$scopes = json_decode($scopesJson, true);
if (!is_array($scopes)) {
    jsonOut(false, 'ข้อมูล Scope ไม่ถูกต้อง: ' . json_last_error_msg());
}

/* validate ScopeNo */
foreach ($scopes as $sc) {
    if (!in_array((int)($sc['scopeNo'] ?? 0), array(1, 2, 3))) {
        jsonOut(false, 'ScopeNo ไม่ถูกต้อง: ' . ($sc['scopeNo'] ?? 'null'));
    }
}

error_log("[users_scope_save] scopes decoded: " . print_r($scopes, true));

/* ── เริ่ม transaction ── */
sqlsrv_begin_transaction($conn);

/* ── Strategy: DELETE ทั้งหมดของ user นี้แล้ว INSERT ใหม่
   ง่ายกว่า upsert และไม่ติดปัญหา sqlsrv + NULL parameter ── */

$resDel = sqlsrv_query($conn,
    "DELETE FROM CFP_UserScopeAccess WHERE UserID=?",
    array($targetID));
if ($resDel === false) {
    $err = sqlsrv_errors();
    sqlsrv_rollback($conn);
    jsonOut(false, 'เกิดข้อผิดพลาดในการรีเซ็ตสิทธิ์: ' . ($err[0]['message'] ?? ''));
}

if (empty($scopes)) {
    sqlsrv_commit($conn);
    jsonOut(true, 'ปิดสิทธิ์ทั้งหมดเรียบร้อย');
}

$insertedCount = 0;
foreach ($scopes as $sc) {
    $sNo    = (int)$sc['scopeNo'];
    $catIDs = trim($sc['categoryIDs'] ?? '');
    $siteID = ((int)($sc['siteID'] ?? 0)) > 0 ? (int)$sc['siteID'] : null;
    $catVal = ($catIDs !== '') ? $catIDs : null;

    /* สร้าง SQL แยกกัน เพื่อหลีกเลี่ยงการส่ง NULL ผ่าน ? */
    if ($siteID === null && $catVal === null) {
        $sqlIn = "INSERT INTO CFP_UserScopeAccess
                  (UserID, ScopeNo, CategoryIDs, SiteID, IsActive, CreatedBy, CreatedDate)
                  VALUES (?, ?, NULL, NULL, 1, ?, GETDATE())";
        $resIn = sqlsrv_query($conn, $sqlIn, array($targetID, $sNo, $myUserID));

    } elseif ($siteID === null) {
        $sqlIn = "INSERT INTO CFP_UserScopeAccess
                  (UserID, ScopeNo, CategoryIDs, SiteID, IsActive, CreatedBy, CreatedDate)
                  VALUES (?, ?, ?, NULL, 1, ?, GETDATE())";
        $resIn = sqlsrv_query($conn, $sqlIn, array($targetID, $sNo, $catVal, $myUserID));

    } elseif ($catVal === null) {
        $sqlIn = "INSERT INTO CFP_UserScopeAccess
                  (UserID, ScopeNo, CategoryIDs, SiteID, IsActive, CreatedBy, CreatedDate)
                  VALUES (?, ?, NULL, ?, 1, ?, GETDATE())";
        $resIn = sqlsrv_query($conn, $sqlIn, array($targetID, $sNo, $siteID, $myUserID));

    } else {
        $sqlIn = "INSERT INTO CFP_UserScopeAccess
                  (UserID, ScopeNo, CategoryIDs, SiteID, IsActive, CreatedBy, CreatedDate)
                  VALUES (?, ?, ?, ?, 1, ?, GETDATE())";
        $resIn = sqlsrv_query($conn, $sqlIn, array($targetID, $sNo, $catVal, $siteID, $myUserID));
    }

    if ($resIn === false) {
        $err = sqlsrv_errors();
        sqlsrv_rollback($conn);
        jsonOut(false, 'เกิดข้อผิดพลาดในการบันทึก Scope ' . $sNo . ': ' . ($err[0]['message'] ?? ''));
    }
    $insertedCount++;
}

sqlsrv_commit($conn);

/* ── Log ── */
$scopeSummary = implode(',', array_map(function($s) {
    return 'Scope' . $s['scopeNo'];
}, $scopes));
logAction($conn, 'USER_SCOPE_SAVE', 'CFP_UserScopeAccess', $targetID,
          null, null, null,
          'สิทธิ์ Data Entry (Scope) [' . ($scopeSummary ?: 'ยกเลิกทั้งหมด') . '] UserID=' . $targetID,
          null, $scopesJson);

jsonOut(true, 'บันทึกสิทธิ์เรียบร้อย (' . $insertedCount . ' รายการ)');
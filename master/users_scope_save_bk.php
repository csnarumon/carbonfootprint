<?php
/* =============================================================
   users_scope_save.php
   รับ POST จาก modalScope ใน users.php
   action: save_scope — upsert CFP_UserScopeAccess
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
function jsonOut($success, $msg) {
    echo json_encode(array('success' => $success, 'msg' => $msg));
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
    "SELECT RoleID FROM CFP_User WHERE UserID=? AND IsActive=1",
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
   บันทึก Site สำหรับ Reviewer (Role 2)
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

    /* 1. ปิด scope เดิมทั้งหมดของ user นี้ */
    $resDeact = sqlsrv_query($conn,
        "UPDATE CFP_UserScopeAccess SET IsActive=0, UpdatedBy=?, UpdatedDate=GETDATE() WHERE UserID=?",
        array($myUserID, $targetID));
    if ($resDeact === false) {
        sqlsrv_rollback($conn);
        jsonOut(false, 'เกิดข้อผิดพลาดในการรีเซ็ตสิทธิ์');
    }

    /* 2. Upsert แต่ละ Site × Scope 1,2,3 (Reviewer เห็นทุก Scope แต่จำกัด Site) */
    foreach ($newSiteIDs as $siteID) {
        foreach (array(1, 2, 3) as $sNo) {
            $resEx = sqlsrv_query($conn,
                "SELECT AccessID FROM CFP_UserScopeAccess WHERE UserID=? AND ScopeNo=? AND SiteID=?",
                array($targetID, $sNo, $siteID));
            $exRow = sqlsrv_fetch_array($resEx, SQLSRV_FETCH_ASSOC);
            if ($exRow) {
                $resUp = sqlsrv_query($conn,
                    "UPDATE CFP_UserScopeAccess SET IsActive=1, UpdatedBy=?, UpdatedDate=GETDATE() WHERE AccessID=?",
                    array($myUserID, (int)$exRow['AccessID']));
                if ($resUp === false) { sqlsrv_rollback($conn); jsonOut(false, 'เกิดข้อผิดพลาดในการอัปเดต'); }
            } else {
                $resIn = sqlsrv_query($conn,
                    "INSERT INTO CFP_UserScopeAccess (UserID, ScopeNo, CategoryIDs, SiteID, IsActive, CreatedBy) VALUES (?,?,NULL,?,1,?)",
                    array($targetID, $sNo, $siteID, $myUserID));
                if ($resIn === false) { sqlsrv_rollback($conn); jsonOut(false, 'เกิดข้อผิดพลาดในการเพิ่ม'); }
            }
        }
    }

    sqlsrv_commit($conn);

    logAction($conn, 'USER_SCOPE_SAVE', 'CFP_UserScopeAccess', $targetID,
              null, null, null,
              'กำหนด Site Reviewer [' . implode(',', $newSiteIDs) . '] UserID=' . $targetID);

    /* redirect กลับ users.php พร้อม toast */
    $_SESSION['toast'] = array('msg' => 'บันทึกสิทธิ์ Reviewer เรียบร้อย', 'type' => 'success');
    header('Location: users.php?tab=tabReviewer');
    exit;
}

/* ===========================
   action: save_scope (Role 1 เดิม)
   =========================== */
if ($action !== 'save_scope') { jsonOut(false, 'คำขอไม่ถูกต้อง'); }

/* ── ตรวจสอบว่า target เป็น DATA_ENTRY เท่านั้น ── */
if ($targetRoleID !== 1) {
    jsonOut(false, 'กำหนด Scope ได้เฉพาะผู้ใช้ Role DATA_ENTRY เท่านั้น');
}

    $scopesJson = $_POST['scopes'] ?? '[]';
    $scopes     = json_decode($scopesJson, true);
    if (!is_array($scopes)) { jsonOut(false, 'ข้อมูล Scope ไม่ถูกต้อง'); }

    /* validate ScopeNo */
    foreach ($scopes as $sc) {
        if (!in_array((int)($sc['scopeNo'] ?? 0), array(1, 2, 3))) {
            jsonOut(false, 'ScopeNo ไม่ถูกต้อง');
        }
    }

    /* ── เริ่ม transaction ── */
    sqlsrv_begin_transaction($conn);

    /* 1. ปิด (IsActive=0) ทุก scope เดิมของ user นี้ก่อน */
    $sqlDeact = "UPDATE CFP_UserScopeAccess
                 SET IsActive=0, UpdatedBy=?, UpdatedDate=GETDATE()
                 WHERE UserID=?";
    $resDeact = sqlsrv_query($conn, $sqlDeact, array($myUserID, $targetID));
    if ($resDeact === false) {
        sqlsrv_rollback($conn);
        jsonOut(false, 'เกิดข้อผิดพลาดในการรีเซ็ตสิทธิ์');
    }

    /* 2. Upsert แต่ละ Scope ที่ส่งมา */
    foreach ($scopes as $sc) {
        $sNo    = (int)$sc['scopeNo'];
        $catIDs = trim($sc['categoryIDs'] ?? '');
        $siteID = ((int)($sc['siteID'] ?? 0)) > 0 ? (int)$sc['siteID'] : null;
        $catVal = ($catIDs !== '') ? $catIDs : null;

        /* ตรวจว่ามี row นี้อยู่แล้วไหม */
        $sqlEx = "SELECT AccessID FROM CFP_UserScopeAccess
                  WHERE UserID=? AND ScopeNo=?
                  AND (SiteID=? OR (SiteID IS NULL AND ? IS NULL))";
        $resEx = sqlsrv_query($conn, $sqlEx, array($targetID, $sNo, $siteID, $siteID));
        $exRow = sqlsrv_fetch_array($resEx, SQLSRV_FETCH_ASSOC);

        if ($exRow) {
            /* UPDATE */
            $sqlUp = "UPDATE CFP_UserScopeAccess
                      SET IsActive=1, CategoryIDs=?, UpdatedBy=?, UpdatedDate=GETDATE()
                      WHERE AccessID=?";
            $resUp = sqlsrv_query($conn, $sqlUp, array($catVal, $myUserID, (int)$exRow['AccessID']));
            if ($resUp === false) {
                sqlsrv_rollback($conn);
                jsonOut(false, 'เกิดข้อผิดพลาดในการอัปเดต Scope ' . $sNo);
            }
        } else {
            /* INSERT */
            $sqlIn = "INSERT INTO CFP_UserScopeAccess
                      (UserID, ScopeNo, CategoryIDs, SiteID, IsActive, CreatedBy)
                      VALUES (?, ?, ?, ?, 1, ?)";
            $resIn = sqlsrv_query($conn, $sqlIn, array($targetID, $sNo, $catVal, $siteID, $myUserID));
            if ($resIn === false) {
                sqlsrv_rollback($conn);
                jsonOut(false, 'เกิดข้อผิดพลาดในการเพิ่ม Scope ' . $sNo);
            }
        }
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

    jsonOut(true, 'บันทึกสิทธิ์ Scope เรียบร้อยแล้ว');

jsonOut(false, 'คำขอไม่ถูกต้อง');
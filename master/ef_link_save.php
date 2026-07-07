<?php
/* ==============================================
   master/ef_link_save.php
   AJAX POST — bulk link / unlink CFP_EFValue.RefID
   Link  body: { csrf_token, action:'link',   pairs:  [{efid, itemID}, ...] }
   Unlink body: { csrf_token, action:'unlink', efids: [efid, ...] }
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(4, 5));
header('Content-Type: application/json; charset=utf-8');

function fail($msg) {
    echo json_encode(array('ok' => false, 'error' => $msg));
    exit;
}

/* ── อ่าน JSON body ── */
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { fail('request body ไม่ถูกต้อง'); }

/* ── ตรวจ CSRF ── */
$csrf = $body['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    fail('CSRF token ไม่ถูกต้อง');
}

$action = $body['action'] ?? 'link';
$conn   = getConnection();
$userID = (int)$_SESSION['user_id'];

/* ════════════════════════════════════
   ACTION: link
   ════════════════════════════════════ */
if ($action === 'link') {
    $pairs = $body['pairs'] ?? array();
    if (empty($pairs) || !is_array($pairs)) { fail('ไม่พบข้อมูลที่จะบันทึก'); }

    $saved  = 0;
    $errors = array();

    foreach ($pairs as $p) {
        $efid   = isset($p['efid'])   ? (int)$p['efid']   : 0;
        $itemID = isset($p['itemID']) ? (int)$p['itemID'] : 0;

        if ($efid <= 0 || $itemID <= 0) {
            $errors[] = "EFID=$efid ItemID=$itemID — ข้อมูลไม่ครบ";
            continue;
        }

        /* ตรวจว่า EF มีอยู่ */
        $resChk = sqlsrv_query($conn,
            "SELECT EFID FROM CFP_EFValue WHERE EFID=? AND IsActive=1",
            array($efid));
        if (!$resChk || !sqlsrv_fetch_array($resChk, SQLSRV_FETCH_ASSOC)) {
            $errors[] = "EFID=$efid — ไม่พบ EF";
            continue;
        }

        /* ตรวจว่า ActivityItem มีอยู่ */
        $resItem = sqlsrv_query($conn,
            "SELECT ItemID FROM CFP_ActivityItem WHERE ItemID=? AND IsActive=1",
            array($itemID));
        if (!$resItem || !sqlsrv_fetch_array($resItem, SQLSRV_FETCH_ASSOC)) {
            $errors[] = "EFID=$efid — ไม่พบ ItemID=$itemID";
            continue;
        }

        $r = sqlsrv_query($conn,
            "UPDATE CFP_EFValue
             SET RefID=?, RefTable='CFP_ActivityItem', UpdatedBy=?, UpdatedDate=GETDATE()
             WHERE EFID=?",
            array($itemID, $userID, $efid));

        if ($r === false) {
            $errors[] = "EFID=$efid — บันทึกไม่สำเร็จ";
        } else {
            $saved++;
        }
    }

    if ($saved > 0) {
        logAction($conn, 'DATA_UPDATE', 'CFP_EFValue', null, null, null, null,
            'Bulk-link EF RefID: สำเร็จ ' . $saved . ' รายการ');
    }

    echo json_encode(array(
        'ok'     => $saved > 0,
        'saved'  => $saved,
        'errors' => $errors,
    ));
    exit;
}

/* ════════════════════════════════════
   ACTION: unlink
   ════════════════════════════════════ */
if ($action === 'unlink') {
    $efids = $body['efids'] ?? array();
    if (empty($efids) || !is_array($efids)) { fail('ไม่พบรายการที่จะยกเลิกการผูก'); }

    $force = !empty($body['force']);

    /* ตรวจว่ามี ActivityData Draft ใช้ EFID เหล่านี้อยู่ไหม */
    if (!$force) {
        $totalDraft = 0;
        foreach ($efids as $rawEfid) {
            $efid = (int)$rawEfid;
            if ($efid <= 0) { continue; }
            $resChk = sqlsrv_query($conn,
                "SELECT COUNT(*) AS Cnt FROM CFP_ActivityData ad
                 JOIN CFP_MonthlyHeader h ON h.HeaderID = ad.HeaderID
                 WHERE ad.EFID = ? AND ad.IsActive = 1 AND h.Status = 0",
                array($efid));
            if ($resChk) {
                $r = sqlsrv_fetch_array($resChk, SQLSRV_FETCH_ASSOC);
                $totalDraft += (int)($r['Cnt'] ?? 0);
            }
        }
        if ($totalDraft > 0) {
            echo json_encode(array('ok' => false, 'requireConfirm' => true, 'draftCount' => $totalDraft));
            exit;
        }
    }

    $unlinked = 0;
    $errors   = array();

    foreach ($efids as $rawEfid) {
        $efid = (int)$rawEfid;
        if ($efid <= 0) { $errors[] = "EFID ไม่ถูกต้อง"; continue; }

        /* ตรวจว่า EF มีอยู่และผูกอยู่จริง */
        $resChk = sqlsrv_query($conn,
            "SELECT EFID FROM CFP_EFValue WHERE EFID=? AND IsActive=1 AND RefID IS NOT NULL",
            array($efid));
        if (!$resChk || !sqlsrv_fetch_array($resChk, SQLSRV_FETCH_ASSOC)) {
            $errors[] = "EFID=$efid — ไม่พบหรือยังไม่ผูก";
            continue;
        }

        $r = sqlsrv_query($conn,
            "UPDATE CFP_EFValue
             SET RefID=NULL, RefTable=NULL, UpdatedBy=?, UpdatedDate=GETDATE()
             WHERE EFID=?",
            array($userID, $efid));

        if ($r === false) {
            $errors[] = "EFID=$efid — ยกเลิกการผูกไม่สำเร็จ";
        } else {
            $unlinked++;
        }
    }

    if ($unlinked > 0) {
        logAction($conn, 'DATA_UPDATE', 'CFP_EFValue', null, null, null, null,
            'Bulk-unlink EF RefID: สำเร็จ ' . $unlinked . ' รายการ');
    }

    echo json_encode(array(
        'ok'       => $unlinked > 0,
        'unlinked' => $unlinked,
        'errors'   => $errors,
    ));
    exit;
}

fail('action ไม่ถูกต้อง');

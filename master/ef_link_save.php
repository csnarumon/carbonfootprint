<?php
/* ==============================================
   master/ef_link_save.php
   AJAX POST — bulk link / unlink CFP_ActivityItem.EFID (many-to-one:
   1 EF ผูกได้หลาย Item, แต่ 1 Item มี EF ได้แค่ตัวเดียว)
   Link  body: { csrf_token, action:'link',   pairs:  [{efid, itemID}, ...] }
   Unlink body: { csrf_token, action:'unlink', itemIds: [itemID, ...] }
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
            "SELECT ItemID, ItemName FROM CFP_ActivityItem WHERE ItemID=? AND IsActive=1",
            array($itemID));
        $itemRow = $resItem ? sqlsrv_fetch_array($resItem, SQLSRV_FETCH_ASSOC) : null;
        if (!$itemRow) {
            $errors[] = "EFID=$efid — ไม่พบ ItemID=$itemID";
            continue;
        }

        /* ไม่ต้องเช็คว่า Item ถูกผูกกับ EF อื่นซ้ำหรือไม่ — set ตรงๆ ได้เลย เพราะ
           CFP_ActivityItem.EFID เป็นคอลัมน์เดี่ยว ค่าใหม่จะทับค่าเดิมโดยธรรมชาติ
           (1 Item มี EF ได้แค่ตัวเดียวเสมอ, ส่วน 1 EF ผูกกับหลาย Item ได้ตามดีไซน์ใหม่) */
        $r = sqlsrv_query($conn,
            "UPDATE CFP_ActivityItem SET EFID=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE ItemID=?",
            array($efid, $userID, $itemID));

        if ($r === false) {
            $errors[] = "EFID=$efid — ItemID=$itemID (" . $itemRow['ItemName'] . ") บันทึกไม่สำเร็จ";
        } else {
            $saved++;
        }
    }

    if ($saved > 0) {
        logAction($conn, 'DATA_UPDATE', 'CFP_ActivityItem', null, null, null, null,
            'Bulk-link EF -> ActivityItem.EFID: สำเร็จ ' . $saved . ' รายการ');
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
    /* ยกเลิกระบุเป็นรายการ ItemID (ไม่ใช่ EFID) — เพราะ 1 EF อาจผูกกับหลาย Item
       ยกเลิกทีละคู่ (EF, Item) ไม่กระทบ Item อื่นที่ใช้ EF ตัวเดียวกันอยู่ */
    $itemIds = $body['itemIds'] ?? array();
    if (empty($itemIds) || !is_array($itemIds)) { fail('ไม่พบรายการที่จะยกเลิกการผูก'); }

    $force = !empty($body['force']);

    /* ตรวจว่ามี ActivityData Draft ของ Item เหล่านี้อยู่ไหม (เฉพาะ Item ที่เลือกจริง
       ไม่เช็คทั้ง EFID เพราะ Item อื่นที่ใช้ EF เดียวกันไม่ควรถูกนับรวมด้วย) */
    if (!$force) {
        $totalDraft = 0;
        foreach ($itemIds as $rawItemID) {
            $itemID = (int)$rawItemID;
            if ($itemID <= 0) { continue; }
            $resChk = sqlsrv_query($conn,
                "SELECT COUNT(*) AS Cnt FROM CFP_ActivityData ad
                 JOIN CFP_MonthlyHeader h ON h.HeaderID = ad.HeaderID
                 WHERE ad.ItemID = ? AND ad.IsActive = 1 AND h.Status = 0",
                array($itemID));
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

    foreach ($itemIds as $rawItemID) {
        $itemID = (int)$rawItemID;
        if ($itemID <= 0) { $errors[] = "ItemID ไม่ถูกต้อง"; continue; }

        /* ตรวจว่า Item มีอยู่และผูก EF อยู่จริง */
        $resChk = sqlsrv_query($conn,
            "SELECT ItemID FROM CFP_ActivityItem WHERE ItemID=? AND IsActive=1 AND EFID IS NOT NULL",
            array($itemID));
        if (!$resChk || !sqlsrv_fetch_array($resChk, SQLSRV_FETCH_ASSOC)) {
            $errors[] = "ItemID=$itemID — ไม่พบหรือยังไม่ผูก";
            continue;
        }

        $r = sqlsrv_query($conn,
            "UPDATE CFP_ActivityItem SET EFID=NULL, UpdatedBy=?, UpdatedDate=GETDATE() WHERE ItemID=?",
            array($userID, $itemID));

        if ($r === false) {
            $errors[] = "ItemID=$itemID — ยกเลิกการผูกไม่สำเร็จ";
        } else {
            $unlinked++;
        }
    }

    if ($unlinked > 0) {
        logAction($conn, 'DATA_UPDATE', 'CFP_ActivityItem', null, null, null, null,
            'Bulk-unlink ActivityItem.EFID: สำเร็จ ' . $unlinked . ' รายการ');
    }

    echo json_encode(array(
        'ok'       => $unlinked > 0,
        'unlinked' => $unlinked,
        'errors'   => $errors,
    ));
    exit;
}

fail('action ไม่ถูกต้อง');

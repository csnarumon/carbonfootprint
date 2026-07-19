<?php
/* ==============================================
   includes/notif_status.php
   ใช้ร่วมกันระหว่าง topbar.php (render แรก) และ
   includes/notif_check.php (AJAX polling รีเฟรชกระดิ่ง)
   ============================================== */

/**
 * ดึงจำนวน + รายการ Header ที่รออนุมัติ (Status=1) ตามสิทธิ์ของผู้ใช้
 * Role 2 (Reviewer) เห็นเฉพาะ Site ที่ได้รับมอบหมายใน CFP_UserScopeAccess
 * Role 3/4/5 เห็นทุก Site
 */
function cfpGetNotifications($conn, $roleID, $userID) {
    $result = array(
        'count' => 0, 'items' => array(), 'approvalCount' => 0,
        'assetRequestCount' => 0, 'assetRequestItems' => array(),
        'fulfilledCount' => 0, 'fulfilledItems' => array(),
    );

    /* Data Entry (role 1): แจ้งเตือนคำขอเพิ่มทรัพย์สินที่ Admin ทำเสร็จแล้ว (ภายใน 7 วัน) ─
       ให้รู้ว่ากลับไปเลือกทรัพย์สินที่เพิ่งสร้างในหน้ากรอกข้อมูลได้แล้ว */
    if ($roleID === 1) {
        $frData = cfpGetFulfilledRequests($conn, $userID);
        $result['fulfilledCount'] = $frData['count'];
        $result['fulfilledItems'] = $frData['items'];
        $result['count'] = $frData['count'];
        return $result;
    }

    if (!in_array($roleID, array(2, 3, 4, 5))) { return $result; }

    $siteFilter = '';
    $params = array();
    /* Admin/SustAdmin (Role จริง 4/5) ที่ Elevate ลงมาเป็น Reviewer (roleID=2) ชั่วคราว
       ต้องเห็นทุก Site เหมือนปกติ — ไม่กรองตาม CFP_UserScopeAccess ของ UserID จริง
       (บัญชี Admin ไม่เคยมีแถวในตารางนี้ ทำให้ elevate แล้วมองไม่เห็นการแจ้งเตือนอะไรเลย) */
    if ($roleID === 2 && !isSuperAdmin()) {
        $resSite = sqlsrv_query($conn,
            "SELECT DISTINCT SiteID FROM CFP_UserScopeAccess WHERE UserID = ? AND IsActive = 1 AND SiteID IS NOT NULL",
            array($userID));
        $allowedSites = array();
        if ($resSite) {
            while ($r = sqlsrv_fetch_array($resSite, SQLSRV_FETCH_ASSOC)) {
                $allowedSites[] = (int)$r['SiteID'];
            }
        }
        if (empty($allowedSites)) { $allowedSites = array(0); }
        $ph = implode(',', array_fill(0, count($allowedSites), '?'));
        $siteFilter = " AND h.SiteID IN ($ph)";
        $params = $allowedSites;
    }

    /* 1 แถว = 1 Header (1 ชุดที่ submit มา) ไม่ใช่ 1 แถวต่อ Activity Item — เพราะ 1 Header
       มีได้หลาย Item พร้อมกัน ถ้า join ตรงๆ แบบเดิมจะได้หลายแถวซ้อนกันจน "1 รายการ" (นับ Header)
       กับจำนวนที่ list แสดงจริงไม่ตรงกัน (เช่น badge บอก 1 แต่เห็นในรายการ 5 บรรทัด) */
    $sql = "
        SELECT TOP 5
               h.HeaderID, h.SiteID, s.SiteName, us.FullName AS SubmitterName, h.SubmittedDate,
               (SELECT TOP 1 i2.ItemName FROM CFP_ActivityData a2
                JOIN CFP_ActivityItem i2 ON i2.ItemID = a2.ItemID
                WHERE a2.HeaderID = h.HeaderID AND a2.IsActive = 1
                ORDER BY a2.ActivityID) AS ItemName,
               (SELECT TOP 1 i3.ScopeNo FROM CFP_ActivityData a3
                JOIN CFP_ActivityItem i3 ON i3.ItemID = a3.ItemID
                WHERE a3.HeaderID = h.HeaderID AND a3.IsActive = 1
                ORDER BY a3.ActivityID) AS ScopeNo,
               (SELECT COUNT(*) FROM CFP_ActivityData a4 WHERE a4.HeaderID = h.HeaderID AND a4.IsActive = 1) AS ItemCount
        FROM CFP_MonthlyHeader h
        LEFT JOIN CFP_Site s ON s.SiteID = h.SiteID
        LEFT JOIN CFP_User us ON us.UserID = h.SubmittedBy
        WHERE h.Status = 1" . $siteFilter . "
        ORDER BY h.SubmittedDate DESC
    ";
    $res = @sqlsrv_query($conn, $sql, $params);
    if ($res) {
        while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $cnt = (int)($row['ItemCount'] ?? 0);
            if ($cnt > 1) {
                $row['ItemName'] = ($row['ItemName'] ?? 'ไม่มีรายการ') . ' และอีก ' . ($cnt - 1) . ' รายการ';
            }
            $result['items'][] = $row;
        }
    }

    $cntSql = "SELECT COUNT(*) AS C FROM CFP_MonthlyHeader h WHERE h.Status = 1" . $siteFilter;
    $cntRes = @sqlsrv_query($conn, $cntSql, $params);
    if ($cntRes) {
        $cntRow = sqlsrv_fetch_array($cntRes, SQLSRV_FETCH_ASSOC);
        $result['count'] = $cntRow ? (int)$cntRow['C'] : 0;
    }
    $result['approvalCount'] = $result['count'];

    /* คำขอเพิ่มทรัพย์สินใหม่ที่ยังค้าง (Status=0) — Admin (4) และ Sustainability Admin (5) เพราะเป็นคนไปสร้างทะเบียนจริง
       'count' รวมทั้งสองอย่าง (ใช้กับ badge หลัก) ส่วน 'approvalCount' คือรออนุมัติล้วนๆ (ใช้แสดงใน section แรก) */
    $result['assetRequestCount'] = 0;
    $result['assetRequestItems'] = array();
    if (in_array($roleID, array(4, 5))) {
        $arData = cfpGetAssetRequests($conn);
        $result['assetRequestCount'] = $arData['count'];
        $result['assetRequestItems'] = $arData['items'];
        $result['count'] += $arData['count'];
    }

    return $result;
}

/**
 * ดึงจำนวน + รายการคำขอเพิ่มทรัพย์สินของตัวเองที่ Admin ทำเสร็จแล้ว (Status=1) ภายใน 7 วันที่ผ่านมา
 * ใช้แจ้งเตือน Data Entry (role 1) ว่ากลับไปเลือกทรัพย์สินที่เพิ่งสร้างได้แล้ว
 */
function cfpGetFulfilledRequests($conn, $userID) {
    $result = array('count' => 0, 'items' => array());

    $sql = "
        SELECT TOP 5
               ar.RequestID, ar.ScopeNo, ar.AssetType, ar.RequestedName,
               s.SiteName, clo.FullName AS ClosedByName, ar.ClosedDate
        FROM CFP_AssetRequest ar
        LEFT JOIN CFP_Site s ON s.SiteID = ar.SiteID
        LEFT JOIN CFP_User clo ON clo.UserID = ar.ClosedBy
        WHERE ar.Status = 1 AND ar.RequestedBy = ? AND ar.ClosedDate >= DATEADD(DAY, -7, GETDATE())
        ORDER BY ar.ClosedDate DESC
    ";
    $res = @sqlsrv_query($conn, $sql, array($userID));
    if ($res) {
        while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $result['items'][] = $row;
        }
    }

    $cntRes = @sqlsrv_query($conn,
        "SELECT COUNT(*) AS C FROM CFP_AssetRequest WHERE Status = 1 AND RequestedBy = ? AND ClosedDate >= DATEADD(DAY, -7, GETDATE())",
        array($userID));
    if ($cntRes) {
        $cntRow = sqlsrv_fetch_array($cntRes, SQLSRV_FETCH_ASSOC);
        $result['count'] = $cntRow ? (int)$cntRow['C'] : 0;
    }

    return $result;
}

/**
 * ดึงจำนวน + รายการคำขอเพิ่มทรัพย์สินที่ยังค้าง (Status=0)
 */
function cfpGetAssetRequests($conn) {
    $result = array('count' => 0, 'items' => array());

    $sql = "
        SELECT TOP 5
               ar.RequestID, ar.ScopeNo, ar.AssetType, ar.RequestedName,
               s.SiteName, us.FullName AS RequesterName, ar.CreatedDate
        FROM CFP_AssetRequest ar
        LEFT JOIN CFP_Site s ON s.SiteID = ar.SiteID
        LEFT JOIN CFP_User us ON us.UserID = ar.RequestedBy
        WHERE ar.Status = 0
        ORDER BY ar.CreatedDate DESC
    ";
    $res = @sqlsrv_query($conn, $sql);
    if ($res) {
        while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $result['items'][] = $row;
        }
    }

    $cntRes = @sqlsrv_query($conn, "SELECT COUNT(*) AS C FROM CFP_AssetRequest WHERE Status = 0");
    if ($cntRes) {
        $cntRow = sqlsrv_fetch_array($cntRes, SQLSRV_FETCH_ASSOC);
        $result['count'] = $cntRow ? (int)$cntRow['C'] : 0;
    }

    return $result;
}

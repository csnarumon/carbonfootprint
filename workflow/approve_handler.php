<?php
/* =============================================================
   workflow/approve_handler.php
   Schema จริง:
   CFP_MonthlyHeader — Status tinyint (0=Draft,1=Submitted,2=Approved)
                       + ReviewedBy/ReviewedDate, ApprovedBy/ApprovedDate, Remark
   CFP_ActivityData  — ไม่มี Status (อยู่ที่ Header)
   Actions:
     GET  ?action=detail&headerID=N  → HTML detail popup
     POST {action, headerID, reason, csrf_token}
          action: approve | reject | unlock
   ============================================================= */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(2, 3, 4, 5));

header('Content-Type: application/json; charset=utf-8');
$conn    = getConnection();
$userID  = (int)$_SESSION['user_id'];
$roleID  = getActualRole();
$effRole = getEffectiveRole();
$isSuperAdmin = isSuperAdmin() && !isViewingAs();

function jsonOut($s, $m, $extra = array()) {
    echo json_encode(array_merge(array('success' => $s, 'msg' => $m), $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── Status map ── */
$STATUS_LABEL = array(0 => 'Draft', 1 => 'Submitted', 2 => 'Approved');
$STATUS_TH    = array(0 => 'ร่าง', 1 => 'รออนุมัติ', 2 => 'อนุมัติแล้ว');
$SCOPE_LABEL  = array('Scope1' => 'Scope 1 — Direct', 'Scope2' => 'Scope 2 — Energy', 'Scope3' => 'Scope 3 — Indirect');

/* =====================================================
   GET: detail popup
   ===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'detail') {
    $headerID = (int)($_GET['headerID'] ?? 0);
    if ($headerID <= 0) { jsonOut(false, 'ไม่พบรหัส'); }

    /* ── ดึง Header info ── */
    $resH = sqlsrv_query($conn,
        "SELECT h.HeaderID, h.SiteID, h.YearMonth, h.Scope, h.Status,
                h.SubmittedBy, h.SubmittedDate, h.ReviewedBy, h.ReviewedDate,
                h.ApprovedBy, h.ApprovedDate, h.Remark,
                s.SiteName,
                ub.FullName AS SubmitterName,
                ur.FullName AS ReviewerName,
                ua.FullName AS ApproverName
         FROM CFP_MonthlyHeader h
         LEFT JOIN CFP_Site s ON s.SiteID = h.SiteID
         LEFT JOIN CFP_User ub ON ub.UserID = h.SubmittedBy
         LEFT JOIN CFP_User ur ON ur.UserID = h.ReviewedBy
         LEFT JOIN CFP_User ua ON ua.UserID = h.ApprovedBy
         WHERE h.HeaderID = ?",
        array($headerID));
    if (!$resH) { jsonOut(false, 'Query ไม่สำเร็จ'); }
    $h = sqlsrv_fetch_array($resH, SQLSRV_FETCH_ASSOC);
    if (!$h) { jsonOut(false, 'ไม่พบข้อมูล Header'); }


    /* ── ดึง ActivityData รายการ (รวม AssetID/AssetType) ── */
    $resA = sqlsrv_query($conn,
        "SELECT a.ActivityID, a.ActivityName, a.Category, a.Quantity, a.CO2e, a.Remark,
                a.AssetID, a.AssetType,
                u.UnitName, i.ItemCode
         FROM CFP_ActivityData a
         LEFT JOIN CFP_Unit u ON u.UnitID = a.UnitID
         LEFT JOIN CFP_ActivityItem i ON i.ItemID = a.ItemID
         WHERE a.HeaderID = ? AND a.IsActive = 1
         ORDER BY a.CategoryNo, a.ActivityID",
        array($headerID));

    $rows = array();
    if ($resA) { while ($r = sqlsrv_fetch_array($resA, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; } }

    /* ── Resolve ชื่อทรัพย์สินจริงจาก AssetType/AssetID (polymorphic lookup)
       Mapping ตาม convention เดียวกับที่ใช้ใน scope1/2/3.php และ dashboard coverage ── */
    $assetTableMap = array(
        'Equipment'     => array('table' => 'CFP_Equipment',     'idCol' => 'EquipmentID', 'nameCol' => 'EquipmentName'),
        'Vehicle'       => array('table' => 'CFP_Vehicle',       'idCol' => 'VehicleID',   'nameCol' => 'VehicleName'),
        'Refrigerant'   => array('table' => 'CFP_Cooling',       'idCol' => 'CoolingID',   'nameCol' => 'CoolingName'),
        'ElectricMeter' => array('table' => 'CFP_ElectricMeter', 'idCol' => 'MeterID',     'nameCol' => 'MeterName'),
        'Vendor'        => array('table' => 'CFP_Vendor',        'idCol' => 'VendorID',    'nameCol' => 'VendorName'),
        'Waste'         => array('table' => 'CFP_Waste',         'idCol' => 'WasteID',     'nameCol' => 'WasteName'),
        'Employee'      => array('table' => 'CFP_Employee',      'idCol' => 'EmployeeID',  'nameCol' => 'FullName'),
    );

    /* จัดกลุ่ม AssetID ตาม AssetType ที่ปรากฏจริงในรายการนี้ */
    $assetIDsByType = array();
    foreach ($rows as $r) {
        $at = $r['AssetType'] ?? '';
        $aid = (int)($r['AssetID'] ?? 0);
        if ($at !== '' && $aid > 0 && isset($assetTableMap[$at])) {
            $assetIDsByType[$at][$aid] = true;
        }
    }

    /* ดึงชื่อทรัพย์สินทีละประเภท (1 query ต่อประเภท ไม่ query ทีละแถว) */
    $assetNameMap = array(); /* [AssetType][AssetID] => AssetName */
    foreach ($assetIDsByType as $at => $idSet) {
        $def = $assetTableMap[$at];
        $ids = array_keys($idSet);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $resAs = sqlsrv_query($conn,
            "SELECT {$def['idCol']} AS AssetID, {$def['nameCol']} AS AssetName
             FROM {$def['table']} WHERE {$def['idCol']} IN ($ph)",
            $ids);
        if ($resAs) {
            while ($rAs = sqlsrv_fetch_array($resAs, SQLSRV_FETCH_ASSOC)) {
                $assetNameMap[$at][(int)$rAs['AssetID']] = $rAs['AssetName'];
            }
        }
    }

    $subDate = $h['SubmittedDate'] instanceof DateTime ? $h['SubmittedDate']->format('d M Y H:i') : '—';
    $revDate = $h['ReviewedDate']  instanceof DateTime ? $h['ReviewedDate']->format('d M Y H:i')  : '—';
    $apvDate = $h['ApprovedDate']  instanceof DateTime ? $h['ApprovedDate']->format('d M Y H:i')   : '—';
    $statusTH = $STATUS_TH[(int)$h['Status']] ?? '—';
    $scopeLbl = $SCOPE_LABEL[$h['Scope']] ?? $h['Scope'];
    $totalCO2 = array_sum(array_column($rows, 'CO2e'));

    /* ── สร้าง HTML ── */
    $rowsHtml = '';
    foreach ($rows as $r) {
        $at  = $r['AssetType'] ?? '';
        $aid = (int)($r['AssetID'] ?? 0);
        $assetLabel = ($at !== '' && $aid > 0 && isset($assetNameMap[$at][$aid]))
            ? htmlspecialchars($assetNameMap[$at][$aid])
            : '<span style="color:var(--cfp-text-muted);">—</span>';

        $rowsHtml .= '<tr>'
            . '<td style="font-size:0.78rem;">' . htmlspecialchars($r['ActivityName'] ?? $r['ItemCode'] ?? '—') . '</td>'
            . '<td style="font-size:0.78rem;color:var(--cfp-text-muted);">' . htmlspecialchars($r['Category'] ?? '—') . '</td>'
            . '<td style="font-size:0.78rem;">' . $assetLabel . '</td>'
            . '<td style="text-align:right;font-size:0.78rem;">' . number_format((float)($r['Quantity'] ?? 0), 4) . '</td>'
            . '<td style="font-size:0.78rem;color:var(--cfp-text-muted);">' . htmlspecialchars($r['UnitName'] ?? '') . '</td>'
            . '<td style="text-align:right;font-size:0.78rem;font-weight:500;color:#059669;">' . ($r['CO2e'] !== null ? number_format((float)$r['CO2e'], 4) : '—') . '</td>'
            . '</tr>';
    }


    /* ── ดึง ActivityData รายการ ── */
    // $resA = sqlsrv_query($conn,
    //     "SELECT a.ActivityID, a.ActivityName, a.Category, a.Quantity, a.CO2e, a.Remark,
    //             u.UnitName, i.ItemCode
    //      FROM CFP_ActivityData a
    //      LEFT JOIN CFP_Unit u ON u.UnitID = a.UnitID
    //      LEFT JOIN CFP_ActivityItem i ON i.ItemID = a.ItemID
    //      WHERE a.HeaderID = ? AND a.IsActive = 1
    //      ORDER BY a.CategoryNo, a.ActivityID",
    //     array($headerID));

    // $rows = array();
    // if ($resA) { while ($r = sqlsrv_fetch_array($resA, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; } }

    // $subDate = $h['SubmittedDate'] instanceof DateTime ? $h['SubmittedDate']->format('d M Y H:i') : '—';
    // $revDate = $h['ReviewedDate']  instanceof DateTime ? $h['ReviewedDate']->format('d M Y H:i')  : '—';
    // $apvDate = $h['ApprovedDate']  instanceof DateTime ? $h['ApprovedDate']->format('d M Y H:i')   : '—';
    // $statusTH = $STATUS_TH[(int)$h['Status']] ?? '—';
    // $scopeLbl = $SCOPE_LABEL[$h['Scope']] ?? $h['Scope'];
    // $totalCO2 = array_sum(array_column($rows, 'CO2e'));

    // /* ── สร้าง HTML ── */
    // $rowsHtml = '';
    // foreach ($rows as $r) {
    //     $rowsHtml .= '<tr>'
    //         . '<td style="font-size:0.78rem;">' . htmlspecialchars($r['ActivityName'] ?? $r['ItemCode'] ?? '—') . '</td>'
    //         . '<td style="font-size:0.78rem;color:var(--cfp-text-muted);">' . htmlspecialchars($r['Category'] ?? '—') . '</td>'
    //         . '<td style="text-align:right;font-size:0.78rem;">' . number_format((float)($r['Quantity'] ?? 0), 4) . '</td>'
    //         . '<td style="font-size:0.78rem;color:var(--cfp-text-muted);">' . htmlspecialchars($r['UnitName'] ?? '') . '</td>'
    //         . '<td style="text-align:right;font-size:0.78rem;font-weight:500;color:#059669;">' . ($r['CO2e'] !== null ? number_format((float)$r['CO2e'], 4) : '—') . '</td>'
    //         . '</tr>';
    // }

    $html = '
    <div style="font-family:\'Prompt\',sans-serif;">
      <table class="table table-bordered table-sm mb-3" style="font-size:0.82rem;">
        <tr><th style="width:130px;background:#EEF6F8;color:#1A3A44;">Scope</th><td>' . htmlspecialchars($scopeLbl) . '</td></tr>
        <tr><th style="background:#EEF6F8;color:#1A3A44;">Site</th><td>' . htmlspecialchars($h['SiteName'] ?? '—') . '</td></tr>
        <tr><th style="background:#EEF6F8;color:#1A3A44;">เดือน/ปี</th><td>' . htmlspecialchars($h['YearMonth'] ?? '—') . '</td></tr>
        <tr><th style="background:#EEF6F8;color:#1A3A44;">สถานะ</th><td><strong>' . $statusTH . '</strong></td></tr>
        <tr><th style="background:#EEF6F8;color:#1A3A44;">ผู้ส่ง</th><td>' . htmlspecialchars($h['SubmitterName'] ?? '—') . ' · ' . $subDate . '</td></tr>
        ' . ($h['ReviewedBy'] ? '<tr><th style="background:#EEF6F8;color:#1A3A44;">ผู้ตรวจ</th><td>' . htmlspecialchars($h['ReviewerName'] ?? '—') . ' · ' . $revDate . '</td></tr>' : '') . '
        ' . ($h['ApprovedBy'] ? '<tr><th style="background:#EEF6F8;color:#1A3A44;">ผู้อนุมัติ</th><td>' . htmlspecialchars($h['ApproverName'] ?? '—') . ' · ' . $apvDate . '</td></tr>' : '') . '
        ' . (!empty($h['Remark']) ? '<tr><th style="background:#FEF3C7;color:#92400E;">หมายเหตุ</th><td>' . htmlspecialchars($h['Remark']) . '</td></tr>' : '') . '
        <tr><th style="background:#D1FAE5;color:#065F46;">รวม kgCO₂e</th><td><strong style="color:#059669;">' . number_format($totalCO2, 4) . ' kgCO₂e = ' . number_format($totalCO2 / 1000, 4) . ' tCO₂e</strong></td></tr>
      </table>
      <div style="font-size:0.72rem;font-weight:600;color:var(--cfp-text-muted);letter-spacing:.5px;text-transform:uppercase;margin-bottom:6px;">รายการกิจกรรม (' . count($rows) . ' รายการ)</div>
      <div class="table-responsive">
      <table class="table table-bordered table-sm" style="font-size:0.78rem;">
        <thead><tr style="background:#EEF6F8;">
          <th>รายการ</th><th>หมวด</th><th>ทรัพย์สิน</th><th class="text-end">ปริมาณ</th><th>หน่วย</th><th class="text-end">kgCO₂e</th>
        </tr></thead>
        <tbody>' . $rowsHtml . '</tbody>
        <tfoot><tr style="background:#F0FDF4;">
          <td colspan="5" style="text-align:right;font-weight:500;">รวม</td>
          <td style="text-align:right;font-weight:700;color:#059669;">' . number_format($totalCO2, 4) . '</td>
        </tr></tfoot>
      </table>
      </div>
    </div>';

    jsonOut(true, '', array('html' => $html));
}

/* =====================================================
   POST: approve | reject | unlock
   ===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);

    $action   = $body['action']     ?? '';
    $headerID = (int)($body['headerID'] ?? 0);
    $reason   = trim($body['reason']   ?? '');
    $csrf     = $body['csrf_token']    ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf)) { jsonOut(false, 'CSRF ไม่ถูกต้อง'); }
    if (isViewingAs()) { jsonOut(false, 'อยู่ในโหมด View-as (Read-only) — ดำเนินการไม่ได้'); }
    if ($headerID <= 0) { jsonOut(false, 'ไม่พบรหัส Header'); }
    if (!in_array($action, array('approve', 'reject', 'unlock', 'mark_reviewed'))) { jsonOut(false, 'Action ไม่ถูกต้อง'); }
    if ($action === 'reject' && empty($reason)) { jsonOut(false, 'กรุณาระบุเหตุผลที่ส่งกลับ'); }

    /* ── unlock ต้องเป็น Admin/SustainAdmin เท่านั้น ── */
    if ($action === 'unlock' && !$isSuperAdmin) {
        jsonOut(false, 'เฉพาะ Admin หรือ Sustainability Admin เท่านั้นที่ unlock ได้');
    }

    /* ── ดึง Header ปัจจุบัน ── */
    $resH = sqlsrv_query($conn,
        "SELECT HeaderID, Status, Scope, SiteID, YearMonth, SubmittedBy, CreatedBy
         FROM CFP_MonthlyHeader WHERE HeaderID = ?",
        array($headerID));
    $h = $resH ? sqlsrv_fetch_array($resH, SQLSRV_FETCH_ASSOC) : null;
    if (!$h) { jsonOut(false, 'ไม่พบ Header'); }

    $curStatus = (int)$h['Status'];

    /* ── Self-approval check (ISO 14064) ── */
    if ($action === 'approve') {
        if ((int)$h['SubmittedBy'] === $userID || (int)$h['CreatedBy'] === $userID) {
            jsonOut(false, 'ไม่สามารถอนุมัติข้อมูลที่ตัวเองบันทึก/ส่งได้ (ISO 14064 Segregation of Duties)');
        }
    }

    /* ── ตรวจ Status ที่อนุญาต ── */
    if ($action === 'approve' || $action === 'reject') {
        if ($curStatus !== 1) {
            jsonOut(false, 'ข้อมูลไม่ได้อยู่ในสถานะ "รออนุมัติ" (Status=' . $curStatus . ')');
        }
    }
    if ($action === 'unlock') {
        if ($curStatus === 0) { jsonOut(false, 'ข้อมูลอยู่ในสถานะ Draft อยู่แล้ว'); }
        if ($curStatus === 2) {
            /* Approved — unlock ได้แต่ต้อง confirm พิเศษ */
            /* ตรวจ override flag */
            $override = (bool)($body['override'] ?? false);
            if (!$override) {
                jsonOut(false, 'ข้อมูลถูกอนุมัติแล้ว ต้องยืนยัน override เพื่อ unlock', array('needOverride' => true));
            }
        }
    }
    if ($action === 'mark_reviewed' && $curStatus !== 1) {
        jsonOut(false, 'ข้อมูลไม่ได้อยู่ในสถานะ "รออนุมัติ"');
    }

    /* ── ดำเนินการ ── */
    if ($action === 'approve') {
        $res = sqlsrv_query($conn,
            "UPDATE CFP_MonthlyHeader SET Status=2, ApprovedBy=?, ApprovedDate=GETDATE() WHERE HeaderID=?",
            array($userID, $headerID));
        if (!$res) { $e = sqlsrv_errors(); jsonOut(false, 'อนุมัติไม่สำเร็จ: ' . ($e[0]['message'] ?? '')); }
        logAction($conn, 'APPROVE', 'CFP_MonthlyHeader', $headerID,
            $h['SiteID'], $h['YearMonth'], $h['Scope'], 'อนุมัติ HeaderID=' . $headerID);
        jsonOut(true, 'อนุมัติเรียบร้อยแล้ว');

    } elseif ($action === 'reject') {
        /* ส่งกลับ Draft + บันทึกเหตุผลใน Remark */
        $res = sqlsrv_query($conn,
            "UPDATE CFP_MonthlyHeader SET Status=0, Remark=?, ReviewedBy=?, ReviewedDate=GETDATE() WHERE HeaderID=?",
            array($reason, $userID, $headerID));
        if (!$res) { $e = sqlsrv_errors(); jsonOut(false, 'ส่งกลับไม่สำเร็จ: ' . ($e[0]['message'] ?? '')); }
        logAction($conn, 'REJECT', 'CFP_MonthlyHeader', $headerID,
            $h['SiteID'], $h['YearMonth'], $h['Scope'], 'ส่งกลับ Draft: ' . $reason);
        jsonOut(true, 'ส่งกลับให้แก้ไขเรียบร้อยแล้ว');

    } elseif ($action === 'unlock') {
        $res = sqlsrv_query($conn,
            "UPDATE CFP_MonthlyHeader SET Status=0, Remark=?, ReviewedBy=?, ReviewedDate=GETDATE() WHERE HeaderID=?",
            array('[Admin Unlock] ' . $reason, $userID, $headerID));
        if (!$res) { $e = sqlsrv_errors(); jsonOut(false, 'Unlock ไม่สำเร็จ: ' . ($e[0]['message'] ?? '')); }
        logAction($conn, 'UNLOCK', 'CFP_MonthlyHeader', $headerID,
            $h['SiteID'], $h['YearMonth'], $h['Scope'], 'Admin Unlock: ' . $reason);
        jsonOut(true, 'Unlock เรียบร้อย — ข้อมูลกลับสู่สถานะ Draft');

    } elseif ($action === 'mark_reviewed') {
        $res = sqlsrv_query($conn,
            "UPDATE CFP_MonthlyHeader SET ReviewedBy=?, ReviewedDate=GETDATE() WHERE HeaderID=?",
            array($userID, $headerID));
        if (!$res) { $e = sqlsrv_errors(); jsonOut(false, 'บันทึกไม่สำเร็จ: ' . ($e[0]['message'] ?? '')); }
        logAction($conn, 'REVIEW', 'CFP_MonthlyHeader', $headerID,
            $h['SiteID'], $h['YearMonth'], $h['Scope'], 'ทำเครื่องหมายตรวจแล้ว HeaderID=' . $headerID);
        jsonOut(true, 'บันทึกว่าตรวจแล้วเรียบร้อย');
    }
}

jsonOut(false, 'คำขอไม่ถูกต้อง');

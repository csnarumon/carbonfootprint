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
        "SELECT a.ActivityID, a.ItemID, a.ActivityName, a.Category, a.Quantity, a.CO2e, a.Remark,
                a.AssetID, a.AssetType, a.EvidenceFile, a.EvidenceFileName,
                u.UnitName, i.ItemCode, i.Scope1Type, i.MobileRoadType
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

    /* ── QA: ตรวจรายการซ้ำ — ItemID+AssetID เดียวกัน ถูกกรอกมากกว่า 1 แถวใน Header นี้
       (นับเฉพาะแถวที่ผูกทรัพย์สินจริง — AssetID > 0 — เพราะแถวที่ไม่ผูกทรัพย์สิน "ซ้ำ" กันได้ตามปกติ เช่น Item เดียวกันคนละรายการค่าใช้จ่าย) ── */
    $dupKeyCounts = array();
    foreach ($rows as $r) {
        $aid = (int)($r['AssetID'] ?? 0);
        if ($aid <= 0) { continue; }
        $key = (int)$r['ItemID'] . '|' . ($r['AssetType'] ?? '') . '|' . $aid;
        $dupKeyCounts[$key] = ($dupKeyCounts[$key] ?? 0) + 1;
    }
    $dupKeys = array_keys(array_filter($dupKeyCounts, function($c) { return $c > 1; }));
    $qaWarnings = array(); /* [key] => ['label'=>.., 'count'=>N] — 1 รายการต่อ key ซ้ำ */
    if (!empty($dupKeys)) {
        foreach ($rows as $r) {
            $aid = (int)($r['AssetID'] ?? 0);
            if ($aid <= 0) { continue; }
            $key = (int)$r['ItemID'] . '|' . ($r['AssetType'] ?? '') . '|' . $aid;
            if (in_array($key, $dupKeys, true) && !isset($qaWarnings[$key])) {
                $at = $r['AssetType'] ?? '';
                $assetLabel = isset($assetNameMap[$at][$aid]) ? $assetNameMap[$at][$aid] : ('#' . $aid);
                $qaWarnings[$key] = array(
                    'label' => htmlspecialchars(($r['ActivityName'] ?? $r['ItemCode'] ?? '—') . ' — ' . $assetLabel),
                    'count' => $dupKeyCounts[$key],
                );
            }
        }
    }

    /* ── สร้าง HTML — จัดกลุ่มตาม Category (Option B: stat bar + group header ในตาราง) ── */
    $scopeFolder = strtolower($h['Scope']); /* Scope1 -> scope1 ตรงกับโฟลเดอร์จริงใน uploads/evidence/ */
    $fileCount = 0;
    $rowsByCat = array();
    foreach ($rows as $r) {
        /* Scope1 ไม่ใช้ CategoryNo (a.Category เลยเป็นค่า placeholder "CAT0" เสมอ) —
           ใช้ Scope1Type ของ Item จริงแทน ให้ตรงกับ Category ที่ใช้ใน ef_link.php
           (Stationary/Mobile-OnRoad/Mobile-OffRoad/Fugitive/Process) */
        if ($h['Scope'] === 'Scope1' && !empty($r['Scope1Type'])) {
            $catKey = ($r['Scope1Type'] === 'Mobile')
                ? 'Mobile-' . ($r['MobileRoadType'] === 'OffRoad' ? 'OffRoad' : 'OnRoad')
                : $r['Scope1Type'];
        } else {
            $catKey = $r['Category'] ?? '—';
        }
        $rowsByCat[$catKey][] = $r;
        if (!empty($r['EvidenceFile'])) { $fileCount++; }
    }

    /* เรียงลำดับกลุ่มตาม convention เดียวกับ ef_link.php: Stationary -> Mobile-OnRoad -> Mobile-OffRoad -> Fugitive -> Process
       ส่วนหมวดอื่นที่ไม่อยู่ใน list นี้ (เช่น CAT1..CAT15 ของ Scope2/3) ให้ต่อท้ายตามลำดับเดิม */
    $catOrder = array('Stationary', 'Mobile-OnRoad', 'Mobile-OffRoad', 'Fugitive', 'Process');
    uksort($rowsByCat, function($a, $b) use ($catOrder) {
        $ia = array_search($a, $catOrder);
        $ib = array_search($b, $catOrder);
        $ia = $ia === false ? 999 : $ia;
        $ib = $ib === false ? 999 : $ib;
        return $ia <=> $ib;
    });

    /* สีและไอคอนต่อหมวด — ใช้ชุดเดียวกับ master/ef_link.php (GRP_COLORS/GRP_ICONS) ให้จำง่ายตรงกันทั้งระบบ */
    $grpColors = array(
        'Stationary'     => '#2AABB8', 'Mobile-OnRoad' => '#1D4ED8', 'Mobile-OffRoad' => '#C2410C',
        'Fugitive'       => '#F59E0B', 'Process'        => '#8B5CF6', 'Electricity'    => '#F59E0B',
    );
    $grpIcons = array(
        'Stationary'     => 'bi-fire', 'Mobile-OnRoad' => 'bi-signpost-split', 'Mobile-OffRoad' => 'bi-cone-striped',
        'Fugitive'       => 'bi-droplet-half', 'Process'        => 'bi-gear-wide-connected', 'Electricity'    => 'bi-lightning-charge-fill',
    );

    $rowsHtml = '';
    foreach ($rowsByCat as $catKey => $catRows) {
        $grpColor = $grpColors[$catKey] ?? '#4A7A88';
        $grpIcon  = $grpIcons[$catKey] ?? 'bi-collection';
        $rowsHtml .= '<tr class="grp-row"><td colspan="6" style="padding:0;position:sticky;top:32px;z-index:1;">'
            . '<div style="display:flex;align-items:center;gap:8px;padding:7px 10px;border-left:4px solid ' . $grpColor . ';'
            . 'background:linear-gradient(90deg,' . $grpColor . '1f,transparent);">'
            . '<span style="width:22px;height:22px;border-radius:6px;background:' . $grpColor . ';color:#fff;'
            . 'display:flex;align-items:center;justify-content:center;font-size:0.68rem;flex-shrink:0;"><i class="bi ' . $grpIcon . '"></i></span>'
            . '<b style="font-size:0.76rem;color:#1A3A44;">' . htmlspecialchars($catKey) . '</b>'
            . '<span style="font-size:0.7rem;font-weight:600;color:#fff;background:' . $grpColor . ';padding:1px 8px;border-radius:8px;margin-left:auto;">' . count($catRows) . ' รายการ</span>'
            . '</div></td></tr>';

        foreach ($catRows as $r) {
            $at  = $r['AssetType'] ?? '';
            $aid = (int)($r['AssetID'] ?? 0);
            $assetLabel = ($at !== '' && $aid > 0 && isset($assetNameMap[$at][$aid]))
                ? htmlspecialchars($assetNameMap[$at][$aid])
                : '<span style="color:var(--cfp-text-muted);">—</span>';

            if (!empty($r['EvidenceFile'])) {
                $fileUrl = '/carbonfootprint/uploads/evidence/' . $scopeFolder . '/' . rawurlencode($r['EvidenceFile']);
                $fileLabel = htmlspecialchars($r['EvidenceFileName'] ?? 'ไฟล์แนบ');
                $onclickArgs = htmlspecialchars(json_encode($fileUrl) . ',' . json_encode($r['EvidenceFileName'] ?? 'ไฟล์แนบ'), ENT_QUOTES, 'UTF-8');
                $evidenceLabel = '<a href="#" onclick="openFilePreview(' . $onclickArgs . ');return false;" style="color:var(--cfp-primary);white-space:nowrap;">'
                    . '<i class="bi bi-paperclip"></i> ' . $fileLabel . '</a>';
            } else {
                $evidenceLabel = '<span style="color:var(--cfp-text-muted);">—</span>';
            }

            $rowsHtml .= '<tr>'
                . '<td style="font-size:0.78rem;">' . htmlspecialchars($r['ActivityName'] ?? $r['ItemCode'] ?? '—') . '</td>'
                . '<td style="font-size:0.78rem;">' . $assetLabel . '</td>'
                . '<td style="text-align:right;font-size:0.78rem;">' . number_format((float)($r['Quantity'] ?? 0), 4) . '</td>'
                . '<td style="font-size:0.78rem;color:var(--cfp-text-muted);">' . htmlspecialchars($r['UnitName'] ?? '') . '</td>'
                . '<td style="text-align:right;font-size:0.78rem;font-weight:500;color:#059669;">' . ($r['CO2e'] !== null ? number_format((float)$r['CO2e'], 4) : '—') . '</td>'
                . '<td style="font-size:0.78rem;">' . $evidenceLabel . '</td>'
                . '</tr>';
        }
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

    $qaHtml = '';
    if (!empty($qaWarnings)) {
        $qaItemsHtml = '';
        foreach ($qaWarnings as $w) {
            $qaItemsHtml .= '<li>' . $w['label'] . ' <strong>— ซ้ำ ' . $w['count'] . ' แถว</strong></li>';
        }
        $qaHtml = '<div style="background:#FFF7ED;border:1px solid #FDBA74;border-left:4px solid #EA580C;border-radius:8px;padding:10px 16px;margin-bottom:14px;">'
            . '<div style="font-size:0.82rem;font-weight:600;color:#9A3412;margin-bottom:4px;"><i class="bi bi-exclamation-triangle-fill me-1"></i>QA: พบรายการที่อาจกรอกซ้ำ (' . count($qaWarnings) . ' รายการ)</div>'
            . '<ul style="margin:0;padding-left:20px;font-size:0.78rem;color:#7C2D12;">' . $qaItemsHtml . '</ul>'
            . '<div style="font-size:0.72rem;color:#9A3412;margin-top:4px;">รายการเดียวกัน + ทรัพย์สินเดียวกัน ถูกกรอกมากกว่า 1 แถวในชุดข้อมูลนี้ — โปรดตรวจสอบก่อนอนุมัติว่าไม่ได้นับซ้ำโดยไม่ตั้งใจ</div>'
            . '</div>';
    }

    $html = '
    <div style="font-family:\'Prompt\',sans-serif;">
      ' . $qaHtml . '
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
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
        <div style="flex:1 1 100px;background:#EEF6F8;border:1px solid #D7E7EA;border-radius:10px;padding:8px 12px;">
          <div style="font-size:1.05rem;font-weight:700;color:#1A3A44;">' . count($rows) . '</div>
          <div style="font-size:0.68rem;color:var(--cfp-text-muted);">รายการทั้งหมด</div>
        </div>
        <div style="flex:1 1 100px;background:#EEF6F8;border:1px solid #D7E7EA;border-radius:10px;padding:8px 12px;">
          <div style="font-size:1.05rem;font-weight:700;color:#1A3A44;">' . $fileCount . '</div>
          <div style="font-size:0.68rem;color:var(--cfp-text-muted);">มีไฟล์แนบ</div>
        </div>
        <div style="flex:1 1 100px;background:#EEF6F8;border:1px solid #D7E7EA;border-radius:10px;padding:8px 12px;">
          <div style="font-size:1.05rem;font-weight:700;color:#1A3A44;">' . number_format($totalCO2, 2) . '</div>
          <div style="font-size:0.68rem;color:var(--cfp-text-muted);">รวม kgCO₂e</div>
        </div>
      </div>
      <div style="border:1px solid #DEE2E6;border-radius:10px;overflow:hidden;">
      <div style="max-height:340px;overflow-y:auto;">
      <table class="table table-bordered table-sm mb-0" style="font-size:0.78rem;">
        <thead><tr style="background:#EEF6F8;">
          <th style="position:sticky;top:0;z-index:2;background:#EEF6F8;">รายการ</th>
          <th style="position:sticky;top:0;z-index:2;background:#EEF6F8;">ทรัพย์สิน</th>
          <th class="text-end" style="position:sticky;top:0;z-index:2;background:#EEF6F8;">ปริมาณ</th>
          <th style="position:sticky;top:0;z-index:2;background:#EEF6F8;">หน่วย</th>
          <th class="text-end" style="position:sticky;top:0;z-index:2;background:#EEF6F8;">kgCO₂e</th>
          <th style="position:sticky;top:0;z-index:2;background:#EEF6F8;">ไฟล์แนบ</th>
        </tr></thead>
        <tbody>' . $rowsHtml . '</tbody>
      </table>
      </div>
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

    /* ── approve ต้องเป็น Approver (3) ขึ้นไปเท่านั้น — Reviewer (2) ทำได้แค่ mark_reviewed/reject
       กันไว้ฝั่ง server ด้วย ไม่พึ่ง UI (defense-in-depth) เหมือนที่ unlock เช็ค $isSuperAdmin ── */
    if ($action === 'approve' && !in_array($effRole, array(3, 4, 5))) {
        jsonOut(false, 'เฉพาะ Approver, Admin หรือ Sustainability Admin เท่านั้นที่อนุมัติได้');
    }

    /* ── ดึง Header ปัจจุบัน ── */
    $resH = sqlsrv_query($conn,
        "SELECT h.HeaderID, h.Status, h.Scope, h.SiteID, h.YearMonth, h.SubmittedBy, h.CreatedBy, s.SiteCode
         FROM CFP_MonthlyHeader h
         LEFT JOIN CFP_Site s ON s.SiteID = h.SiteID
         WHERE h.HeaderID = ?",
        array($headerID));
    $h = $resH ? sqlsrv_fetch_array($resH, SQLSRV_FETCH_ASSOC) : null;
    if (!$h) { jsonOut(false, 'ไม่พบ Header'); }

    $curStatus = (int)$h['Status'];

    /* ── Reviewer (2) / Approver (3) ทำได้เฉพาะ Site ที่ตัวเองมีสิทธิ์เท่านั้น
       กันไว้ฝั่ง server เผื่อมีคนยิง POST headerID ของ Site อื่นตรงๆ ข้าม UI
       Admin/SustainAdmin (4,5) ไม่ถูกจำกัด Site — รวมถึงตอน Elevate ลงมาเป็น 2/3 ชั่วคราวด้วย
       (บัญชี Admin จริงไม่เคยมีแถวใน CFP_UserScopeAccess จึงต้องข้ามการเช็คนี้ให้) ── */
    if (!$isSuperAdmin && in_array($effRole, array(2, 3)) && in_array($action, array('approve', 'reject', 'mark_reviewed'))) {
        $resSiteChk = sqlsrv_query($conn,
            "SELECT COUNT(*) AS Cnt FROM CFP_UserScopeAccess
             WHERE UserID = ? AND SiteID = ? AND IsActive = 1",
            array($userID, (int)$h['SiteID']));
        $rowSiteChk = $resSiteChk ? sqlsrv_fetch_array($resSiteChk, SQLSRV_FETCH_ASSOC) : null;
        if (!$rowSiteChk || (int)$rowSiteChk['Cnt'] === 0) {
            jsonOut(false, 'ไม่มีสิทธิ์ดำเนินการกับข้อมูลของ Site นี้');
        }
    }

    /* ── Self-approval check (ISO 14064) ──
       ยกเว้นเฉพาะ Site ทดสอบ (TEST-01) เท่านั้น เพื่อให้ Admin ทดสอบ workflow ครบวงจรคนเดียวได้
       โดยไม่กระทบ Segregation of Duties ของข้อมูล Site จริง */
    $isTestSite = (($h['SiteCode'] ?? '') === 'TEST-01');
    if ($action === 'approve' && !$isTestSite) {
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

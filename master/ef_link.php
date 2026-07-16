<?php
/* ==============================================
   master/ef_link.php
   Bulk-link / Bulk-unlink CFP_EFValue.RefID ↔ CFP_ActivityItem
   Admin (4), SustainAdmin (5)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(4, 5));
$conn = getConnection();

/* ===== Toast ===== */
$toastMsg  = '';
$toastType = '';
if (!empty($_SESSION['toast'])) {
    $toastMsg  = $_SESSION['toast']['msg'];
    $toastType = $_SESSION['toast']['type'];
    unset($_SESSION['toast']);
}

/* ===== ดึง EFValue ที่ยังไม่ผูก (RefID IS NULL) ===== */
$resEF = sqlsrv_query($conn, "
    SELECT e.EFID, e.EFCode, e.EFName, e.Scope, e.Category, e.GasType,
           e.EFValue, e.Unit, e.YearApply
    FROM CFP_EFValue e
    WHERE e.RefID IS NULL AND e.IsActive = 1
    ORDER BY e.Scope, e.Category, e.EFName
");
$efRows      = array();
while ($r = sqlsrv_fetch_array($resEF, SQLSRV_FETCH_ASSOC)) { $efRows[] = $r; }
$unlinkCount = count($efRows);

/* ===== ดึง EFValue ที่ผูกแล้ว (RefID IS NOT NULL) ===== */
$resLinked = sqlsrv_query($conn, "
    SELECT e.EFID, e.EFCode, e.EFName, e.Scope, e.Category, e.GasType,
           e.EFValue, e.Unit, e.YearApply,
           a.ItemCode, a.ItemName
    FROM CFP_EFValue e
    LEFT JOIN CFP_ActivityItem a ON a.ItemID = e.RefID AND e.RefTable = 'CFP_ActivityItem'
    WHERE e.RefID IS NOT NULL AND e.IsActive = 1
    ORDER BY e.Scope, e.Category, e.EFName
");
$linkedRows   = array();
while ($r = sqlsrv_fetch_array($resLinked, SQLSRV_FETCH_ASSOC)) { $linkedRows[] = $r; }
$linkedCount  = count($linkedRows);

/* ===== ดึง ActivityItem ทั้งหมด จัดกลุ่มตาม ScopeNo ===== */
$resItem = sqlsrv_query($conn, "
    SELECT ItemID, ItemCode, ItemName, ScopeNo, Scope1Type, CategoryNo
    FROM CFP_ActivityItem
    WHERE IsActive = 1
    ORDER BY ScopeNo, Scope1Type, CategoryNo, ItemName
");
$itemsByScopeNo = array(1 => array(), 2 => array(), 3 => array());
$allItems       = array();
while ($r = sqlsrv_fetch_array($resItem, SQLSRV_FETCH_ASSOC)) {
    $sno = (int)$r['ScopeNo'];
    $itemsByScopeNo[$sno][] = $r;
    $allItems[] = $r;
}

$scopeToNo = array('Scope1' => 1, 'Scope2' => 2, 'Scope3' => 3);
$gasColors = array('CO2'=>'#3B82F6','CH4'=>'#EC4899','N2O'=>'#84CC16','HFCs'=>'#B45309','CO2e'=>'#16A34A');

$jsItems = array();
foreach ($allItems as $it) {
    $jsItems[] = array(
        'id'      => (int)$it['ItemID'],
        'code'    => $it['ItemCode'],
        'name'    => $it['ItemName'],
        'scopeNo' => (int)$it['ScopeNo'],
        'type'    => $it['Scope1Type'] ?? '',
        'catNo'   => (int)($it['CategoryNo'] ?? 0),
    );
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>จัดการการผูก EF กับรา��การ Activity — ระบบบริหารจัดการคาร์บอนองค์กร</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets/css/cfp-theme.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body { font-family:'Prompt',sans-serif; }
    .font-prompt { font-family:'Prompt',sans-serif !important; }
    .ef-table th { background:var(--cfp-bg); color:var(--cfp-text-muted); font-size:0.72rem; font-weight:500; padding:7px 10px; border-bottom:1px solid var(--cfp-border); text-transform:uppercase; letter-spacing:.3px; white-space:nowrap; }
    .ef-table td { padding:8px 10px; border-bottom:1px solid var(--cfp-border); font-size:0.82rem; vertical-align:middle; }
    .ef-table tr:last-child td { border-bottom:none; }
    .ef-table tr.linked td { background:#F0FDF4; }
    .ef-table tr.linked td:first-child { border-left:3px solid #4CAF50; }
    .ef-table tr.unlink-sel td { background:#FEF2F2; }
    .item-sel { font-size:0.8rem; border:1px solid var(--cfp-border); border-radius:6px; padding:4px 8px; font-family:'Prompt',sans-serif; color:var(--cfp-text); background:#fff; width:100%; }
    .item-sel.matched { border-color:#4CAF50; background:#F0FDF4; }
    .scope-tab { padding:7px 16px; font-size:0.8rem; border-radius:20px; cursor:pointer; border:1px solid var(--cfp-border); background:#fff; color:var(--cfp-text-muted); }
    .scope-tab.active { background:var(--cfp-primary); color:#fff; border-color:var(--cfp-primary); font-weight:600; }
    .badge-unlink { background:#FEF2F2; color:#B91C1C; font-size:0.7rem; padding:2px 8px; border-radius:10px; font-weight:600; }
    .badge-linked { background:#F0FDF4; color:#166534; font-size:0.7rem; padding:2px 8px; border-radius:10px; font-weight:600; }
    .save-bar { position:sticky; bottom:0; background:#fff; border-top:1px solid var(--cfp-border); padding:12px 0; z-index:100; }
    .section-title { font-size:0.88rem; font-weight:600; color:var(--cfp-primary); padding:12px 16px; border-bottom:1px solid var(--cfp-border); display:flex; align-items:center; gap:8px; }
    .chk-unlink { width:16px; height:16px; cursor:pointer; accent-color:#E05050; }
    #progressWrap { display:none; }
  </style>
</head>
<body>
<div class="d-flex">
  <?php include '../includes/sidebar.php'; ?>
  <div class="cfp-main">
    <?php $pageTitle='ผูก EF กับรายการ Activity'; $pageIcon='link-45deg'; include '../includes/topbar.php'; ?>
    <div class="cfp-content">

      <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
          <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);">
            <i class="bi bi-link-45deg me-2"></i>จัดการการผูก EF กับรายการ Activity
          </h5>
          <div style="font-size:0.78rem;color:var(--cfp-text-muted);margin-top:2px;">
            ผูก หรือ ยกเลิกการผูก ค่า EF กับรายการกิจกรรม
          </div>
        </div>
        <a href="ef_value.php" class="btn btn-sm btn-outline-secondary font-prompt">
          <i class="bi bi-arrow-left me-1"></i>กลับหน้า EF Value
        </a>
      </div>

      <!-- KPI -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div style="width:44px;height:44px;border-radius:10px;background:#FEF2F2;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
              <i class="bi bi-unlink" style="color:#B91C1C;"></i>
            </div>
            <div>
              <div id="kpiUnlink" style="font-size:1.5rem;font-weight:700;color:#B91C1C;line-height:1.1;"><?php echo $unlinkCount; ?></div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ยังไม่ได้ผูก</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div style="width:44px;height:44px;border-radius:10px;background:#F0FDF4;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
              <i class="bi bi-link-45deg" style="color:#166534;"></i>
            </div>
            <div>
              <div id="kpiLinkedDB" style="font-size:1.5rem;font-weight:700;color:#166534;line-height:1.1;"><?php echo $linkedCount; ?></div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ผูกแล้วในฐานข้อมูล</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div style="width:44px;height:44px;border-radius:10px;background:#EFF6FF;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
              <i class="bi bi-check2-circle" style="color:#1D4ED8;"></i>
            </div>
            <div>
              <div id="kpiPending" style="font-size:1.5rem;font-weight:700;color:#1D4ED8;line-height:1.1;">0</div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">เลือกแล้ว (รอบันทึก)</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div style="width:44px;height:44px;border-radius:10px;background:#FFF7ED;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
              <i class="bi bi-x-circle" style="color:#C2410C;"></i>
            </div>
            <div>
              <div id="kpiUnlinkSel" style="font-size:1.5rem;font-weight:700;color:#C2410C;line-height:1.1;">0</div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">เลือกยกเลิกการผูก</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════ SECTION 1: ยังไม่ผูก ═══════════ -->
      <div class="cfp-card mb-3" style="padding:0;overflow:hidden;">
        <div class="section-title">
          <i class="bi bi-link-45deg" style="color:#2AABB8;"></i>
          ผูก EF กับ Activity Item
          <span class="badge-unlink ms-1"><?php echo $unlinkCount; ?> รายการ</span>
          <div class="ms-auto d-flex gap-2">
            <?php if ($unlinkCount > 0): ?>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span style="font-size:0.72rem;color:var(--cfp-text-muted);">Scope:</span>
              <button class="scope-tab active" onclick="filterScope('all', this)">ทั้งหมด</button>
              <?php
              $scopeCounts = array('Scope1'=>0,'Scope2'=>0,'Scope3'=>0);
              foreach ($efRows as $r) { if (isset($scopeCounts[$r['Scope']])) $scopeCounts[$r['Scope']]++; }
              foreach ($scopeCounts as $sc => $cnt) {
                  if ($cnt > 0) {
                      $scNo = str_replace('Scope','Scope ',$sc);
                      echo '<button class="scope-tab" onclick="filterScope(\''.$sc.'\', this)">'.$scNo.' <span style="font-size:0.65rem;background:#E5E7EB;color:#374151;padding:0 5px;border-radius:8px;">'.$cnt.'</span></button>';
                  }
              }
              ?>
            </div>
            <button class="btn btn-sm font-prompt" style="background:#F59E0B;color:#fff;font-size:0.78rem;" onclick="autoMatch()">
              <i class="bi bi-magic me-1"></i>Auto-match
            </button>
            <button class="btn btn-sm font-prompt" style="background:#E5E7EB;color:#374151;font-size:0.78rem;" onclick="clearLinkSel()">
              <i class="bi bi-x-circle me-1"></i>ล้าง
            </button>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($unlinkCount === 0): ?>
        <div class="text-center py-4" style="color:var(--cfp-text-muted);">
          <i class="bi bi-check-circle-fill" style="font-size:2rem;color:#4CAF50;display:block;margin-bottom:8px;"></i>
          EF ทุกรายการผูก Activity Item ครบแล้ว
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="ef-table w-100" id="efTable">
            <thead>
              <tr>
                <th style="width:32px;">#</th>
                <th style="min-width:220px;">ชื่อ EF</th>
                <th style="width:56px;">Scope</th>
                <th style="width:90px;">Category</th>
                <th style="width:56px;">Gas</th>
                <th style="width:90px;">ค่า EF</th>
                <th style="width:50px;">ปี</th>
                <th style="min-width:220px;">ผูกกับ Activity Item</th>
                <th style="width:70px;">สถานะ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($efRows as $idx => $ef):
                  $sno  = $scopeToNo[$ef['Scope']] ?? 0;
                  $efid = (int)$ef['EFID'];
                  $scope = htmlspecialchars($ef['Scope'] ?? '');
                  $cat   = htmlspecialchars($ef['Category'] ?? '');
              ?>
              <tr data-efid="<?php echo $efid; ?>"
                  data-scope="<?php echo $scope; ?>"
                  data-scopeno="<?php echo $sno; ?>"
                  data-category="<?php echo htmlspecialchars($ef['Category'] ?? ''); ?>"
                  data-efname="<?php echo htmlspecialchars(strtolower($ef['EFName'])); ?>">
                <td style="color:var(--cfp-text-muted);font-size:0.72rem;"><?php echo $idx+1; ?></td>
                <td>
                  <div style="font-weight:500;white-space:nowrap;"><?php echo htmlspecialchars($ef['EFName']); ?></div>
                  <code style="font-size:0.68rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($ef['EFCode']); ?></code>
                </td>
                <td>
                  <span style="font-size:0.7rem;padding:2px 8px;border-radius:10px;color:#fff;font-weight:600;background:<?php
                      echo ($scope==='Scope1'?'#2AABB8':($scope==='Scope2'?'#F59E0B':'#8B5CF6')); ?>;">
                    <?php echo $scope; ?>
                  </span>
                </td>
                <td style="font-size:0.66rem;color:var(--cfp-text-muted);white-space:nowrap;"><?php echo $cat ?: '—'; ?></td>
                <td><span style="font-size:0.7rem;padding:1px 7px;border-radius:8px;color:#fff;font-weight:600;background:<?php echo $gasColors[$ef['GasType'] ?? ''] ?? '#888'; ?>;"><?php echo htmlspecialchars($ef['GasType'] ?? ''); ?></span></td>
                <td style="font-family:monospace;font-size:0.82rem;color:var(--cfp-primary);font-weight:600;">
                  <?php echo number_format((float)$ef['EFValue'], 6); ?>
                  <?php if ($ef['Unit']) echo '<div style="font-size:0.68rem;color:var(--cfp-text-muted);">'.htmlspecialchars($ef['Unit']).'</div>'; ?>
                </td>
                <td style="font-size:0.82rem;"><?php echo (int)$ef['YearApply']; ?></td>
                <td>
                  <select class="item-sel" id="sel_<?php echo $efid; ?>"
                          data-efid="<?php echo $efid; ?>"
                          onchange="onSelChange(this, <?php echo $efid; ?>)">
                    <option value="">— ไม่ผูก —</option>
                    <?php
                    $itemsForScope = $itemsByScopeNo[$sno] ?? array();
                    if ($sno === 1) {
                        $groups = array();
                        foreach ($itemsForScope as $it) { $groups[$it['Scope1Type'] ?? 'อื่นๆ'][] = $it; }
                        $grpLabels = array('Stationary'=>'Stationary Combustion','Mobile'=>'Mobile Combustion','Fugitive'=>'Fugitive Emissions','Process'=>'Process Emissions');
                        foreach ($groups as $grp => $its) {
                            echo '<optgroup label="'.($grpLabels[$grp] ?? $grp).'">';
                            foreach ($its as $it) {
                                echo '<option value="'.(int)$it['ItemID'].'">'.htmlspecialchars($it['ItemName']).'</option>';
                            }
                            echo '</optgroup>';
                        }
                    } else {
                        foreach ($itemsForScope as $it) {
                            echo '<option value="'.(int)$it['ItemID'].'">'.htmlspecialchars($it['ItemName']).'</option>';
                        }
                    }
                    ?>
                  </select>
                </td>
                <td id="status_<?php echo $efid; ?>" class="text-center">
                  <span class="badge-unlink">ว่าง</span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <!-- Save bar (link) -->
        <div class="save-bar px-3" id="linkSaveBar" style="<?php echo $unlinkCount===0 ? 'display:none;' : ''; ?>">
          <div class="d-flex align-items-center gap-3 flex-wrap">
            <div style="font-size:0.82rem;color:var(--cfp-text-muted);">
              เลือกแล้ว <strong id="selCount">0</strong> จาก <?php echo $unlinkCount; ?> รายการ
            </div>
            <div id="progressWrap" style="flex:1;min-width:100px;">
              <div style="font-size:0.72rem;color:var(--cfp-text-muted);margin-bottom:3px;">กำลังบันทึก...</div>
              <div style="background:var(--cfp-bg);border-radius:4px;height:6px;overflow:hidden;">
                <div id="progressBar" style="background:var(--cfp-primary);height:100%;width:0%;transition:width .3s;"></div>
              </div>
            </div>
            <div class="ms-auto">
              <button id="btnSave" class="btn btn-sm font-prompt fw-600"
                      style="background:var(--cfp-primary);color:#fff;padding:6px 20px;"
                      onclick="saveAll()">
                <i class="bi bi-save me-1"></i>บันทึกทั้งหมด
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════ SECTION 2: ผูกแล้ว / Unlink ═══════════ -->
      <div class="cfp-card" style="padding:0;overflow:hidden;">
        <div class="section-title" style="cursor:pointer;" onclick="toggleLinkedSection()">
          <i class="bi bi-database-check" style="color:#166534;"></i>
          EF ที่ผูกแล้ว — คลิกเพื่อดูและยกเลิกการผูก
          <span class="badge-linked ms-1"><?php echo $linkedCount; ?> รายการ</span>
          <i class="bi bi-chevron-down ms-auto" id="linkedChevron"></i>
        </div>
        <div id="linkedSection" style="display:none;">
          <?php if ($linkedCount === 0): ?>
          <div class="text-center py-4" style="color:var(--cfp-text-muted);font-size:0.85rem;">ยังไม่มีรายการที่ผูกแล้ว</div>
          <?php else: ?>
          <div style="padding:8px 16px;background:#F0FDF4;border-bottom:1px solid var(--cfp-border);display:flex;align-items:center;gap:10px;">
            <label style="font-size:0.78rem;color:#166534;cursor:pointer;display:flex;align-items:center;gap:6px;">
              <input type="checkbox" id="chkAll" onchange="toggleAllUnlink(this)" style="width:16px;height:16px;accent-color:#E05050;cursor:pointer;">
              เลือกทั้งหมด
            </label>
            <span style="font-size:0.75rem;color:var(--cfp-text-muted);">เลือกรายการที่ต้องการยกเลิกการผูก แล้วกด "ยกเลิกการผูก"</span>
            <div class="ms-auto">
              <button class="btn btn-sm font-prompt"
                      style="background:#E05050;color:#fff;font-size:0.78rem;"
                      onclick="unlinkSelected()">
                <i class="bi bi-unlink me-1"></i>ยกเลิกการผูกที่เลือก
                (<span id="unlinkSelCount">0</span>)
              </button>
            </div>
          </div>
          <div style="overflow-x:auto;">
            <table class="ef-table w-100" id="linkedTable">
              <thead>
                <tr>
                  <th style="width:36px;"><i class="bi bi-check2-square"></i></th>
                  <th style="min-width:220px;">ชื่อ EF</th>
                  <th style="width:56px;">Scope</th>
                  <th style="width:90px;">ค่า EF</th>
                  <th style="width:50px;">ปี</th>
                  <th style="min-width:220px;">ผูกกับ Activity Item</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($linkedRows as $idx => $ef):
                    $efid  = (int)$ef['EFID'];
                    $scope = htmlspecialchars($ef['Scope'] ?? '');
                    $sno   = $scopeToNo[$ef['Scope']] ?? 0;
                ?>
                <tr data-efid="<?php echo $efid; ?>"
                    data-efcode="<?php echo htmlspecialchars($ef['EFCode'] ?? ''); ?>"
                    data-efnamedisplay="<?php echo htmlspecialchars($ef['EFName'] ?? ''); ?>"
                    data-efname="<?php echo htmlspecialchars(strtolower($ef['EFName'] ?? '')); ?>"
                    data-scope="<?php echo $scope; ?>"
                    data-scopeno="<?php echo $sno; ?>"
                    data-category="<?php echo htmlspecialchars($ef['Category'] ?? ''); ?>"
                    data-gastype="<?php echo htmlspecialchars($ef['GasType'] ?? ''); ?>"
                    data-efvalue="<?php echo (float)$ef['EFValue']; ?>"
                    data-unit="<?php echo htmlspecialchars($ef['Unit'] ?? ''); ?>"
                    data-year="<?php echo (int)$ef['YearApply']; ?>">
                  <td class="text-center">
                    <input type="checkbox" class="chk-unlink" value="<?php echo $efid; ?>"
                           onchange="onUnlinkCheck(this)">
                  </td>
                  <td>
                    <div style="font-weight:500;white-space:nowrap;"><?php echo htmlspecialchars($ef['EFName']); ?></div>
                    <code style="font-size:0.68rem;color:var(--cfp-text-muted);"><?php echo htmlspecialchars($ef['EFCode']); ?></code>
                  </td>
                  <td>
                    <span style="font-size:0.7rem;padding:2px 8px;border-radius:10px;color:#fff;font-weight:600;background:<?php
                        echo ($scope==='Scope1'?'#2AABB8':($scope==='Scope2'?'#F59E0B':'#8B5CF6')); ?>;">
                      <?php echo $scope; ?>
                    </span>
                  </td>
                  <td style="font-family:monospace;font-size:0.82rem;color:var(--cfp-primary);font-weight:600;">
                    <?php echo number_format((float)$ef['EFValue'], 6); ?>
                    <?php if ($ef['Unit']) echo '<span style="font-size:0.72rem;color:var(--cfp-text-muted);margin-left:4px;">'.htmlspecialchars($ef['Unit']).'</span>'; ?>
                  </td>
                  <td style="font-size:0.82rem;"><?php echo (int)$ef['YearApply']; ?></td>
                  <td>
                    <?php if ($ef['ItemCode']): ?>
                    <span class="badge-linked"><i class="bi bi-link-45deg"></i>
                      <?php echo htmlspecialchars($ef['ItemCode'].' — '.$ef['ItemName']); ?>
                    </span>
                    <?php else: ?>
                    <span style="font-size:0.72rem;color:var(--cfp-text-muted);">— ไม่พบ Item —</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- cfp-content -->
  </div><!-- cfp-main -->
</div>

<?php if ($toastMsg): ?>
<div id="cfpToast" class="position-fixed bottom-0 end-0 m-3 toast show align-items-center text-white border-0"
     style="background:<?php echo $toastType==='success'?'#2E7D32':'#B71C1C'; ?>;z-index:9999;min-width:260px;">
  <div class="d-flex">
    <div class="toast-body font-prompt"><?php echo htmlspecialchars($toastMsg); ?></div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest('.toast').remove()"></button>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
var ITEMS   = <?php echo json_encode($jsItems, JSON_UNESCAPED_UNICODE); ?>;
var CSRF    = '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>';
var pending    = {}; /* efid → itemID (รอ link) */
var unlinkSel  = {}; /* efid → true (รอ unlink) */

/* ═══ SECTION 1: Link ═══ */

function filterScope(scope, btn) {
    document.querySelectorAll('.scope-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#efTable tbody tr').forEach(function(tr) {
        tr.style.display = (scope === 'all' || tr.dataset.scope === scope) ? '' : 'none';
    });
}

function onSelChange(sel, efid) {
    var val = parseInt(sel.value) || 0;
    var tr  = sel.closest('tr');
    var st  = document.getElementById('status_' + efid);
    if (val) {
        pending[efid] = val;
        sel.classList.add('matched');
        if (tr)  { tr.classList.add('linked'); }
        if (st)  { st.innerHTML = '<span class="badge-linked"><i class="bi bi-check2"></i> เลือกแล้ว</span>'; }
    } else {
        delete pending[efid];
        sel.classList.remove('matched');
        if (tr)  { tr.classList.remove('linked'); }
        if (st)  { st.innerHTML = '<span class="badge-unlink">ว่าง</span>'; }
    }
    updateKpi();
}

function updateKpi() {
    var pCnt = Object.keys(pending).length;
    var uCnt = Object.keys(unlinkSel).length;
    var kpiUnlink  = document.getElementById('kpiUnlink');
    var kpiPending = document.getElementById('kpiPending');
    var selCount   = document.getElementById('selCount');
    var kpiUnlinkSel = document.getElementById('kpiUnlinkSel');
    var unlinkSelCount = document.getElementById('unlinkSelCount');
    if (kpiUnlink)     { kpiUnlink.textContent  = <?php echo $unlinkCount; ?> - pCnt; }
    if (kpiPending)    { kpiPending.textContent  = pCnt; }
    if (selCount)      { selCount.textContent    = pCnt; }
    if (kpiUnlinkSel)  { kpiUnlinkSel.textContent = uCnt; }
    if (unlinkSelCount){ unlinkSelCount.textContent = uCnt; }
}

function autoMatch() {
    var matched = 0;
    document.querySelectorAll('#efTable tbody tr').forEach(function(tr) {
        if (tr.style.display === 'none') { return; }
        var efid    = parseInt(tr.dataset.efid);
        var efname  = tr.dataset.efname;
        var scopeNo = parseInt(tr.dataset.scopeno);
        var cat     = (tr.dataset.category || '').toLowerCase();

        var candidates = ITEMS.filter(function(it) { return it.scopeNo === scopeNo; });
        if (scopeNo === 1 && cat) {
            var tc = candidates.filter(function(it) { return it.type.toLowerCase() === cat; });
            if (tc.length > 0) { candidates = tc; }
        }

        var best = null, bestScore = 0;
        candidates.forEach(function(it) {
            var iname = it.name.toLowerCase();
            var score = 0;
            if (efname === iname) { score = 100; }
            else if (efname.indexOf(iname) !== -1 || iname.indexOf(efname) !== -1) { score = 80; }
            else {
                var ew = efname.split(/\s+/);
                var iw = iname.split(/\s+/);
                var overlap = ew.filter(function(w) { return w.length > 2 && iw.indexOf(w) !== -1; }).length;
                score = overlap * 20;
            }
            if (scopeNo === 1 && cat && it.type.toLowerCase() === cat) { score += 10; }
            if (score > bestScore) { bestScore = score; best = it; }
        });

        if (best && bestScore >= 20) {
            var sel = document.getElementById('sel_' + efid);
            if (sel) { sel.value = best.id; onSelChange(sel, efid); matched++; }
        }
    });
    Swal.fire({ icon: 'info', title: 'Auto-match เสร็จสิ้น', text: 'จับคู่ได้ ' + matched + ' รายการ — กรุณาตรวจสอบก่อนบันทึก', confirmButtonColor: '#1B3A4A' });
}

function clearLinkSel() {
    document.querySelectorAll('.item-sel').forEach(function(sel) {
        sel.value = '';
        var efid = parseInt(sel.dataset.efid);
        if (!isNaN(efid)) { onSelChange(sel, efid); }
    });
}

function saveAll() {
    var pairs = [];
    for (var efid in pending) { pairs.push({ efid: parseInt(efid), itemID: pending[efid] }); }
    if (pairs.length === 0) {
        Swal.fire({ icon: 'warning', title: 'ยังไม่ได้เลือก', text: 'กรุณาเลือก Activity Item ก่อนบันทึก', confirmButtonColor: '#1B3A4A' });
        return;
    }
    Swal.fire({
        icon: 'question',
        title: 'ยืนยันการบันทึก',
        text: 'บันทึกการผูก ' + pairs.length + ' รายการ?',
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#1B3A4A',
        cancelButtonColor: '#6B7280'
    }).then(function(result) {
        if (!result.isConfirmed) { return; }

        var btn = document.getElementById('btnSave');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>กำลังบันทึก...';
        document.getElementById('progressWrap').style.display = 'block';

        fetch('ef_link_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF, action: 'link', pairs: pairs })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('progressBar').style.width = '100%';
            if (data.ok) {
                pairs.forEach(function(p) {
                    var tr = document.querySelector('#efTable tbody tr[data-efid="' + p.efid + '"]');
                    if (tr) { tr.remove(); }
                    delete pending[p.efid];
                });
                /* อัปเดต KPI ผูกแล้วใน DB */
                var kpiDB = document.getElementById('kpiLinkedDB');
                if (kpiDB) { kpiDB.textContent = parseInt(kpiDB.textContent || 0) + data.saved; }
                updateKpi();
                showToast('บันทึกสำเร็จ ' + data.saved + ' รายการ', 'success');
            } else {
                showToast('เกิดข้อผิดพลาด: ' + (data.error || 'ไม่ทราบสาเหตุ'), 'error');
            }
        })
        .catch(function(e) { showToast('เชื่อมต่อไม่ได้: ' + e.message, 'error'); })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-save me-1"></i>บันทึกทั้งหมด';
            setTimeout(function() { document.getElementById('progressWrap').style.display = 'none'; }, 1200);
        });
    });
}

/* ═══ SECTION 2: Unlink ═══ */

function toggleLinkedSection() {
    var sec = document.getElementById('linkedSection');
    var chv = document.getElementById('linkedChevron');
    var open = sec.style.display !== 'none';
    sec.style.display = open ? 'none' : '';
    chv.className = open ? 'bi bi-chevron-down ms-auto' : 'bi bi-chevron-up ms-auto';
}

function onUnlinkCheck(chk) {
    var efid = parseInt(chk.value);
    var tr   = chk.closest('tr');
    if (chk.checked) {
        unlinkSel[efid] = true;
        if (tr) { tr.classList.add('unlink-sel'); }
    } else {
        delete unlinkSel[efid];
        if (tr) { tr.classList.remove('unlink-sel'); }
    }
    updateKpi();
}

function toggleAllUnlink(masterChk) {
    document.querySelectorAll('.chk-unlink').forEach(function(chk) {
        chk.checked = masterChk.checked;
        onUnlinkCheck(chk);
    });
}

function unlinkSelected() {
    var ids = Object.keys(unlinkSel).map(Number);
    if (ids.length === 0) {
        Swal.fire({ icon: 'warning', title: 'ยังไม่ได้เลือก', text: 'กรุณาเลือก checkbox รายการที่ต้องการยกเลิกการผูก', confirmButtonColor: '#1B3A4A' });
        return;
    }
    Swal.fire({
        icon: 'warning',
        title: 'ยืนยันการยกเลิกการผูก',
        html: 'ยกเลิกการผูก <strong>' + ids.length + ' รายการ</strong>?<br><small style="color:#6B7280;">EF เหล่านี้จะกลับไปไม่มี Activity Item</small>',
        showCancelButton: true,
        confirmButtonText: 'ยกเลิกการผูก',
        cancelButtonText: 'ปิด',
        confirmButtonColor: '#E05050',
        cancelButtonColor: '#6B7280'
    }).then(function(result) {
        if (!result.isConfirmed) { return; }
        doUnlink(ids, false);
    });
}

function doUnlink(ids, force) {
    fetch('ef_link_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: CSRF, action: 'unlink', efids: ids, force: force })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.requireConfirm) {
            Swal.fire({
                icon: 'warning',
                title: 'พบข้อมูล Draft ที่ใช้ EF นี้อยู่',
                html: 'มี <strong>' + data.draftCount + ' รายการ Draft</strong> ที่ใช้ EF ที่เลือกอยู่<br>' +
                      '<small style="color:#6B7280;">ถ้ายังคง Unlink และผู้ใช้บันทึกข้อมูล Draft นั้นใหม่ CO2e จะเป็น null</small>',
                showCancelButton: true,
                confirmButtonText: 'ยืนยัน ยกเลิกการผูก',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#E05050',
                cancelButtonColor: '#6B7280'
            }).then(function(r) {
                if (r.isConfirmed) { doUnlink(ids, true); }
            });
            return;
        }
        if (data.ok) {
            ids.forEach(function(efid) {
                var tr = document.querySelector('#linkedTable tbody tr[data-efid="' + efid + '"]');
                if (tr) {
                    appendToSection1(tr);
                    tr.remove();
                }
                delete unlinkSel[efid];
            });
            var kpiDB = document.getElementById('kpiLinkedDB');
            if (kpiDB) { kpiDB.textContent = Math.max(0, parseInt(kpiDB.textContent || 0) - data.unlinked); }
            var kpiUnlink = document.getElementById('kpiUnlink');
            if (kpiUnlink) { kpiUnlink.textContent = parseInt(kpiUnlink.textContent || 0) + data.unlinked; }
            updateKpi();
            document.getElementById('chkAll').checked = false;
            showToast('ยกเลิกการผูกสำเร็จ ' + data.unlinked + ' รายการ', 'success');
        } else {
            showToast('เกิดข้อผิดพลาด: ' + (data.error || 'ไม่ทราบสาเหตุ'), 'error');
        }
    })
    .catch(function(e) { showToast('เชื่อมต่อไม่ได้: ' + e.message, 'error'); });
}

function appendToSection1(srcTr) {
    var efid     = srcTr.dataset.efid;
    var efcode   = srcTr.dataset.efcode   || '';
    var efname   = srcTr.dataset.efnamedisplay || '';
    var efnameLc = srcTr.dataset.efname   || '';
    var scope    = srcTr.dataset.scope    || '';
    var scopeNo  = parseInt(srcTr.dataset.scopeno) || 0;
    var cat      = srcTr.dataset.category || '';
    var gasType  = srcTr.dataset.gastype  || '';
    var efval    = parseFloat(srcTr.dataset.efvalue) || 0;
    var unit     = srcTr.dataset.unit     || '';
    var year     = srcTr.dataset.year     || '';

    var scopeColor = scope === 'Scope1' ? '#2AABB8' : (scope === 'Scope2' ? '#F59E0B' : '#8B5CF6');
    var GAS_COLORS = { CO2:'#3B82F6', CH4:'#EC4899', N2O:'#84CC16', HFCs:'#B45309', CO2e:'#16A34A' };
    var gasColor = GAS_COLORS[gasType] || '#888';

    /* สร้าง dropdown options filter ตาม scopeNo */
    var opts = '<option value="">— ไม่ผูก —</option>';
    var groups = {};
    ITEMS.forEach(function(it) {
        if (it.scopeNo !== scopeNo) { return; }
        var grpKey = scopeNo === 1 ? (it.type || 'อื่นๆ') : 'items';
        if (!groups[grpKey]) { groups[grpKey] = []; }
        groups[grpKey].push(it);
    });
    var grpLabels = { Stationary: 'Stationary Combustion', Mobile: 'Mobile Combustion', Fugitive: 'Fugitive Emissions', Process: 'Process Emissions' };
    Object.keys(groups).forEach(function(grp) {
        var label = grpLabels[grp] || grp;
        if (scopeNo === 1) { opts += '<optgroup label="' + label + '">'; }
        groups[grp].forEach(function(it) {
            opts += '<option value="' + it.id + '">' + it.code + ' — ' + it.name + '</option>';
        });
        if (scopeNo === 1) { opts += '</optgroup>'; }
    });

    var tbody = document.querySelector('#efTable tbody');
    if (!tbody) { return; }

    /* นับลำดับที่ */
    var rowCount = tbody.querySelectorAll('tr').length + 1;

    var tr = document.createElement('tr');
    tr.dataset.efid     = efid;
    tr.dataset.scope    = scope;
    tr.dataset.scopeno  = scopeNo;
    tr.dataset.category = cat;
    tr.dataset.efname   = efnameLc;
    tr.innerHTML =
        '<td style="color:var(--cfp-text-muted);font-size:0.72rem;">' + rowCount + '</td>' +
        '<td><code style="font-size:0.75rem;">' + efcode + '</code></td>' +
        '<td><div style="font-weight:500;white-space:nowrap;">' + efname + '</div></td>' +
        '<td><span style="font-size:0.7rem;padding:2px 8px;border-radius:10px;color:#fff;font-weight:600;background:' + scopeColor + ';">' + scope + '</span></td>' +
        '<td style="font-size:0.66rem;color:var(--cfp-text-muted);white-space:nowrap;">' + (cat || '—') + '</td>' +
        '<td><span style="font-size:0.7rem;padding:1px 7px;border-radius:8px;color:#fff;font-weight:600;background:' + gasColor + ';">' + gasType + '</span></td>' +
        '<td style="font-family:monospace;font-size:0.82rem;color:var(--cfp-primary);font-weight:600;">' +
            efval.toFixed(6) +
            (unit ? '<div style="font-size:0.68rem;color:var(--cfp-text-muted);">' + unit + '</div>' : '') +
        '</td>' +
        '<td style="font-size:0.82rem;">' + year + '</td>' +
        '<td><select class="item-sel" id="sel_' + efid + '" data-efid="' + efid + '" onchange="onSelChange(this,' + efid + ')">' + opts + '</select></td>' +
        '<td id="status_' + efid + '" class="text-center"><span class="badge-unlink">ว่าง</span></td>';

    tbody.appendChild(tr);

    /* แสดง save bar และอัปเดต counter */
    document.getElementById('linkSaveBar').style.display = '';
}

function showToast(msg, type) {
    var t = document.createElement('div');
    t.className = 'position-fixed bottom-0 end-0 m-3 toast show align-items-center text-white border-0';
    t.style.cssText = 'background:' + (type==='success'?'#2E7D32':'#B71C1C') + ';z-index:9999;min-width:260px;';
    t.innerHTML = '<div class="d-flex"><div class="toast-body font-prompt">' + msg +
        '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest(\'.toast\').remove()"></button></div>';
    document.body.appendChild(t);
    setTimeout(function() { t.remove(); }, 4000);
}
</script>
</body>
</html>

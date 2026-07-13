<?php
/* ==============================================
   includes/topbar.php — Wave Mint-Blue Theme
   Topbar + Elevation Banner + Modal
   ต้องกำหนด $pageTitle และ $pageIcon ก่อน include
   ============================================== */

$isElevated   = !empty($_SESSION['elevated_role']);
$elevRole     = (int)($_SESSION['elevated_role'] ?? 0);
$elevStart    = $_SESSION['elevation_start'] ?? '';
$elevReason   = $_SESSION['elevation_reason'] ?? '';
$isAdmin      = (int)$_SESSION['role_id'] === 4;
$isAdminOrSustain = in_array((int)$_SESSION['role_id'], array(4, 5));

$isViewingAs  = !empty($_SESSION['view_as_user_id']);
$viewAsName   = $_SESSION['view_as_name'] ?? '';
$viewAsStart  = $_SESSION['view_as_start'] ?? '';
$viewAsReason = $_SESSION['view_as_reason'] ?? '';

/* ===== Notification Badge: รออนุมัติ =====
   Bug fix: CFP_ActivityData ไม่มีคอลัมน์ Status (Status อยู่ที่ CFP_MonthlyHeader เท่านั้น)
   query เดิม query ผิดตาราง (a.Status) ทำให้ error ทุกครั้งแบบเงียบๆ (@ กลืน error)
   กระดิ่งเลยไม่เคยแสดงอะไรเลยให้ใครทั้งนั้น — logic ย้ายไป includes/notif_status.php
   เพื่อใช้ร่วมกับ includes/notif_check.php (AJAX รีเฟรชกระดิ่งอัตโนมัติ) ===== */
require_once __DIR__ . '/notif_status.php';
$_notifRoleID = getEffectiveRole();
$_notifData   = cfpGetNotifications(getConnection(), $_notifRoleID, getEffectiveUserID());
$_notifCount  = $_notifData['count'];          /* รวมทุกประเภท ใช้กับ badge หลัก */
$_notifApprovalCount = $_notifData['approvalCount'];
$_notifItems  = $_notifData['items'];
$_notifAssetReqCount = $_notifData['assetRequestCount'];
$_notifAssetReqItems = $_notifData['assetRequestItems'];
$_notifFulfilledCount = $_notifData['fulfilledCount'];
$_notifFulfilledItems = $_notifData['fulfilledItems'];
$_notifScopeColor = array(1=>'#43A047', 2=>'#7C3AED', 3=>'#F59E0B');

$roleNames    = array(2 => 'Reviewer', 3 => 'Approver');
$roleIcons    = array(2 => 'bi-search', 3 => 'bi-check2-circle');
$elevRoleName = $roleNames[$elevRole] ?? '';
$elevIcon     = $roleIcons[$elevRole] ?? 'bi-person';

$thMonth = array(
    1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',
    5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',
    9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'
);

/* initials จาก FullName */
$fullname  = $_SESSION['fullname'] ?? '';
$initials  = '';
$parts     = explode(' ', trim($fullname));
if (count($parts) >= 2) {
    $initials = mb_substr($parts[0], 0, 1, 'UTF-8') . mb_substr($parts[1], 0, 1, 'UTF-8');
} else {
    $initials = mb_substr($fullname, 0, 2, 'UTF-8');
}

$rNames = array(
    1 => 'Data Entry', 2 => 'Reviewer', 3 => 'Approver',
    4 => 'Admin', 5 => 'Sustainability Admin', 6 => 'Viewer'
);
?>

<?php if ($isElevated) { ?>
<!-- ===== ELEVATION BANNER ===== -->
<div id="elevationBanner"
     style="background:#FFF8E8;border-bottom:1px solid #FFE082;
            padding:6px 1.5rem;display:flex;align-items:center;
            justify-content:space-between;font-family:'Prompt',sans-serif;
            position:sticky;top:0;z-index:901;">
    <div style="display:flex;align-items:center;gap:10px;">
        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;
                     background:var(--cfp-warning);
                     animation:cfpPulse 1.5s infinite;flex-shrink:0;"></span>
        <span style="font-size:0.8rem;font-weight:500;color:#92400E;">
            <i class="bi <?php echo $elevIcon; ?> me-1"></i>
            Admin กำลังทำแทน <strong><?php echo htmlspecialchars($elevRoleName); ?></strong>
        </span>
        <?php if ($elevStart !== '') { ?>
        <span style="font-size:0.75rem;color:#A07840;">
            | เริ่ม <?php echo htmlspecialchars($elevStart); ?> น.
        </span>
        <?php } ?>
        <?php if ($elevReason !== '') { ?>
        <span style="font-size:0.75rem;color:#A07840;
                     max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
              title="<?php echo htmlspecialchars($elevReason); ?>">
            — <?php echo htmlspecialchars($elevReason); ?>
        </span>
        <?php } ?>
    </div>
    <button onclick="endElevation()"
            style="font-family:'Prompt',sans-serif;font-size:0.78rem;
                   background:#FFECCC;color:#92400E;border:1px solid #FDD99A;
                   border-radius:7px;padding:4px 12px;cursor:pointer;
                   display:flex;align-items:center;gap:5px;white-space:nowrap;">
        <i class="bi bi-stop-circle"></i> สิ้นสุด Elevation
    </button>
</div>
<?php } ?>

<?php if ($isViewingAs) { ?>
<!-- ===== VIEW-AS BANNER (read-only) — โทนเดียวกับ Elevation แต่เข้ม/เด่นกว่า ===== -->
<div id="viewAsBanner"
     style="background:linear-gradient(90deg, #D97706, #F59E0B);border-bottom:1px solid #B45309;
            padding:6px 1.5rem;display:flex;align-items:center;
            justify-content:space-between;font-family:'Prompt',sans-serif;
            position:sticky;top:0;z-index:901;box-shadow:0 1px 6px rgba(180,83,9,0.3);">
    <div style="display:flex;align-items:center;gap:10px;">
        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;
                     background:#fff;
                     animation:cfpPulse 1.5s infinite;flex-shrink:0;"></span>
        <span style="font-size:0.8rem;font-weight:600;color:#fff;">
            <i class="bi bi-eye-fill me-1"></i>
            Admin กำลังดูแทน <strong><?php echo htmlspecialchars($viewAsName); ?></strong>
            <span style="background:rgba(255,255,255,0.9);color:#B45309;border-radius:6px;padding:1px 6px;font-size:0.68rem;font-weight:700;margin-left:4px;">Read-only</span>
        </span>
        <?php if ($viewAsStart !== '') { ?>
        <span style="font-size:0.75rem;color:rgba(255,255,255,0.85);">
            | เริ่ม <?php echo htmlspecialchars($viewAsStart); ?> น.
        </span>
        <?php } ?>
        <?php if ($viewAsReason !== '') { ?>
        <span style="font-size:0.75rem;color:rgba(255,255,255,0.85);
                     max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
              title="<?php echo htmlspecialchars($viewAsReason); ?>">
            — <?php echo htmlspecialchars($viewAsReason); ?>
        </span>
        <?php } ?>
    </div>
    <button onclick="endViewAs()"
            style="font-family:'Prompt',sans-serif;font-size:0.78rem;font-weight:600;
                   background:#fff;color:#B45309;border:1px solid rgba(255,255,255,0.6);
                   border-radius:7px;padding:4px 12px;cursor:pointer;
                   display:flex;align-items:center;gap:5px;white-space:nowrap;">
        <i class="bi bi-stop-circle"></i> สิ้นสุด View-as
    </button>
</div>
<?php } ?>


<style>
@keyframes cfpBellPulse {
    0%,100% { transform: rotate(0deg); }
    10%     { transform: rotate(12deg); }
    20%     { transform: rotate(-10deg); }
    30%     { transform: rotate(8deg); }
    40%     { transform: rotate(-6deg); }
    50%     { transform: rotate(0deg); }
}
.cfp-bell-animate { animation: cfpBellPulse 2.5s ease-in-out infinite; }
.cfp-notif-badge {
    position: absolute; top: -4px; right: -4px;
    min-width: 17px; height: 17px;
    background: #EF4444; color: #fff;
    font-size: 9px; font-weight: 700;
    border-radius: 9px; border: 2px solid #2AABB8;
    display: flex; align-items: center; justify-content: center;
    padding: 0 3px; line-height: 1;
    pointer-events: none;
}
.cfp-notif-dropdown {
    position: absolute; top: calc(100% + 10px); right: 0;
    width: 290px; background: #fff;
    border: 1px solid #D0E8EE; border-radius: 12px;
    box-shadow: 0 8px 24px rgba(26,58,72,0.12);
    z-index: 9999; display: none;
    font-family: 'Prompt', sans-serif;
}
.cfp-notif-dropdown.show { display: block; }
.cfp-notif-hd {
    padding: 10px 14px 8px;
    border-bottom: 1px solid #D0E8EE;
    display: flex; align-items: center; justify-content: space-between;
}
.cfp-notif-hd-title { font-size: 0.8rem; font-weight: 600; color: #1A3A44; }
.cfp-notif-hd-badge { background: #FEF3C7; color: #92400E; border: 1px solid #FCD34D; padding: 1px 7px; border-radius: 9px; font-size: 0.72rem; font-weight: 600; }
.cfp-notif-item {
    display: flex; gap: 8px; padding: 8px 14px;
    border-bottom: 1px solid #EEF6F8;
    text-decoration: none; color: inherit;
}
.cfp-notif-item:hover { background: #EEF6F8; }
.cfp-notif-item:last-of-type { border-bottom: none; }
.cfp-notif-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; margin-top: 4px; }
.cfp-notif-name { font-size: 0.8rem; font-weight: 500; color: #1A3A44; }
.cfp-notif-sub  { font-size: 0.7rem; color: #7AAAB8; }
.cfp-notif-footer { padding: 8px 14px; text-align: center; border-top: 1px solid #D0E8EE; }
.cfp-notif-footer a { font-size: 0.78rem; color: #2AABB8; text-decoration: none; font-weight: 500; }
.cfp-notif-footer a:hover { text-decoration: underline; }
.cfp-notif-empty { padding: 20px 14px; text-align: center; color: #7AAAB8; font-size: 0.8rem; }
</style>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- ===== TOPBAR ===== -->
<div class="cfp-topbar" style="<?php echo ($isElevated || $isViewingAs) ? 'top:37px;' : ''; ?>">

    <!-- Wave SVG V2 — ล่าง 3 ชั้น + บน 2 ชั้น เหมือน login -->
    <svg aria-hidden="true"
         style="position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:0;"
         viewBox="0 0 1200 58" preserveAspectRatio="none"
         xmlns="http://www.w3.org/2000/svg">
        <!-- wave ล่าง ชั้น 1 -->
        <path d="M0,32 C200,14 400,50 600,30 C800,10 1000,46 1200,26 L1200,58 L0,58 Z"
              fill="rgba(255,255,255,0.07)"/>
        <!-- wave ล่าง ชั้น 2 -->
        <path d="M0,42 C180,24 360,56 600,38 C840,20 1020,52 1200,36 L1200,58 L0,58 Z"
              fill="rgba(255,255,255,0.10)"/>
        <!-- wave ล่าง ชั้น 3 (เข้มสุด) -->
        <path d="M0,52 C200,36 420,60 660,46 C900,30 1060,56 1200,46 L1200,58 L0,58 Z"
              fill="rgba(255,255,255,0.14)"/>
        <!-- wave บน ชั้น 1 -->
        <path d="M0,0 C220,18 480,-4 740,14 C960,28 1100,6 1200,16 L1200,0 Z"
              fill="rgba(255,255,255,0.07)"/>
        <!-- wave บน ชั้น 2 -->
        <path d="M0,0 C160,10 360,0 600,8 C840,14 1060,2 1200,8 L1200,0 Z"
              fill="rgba(255,255,255,0.05)"/>
    </svg>

    <div class="d-flex align-items-center gap-2" style="position:relative;z-index:1;">
        <!-- Hamburger: mobile/tablet — ส่ง postMessage ไป parent (app.php) เพื่อเปิด sidebar -->
        <button class="btn p-1 d-lg-none border-0 bg-transparent"
                id="sidebarToggle" aria-label="เปิดเมนู"
                onclick="(function(){var s=document.getElementById('cfpSidebar'),o=document.getElementById('cfpOverlay');if(!s)return;s.classList.toggle('show');if(!o){o=document.createElement('div');o.id='cfpOverlay';o.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:1049;';o.onclick=function(){s.classList.remove('show');o.style.display='none';};document.body.appendChild(o);}o.style.display=s.classList.contains('show')?'block':'none';})();">
            <i class="bi bi-list" style="font-size:1.3rem;color:#1B4A52;"></i>
        </button>

        <!-- Page icon + title + subtitle -->
        <div style="display:flex;align-items:center;gap:10px;">
            <div class="topbar-page-icon">
                <i class="bi bi-<?php echo htmlspecialchars($pageIcon ?? 'grid-1x2'); ?>"></i>
            </div>
            <div>
                <div class="page-title">
                    <?php echo htmlspecialchars($pageTitle ?? 'TRUBB Greenhouse Gas Management System'); ?>
                </div>
                <div class="topbar-subtitle">
                    <?php
                    $subtitleYear = (int)date('Y') + 543;
                    echo 'ภาพรวม · ปี ' . $subtitleYear;
                    ?>
                </div>
            </div>
        </div>
    </div>

    <div class="topbar-right d-flex align-items-center gap-2" style="position:relative;z-index:1;">

        <?php if ($isAdmin && !$isElevated && !$isViewingAs) { ?>
        <!-- Elevation button — ซ่อนบน mobile -->
        <button onclick="openElevationModal()"
                title="ทำงานแทน Role อื่น"
                class="topbar-elev-btn topbar-hide-mobile">
            <i class="bi bi-shield-lock"></i>
            <span class="d-none d-lg-inline">Elevate Role</span>
        </button>
        <?php } ?>

        <?php if ($isAdmin && !$isElevated && !$isViewingAs) { ?>
        <!-- View-as button — ซ่อนบน mobile -->
        <button onclick="openViewAsModal()"
                title="ดูแทน User คนใดคนหนึ่ง (Read-only)"
                class="topbar-elev-btn topbar-hide-mobile">
            <i class="bi bi-eye"></i>
            <span class="d-none d-lg-inline">View as</span>
        </button>
        <?php } ?>


        <!-- Bell notification -->
        <div class="topbar-ic-round topbar-hide-mobile" style="position:relative;" id="cfpBellWrap">
            <i class="bi bi-bell<?php echo $_notifCount > 0 ? '-fill cfp-bell-animate' : ''; ?>" id="cfpBellIcon"></i>
            <span class="cfp-notif-badge" id="cfpNotifBadge" style="<?php echo $_notifCount > 0 ? '' : 'display:none;'; ?>">
                <?php echo $_notifCount > 99 ? '99+' : $_notifCount; ?>
            </span>

            <!-- Dropdown -->
            <div class="cfp-notif-dropdown" id="cfpNotifDropdown">
                <?php if ($_notifRoleID !== 1) { ?>
                <div class="cfp-notif-hd">
                    <span class="cfp-notif-hd-title"><i class="bi bi-bell-fill me-1" style="color:#F59E0B;"></i>รออนุมัติ</span>
                    <span class="cfp-notif-hd-badge" id="cfpNotifHdBadge" style="<?php echo $_notifApprovalCount > 0 ? '' : 'display:none;'; ?>">
                        <?php echo $_notifApprovalCount; ?> รายการ
                    </span>
                </div>
                <div id="cfpNotifBody">
                <?php if (empty($_notifItems)) { ?>
                <div class="cfp-notif-empty">
                    <i class="bi bi-inbox" style="font-size:1.4rem;display:block;margin-bottom:4px;"></i>
                    ไม่มีรายการรออนุมัติ
                </div>
                <?php } else { ?>
                <?php foreach ($_notifItems as $_ni) {
                    $_niDate  = $_ni['SubmittedDate'] instanceof DateTime ? $_ni['SubmittedDate']->format('d M Y') : '';
                    $_niColor = $_notifScopeColor[$_ni['ScopeNo']] ?? '#2AABB8';
                    $_niScope = 'Scope ' . (int)$_ni['ScopeNo'];
                ?>
                <a href="/carbonfootprint/workflow/review.php" class="cfp-notif-item">
                    <div class="cfp-notif-dot" style="background:<?php echo $_niColor; ?>;"></div>
                    <div>
                        <div class="cfp-notif-name"><?php echo htmlspecialchars($_ni['ItemName'] ?? 'ไม่มีรายการ'); ?></div>
                        <div class="cfp-notif-sub">
                            <?php echo $_niScope; ?>
                            <?php if (!empty($_ni['SiteName'])) echo ' · ' . htmlspecialchars($_ni['SiteName']); ?>
                            <?php if (!empty($_ni['SubmitterName'])) echo ' · ' . htmlspecialchars($_ni['SubmitterName']); ?>
                            <?php if ($_niDate) echo ' · ' . $_niDate; ?>
                        </div>
                    </div>
                </a>
                <?php } ?>
                <?php if ($_notifApprovalCount > 5) { ?>
                <div class="cfp-notif-footer">
                    <a href="/carbonfootprint/workflow/review.php">ดูทั้งหมด <?php echo $_notifApprovalCount; ?> รายการ →</a>
                </div>
                <?php } ?>
                <?php } ?>
                </div>
                <?php } ?>

                <?php if ($_notifRoleID === 1) { ?>
                <!-- ===== Data Entry: คำขอเพิ่มทรัพย์สินที่ Admin ทำเสร็จแล้ว (7 วันล่าสุด) ===== -->
                <div class="cfp-notif-hd">
                    <span class="cfp-notif-hd-title"><i class="bi bi-box-seam me-1" style="color:#2AABB8;"></i>ทรัพย์สินพร้อมใช้แล้ว</span>
                    <span class="cfp-notif-hd-badge" id="cfpFulfilledHdBadge" style="<?php echo $_notifFulfilledCount > 0 ? '' : 'display:none;'; ?>">
                        <?php echo $_notifFulfilledCount; ?> รายการ
                    </span>
                </div>
                <div id="cfpFulfilledBody">
                <?php if (empty($_notifFulfilledItems)) { ?>
                <div class="cfp-notif-empty">
                    <i class="bi bi-inbox" style="font-size:1.4rem;display:block;margin-bottom:4px;"></i>
                    ยังไม่มีคำขอที่ทำเสร็จ
                </div>
                <?php } else { ?>
                <?php foreach ($_notifFulfilledItems as $_fr) {
                    $_frDate = $_fr['ClosedDate'] instanceof DateTime ? $_fr['ClosedDate']->format('d M Y') : '';
                ?>
                <a href="/carbonfootprint/data_entry/my_asset_requests.php" class="cfp-notif-item">
                    <div class="cfp-notif-dot" style="background:#43A047;"></div>
                    <div>
                        <div class="cfp-notif-name"><?php echo htmlspecialchars($_fr['AssetType'] . ': ' . $_fr['RequestedName']); ?></div>
                        <div class="cfp-notif-sub">
                            Scope <?php echo (int)$_fr['ScopeNo']; ?>
                            <?php if (!empty($_fr['SiteName'])) echo ' · ' . htmlspecialchars($_fr['SiteName']); ?>
                            <?php if (!empty($_fr['ClosedByName'])) echo ' · โดย ' . htmlspecialchars($_fr['ClosedByName']); ?>
                            <?php if ($_frDate) echo ' · ' . $_frDate; ?>
                        </div>
                    </div>
                </a>
                <?php } ?>
                <?php if ($_notifFulfilledCount > 5) { ?>
                <div class="cfp-notif-footer">
                    <a href="/carbonfootprint/data_entry/my_asset_requests.php">ดูทั้งหมด <?php echo $_notifFulfilledCount; ?> รายการ →</a>
                </div>
                <?php } ?>
                <?php } ?>
                </div>
                <?php } ?>

                <?php if (in_array($_notifRoleID, array(4, 5))) { ?>
                <!-- ===== Section 2: คำขอเพิ่มทรัพย์สินใหม่ — Admin และ Sustainability Admin ===== -->
                <div class="cfp-notif-hd" style="border-top:1px solid #D0E8EE;">
                    <span class="cfp-notif-hd-title"><i class="bi bi-box-seam me-1" style="color:#2AABB8;"></i>คำขอเพิ่มทรัพย์สิน</span>
                    <span class="cfp-notif-hd-badge" id="cfpAssetReqHdBadge" style="<?php echo $_notifAssetReqCount > 0 ? '' : 'display:none;'; ?>">
                        <?php echo $_notifAssetReqCount; ?> รายการ
                    </span>
                </div>
                <div id="cfpAssetReqBody">
                <?php if (empty($_notifAssetReqItems)) { ?>
                <div class="cfp-notif-empty">
                    <i class="bi bi-inbox" style="font-size:1.4rem;display:block;margin-bottom:4px;"></i>
                    ไม่มีคำขอค้างอยู่
                </div>
                <?php } else { ?>
                <?php foreach ($_notifAssetReqItems as $_ar) {
                    $_arDate = $_ar['CreatedDate'] instanceof DateTime ? $_ar['CreatedDate']->format('d M Y') : '';
                ?>
                <a href="/carbonfootprint/master/asset_requests.php" class="cfp-notif-item">
                    <div class="cfp-notif-dot" style="background:#2AABB8;"></div>
                    <div>
                        <div class="cfp-notif-name"><?php echo htmlspecialchars($_ar['AssetType'] . ': ' . $_ar['RequestedName']); ?></div>
                        <div class="cfp-notif-sub">
                            Scope <?php echo (int)$_ar['ScopeNo']; ?>
                            <?php if (!empty($_ar['SiteName'])) echo ' · ' . htmlspecialchars($_ar['SiteName']); ?>
                            <?php if (!empty($_ar['RequesterName'])) echo ' · ' . htmlspecialchars($_ar['RequesterName']); ?>
                            <?php if ($_arDate) echo ' · ' . $_arDate; ?>
                        </div>
                    </div>
                </a>
                <?php } ?>
                <?php if ($_notifAssetReqCount > 5) { ?>
                <div class="cfp-notif-footer">
                    <a href="/carbonfootprint/master/asset_requests.php">ดูทั้งหมด <?php echo $_notifAssetReqCount; ?> รายการ →</a>
                </div>
                <?php } ?>
                <?php } ?>
                </div>
                <?php } ?>
            </div>
        </div>


        <?php if ($isAdminOrSustain) { ?>
        <!-- Settings — เฉพาะ Admin/Sustainability Admin — ซ่อนบน mobile -->
        <div class="topbar-ic-round topbar-hide-mobile" title="ตั้งค่า" onclick="openSettingsModal()" style="cursor:pointer;">
            <i class="bi bi-sliders"></i>
        </div>
        <?php } ?>

        <!-- User avatar — แสดงทุก breakpoint -->
        <div class="dropdown">
            <button class="btn p-0 border-0 bg-transparent"
                    type="button" data-bs-toggle="dropdown" aria-expanded="false"
                    style="cursor:pointer;">
                <div class="topbar-avatar" title="<?php echo htmlspecialchars($fullname); ?>">
                    <?php echo htmlspecialchars($initials); ?>
                    <?php if ($isElevated) { ?>
                    <span class="topbar-elev-dot"></span>
                    <?php } ?>
                </div>
            </button>

            <ul class="dropdown-menu dropdown-menu-end"
                style="min-width:220px;">
                <li>
                    <div style="padding:8px 14px 6px;">
                        <div style="font-size:0.82rem;font-weight:600;color:var(--cfp-text);">
                            <?php echo htmlspecialchars($fullname); ?>
                        </div>
                        <div style="font-size:0.72rem;color:var(--cfp-text-muted);">
                            <?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>
                        </div>
                    </div>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 py-2"
                       href="#" onclick="openModalChangePass(); return false;">
                        <i class="bi bi-key" style="width:18px;color:var(--cfp-primary);"></i>
                        เปลี่ยนรหัสผ่าน
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 py-2"
                       href="#" onclick="confirmLogout(); return false;"
                       style="color:var(--cfp-danger);">
                        <i class="bi bi-box-arrow-right" style="width:18px;"></i>
                        ออกจากระบบ
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<!-- ===== MODAL: เปลี่ยนรหัสผ่าน ===== -->
<div class="modal fade" id="modalChangePass" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">
                    <i class="bi bi-key me-2" style="color:rgba(255,255,255,0.7);"></i>เปลี่ยนรหัสผ่าน
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formChangePass" onsubmit="submitChangePass(event)">
                <input type="hidden" name="csrf_token"
                       value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">รหัสผ่านปัจจุบัน <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="cpOldPass"
                                   placeholder="รหัสผ่านเดิม" required
                                   style="font-family:'Prompt',sans-serif;">
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                    onclick="togglePassVis('cpOldPass','eyeOld')">
                                <i class="bi bi-eye" id="eyeOld"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รหัสผ่านใหม่ <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="cpNewPass"
                                   placeholder="อย่างน้อย 8 ตัวอักษร" required minlength="8"
                                   oninput="checkPassStrength(this.value)"
                                   style="font-family:'Prompt',sans-serif;">
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                    onclick="togglePassVis('cpNewPass','eyeNew')">
                                <i class="bi bi-eye" id="eyeNew"></i>
                            </button>
                        </div>
                        <div style="margin-top:6px;">
                            <div style="height:4px;background:#E4F4F8;border-radius:4px;overflow:hidden;">
                                <div id="passStrengthBar"
                                     style="height:100%;width:0%;border-radius:4px;transition:all 0.3s;"></div>
                            </div>
                            <div id="passStrengthText"
                                 style="font-size:0.72rem;color:var(--cfp-text-muted);margin-top:3px;"></div>
                        </div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">ยืนยันรหัสผ่านใหม่ <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="cpConfirmPass"
                                   placeholder="พิมพ์รหัสผ่านใหม่อีกครั้ง" required
                                   style="font-family:'Prompt',sans-serif;">
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                    onclick="togglePassVis('cpConfirmPass','eyeConfirm')">
                                <i class="bi bi-eye" id="eyeConfirm"></i>
                            </button>
                        </div>
                        <div id="cpMatchMsg" style="font-size:0.75rem;margin-top:4px;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            data-bs-dismiss="modal"
                            style="font-family:'Prompt',sans-serif;">ยกเลิก</button>
                    <button type="submit" class="btn btn-sm btn-cfp-add" id="btnSavePass">
                        <i class="bi bi-check-circle me-1"></i>บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($isAdminOrSustain) { ?>
<!-- ===== MODAL: ตั้งค่า — เฉพาะ Admin/Sustainability Admin ===== -->
<div class="modal fade" id="modalSettings" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">
                    <i class="bi bi-sliders me-2" style="color:rgba(255,255,255,0.7);"></i>ตั้งค่า
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div>
                        <div style="font-size:0.85rem;font-weight:600;color:var(--cfp-text);">การแจ้งเตือน (in-app)</div>
                        <div style="font-size:0.72rem;color:var(--cfp-text-muted);">เปิด/ปิดกระดิ่งแจ้งเตือนรออนุมัติ</div>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="settingNotifEnabled"
                               style="width:2.4em;height:1.3em;cursor:pointer;" onchange="onSettingsNotifToggle()">
                    </div>
                </div>
                <div class="mb-1">
                    <label class="form-label" style="font-size:0.8rem;">ความถี่รีเฟรชกระดิ่งอัตโนมัติ</label>
                    <select id="settingNotifRefresh" class="form-select form-select-sm"
                            style="font-family:'Prompt',sans-serif;font-size:0.82rem;" onchange="onSettingsRefreshChange()">
                        <option value="0">ปิด (รีเฟรชเมื่อโหลดหน้าใหม่เท่านั้น)</option>
                        <option value="1">ทุก 1 นาที</option>
                        <option value="5">ทุก 5 นาที</option>
                        <option value="10">ทุก 10 นาที</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-cfp-add" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle me-1"></i>ปิดหน้าต่าง
                </button>
            </div>
        </div>
    </div>
</div>
<?php } ?>

<?php if ($isAdmin) { ?>
<!-- ===== MODAL: Elevation ===== -->
<div class="modal fade" id="modalElevation" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px;">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">
                    <i class="bi bi-shield-lock me-2" style="color:rgba(255,255,255,0.7);"></i>Role Elevation
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p style="font-size:0.82rem;color:var(--cfp-text-muted);margin-bottom:14px;">
                    เลือก Role ที่ต้องการทำงานแทนชั่วคราว — ทุกการกระทำจะถูกบันทึก Audit Log
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
                    <div id="roleCard2" onclick="selectElevRole(2)"
                         style="border:1.5px solid var(--cfp-border);border-radius:10px;
                                padding:14px 12px;cursor:pointer;text-align:center;
                                transition:all .18s;background:#fff;">
                        <i id="icon2" class="bi bi-circle"
                           style="color:var(--cfp-border);font-size:1.1rem;margin-bottom:6px;display:block;"></i>
                        <div style="font-size:0.82rem;font-weight:600;color:var(--cfp-text);">Reviewer</div>
                        <div style="font-size:0.7rem;color:var(--cfp-text-muted);margin-top:2px;">ตรวจสอบข้อมูล</div>
                    </div>
                    <div id="roleCard3" onclick="selectElevRole(3)"
                         style="border:1.5px solid var(--cfp-border);border-radius:10px;
                                padding:14px 12px;cursor:pointer;text-align:center;
                                transition:all .18s;background:#fff;">
                        <i id="icon3" class="bi bi-circle"
                           style="color:var(--cfp-border);font-size:1.1rem;margin-bottom:6px;display:block;"></i>
                        <div style="font-size:0.82rem;font-weight:600;color:var(--cfp-text);">Approver</div>
                        <div style="font-size:0.7rem;color:var(--cfp-text-muted);margin-top:2px;">อนุมัติรายเดือน</div>
                    </div>
                </div>
                <div style="background:var(--cfp-bg);border-radius:8px;padding:8px 12px;
                            font-size:0.76rem;color:var(--cfp-text-muted);margin-bottom:12px;
                            display:flex;align-items:center;gap:6px;border:1px solid var(--cfp-border);">
                    <i class="bi bi-person-badge" style="color:var(--cfp-primary);"></i>
                    <span id="actorPreview">Admin acting as ?</span>
                </div>
                <input type="hidden" id="selectedElevRole" value="0">
                <div class="mb-1">
                    <label class="form-label" style="font-size:0.8rem;">
                        เหตุผลการ Elevate <span class="text-danger">*</span>
                        <span style="color:var(--cfp-text-muted);font-weight:400;">(อย่างน้อย 3 ตัวอักษร)</span>
                    </label>
                    <textarea id="elevReason" class="form-control" rows="2" maxlength="200"
                              placeholder="ระบุเหตุผล เช่น ตรวจสอบข้อมูล Scope 1 เดือน มิ.ย."
                              style="resize:none;font-size:0.84rem;font-family:'Prompt',sans-serif;"></textarea>
                    <div style="text-align:right;font-size:0.7rem;color:var(--cfp-text-muted);margin-top:3px;">
                        <span id="reasonCount">0</span>/200
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        data-bs-dismiss="modal"
                        style="font-family:'Prompt',sans-serif;">ยกเลิก</button>
                <button id="btnStartElev" onclick="startElevation()" disabled
                        class="btn btn-sm btn-cfp-primary"
                        style="font-family:'Prompt',sans-serif;opacity:0.5;">
                    <i class="bi bi-shield-lock"></i> เริ่ม Elevation
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL: View-as ===== -->
<div class="modal fade" id="modalViewAs" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px;">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">
                    <i class="bi bi-eye me-2" style="color:rgba(255,255,255,0.7);"></i>View as — ดูแทน User
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p style="font-size:0.82rem;color:var(--cfp-text-muted);margin-bottom:14px;">
                    เลือก user ที่ต้องการดูแทน — โหมดนี้เป็น <strong>Read-only เท่านั้น</strong> ไม่สามารถบันทึก/แก้ไขข้อมูลในนาม user นั้นได้ ทุกการเข้า-ออกจะถูกบันทึก Audit Log
                </p>
                <div class="mb-3">
                    <label class="form-label" style="font-size:0.8rem;">เลือก User <span class="text-danger">*</span></label>
                    <input type="hidden" id="viewAsSelectedUserId" value="">
                    <div class="cfp-viewas-search">
                        <i class="bi bi-search"></i>
                        <input type="text" id="viewAsUserSearch" placeholder="พิมพ์ชื่อหรือ username เพื่อค้นหา..."
                               oninput="filterViewAsUsers(this.value)"
                               style="font-family:'Prompt',sans-serif;">
                    </div>
                    <div class="cfp-viewas-list" id="viewAsUserList">
                        <div class="cfp-viewas-empty">กำลังโหลดรายชื่อ...</div>
                    </div>
                </div>
                <div style="background:var(--cfp-bg);border-radius:8px;padding:8px 12px;
                            font-size:0.76rem;color:var(--cfp-text-muted);margin-bottom:12px;
                            display:flex;align-items:center;gap:6px;border:1px solid var(--cfp-border);">
                    <i class="bi bi-person-badge" style="color:var(--cfp-primary);"></i>
                    <span id="viewAsPreview">Admin viewing as ?</span>
                </div>
                <div class="mb-1">
                    <label class="form-label" style="font-size:0.8rem;">
                        เหตุผลการ View-as <span class="text-danger">*</span>
                        <span style="color:var(--cfp-text-muted);font-weight:400;">(อย่างน้อย 3 ตัวอักษร)</span>
                    </label>
                    <textarea id="viewAsReasonInp" class="form-control" rows="2" maxlength="200"
                              placeholder="ระบุเหตุผล เช่น ตรวจสอบว่าทำไม user นี้เห็นเมนูไม่ครบ"
                              style="resize:none;font-size:0.84rem;font-family:'Prompt',sans-serif;"></textarea>
                    <div style="text-align:right;font-size:0.7rem;color:var(--cfp-text-muted);margin-top:3px;">
                        <span id="viewAsReasonCount">0</span>/200
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        data-bs-dismiss="modal"
                        style="font-family:'Prompt',sans-serif;">ยกเลิก</button>
                <button id="btnStartViewAs" onclick="startViewAs()" disabled
                        class="btn btn-sm btn-cfp-primary"
                        style="font-family:'Prompt',sans-serif;opacity:0.5;">
                    <i class="bi bi-eye"></i> เริ่ม View-as
                </button>
            </div>
        </div>
    </div>
</div>
<?php } ?>

<!-- Toast -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:9999;">
    <div id="toastElev" class="toast align-items-center text-white border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2">
                <i class="bi bi-shield-check-fill" id="toastElevIcon"></i>
                <span id="toastElevMsg">...</span>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto"
                    data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<style>
@keyframes cfpPulse {
    0%,100% { opacity:1; transform:scale(1); }
    50%     { opacity:0.3; transform:scale(0.8); }
}
.role-card-selected {
    border-color: var(--cfp-primary) !important;
    background: var(--cfp-hover) !important;
}
#elevReason:focus {
    border-color: var(--cfp-primary);
    box-shadow: 0 0 0 3px rgba(42,171,184,0.12);
}

/* ===== View-as: searchable grouped user list ===== */
.cfp-viewas-search { position: relative; margin-bottom: 8px; }
.cfp-viewas-search i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--cfp-text-muted); font-size: 0.82rem; }
.cfp-viewas-search input { width: 100%; font-size: 0.82rem; border: 1px solid var(--cfp-border); border-radius: 8px; padding: 7px 10px 7px 30px; background: #fff; color: var(--cfp-text); }
.cfp-viewas-search input:focus { outline: none; border-color: var(--cfp-primary); box-shadow: 0 0 0 3px rgba(42,171,184,0.12); }
.cfp-viewas-list { max-height: 230px; overflow-y: auto; border: 1px solid var(--cfp-border); border-radius: 10px; background: var(--cfp-bg); }
.cfp-viewas-group { position: sticky; top: 0; background: var(--cfp-hover); color: var(--cfp-text-muted); font-size: 0.66rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; padding: 5px 12px; border-bottom: 1px solid var(--cfp-border); }
.cfp-viewas-row { display: flex; align-items: center; gap: 10px; padding: 7px 12px; cursor: pointer; border-bottom: 1px solid var(--cfp-border); }
.cfp-viewas-row:last-child { border-bottom: none; }
.cfp-viewas-row:hover { background: var(--cfp-hover); }
.cfp-viewas-row.selected { background: rgba(42,171,184,0.14); }
.cfp-viewas-avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--cfp-primary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.62rem; font-weight: 700; flex-shrink: 0; }
.cfp-viewas-meta { min-width: 0; }
.cfp-viewas-meta .u-name { font-weight: 600; font-size: 0.78rem; color: var(--cfp-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cfp-viewas-meta .u-sub { font-size: 0.66rem; color: var(--cfp-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cfp-viewas-check { margin-left: auto; width: 16px; height: 16px; border-radius: 50%; border: 1.5px solid var(--cfp-border); background: #fff; flex-shrink: 0; }
.cfp-viewas-row.selected .cfp-viewas-check { background: var(--cfp-primary); border-color: var(--cfp-primary); }
.cfp-viewas-empty { padding: 16px; text-align: center; font-size: 0.78rem; color: var(--cfp-text-muted); }
</style>

<script>
/* ===== Logout ===== */
function confirmLogout() {
    Swal.fire({
        title: 'ออกจากระบบ?',
        text: 'คุณต้องการออกจากระบบใช่หรือไม่',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#E05050',
        cancelButtonColor: '#7AAAB8',
        confirmButtonText: 'ออกจากระบบ',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true,
        customClass: { popup: 'font-prompt' }
    }).then(function(result) {
        if (result.isConfirmed) {
            window.location.href = '/carbonfootprint/logout.php';
        }
    });
}

/* ===== Change Password ===== */
function openModalChangePass() {
    document.getElementById('formChangePass').reset();
    document.getElementById('passStrengthBar').style.width = '0%';
    document.getElementById('passStrengthText').textContent = '';
    document.getElementById('cpMatchMsg').textContent = '';
    new bootstrap.Modal(document.getElementById('modalChangePass')).show();
}

function togglePassVis(inputID, iconID) {
    var inp  = document.getElementById(inputID);
    var icon = document.getElementById(iconID);
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

function checkPassStrength(val) {
    var bar   = document.getElementById('passStrengthBar');
    var txt   = document.getElementById('passStrengthText');
    var score = 0;
    if (val.length >= 8)              { score++; }
    if (val.length >= 12)             { score++; }
    if (/[A-Z]/.test(val))           { score++; }
    if (/[0-9]/.test(val))           { score++; }
    if (/[^A-Za-z0-9]/.test(val))   { score++; }
    var levels = [
        { pct:'20%', color:'#E05050', label:'อ่อนมาก' },
        { pct:'40%', color:'#F2A541', label:'อ่อน' },
        { pct:'60%', color:'#FFD54F', label:'ปานกลาง' },
        { pct:'80%', color:'#5CC8D8', label:'แข็งแกร่ง' },
        { pct:'100%',color:'#2AABB8', label:'แข็งแกร่งมาก' },
    ];
    var lv = levels[Math.min(score, 4)];
    bar.style.width      = val.length > 0 ? lv.pct : '0%';
    bar.style.background = lv.color;
    txt.textContent      = val.length > 0 ? 'ความแข็งแกร่ง: ' + lv.label : '';
    txt.style.color      = lv.color;
    checkPassMatch();
}

function checkPassMatch() {
    var np  = document.getElementById('cpNewPass').value;
    var cp  = document.getElementById('cpConfirmPass').value;
    var msg = document.getElementById('cpMatchMsg');
    if (cp === '') { msg.textContent = ''; return; }
    if (np === cp) {
        msg.textContent = '✓ รหัสผ่านตรงกัน';
        msg.style.color = '#2AABB8';
    } else {
        msg.textContent = '✗ รหัสผ่านไม่ตรงกัน';
        msg.style.color = '#E05050';
    }
}

document.getElementById('cpConfirmPass') &&
    document.getElementById('cpConfirmPass').addEventListener('input', checkPassMatch);

function submitChangePass(e) {
    e.preventDefault();
    var oldPass  = document.getElementById('cpOldPass').value;
    var newPass  = document.getElementById('cpNewPass').value;
    var confPass = document.getElementById('cpConfirmPass').value;
    if (newPass !== confPass) {
        Swal.fire({ icon:'error', title:'รหัสผ่านไม่ตรงกัน', confirmButtonColor:'#2AABB8', customClass:{popup:'font-prompt'} });
        return;
    }
    if (newPass.length < 8) {
        Swal.fire({ icon:'warning', title:'รหัสผ่านสั้นเกินไป', text:'ต้องมีอย่างน้อย 8 ตัวอักษร', confirmButtonColor:'#2AABB8', customClass:{popup:'font-prompt'} });
        return;
    }
    var btn = document.getElementById('btnSavePass');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>กำลังบันทึก...';
    var csrf = document.querySelector('#formChangePass [name="csrf_token"]').value;
    fetch('/carbonfootprint/profile/change_password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: csrf, old_password: oldPass, new_password: newPass })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        bootstrap.Modal.getInstance(document.getElementById('modalChangePass')).hide();
        if (data.success) {
            Swal.fire({ icon:'success', title:'เปลี่ยนรหัสผ่านสำเร็จ', confirmButtonColor:'#2AABB8', customClass:{popup:'font-prompt'} });
        } else {
            Swal.fire({ icon:'error', title:'ไม่สำเร็จ', text: data.msg || 'เกิดข้อผิดพลาด', confirmButtonColor:'#2AABB8', customClass:{popup:'font-prompt'} });
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>บันทึก';
    })
    .catch(function() {
        Swal.fire({ icon:'error', title:'เชื่อมต่อไม่ได้', confirmButtonColor:'#2AABB8', customClass:{popup:'font-prompt'} });
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>บันทึก';
    });
}

/* ===== Elevation ===== */
var selectedElevRole = 0;

function openElevationModal() {
    selectedElevRole = 0;
    document.getElementById('selectedElevRole').value = '0';
    document.getElementById('elevReason').value = '';
    document.getElementById('reasonCount').textContent = '0';
    document.getElementById('btnStartElev').disabled = true;
    document.getElementById('btnStartElev').style.opacity = '0.5';
    document.getElementById('actorPreview').textContent = 'Admin acting as ?';
    resetRoleCards();
    new bootstrap.Modal(document.getElementById('modalElevation')).show();
}

function resetRoleCards() {
    [2, 3].forEach(function(r) {
        var card = document.getElementById('roleCard' + r);
        var icon = document.getElementById('icon' + r);
        if (card) { card.style.borderColor = 'var(--cfp-border)'; card.style.background = '#fff'; }
        if (icon) { icon.className = 'bi bi-circle'; icon.style.color = 'var(--cfp-border)'; }
    });
}

function selectElevRole(role) {
    selectedElevRole = role;
    document.getElementById('selectedElevRole').value = role;
    resetRoleCards();
    var card = document.getElementById('roleCard' + role);
    var icon = document.getElementById('icon' + role);
    if (card) { card.style.borderColor = 'var(--cfp-primary)'; card.style.background = 'var(--cfp-hover)'; }
    if (icon) { icon.className = 'bi bi-check-circle-fill'; icon.style.color = 'var(--cfp-primary)'; }
    var rn = {2:'Reviewer',3:'Approver'};
    document.getElementById('actorPreview').textContent = 'Admin acting as ' + (rn[role] || '?');
    checkElevReady();
}

function checkElevReady() {
    var role   = selectedElevRole;
    var reason = (document.getElementById('elevReason') || {}).value || '';
    var btn    = document.getElementById('btnStartElev');
    var ready  = (role === 2 || role === 3) && reason.trim().length >= 3;
    btn.disabled      = !ready;
    btn.style.opacity = ready ? '1' : '0.5';
}

var elevReasonInp = document.getElementById('elevReason');
if (elevReasonInp) {
    elevReasonInp.addEventListener('input', function() {
        document.getElementById('reasonCount').textContent = this.value.length;
        checkElevReady();
    });
}

function startElevation() {
    var reason = document.getElementById('elevReason').value.trim();
    if (selectedElevRole === 0 || reason === '') { return; }
    var btn = document.getElementById('btnStartElev');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>กำลังเริ่ม...';
    fetch('/carbonfootprint/admin/elevation_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action:'start', elevated_role: selectedElevRole, reason: reason })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalElevation')).hide();
            showElevToast('เริ่ม Elevation เป็น ' + data.role_name + ' แล้ว', '#F2A541', true);
            setTimeout(function() { window.location.reload(); }, 1000);
        } else {
            showElevToast(data.msg || 'เกิดข้อผิดพลาด', '#E05050', false);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-shield-lock"></i> เริ่ม Elevation';
        }
    })
    .catch(function() {
        showElevToast('เชื่อมต่อ server ไม่ได้', '#E05050', false);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-shield-lock"></i> เริ่ม Elevation';
    });
}

function endElevation() {
    if (!confirm('ยืนยันการสิ้นสุด Role Elevation?')) { return; }
    fetch('/carbonfootprint/admin/elevation_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'end' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showElevToast('สิ้นสุด Elevation แล้ว กลับสู่ Admin Mode', '#2AABB8', true);
            setTimeout(function() { window.location.reload(); }, 1000);
        } else {
            showElevToast(data.msg || 'เกิดข้อผิดพลาด', '#E05050', false);
        }
    })
    .catch(function() { showElevToast('เชื่อมต่อ server ไม่ได้', '#E05050', false); });
}

function showElevToast(msg, bg, isSuccess) {
    var toast = document.getElementById('toastElev');
    var icon  = document.getElementById('toastElevIcon');
    var msgEl = document.getElementById('toastElevMsg');
    if (!toast) { return; }
    toast.style.background = bg || '#2AABB8';
    if (icon) { icon.className = isSuccess ? 'bi bi-shield-check-fill' : 'bi bi-exclamation-circle-fill'; }
    if (msgEl) { msgEl.textContent = msg; }
    new bootstrap.Toast(toast, { delay: 3000 }).show();
}

/* ===== View-as ===== */
var viewAsUsersLoaded = false;

function openViewAsModal() {
    document.getElementById('viewAsReasonInp').value = '';
    document.getElementById('viewAsReasonCount').textContent = '0';
    document.getElementById('viewAsPreview').textContent = 'Admin viewing as ?';
    document.getElementById('viewAsSelectedUserId').value = '';
    document.getElementById('viewAsUserSearch').value = '';
    document.getElementById('btnStartViewAs').disabled = true;
    document.getElementById('btnStartViewAs').style.opacity = '0.5';
    new bootstrap.Modal(document.getElementById('modalViewAs')).show();
    if (!viewAsUsersLoaded) { loadViewAsUsers(); }
}

var viewAsUsersData = [];

function loadViewAsUsers() {
    var list = document.getElementById('viewAsUserList');
    fetch('/carbonfootprint/admin/view_as_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'list_users' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success || !data.users.length) {
            list.innerHTML = '<div class="cfp-viewas-empty">ไม่พบรายชื่อ user</div>';
            return;
        }
        viewAsUsersLoaded = true;
        viewAsUsersData   = data.users;
        renderViewAsUserList(data.users);
    })
    .catch(function() { list.innerHTML = '<div class="cfp-viewas-empty">เชื่อมต่อ server ไม่ได้</div>'; });
}

function viewAsInitials(name) {
    var parts = (name || '').trim().split(' ');
    if (parts.length >= 2) { return parts[0].charAt(0) + parts[1].charAt(0); }
    return (name || '').substr(0, 2);
}

function renderViewAsUserList(users) {
    var list = document.getElementById('viewAsUserList');
    if (!users.length) { list.innerHTML = '<div class="cfp-viewas-empty">ไม่พบ user ที่ตรงกับคำค้นหา</div>'; return; }
    var html = '';
    var lastRole = null;
    users.forEach(function(u) {
        if (u.roleName !== lastRole) {
            html += '<div class="cfp-viewas-group">' + u.roleName + '</div>';
            lastRole = u.roleName;
        }
        html += '<div class="cfp-viewas-row" data-userid="' + u.userID + '" data-name="' + u.fullName + '" onclick="selectViewAsUser(' + u.userID + ')">'
              +   '<div class="cfp-viewas-avatar">' + viewAsInitials(u.fullName) + '</div>'
              +   '<div class="cfp-viewas-meta"><div class="u-name">' + u.fullName + '</div><div class="u-sub">' + u.username + '</div></div>'
              +   '<span class="cfp-viewas-check"></span>'
              + '</div>';
    });
    list.innerHTML = html;
    /* คงสถานะ selected ไว้ถ้ามีการเลือกอยู่แล้วก่อนกรอง */
    var selID = document.getElementById('viewAsSelectedUserId').value;
    if (selID) {
        var row = list.querySelector('.cfp-viewas-row[data-userid="' + selID + '"]');
        if (row) { row.classList.add('selected'); }
    }
}

function filterViewAsUsers(query) {
    query = (query || '').trim().toLowerCase();
    if (!query) { renderViewAsUserList(viewAsUsersData); return; }
    var filtered = viewAsUsersData.filter(function(u) {
        return u.fullName.toLowerCase().indexOf(query) !== -1
            || u.username.toLowerCase().indexOf(query) !== -1;
    });
    renderViewAsUserList(filtered);
}

function selectViewAsUser(userID) {
    document.getElementById('viewAsSelectedUserId').value = userID;
    document.querySelectorAll('.cfp-viewas-row').forEach(function(row) {
        row.classList.toggle('selected', parseInt(row.dataset.userid) === userID);
    });
    var u = viewAsUsersData.find(function(x) { return x.userID === userID; });
    document.getElementById('viewAsPreview').textContent = u ? ('Admin viewing as ' + u.fullName + ' — ' + u.roleName) : 'Admin viewing as ?';
    checkViewAsReady();
}

function checkViewAsReady() {
    var userID = document.getElementById('viewAsSelectedUserId').value;
    var reason = (document.getElementById('viewAsReasonInp') || {}).value || '';
    var btn    = document.getElementById('btnStartViewAs');
    var ready  = !!userID && reason.trim().length >= 3;
    btn.disabled      = !ready;
    btn.style.opacity = ready ? '1' : '0.5';
}

var viewAsReasonInp = document.getElementById('viewAsReasonInp');
if (viewAsReasonInp) {
    viewAsReasonInp.addEventListener('input', function() {
        document.getElementById('viewAsReasonCount').textContent = this.value.length;
        checkViewAsReady();
    });
}

function startViewAs() {
    var userID = document.getElementById('viewAsSelectedUserId').value;
    var reason = document.getElementById('viewAsReasonInp').value.trim();
    if (!userID || reason === '') { return; }
    var btn = document.getElementById('btnStartViewAs');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>กำลังเริ่ม...';
    fetch('/carbonfootprint/admin/view_as_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action:'start', target_user_id: parseInt(userID), reason: reason })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalViewAs')).hide();
            showElevToast('เริ่ม View-as ' + data.user_name + ' แล้ว', '#D97706', true);
            setTimeout(function() { window.location.reload(); }, 1000);
        } else {
            showElevToast(data.msg || 'เกิดข้อผิดพลาด', '#E05050', false);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-eye"></i> เริ่ม View-as';
        }
    })
    .catch(function() {
        showElevToast('เชื่อมต่อ server ไม่ได้', '#E05050', false);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-eye"></i> เริ่ม View-as';
    });
}

function endViewAs() {
    if (!confirm('ยืนยันการสิ้นสุด View-as?')) { return; }
    fetch('/carbonfootprint/admin/view_as_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'end' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showElevToast('สิ้นสุด View-as แล้ว กลับสู่ Admin Mode', '#2AABB8', true);
            setTimeout(function() { window.location.reload(); }, 1000);
        } else {
            showElevToast(data.msg || 'เกิดข้อผิดพลาด', '#E05050', false);
        }
    })
    .catch(function() { showElevToast('เชื่อมต่อ server ไม่ได้', '#E05050', false); });
}


/* ===== Bell Notification Toggle ===== */
(function() {
    var wrap = document.getElementById('cfpBellWrap');
    var drop = document.getElementById('cfpNotifDropdown');
    if (!wrap || !drop) { return; }
    wrap.style.cursor = 'pointer';
    wrap.addEventListener('click', function(e) {
        e.stopPropagation();
        drop.classList.toggle('show');
    });
    document.addEventListener('click', function(e) {
        if (!wrap.contains(e.target)) {
            drop.classList.remove('show');
        }
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { drop.classList.remove('show'); }
    });
})();

/* ===== ตั้งค่า: แจ้งเตือน (เปิด/ปิด + ความถี่รีเฟรช) — เก็บเป็น localStorage ต่อเบราว์เซอร์ ===== */
var CFP_NOTIF_ENABLED_KEY = 'cfp_notif_enabled';
var CFP_NOTIF_REFRESH_KEY = 'cfp_notif_refresh_min';
var cfpNotifPollTimer = null;

function cfpNotifEnabled() {
    var v = localStorage.getItem(CFP_NOTIF_ENABLED_KEY);
    return v === null ? true : v === '1';
}
function cfpNotifRefreshMin() {
    var v = parseInt(localStorage.getItem(CFP_NOTIF_REFRESH_KEY) || '0');
    return isNaN(v) ? 0 : v;
}

function openSettingsModal() {
    document.getElementById('settingNotifEnabled').checked = cfpNotifEnabled();
    document.getElementById('settingNotifRefresh').value = String(cfpNotifRefreshMin());
    new bootstrap.Modal(document.getElementById('modalSettings')).show();
}

function onSettingsNotifToggle() {
    var on = document.getElementById('settingNotifEnabled').checked;
    localStorage.setItem(CFP_NOTIF_ENABLED_KEY, on ? '1' : '0');
    applyNotifSettings();
}

function onSettingsRefreshChange() {
    var min = document.getElementById('settingNotifRefresh').value;
    localStorage.setItem(CFP_NOTIF_REFRESH_KEY, min);
    applyNotifSettings();
}

function applyNotifSettings() {
    var wrap = document.getElementById('cfpBellWrap');
    if (!wrap) { return; }
    var enabled = cfpNotifEnabled();
    wrap.style.display = enabled ? '' : 'none';

    if (cfpNotifPollTimer) { clearInterval(cfpNotifPollTimer); cfpNotifPollTimer = null; }
    var min = cfpNotifRefreshMin();
    if (enabled && min > 0) {
        cfpNotifPollTimer = setInterval(cfpPollNotifications, min * 60 * 1000);
    }
}

function cfpPollNotifications() {
    fetch('/carbonfootprint/includes/notif_check.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) { return; }
            var badge   = document.getElementById('cfpNotifBadge');
            var hdBadge = document.getElementById('cfpNotifHdBadge');
            var icon    = document.getElementById('cfpBellIcon');
            var body    = document.getElementById('cfpNotifBody');
            var count   = data.count;              /* รวมทุกประเภท ใช้กับ badge หลัก */
            var approvalCount = data.approvalCount; /* รออนุมัติล้วนๆ ใช้กับ section แรก */

            if (count > 0) {
                badge.style.display = ''; badge.textContent = count > 99 ? '99+' : count;
                icon.className = 'bi bi-bell-fill cfp-bell-animate';
            } else {
                badge.style.display = 'none';
                icon.className = 'bi bi-bell';
            }

            /* ── Section รออนุมัติ — element อาจไม่มีถ้าเป็น Data Entry (role 1) ── */
            if (hdBadge && body) {
                if (approvalCount > 0) {
                    hdBadge.style.display = ''; hdBadge.textContent = approvalCount + ' รายการ';
                } else {
                    hdBadge.style.display = 'none';
                }

                if (!data.items.length) {
                    body.innerHTML = '<div class="cfp-notif-empty"><i class="bi bi-inbox" style="font-size:1.4rem;display:block;margin-bottom:4px;"></i>ไม่มีรายการรออนุมัติ</div>';
                } else {
                    var html = '';
                    data.items.forEach(function(it) {
                        html += '<a href="/carbonfootprint/workflow/review.php" class="cfp-notif-item">'
                              +   '<div class="cfp-notif-dot" style="background:' + it.scopeColor + ';"></div>'
                              +   '<div>'
                              +     '<div class="cfp-notif-name">' + it.itemName + '</div>'
                              +     '<div class="cfp-notif-sub">Scope ' + it.scopeNo
                              +       (it.siteName ? ' · ' + it.siteName : '')
                              +       (it.submitterName ? ' · ' + it.submitterName : '')
                              +       (it.submittedDate ? ' · ' + it.submittedDate : '')
                              +     '</div>'
                              +   '</div>'
                              + '</a>';
                    });
                    if (approvalCount > 5) {
                        html += '<div class="cfp-notif-footer"><a href="/carbonfootprint/workflow/review.php">ดูทั้งหมด ' + approvalCount + ' รายการ →</a></div>';
                    }
                    body.innerHTML = html;
                }
            }

            /* ── Data Entry: ทรัพย์สินที่ Admin ทำเสร็จแล้ว (element มีเฉพาะ role 1) ── */
            var frHdBadge = document.getElementById('cfpFulfilledHdBadge');
            var frBody    = document.getElementById('cfpFulfilledBody');
            if (frHdBadge && frBody) {
                var frCount = data.fulfilledCount || 0;
                if (frCount > 0) {
                    frHdBadge.style.display = ''; frHdBadge.textContent = frCount + ' รายการ';
                } else {
                    frHdBadge.style.display = 'none';
                }
                if (!data.fulfilledItems.length) {
                    frBody.innerHTML = '<div class="cfp-notif-empty"><i class="bi bi-inbox" style="font-size:1.4rem;display:block;margin-bottom:4px;"></i>ยังไม่มีคำขอที่ทำเสร็จ</div>';
                } else {
                    var frHtml = '';
                    data.fulfilledItems.forEach(function(fr) {
                        frHtml += '<a href="/carbonfootprint/data_entry/my_asset_requests.php" class="cfp-notif-item">'
                                +   '<div class="cfp-notif-dot" style="background:#43A047;"></div>'
                                +   '<div>'
                                +     '<div class="cfp-notif-name">' + fr.assetType + ': ' + fr.requestedName + '</div>'
                                +     '<div class="cfp-notif-sub">Scope ' + fr.scopeNo
                                +       (fr.siteName ? ' · ' + fr.siteName : '')
                                +       (fr.closedByName ? ' · โดย ' + fr.closedByName : '')
                                +       (fr.closedDate ? ' · ' + fr.closedDate : '')
                                +     '</div>'
                                +   '</div>'
                                + '</a>';
                    });
                    if (frCount > 5) {
                        frHtml += '<div class="cfp-notif-footer"><a href="/carbonfootprint/data_entry/my_asset_requests.php">ดูทั้งหมด ' + frCount + ' รายการ →</a></div>';
                    }
                    frBody.innerHTML = frHtml;
                }
            }

            /* ── Section 2: คำขอเพิ่มทรัพย์สินใหม่ — มีเฉพาะ Admin เท่านั้น (element อาจไม่มีถ้าไม่ใช่ Admin) ── */
            var arHdBadge = document.getElementById('cfpAssetReqHdBadge');
            var arBody    = document.getElementById('cfpAssetReqBody');
            if (arHdBadge && arBody) {
                var arCount = data.assetRequestCount || 0;
                if (arCount > 0) {
                    arHdBadge.style.display = ''; arHdBadge.textContent = arCount + ' รายการ';
                } else {
                    arHdBadge.style.display = 'none';
                }
                if (!data.assetRequestItems.length) {
                    arBody.innerHTML = '<div class="cfp-notif-empty"><i class="bi bi-inbox" style="font-size:1.4rem;display:block;margin-bottom:4px;"></i>ไม่มีคำขอค้างอยู่</div>';
                } else {
                    var arHtml = '';
                    data.assetRequestItems.forEach(function(ar) {
                        arHtml += '<a href="/carbonfootprint/master/asset_requests.php" class="cfp-notif-item">'
                                +   '<div class="cfp-notif-dot" style="background:#2AABB8;"></div>'
                                +   '<div>'
                                +     '<div class="cfp-notif-name">' + ar.assetType + ': ' + ar.requestedName + '</div>'
                                +     '<div class="cfp-notif-sub">Scope ' + ar.scopeNo
                                +       (ar.siteName ? ' · ' + ar.siteName : '')
                                +       (ar.requesterName ? ' · ' + ar.requesterName : '')
                                +       (ar.createdDate ? ' · ' + ar.createdDate : '')
                                +     '</div>'
                                +   '</div>'
                                + '</a>';
                    });
                    if (arCount > 5) {
                        arHtml += '<div class="cfp-notif-footer"><a href="/carbonfootprint/master/asset_requests.php">ดูทั้งหมด ' + arCount + ' รายการ →</a></div>';
                    }
                    arBody.innerHTML = arHtml;
                }
            }
        })
        .catch(function() { /* เงียบไว้ ไม่รบกวนผู้ใช้ถ้า poll พลาดรอบเดียว */ });
}

applyNotifSettings();

/* ── set body.cfp-mobile ตาม window.innerWidth จริง (ไม่พึ่ง media query) ── */
(function() {
    function _cfpCheckMobile() {
        if (window.innerWidth < 768) {
            document.body.classList.add('cfp-mobile');
        } else {
            document.body.classList.remove('cfp-mobile');
            var s = document.getElementById('cfpSidebar');
            var o = document.getElementById('cfpOverlay');
            if (s) { s.classList.remove('show'); }
            if (o) { o.style.display = 'none'; }
        }
    }
    _cfpCheckMobile();
    window.addEventListener('resize', _cfpCheckMobile);
})();
</script>
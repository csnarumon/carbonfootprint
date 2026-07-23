<?php
/* ==============================================
   includes/sidebar.php — Modern Light Theme
   (อัปเดตสำหรับ iframe shell: เพิ่ม data-page ใน nav-link)
   ============================================== */
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
/* ── ใช้ Effective Role/UserID เสมอ — ถ้า Admin กำลัง View-as จะเห็นเมนูตรงกับ user ที่สวมอยู่จริง ── */
$roleID      = getEffectiveRole();
/* ── Admin IT (4) และ Sustainability Admin (5): เมนูเริ่มต้นแบบย่อ (เพราะมีเมนูเยอะ) ── สิทธิ์อื่นเริ่มต้นแบบขยาย ── */
$defaultOpen = !in_array($roleID, array(4, 5));
$roleNames   = array(
    1 => 'Data Entry',
    2 => 'Reviewer',
    3 => 'Approver',
    4 => 'Admin',
    5 => 'Sustainability Admin',
    6 => 'Viewer',
);

/* ── สิทธิ์ Scope ของ Data Entry (roleID 1) — Admin/SustainAdmin เห็นทุก Scope เสมอ
   และตอน Admin Elevate เป็น Data Entry ก็เห็นทุก Scope เช่นกัน (Elevate ให้สิทธิ์ Role
   สำหรับทดสอบ ไม่ได้ผูก CFP_UserScopeAccess ของ Admin จริงไว้) ── */
$allowedScopes = array(1 => true, 2 => true, 3 => true);
if ($roleID === 1 && isset($conn) && !isElevating()) {
    $userID = getEffectiveUserID();
    $allowedScopes = array();
    $resMyScope = sqlsrv_query($conn,
        "SELECT ScopeNo FROM CFP_UserScopeAccess WHERE UserID = ? AND IsActive = 1",
        array($userID)
    );
    if ($resMyScope) {
        while ($r = sqlsrv_fetch_array($resMyScope, SQLSRV_FETCH_ASSOC)) {
            $allowedScopes[(int)$r['ScopeNo']] = true;
        }
    }
}

/* ── View-as: แสดงชื่อ/role ของ user ที่กำลังสวมอยู่แทน Admin ตัวจริง ── */
$fullname  = isViewingAs() ? ($_SESSION['view_as_name'] ?? '') : ($_SESSION['fullname'] ?? '');
$initials  = '';
$nameParts = explode(' ', trim($fullname));
if (count($nameParts) >= 2) {
    $initials = mb_substr($nameParts[0], 0, 1, 'UTF-8')
              . mb_substr($nameParts[1], 0, 1, 'UTF-8');
} else {
    $initials = mb_substr($fullname, 0, 2, 'UTF-8');
}

/* --------------------------------------------------
   cfpNavLink — เพิ่ม data-page เพื่อให้ shell จับ click
   -------------------------------------------------- */
function cfpNavLink($href, $icon, $label, $page, $current, $color = '', $autoCode = false) {
    $active     = ($current === $page) ? ' active' : '';
    $colorClass = $color ? ' scope-' . $color : '';
    $badge      = $autoCode
        ? ' <span class="nav-auto-badge" title="ระบบรันรหัสให้อัตโนมัติ">A</span>'
        : '';
    return '<a href="' . $href . '" data-page="' . htmlspecialchars($page) . '"'
         . ' class="nav-link' . $active . $colorClass . '">'
         . '<i class="bi bi-' . $icon . '"></i>'
         . '<span class="nav-label">' . htmlspecialchars($label) . '</span>' . $badge
         . '</a>';
}

function cfpCollapsibleSection($title, $icon, $badge, $badgeClass, $items, $isOpen = true) {
    $id = 'collapse_' . preg_replace('/[^a-zA-Z0-9]/', '', $title . uniqid());
    $openClass    = $isOpen ? 'open' : '';
    $showClass    = $isOpen ? 'show' : '';
    $collapseClass = '';

    $scopeClass = '';
    if (strpos($badgeClass, 'assetreq') !== false)  $scopeClass = 'assetreq';
    elseif (strpos($badgeClass, 'entry') !== false)     $scopeClass = 'entry';
    elseif (strpos($badgeClass, 'review') !== false) $scopeClass = 'review';
    elseif (strpos($badgeClass, 'scope1') !== false) $scopeClass = 'scope1';
    elseif (strpos($badgeClass, 'scope2') !== false) $scopeClass = 'scope2';
    elseif (strpos($badgeClass, 'scope3') !== false) $scopeClass = 'scope3';
    elseif (strpos($badgeClass, 'settings') !== false) $scopeClass = 'settings';
    elseif (strpos($badgeClass, 'reference') !== false) $scopeClass = 'reference';
    elseif (strpos($badgeClass, 'admin-light') !== false) $scopeClass = 'admin-light';
    elseif (strpos($badgeClass, 'admin') !== false)  $scopeClass = 'admin';
    elseif (strpos($badgeClass, 'report') !== false) $scopeClass = 'report';

    $html  = '<div class="nav-section-collapsible ' . $openClass . ' ' . $scopeClass . '">';
    $html .= '<div class="nav-section-header" data-target="#' . $id . '">';
    $html .= '<i class="bi bi-' . $icon . '"></i>';
    $html .= '<span class="nav-section-title">' . htmlspecialchars($title) . '</span>';
    $html .= '<span class="badge-scope ' . $badgeClass . '">' . $badge . '</span>';
    $html .= '<i class="bi bi-chevron-down collapse-icon"></i>';
    $html .= '</div>';
    $html .= '<div class="nav-section-body ' . $collapseClass . ' ' . $showClass . '" id="' . $id . '">';
    $html .= '<div class="nav-section-inner">' . $items . '</div>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}
?>

<style>
.nav-auto-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 15px;
    height: 15px;
    margin-left: 4px;
    border-radius: 4px;
    background: rgba(42, 171, 184, 0.15);
    color: var(--cfp-primary, #2AABB8);
    font-size: 0.6rem;
    font-weight: 700;
    line-height: 1;
    flex-shrink: 0;
}

/* ค้นหาเมนู */
.nav-search-wrap {
    position: relative;
    margin: 8px 14px 10px;
    flex-shrink: 0;
}
.nav-search-wrap input {
    width: 100%;
    background: var(--cfp-bg, #F3F8F9);
    border: 1px solid var(--cfp-border, #D0E8EE);
    border-radius: 9px;
    color: var(--cfp-text, #1B3A4A);
    font-family: 'Prompt', sans-serif;
    font-size: 0.78rem;
    padding: 7px 30px;
    outline: none;
}
.nav-search-wrap input:focus {
    border-color: var(--cfp-primary, #2AABB8);
}
.nav-search-wrap input::placeholder {
    color: var(--cfp-text-muted, #6B8A92);
}
.nav-search-wrap .bi-search {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.78rem;
    color: var(--cfp-text-muted, #6B8A92);
    pointer-events: none;
}
.nav-search-wrap .nav-search-clear {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: var(--cfp-text-muted, #6B8A92);
    font-size: 0.85rem;
    display: none;
}
.nav-label mark {
    background: #FFE9A8;
    color: #7A5A00;
    border-radius: 3px;
    padding: 0 1px;
}
.nav-section-collapsible.nav-search-dim {
    opacity: 0.32;
}
</style>

<nav class="cfp-sidebar theme-card" id="cfpSidebar" style="visibility:hidden;">

    <!-- Brand -->
    <a href="/carbonfootprint/dashboard/index.php"
       data-page="index"
       class="brand nav-link">
        <div class="brand-icon"><i class="bi bi-leaf-fill"></i></div>
        <div>
            ระบบบริหารจัดการ
            <span class="brand-sub">การปล่อยก๊าซเรือนกระจก · CFP</span>
        </div>
    </a>

    <!-- User Info -->
    <div class="sidebar-user">
        <div class="user-avatar"><?php echo htmlspecialchars($initials); ?></div>
        <div>
            <div class="user-role"><?php echo htmlspecialchars($roleNames[$roleID] ?? ''); ?></div>
            <div class="user-name"><?php echo htmlspecialchars($fullname); ?></div>
        </div>
    </div>

    <!-- Navigation scroll area -->
    <div class="nav-scroll p-0" id="navScroll">

        <!-- Dashboard -->
        <a href="/carbonfootprint/dashboard/index.php"
           data-page="index"
           class="nav-dashboard-pill <?php echo ($currentPage === 'index') ? 'active' : ''; ?>">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <div class="nav-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="navSearchInput" placeholder="ค้นหาเมนู..." autocomplete="off">
            <i class="bi bi-x-circle-fill nav-search-clear" id="navSearchClear"></i>
        </div>

        <?php
        // ── 1. บันทึกข้อมูล ──────────────────────────────
        if (in_array($roleID, array(1, 4))) {
            $items = '';
            $badges = array();
            if (!empty($allowedScopes[1])) {
                $items   .= cfpNavLink('/carbonfootprint/data_entry/scope1.php','fire','Scope 1 — Direct','scope1',$currentPage,'scope1');
                $badges[] = 'S1';
            }
            if (!empty($allowedScopes[2])) {
                $items   .= cfpNavLink('/carbonfootprint/data_entry/scope2.php','lightning-charge','Scope 2 — Energy','scope2',$currentPage,'scope2');
                $badges[] = 'S2';
            }
            if (!empty($allowedScopes[3])) {
                $items   .= cfpNavLink('/carbonfootprint/data_entry/scope3.php','globe-asia-australia','Scope 3 — Other','scope3',$currentPage,'scope3');
                $badges[] = 'S3';
            }
            if ($items !== '') {
                echo cfpCollapsibleSection('บันทึกข้อมูล', 'pencil-square', implode(',', $badges), 'entry-badge', $items, $defaultOpen);
            }
        }

        // ── 1b. คำขอเพิ่มทรัพย์สินของฉัน — Data Entry เท่านั้น ──
        if ($roleID === 1 && isset($conn)) {
            $myUID = getEffectiveUserID();
            $resMyAR = sqlsrv_query($conn, "SELECT COUNT(*) AS C FROM CFP_AssetRequest WHERE RequestedBy = ? AND Status = 0", array($myUID));
            $myARCount = 0;
            if ($resMyAR) { $rMyAR = sqlsrv_fetch_array($resMyAR, SQLSRV_FETCH_ASSOC); $myARCount = $rMyAR ? (int)$rMyAR['C'] : 0; }
            $items = cfpNavLink('/carbonfootprint/data_entry/my_asset_requests.php','box-seam','คำขอเพิ่มทรัพย์สินของฉัน','my_asset_requests',$currentPage,'entry');
            echo cfpCollapsibleSection('คำขอเพิ่มทรัพย์สิน', 'box-seam', $myARCount > 0 ? (string)$myARCount : '0', 'assetreq-badge', $items, $defaultOpen);
        }

        // ── 2. ตรวจสอบ / อนุมัติ ─────────────────────────
        if (in_array($roleID, array(2, 3, 4, 5))) {
            $items  = cfpNavLink('/carbonfootprint/workflow/review.php','clipboard-check','รออนุมัติ','review',$currentPage,'review');
            $items .= cfpNavLink('/carbonfootprint/workflow/approved.php','check2-all','อนุมัติแล้ว','approved',$currentPage,'review');
            if (in_array($roleID, array(3, 4, 5))) {
                $items .= cfpNavLink('/carbonfootprint/workflow/month_close.php','lock','ปิดเดือน','month_close',$currentPage,'review');
            }
            echo cfpCollapsibleSection('ตรวจสอบ / อนุมัติ', 'check-circle', 'WF', 'review-badge', $items, $defaultOpen);
        }

        // ── 3. ทะเบียนทรัพย์สิน Scope 1 — Admin + SustainAdmin ──
        if (in_array($roleID, array(4, 5))) {
            $items  = cfpNavLink('/carbonfootprint/master/equipment.php','gear-wide-connected','เครื่องจักร','equipment',$currentPage,'scope1');
            $items .= cfpNavLink('/carbonfootprint/master/vehicle.php','truck','ยานพาหนะ','vehicle',$currentPage,'scope1');
            $items .= cfpNavLink('/carbonfootprint/master/refrigerant.php','thermometer-snow','อุปกรณ์ทำความเย็น','refrigerant',$currentPage,'scope1');
            $items .= cfpNavLink('/carbonfootprint/master/watermeter.php','droplet-half','มิเตอร์น้ำ','watermeter',$currentPage,'scope1');
            echo cfpCollapsibleSection('ทะเบียนทรัพย์สิน (Scope 1)', 'fire', 'S1', 'scope1-badge', $items, $defaultOpen);
        }

        // ── 4. ทะเบียนทรัพย์สิน Scope 2 — Admin + SustainAdmin ──
        if (in_array($roleID, array(4, 5))) {
            $items = cfpNavLink('/carbonfootprint/master/electricmeter.php','lightning-charge','มิเตอร์ไฟฟ้า','electricmeter',$currentPage,'scope2');
            echo cfpCollapsibleSection('ทะเบียนทรัพย์สิน (Scope 2)', 'lightning-charge', 'S2', 'scope2-badge', $items, $defaultOpen);
        }

        // ── 5. ทะเบียนทรัพย์สิน Scope 3 — Admin + SustainAdmin ──
        if (in_array($roleID, array(4, 5))) {
            $items = cfpNavLink('/carbonfootprint/master/vendor.php','tree','ทะเบียนชาวสวน','vendor',$currentPage,'scope3');
            $items .= cfpNavLink('/carbonfootprint/master/waste.php','trash3','รายการขยะ / ของเสีย','waste',$currentPage,'scope3');
            $items .= cfpNavLink('/carbonfootprint/master/employee.php','person-walking','การเดินทางของพนักงาน','employee',$currentPage,'scope3');
            $items .= cfpNavLink('/carbonfootprint/master/positionvehicle.php','car-front-fill','รถประจำตำแหน่ง','positionvehicle',$currentPage,'scope3');
            echo cfpCollapsibleSection('ทะเบียนทรัพย์สิน (Scope 3)', 'globe-asia-australia', 'S3', 'scope3-badge', $items, $defaultOpen);
        }

        
        // ── 7b. คำขอเพิ่มทรัพย์สินใหม่ — Admin และ Sustainability Admin (คนสร้างทะเบียนจริง) ──
        if (in_array($roleID, array(4, 5)) && isset($conn)) {
            $resAR = sqlsrv_query($conn, "SELECT COUNT(*) AS C FROM CFP_AssetRequest WHERE Status = 0");
            $arCount = 0;
            if ($resAR) { $rAR = sqlsrv_fetch_array($resAR, SQLSRV_FETCH_ASSOC); $arCount = $rAR ? (int)$rAR['C'] : 0; }
            $items = cfpNavLink('/carbonfootprint/master/asset_requests.php','box-seam','คำขอเพิ่มทรัพย์สิน','asset_requests',$currentPage,'admin');
            echo cfpCollapsibleSection('คำขอเพิ่มทรัพย์สิน', 'box-seam', $arCount > 0 ? (string)$arCount : '0', 'assetreq-badge', $items, $defaultOpen);
        }
        
        // ── 6. การตั้งค่าข้อมูลพื้นฐาน ───────────────────
        if (in_array($roleID, array(4, 5))) {

            $items  = cfpNavLink('/carbonfootprint/master/vehicletype.php','card-list','ประเภทยานพาหนะ','vehicletype',$currentPage,'settings',true);
            $items .= cfpNavLink('/carbonfootprint/master/equipmenttype.php','gear-wide-connected','ประเภทเครื่องจักร','equipmenttype',$currentPage,'settings',true);
            $items .= cfpNavLink('/carbonfootprint/master/fueltype.php','fuel-pump','ประเภทเชื้อเพลิง','fueltype',$currentPage,'settings',true);
            $items .= cfpNavLink('/carbonfootprint/master/refrigeranttype.php','snow2','ประเภทสารทำความเย็น','refrigeranttype',$currentPage,'settings',true);
            $items .= cfpNavLink('/carbonfootprint/master/watersourcetype.php','water','แหล่งน้ำ','watersourcetype',$currentPage,'settings',true);
            $items .= cfpNavLink('/carbonfootprint/master/watermetertype.php','speedometer2','ประเภทมิเตอร์น้ำ','watermetertype',$currentPage,'settings',true);
            $items .= cfpNavLink('/carbonfootprint/master/electricsourcetype.php','plug','แหล่งไฟฟ้า','electricsourcetype',$currentPage,'settings',true);
            $items .= cfpNavLink('/carbonfootprint/master/electricmetertype.php','toggles','ประเภทมิเตอร์ไฟฟ้า','electricmetertype',$currentPage,'settings',true);
            $items .= cfpNavLink('/carbonfootprint/master/wastewatertype.php','droplet','ประเภทการปล่อยน้ำเสีย','wastewatertype',$currentPage,'settings',true);
            $items .= cfpNavLink('/carbonfootprint/master/extinguishertype.php','fire','ประเภทสารดับเพลิง','extinguishertype',$currentPage,'settings',true);
            $items .= cfpNavLink('/carbonfootprint/master/flourwastetype.php','recycle','ประเภทการกำจัดเศษแป้ง','flourwastetype',$currentPage,'settings',true);
            $items .= cfpNavLink('/carbonfootprint/master/wastetype.php','bag-x','ประเภทขยะ','wastetype',$currentPage,'settings',true);
            $items .= cfpNavLink('/carbonfootprint/master/wastedisposalsite.php','geo-alt','สถานที่กำจัดขยะ','wastedisposalsite',$currentPage,'settings',true);
            $items .= cfpNavLink('/carbonfootprint/master/wastedisposalmethod.php','arrow-repeat','วิธีกำจัดขยะ','wastedisposalmethod',$currentPage,'settings',true);
            $items .= cfpNavLink('/carbonfootprint/master/scope3category.php','list-check','Category (Scope 3)','scope3category',$currentPage,'settings');
            if ($roleID === 4) {
                $items .= cfpNavLink('/carbonfootprint/master/unit.php','rulers','หน่วยวัด','unit',$currentPage,'settings');
            }
            echo cfpCollapsibleSection('การตั้งค่าข้อมูลพื้นฐาน', 'gear', 'SET', 'settings-badge', $items, $defaultOpen);
        }

        // ── 7. จัดการ ──────────────────────────────────────
        if (in_array($roleID, array(4, 5))) {
            $items = cfpNavLink('/carbonfootprint/master/users.php','people','ผู้ใช้งาน/สิทธิ์','users',$currentPage,'admin');
            $items .= cfpNavLink('/carbonfootprint/master/activityitem.php','list-check','รายการกิจกรรม','activityitem',$currentPage,'settings');
            echo cfpCollapsibleSection('จัดการสิทธิ์/รายการกิจกรรม', 'people-fill', 'AD', 'admin-light-badge', $items, $defaultOpen);
        }


        // ── 8. ข้อมูลอ้างอิง ───────────────────────────────
        // unit/org = Admin เท่านั้น (โครงสร้างระบบ) / ef_link+ef_value = Admin+SustainAdmin (มาตรฐานคำนวณคาร์บอน)
        if (in_array($roleID, array(4, 5))) {
            $items = '';
            $items .= cfpNavLink('/carbonfootprint/master/ef_source.php','journal-bookmark','แหล่งอ้างอิง EF','ef_source',$currentPage,'reference');
            $items .= cfpNavLink('/carbonfootprint/master/ef_value.php','database','ค่า EF','ef_value',$currentPage,'reference');
            $items .= cfpNavLink('/carbonfootprint/master/ef_link.php','link-45deg','ผูก EF กับกิจกรรม','ef_link',$currentPage,'reference');
            if ($roleID === 4) {
                $items .= cfpNavLink('/carbonfootprint/master/org.php','diagram-3','โครงสร้างองค์กร','org',$currentPage,'reference');
            }
            echo cfpCollapsibleSection('ข้อมูลอ้างอิง', 'book', 'REF', 'reference-badge', $items, $defaultOpen);
        }

        // ── 9. ข้อมูลรายงาน ────────────────────────────────
        if (in_array($roleID, array(4, 5))) {
            $items  = cfpNavLink('/carbonfootprint/report/summary.php','file-earmark-bar-graph','รายงานสรุป','summary',$currentPage,'report');
            $items .= cfpNavLink('/carbonfootprint/report/scope1.php','fire','รายงาน Scope 1','report_s1',$currentPage,'report');
            $items .= cfpNavLink('/carbonfootprint/report/scope2.php','lightning-charge','รายงาน Scope 2','report_s2',$currentPage,'report');
            $items .= cfpNavLink('/carbonfootprint/report/scope3.php','globe-asia-australia','รายงาน Scope 3','report_s3',$currentPage,'report');
            echo cfpCollapsibleSection('ข้อมูลรายงาน', 'bar-chart-fill', 'RPT', 'report-badge', $items, $defaultOpen);
        }
        ?>

    </div><!-- /#navScroll -->

    <!-- ปุ่มย่อ/ขยายทั้งหมด — sticky ด้านล่าง sidebar -->
    <div class="sidebar-toggle-all">
        <button type="button" id="toggleAllSections" class="btn-toggle-all" title="ย่อ/ขยายเมนูทั้งหมด">
            <i class="bi bi-arrows-expand"></i>
            <span>ย่อ/ขยายทั้งหมด</span>
        </button>
    </div>

    <!-- Logout — full navigate (ไม่ intercept) -->
    <div class="sidebar-footer">
        <a href="/carbonfootprint/logout.php"
           class="nav-link"
           data-cfp-logout="1">
            <i class="bi bi-box-arrow-left"></i>
            <span class="nav-label">ออกจากระบบ</span>
        </a>
    </div>

</nav>

<!-- ============================================================
     SIDEBAR SCRIPT — collapsible sections + menu state เท่านั้น
     scroll restore/save จัดการโดย app.php (shell) เพื่อป้องกัน race condition
     ============================================================ -->
<script>
/* กัน sidebar ค้างซ่อนถาวรถ้า error เกิดก่อนถึงจุด reveal ด้านล่าง */
setTimeout(function () {
    var s = document.getElementById('cfpSidebar');
    if (s && s.style.visibility === 'hidden') { s.style.visibility = 'visible'; }
}, 500);

(function () {
    /* แยก state ตาม role กันไม่ให้ผลของ role นึงมาทับ default ของอีก role (เช่น Admin ต้องย่อ แต่ role อื่นขยาย) */
    var STATE_KEY = 'cfp_sidebar_menu_state_role<?php echo (int)$roleID; ?>';

    /* ── 1. Restore menu state จาก localStorage ── */
    var allSections = document.querySelectorAll('.nav-section-collapsible');
    var savedState  = localStorage.getItem(STATE_KEY);

    if (savedState) {
        try {
            var states = JSON.parse(savedState);
            allSections.forEach(function (sec, i) {
                var isOpen = (states[i] !== undefined) ? states[i] : true;
                var body   = sec.querySelector('.nav-section-body');
                if (isOpen) {
                    sec.classList.add('open');
                    if (body) { body.classList.add('show'); }
                } else {
                    sec.classList.remove('open');
                    if (body) { body.classList.remove('show'); }
                }
            });
        } catch (e) {
            /* fallback: เปิดทั้งหมด */
            allSections.forEach(function (sec) {
                sec.classList.add('open');
                var body = sec.querySelector('.nav-section-body');
                if (body) { body.classList.add('show'); }
            });
        }
    }

    /* ── 1b. เปิดหมวดที่มีหน้าปัจจุบันอยู่เสมอ (ทับ state ที่บันทึกไว้เฉพาะหมวดนี้) ── */
    var activeLink = document.querySelector('.nav-section-inner .nav-link.active');
    if (activeLink) {
        var activeSection = activeLink.closest('.nav-section-collapsible');
        if (activeSection) {
            activeSection.classList.add('open');
            var activeBody = activeSection.querySelector('.nav-section-body');
            if (activeBody) { activeBody.classList.add('show'); }
        }
    }

    /* ── 1c. state (เปิด/ปิดหมวด) กำหนดครบแล้ว — ค่อยแสดง sidebar
       กัน animation เปิดหมวดให้เห็นตอนโหลดหน้า (เต้น) ── */
    var sidebarEl = document.getElementById('cfpSidebar');
    if (sidebarEl) { sidebarEl.style.visibility = 'visible'; }

    /* ── 2. บันทึก menu state ── */
    function saveMenuState() {
        var states = [];
        document.querySelectorAll('.nav-section-collapsible').forEach(function (sec) {
            states.push(sec.classList.contains('open'));
        });
        localStorage.setItem(STATE_KEY, JSON.stringify(states));
    }

    /* ── 3. Collapsible headers — toggle open/close ── */
    document.querySelectorAll('.nav-section-header').forEach(function (header) {
        header.addEventListener('click', function (e) {
            e.stopPropagation();
            var targetId = this.dataset.target;
            var body     = document.querySelector(targetId);
            var parent   = this.closest('.nav-section-collapsible');
            if (!body || !parent) { return; }
            var isOpen = parent.classList.toggle('open');
            if (isOpen) {
                body.classList.add('show');
            } else {
                body.classList.remove('show');
            }
            setTimeout(saveMenuState, 150);
        });
    });

    /* ── 4. Toggle all sections ── */
    var toggleBtn = document.getElementById('toggleAllSections');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            var sections = document.querySelectorAll('.nav-section-collapsible');
            var allOpen  = true;
            sections.forEach(function (s) { if (!s.classList.contains('open')) { allOpen = false; } });
            var open = !allOpen;
            sections.forEach(function (s) {
                var body = s.querySelector('.nav-section-body');
                if (open) {
                    s.classList.add('open');
                    if (body) { body.classList.add('show'); }
                } else {
                    s.classList.remove('open');
                    if (body) { body.classList.remove('show'); }
                }
            });
            setTimeout(saveMenuState, 150);
        });
    }

    /* ── 5. nav-scroll แสดงได้ (CSS ซ่อน visibility จนกว่าจะ ready) ── */
    var navScroll = document.getElementById('navScroll');
    if (navScroll) { navScroll.classList.add('ready'); }

    /* ── 6. จำตำแหน่ง scroll ของ sidebar ── */
    var SCROLL_KEY = 'cfp_sidebar_scroll';
    if (navScroll) {
        /* restore ก่อน */
        var savedScroll = sessionStorage.getItem(SCROLL_KEY);
        if (savedScroll !== null) {
            navScroll.scrollTop = parseInt(savedScroll, 10);
        }
        /* save ทุกครั้งที่กดลิงก์ */
        navScroll.querySelectorAll('a').forEach(function(link) {
            link.addEventListener('click', function() {
                sessionStorage.setItem(SCROLL_KEY, navScroll.scrollTop);
            });
        });
    }

    /* ── 7. ค้นหาเมนู — กรองข้ามทุกหมวด ไม่ยิง request ── */
    var searchInput = document.getElementById('navSearchInput');
    var searchClear = document.getElementById('navSearchClear');
    if (searchInput) {
        var searchSections = document.querySelectorAll('.nav-section-collapsible');

        function escapeHtml(s) {
            return s.replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function clearSearch() {
            searchInput.value = '';
            searchClear.style.display = 'none';
            searchSections.forEach(function (sec) {
                sec.classList.remove('nav-search-dim');
                sec.querySelectorAll('.nav-label').forEach(function (label) {
                    label.textContent = label.textContent; /* ล้าง <mark> ที่ใส่ไว้ */
                });
            });
            /* คืนสถานะเปิด/ปิดตาม localStorage เดิม */
            var saved = localStorage.getItem(STATE_KEY);
            if (saved) {
                try {
                    var states = JSON.parse(saved);
                    searchSections.forEach(function (sec, i) {
                        var isOpen = (states[i] !== undefined) ? states[i] : true;
                        var body = sec.querySelector('.nav-section-body');
                        sec.classList.toggle('open', isOpen);
                        if (body) { body.classList.toggle('show', isOpen); }
                    });
                } catch (e) { /* เงียบไว้ */ }
            }
            if (activeLink) {
                var activeSection2 = activeLink.closest('.nav-section-collapsible');
                if (activeSection2) {
                    activeSection2.classList.add('open');
                    var b = activeSection2.querySelector('.nav-section-body');
                    if (b) { b.classList.add('show'); }
                }
            }
        }

        searchInput.addEventListener('input', function () {
            var q = searchInput.value.trim().toLowerCase();
            searchClear.style.display = q ? '' : 'none';

            if (!q) { clearSearch(); return; }

            searchSections.forEach(function (sec) {
                var body  = sec.querySelector('.nav-section-body');
                var links = sec.querySelectorAll('.nav-section-inner .nav-link');
                var hasMatch = false;

                links.forEach(function (link) {
                    var label = link.querySelector('.nav-label');
                    if (!label) { return; }
                    var text = label.textContent;
                    var idx  = text.toLowerCase().indexOf(q);
                    if (idx === -1) {
                        label.textContent = text; /* ล้าง mark เดิม */
                        return;
                    }
                    hasMatch = true;
                    var before = escapeHtml(text.slice(0, idx));
                    var match  = escapeHtml(text.slice(idx, idx + q.length));
                    var after  = escapeHtml(text.slice(idx + q.length));
                    label.innerHTML = before + '<mark>' + match + '</mark>' + after;
                });

                if (hasMatch) {
                    sec.classList.remove('nav-search-dim');
                    sec.classList.add('open');
                    if (body) { body.classList.add('show'); }
                } else {
                    sec.classList.add('nav-search-dim');
                }
            });
        });

        searchClear.addEventListener('click', function () {
            clearSearch();
            searchInput.focus();
        });
    }

}());
</script>
<?php
/* ==============================================
   includes/sidebar.php — Modern Light Theme (พร้อม CSS ในไฟล์)
   ============================================== */
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$roleID      = (int)$_SESSION['role_id'];
$roleNames   = array(
    1 => 'Data Entry',
    2 => 'Reviewer',
    3 => 'Approver',
    4 => 'Admin',
    5 => 'Sustainability Admin',
    6 => 'Viewer',
);

$fullname  = $_SESSION['fullname'] ?? '';
$initials  = '';
$nameParts = explode(' ', trim($fullname));
if (count($nameParts) >= 2) {
    $initials = mb_substr($nameParts[0], 0, 1, 'UTF-8')
              . mb_substr($nameParts[1], 0, 1, 'UTF-8');
} else {
    $initials = mb_substr($fullname, 0, 2, 'UTF-8');
}

function cfpNavLink($href, $icon, $label, $page, $current, $color = '') {
    $active = ($current === $page) ? ' active' : '';
    $colorClass = $color ? ' scope-' . $color : '';
    return '<a href="' . $href . '" class="nav-link' . $active . $colorClass . '">'
         . '<i class="bi bi-' . $icon . '"></i>'
         . '<span class="nav-label">' . htmlspecialchars($label) . '</span>'
         . '</a>';
}

function cfpCollapsibleSection($title, $icon, $badge, $badgeClass, $items, $isOpen = true) {
    $id = 'collapse_' . preg_replace('/[^a-zA-Z0-9]/', '', $title . '_' . uniqid());
    $openClass = $isOpen ? 'open' : '';
    $showClass = $isOpen ? 'show' : '';
    $collapseClass = $isOpen ? '' : 'collapse';
    
    // กำหนด scope class จาก badge
    $scopeClass = '';
    if (strpos($badgeClass, 'scope1') !== false) $scopeClass = 'scope1';
    elseif (strpos($badgeClass, 'scope2') !== false) $scopeClass = 'scope2';
    elseif (strpos($badgeClass, 'scope3') !== false) $scopeClass = 'scope3';
    elseif (strpos($badgeClass, 'admin') !== false) $scopeClass = 'admin';
    elseif (strpos($badgeClass, 'settings') !== false) $scopeClass = 'settings';
    elseif (strpos($badgeClass, 'reference') !== false) $scopeClass = 'reference';
    
    $html = '<div class="nav-section-collapsible ' . $openClass . ' ' . $scopeClass . '">';
    $html .= '<div class="nav-section-header" data-target="#' . $id . '">';
    $html .= '<i class="bi bi-' . $icon . '"></i>';
    $html .= '<span class="nav-section-title">' . htmlspecialchars($title) . '</span>';
    $html .= '<span class="badge-scope ' . $badgeClass . '">' . $badge . '</span>';
    $html .= '<i class="bi bi-chevron-down collapse-icon"></i>';
    $html .= '</div>';
    $html .= '<div class="nav-section-body ' . $collapseClass . ' ' . $showClass . '" id="' . $id . '">';
    $html .= '<div class="nav-section-inner">';
    $html .= $items;
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}
?>

<style>

</style>

<nav class="cfp-sidebar" id="cfpSidebar">
    <a href="/carbonfootprint/dashboard/index.php" class="brand">
        <div class="brand-icon"><i class="bi bi-leaf-fill"></i></div>
        <div>
            ระบบบริหารจัดการ
            <span class="brand-sub">คาร์บอนองค์กร · CFP</span>
        </div>
    </a>

    <div class="sidebar-user">
        <div class="user-avatar"><?php echo htmlspecialchars($initials); ?></div>
        <div>
            <div class="user-role"><?php echo htmlspecialchars($roleNames[$roleID] ?? ''); ?></div>
            <div class="user-name"><?php echo htmlspecialchars($fullname); ?></div>
        </div>
    </div>

    <div class="nav-scroll p-0">
        <!-- Dashboard -->
        <a href="/carbonfootprint/dashboard/index.php" class="nav-link <?php echo ($currentPage==='index') ? 'active' : ''; ?>">
            <i class="bi bi-grid-1x2-fill"></i>
            <span class="nav-label">Dashboard</span>
        </a>

        <?php if (in_array($roleID, array(1, 4))) { ?>
            <?php
                $items = '';
                $items .= cfpNavLink('/carbonfootprint/data_entry/scope1.php','fire','Scope 1 — Direct','scope1',$currentPage,'scope1');
                $items .= cfpNavLink('/carbonfootprint/data_entry/scope2.php','lightning-charge','Scope 2 — Energy','scope2',$currentPage,'scope2');
                $items .= cfpNavLink('/carbonfootprint/data_entry/scope3.php','globe-asia-australia','Scope 3 — Other','scope3',$currentPage,'scope3');
                echo cfpCollapsibleSection('บันทึกข้อมูล', 'pencil-square', 'S1,S2,S3', 'scope1-badge', $items, true);
            ?>
        <?php } ?>

        

        <?php if ($roleID === 4) { ?>
            <?php
                $items = '';
                $items .= cfpNavLink('/carbonfootprint/master/equipment.php','gear-wide-connected','เครื่องจักร','equipment',$currentPage,'scope1');
                $items .= cfpNavLink('/carbonfootprint/master/vehicle.php','truck','ยานพาหนะ','vehicle',$currentPage,'scope1');
                $items .= cfpNavLink('/carbonfootprint/master/refrigerant.php','thermometer-snow','อุปกรณ์ทำความเย็น','refrigerant',$currentPage,'scope1');
                $items .= cfpNavLink('/carbonfootprint/master/watermeter.php','droplet-half','มิเตอร์น้ำ','watermeter',$currentPage,'scope1');
                echo cfpCollapsibleSection('ทะเบียนทรัพย์สิน (Scope 1)', 'fire', 'S1', 'scope1-badge', $items, true);
            ?>
            <?php
                $items = '';
                $items .= cfpNavLink('/carbonfootprint/master/electricmeter.php','lightning-charge','มิเตอร์ไฟฟ้า','electric',$currentPage,'scope2');
                echo cfpCollapsibleSection('ทะเบียนทรัพย์สิน (Scope 2)', 'lightning-charge', 'S2', 'scope2-badge', $items, true);
            ?>
            <?php
                $items = '';
                $items .= cfpNavLink('/carbonfootprint/master/vendor.php','tree','ทะเบียนชาวสวน','vendor',$currentPage,'scope3');
                $items .= cfpNavLink('/carbonfootprint/master/waste.php','trash3','รายการขยะ / ของเสีย','waste',$currentPage,'scope3');
                $items .= cfpNavLink('/carbonfootprint/master/employee.php','person-walking','ทะเบียนพนักงาน','employee',$currentPage,'scope3');
                echo cfpCollapsibleSection('ทะเบียนทรัพย์สิน (Scope 3)', 'globe-asia-australia', 'S3', 'scope3-badge', $items, true);
            ?>
        <?php } ?>

        <?php if (in_array($roleID, array(4, 5))) { ?>
            <?php
                $items = '';
                $items .= cfpNavLink('/carbonfootprint/master/vehicletype.php','card-list','ประเภทยานพาหนะ','vehicletype',$currentPage,'settings');
                $items .= cfpNavLink('/carbonfootprint/master/equipmenttype.php','gear-wide-connected','ประเภทเครื่องจักร','equipmenttype',$currentPage,'settings');
                $items .= cfpNavLink('/carbonfootprint/master/fueltype.php','fuel-pump','ประเภทเชื้อเพลิง','fueltype',$currentPage,'settings');
                $items .= cfpNavLink('/carbonfootprint/master/refrigeranttype.php','snow2','ประเภทสารทำความเย็น','refrigeranttype',$currentPage,'settings');
                $items .= cfpNavLink('/carbonfootprint/master/watersourcetype.php','water','แหล่งน้ำ','watersourcetype',$currentPage,'settings');
                $items .= cfpNavLink('/carbonfootprint/master/watermetertype.php','speedometer2','ประเภทมิเตอร์น้ำ','watermetertype',$currentPage,'settings');
                $items .= cfpNavLink('/carbonfootprint/master/electricsourcetype.php','plug','แหล่งไฟฟ้า','electricsourcetype',$currentPage,'settings');
                $items .= cfpNavLink('/carbonfootprint/master/electricmetertype.php','toggles','ประเภทมิเตอร์ไฟฟ้า','electricmetertype',$currentPage,'settings');
                $items .= cfpNavLink('/carbonfootprint/master/wastewatertype.php','droplet','ประเภทการปล่อยน้ำเสีย','wastewatertype',$currentPage,'settings');
                $items .= cfpNavLink('/carbonfootprint/master/extinguishertype.php','fire','ประเภทสารดับเพลิง','extinguishertype',$currentPage,'settings');
                $items .= cfpNavLink('/carbonfootprint/master/flourwastetype.php','recycle','ประเภทการกำจัดเศษแป้ง','flourwastetype',$currentPage,'settings');
                $items .= cfpNavLink('/carbonfootprint/master/wastetype.php','bag-x','ประเภทขยะ','wastetype',$currentPage,'settings');
                $items .= cfpNavLink('/carbonfootprint/master/wastedisposalsite.php','geo-alt','สถานที่กำจัดขยะ','wastedisposalsite',$currentPage,'settings');
                $items .= cfpNavLink('/carbonfootprint/master/wastedisposalmethod.php','arrow-repeat','วิธีกำจัดขยะ','wastedisposalmethod',$currentPage,'settings');
                $items .= cfpNavLink('/carbonfootprint/master/activityitem.php','list-check','รายการกิจกรรม','activityitem',$currentPage,'settings');
                echo cfpCollapsibleSection('การตั้งค่าข้อมูลพื้นฐาน', 'gear', 'SET', 'settings-badge', $items, true);
            ?>
        <?php } ?>

        <?php if (in_array($roleID, array(4, 5))) { ?>
            <?php
                $items = '';
                $items .= cfpNavLink('/carbonfootprint/master/users.php','people','ผู้ใช้งาน','users',$currentPage,'admin');
                echo cfpCollapsibleSection('จัดการ', 'people-fill', 'AD', 'admin-badge', $items, true);
            ?>
        <?php } ?>

        <?php if ($roleID === 4) { ?>
            <?php
                $items = '';
                $items .= cfpNavLink('/carbonfootprint/master/unit.php','rulers','หน่วยวัด','unit',$currentPage,'reference');
                $items .= cfpNavLink('/carbonfootprint/master/ef_source.php','journal-bookmark','แหล่งอ้างอิง EF','ef_source',$currentPage,'reference');
                $items .= cfpNavLink('/carbonfootprint/master/ef_value.php','database','ค่า EF','ef_value',$currentPage,'reference');
                $items .= cfpNavLink('/carbonfootprint/master/org.php','diagram-3','โครงสร้างองค์กร','org',$currentPage,'reference');
                echo cfpCollapsibleSection('ข้อมูลอ้างอิง', 'book', 'REF', 'reference-badge', $items, true);
            ?>
        <?php } ?>
    </div>

    <div class="sidebar-footer">
        <a href="/carbonfootprint/logout.php" class="nav-link">
            <i class="bi bi-box-arrow-left"></i>
            <span class="nav-label">ออกจากระบบ</span>
        </a>
    </div>
</nav>

<div class="cfp-sidebar-overlay" id="sidebarOverlay"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // ============================================
    // TOGGLE MOBILE SIDEBAR
    // ============================================
    var toggle = document.getElementById('sidebarToggle');
    var sidebar = document.querySelector('.cfp-sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    
    if (toggle && sidebar && overlay) {
        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        });
        overlay.addEventListener('click', function () {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }

    // ============================================
    // COLLAPSIBLE SECTIONS — SMOOTH ANIMATION
    // ============================================
    var headers = document.querySelectorAll('.nav-section-header');
    
    headers.forEach(function (header) {
        header.addEventListener('click', function (e) {
            e.stopPropagation();
            
            var targetId = this.dataset.target;
            var body = document.querySelector(targetId);
            var parent = this.closest('.nav-section-collapsible');
            
            if (!body || !parent) return;
            
            // Toggle open/close
            var isOpen = parent.classList.toggle('open');
            
            if (isOpen) {
                body.classList.remove('collapse');
                body.classList.add('show');
            } else {
                body.classList.remove('show');
                body.classList.add('collapse');
            }
        });
    });

    // ============================================
    // DEFAULT: ทุกเมนูขยาย (open)
    // ============================================
    var sections = document.querySelectorAll('.nav-section-collapsible');
    sections.forEach(function (section) {
        var body = section.querySelector('.nav-section-body');
        if (body) {
            section.classList.add('open');
            body.classList.remove('collapse');
            body.classList.add('show');
        }
    });

    // ============================================
    // SAVE SCROLL POSITION
    // ============================================
    var navScroll = document.querySelector('.cfp-sidebar .nav-scroll');
    if (navScroll) {
        var saved = sessionStorage.getItem('cfp_sidebar_scroll');
        if (saved !== null) {
            navScroll.scrollTop = parseInt(saved, 10);
        }
        navScroll.querySelectorAll('a.nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                sessionStorage.setItem('cfp_sidebar_scroll', navScroll.scrollTop);
            });
        });
    }
});
</script>
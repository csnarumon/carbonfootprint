<?php
/* ==============================================
   app.php — Shell หลัก (iframe layout)
   sidebar + topbar คงอยู่ ไม่ refresh เมื่อเปลี่ยนหน้า
   วางไว้ที่: /carbonfootprint/app.php
   ============================================== */
require_once 'includes/auth_check.php';

requireRole(array(1, 2, 3, 4, 5, 6));

/* URL เริ่มต้นที่จะโหลดใน iframe */
$defaultPage = '/carbonfootprint/dashboard/index.php';

/* รับ page จาก query string (สำหรับ deep link / bookmark) */
$startPage = isset($_GET['page']) ? trim($_GET['page']) : $defaultPage;

/* Whitelist domain — ป้องกัน open redirect */
$allowedPrefix = '/carbonfootprint/';
if (strpos($startPage, $allowedPrefix) !== 0) {
    $startPage = $defaultPage;
}

/* page meta สำหรับ topbar ของ shell (ซ่อนอยู่ — topbar จริงอยู่ใน iframe) */
$pageTitle = 'ระบบบริหารจัดการคาร์บอนองค์กร';
$pageIcon  = 'grid-1x2-fill';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบบริหารจัดการคาร์บอนองค์กร</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">
    <style>
        /* =========================================
           SHELL LAYOUT — sidebar คงอยู่ตลอด
           ========================================= */
        *, *::before, *::after { box-sizing: border-box; }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: 'Prompt', sans-serif;
            overflow: hidden; /* ป้องกัน body scroll — scroll อยู่ใน iframe แทน */
        }

        /* Wrapper ครอบทั้งหน้า */
        .cfp-shell {
            display: flex;
            height: 100vh;
            width: 100vw;
            overflow: hidden;
        }

        /* Sidebar — ใช้ position:fixed จาก cfp-theme.css
           app.php ไม่ override position เพื่อไม่ให้ขัดกัน */
        .cfp-sidebar {
            width: 280px; /* ตรงกับ cfp-theme.css */
        }

        /* Content Area — เว้นซ้ายให้ sidebar fixed */
        .cfp-content-area {
            margin-left: 280px;
            height: 100vh;
            overflow: hidden;
            position: relative;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        @media (min-width: 768px) and (max-width: 991px) {
            .cfp-content-area { margin-left: 64px; }
        }

        @media (max-width: 767px) {
            .cfp-content-area { margin-left: 0; }
        }

        /* iframe */
        #cfpFrame {
            width: 100%;
            flex: 1;
            border: none;
            display: block;
            background: var(--cfp-bg, #EEF6F8);
        }

        /* Loading overlay — แสดงระหว่าง iframe โหลด */
        #frameLoader {
            position: absolute;
            inset: 0;
            background: rgba(238, 246, 248, 0.85);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 50;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s;
        }
        #frameLoader.show {
            opacity: 1;
            pointer-events: all;
        }
        .loader-dot {
            width: 10px; height: 10px;
            border-radius: 50%;
            background: #2AABB8;
            display: inline-block;
            margin: 0 4px;
            animation: loaderBounce 0.9s infinite ease-in-out;
        }
        .loader-dot:nth-child(2) { animation-delay: 0.15s; }
        .loader-dot:nth-child(3) { animation-delay: 0.30s; }
        @keyframes loaderBounce {
            0%, 80%, 100% { transform: translateY(0); opacity: 0.5; }
            40%            { transform: translateY(-10px); opacity: 1; }
        }

        /* =========================================
           MOBILE / TABLET RESPONSIVE
           ========================================= */

        /* Overlay — cfp-theme.css กำหนด z-index:1040 และ display:none ไว้แล้ว
           app.php เพิ่มเฉพาะ .show state */
        .cfp-sidebar-overlay.show { display: block !important; }

        /* Tablet (768–991px): sidebar แคบ แสดงแค่ icon */
        @media (min-width: 768px) and (max-width: 991px) {
            .cfp-sidebar { width: 64px; }
        }

        /* Mobile (<768px): ให้ cfp-theme.css จัดการ transform/z-index
           app.php เพิ่มเฉพาะ content-area margin */
        @media (max-width: 767px) {
            .cfp-content-area { margin-left: 0 !important; }
        }

        /* FORCE mobile sidebar — ทำงานโดยไม่พึ่ง media query
           JS จะ add/remove class 'mobile-mode' ที่ body ตาม window.innerWidth */
        body.mobile-mode .cfp-sidebar {
            transform: translateX(-100%) !important;
            transition: transform 0.3s ease !important;
            z-index: 1100 !important;
        }
        body.mobile-mode .cfp-sidebar.show {
            transform: translateX(0) !important;
            box-shadow: 4px 0 32px rgba(0,0,0,0.18) !important;
        }
        body.mobile-mode .cfp-content-area {
            margin-left: 0 !important;
        }

        /* shell-topbar-mobile ซ่อนไว้ — hamburger จริงอยู่ใน topbar.php (iframe)
           ส่ง postMessage cfp_toggle_sidebar ขึ้นมาหา app.php แทน */
        .shell-topbar-mobile { display: none !important; }
    </style>
</head>
<body>

<div class="cfp-shell">

    <!-- ========== SIDEBAR ========== -->
    <?php
    $sidebarPath = __DIR__ . '/includes/sidebar.php';
    if (file_exists($sidebarPath)) {
        include $sidebarPath;
    } else {
        echo '<!-- sidebar.php not found at: ' . htmlspecialchars($sidebarPath) . ' -->';
    }
    ?>

    <!-- Overlay (mobile) -->
    <div class="cfp-sidebar-overlay" id="shellOverlay"></div>

    <!-- ========== CONTENT AREA ========== -->
    <div class="cfp-content-area">

        <!-- Topbar mini — แสดงเฉพาะ mobile/tablet เพื่อมี hamburger button -->
        <!-- topbar จริง (cfp-topbar) อยู่ใน iframe ของแต่ละหน้า -->
        <div class="shell-topbar-mobile">
            <button id="btnHamburger" aria-label="เปิดเมนู" title="เปิดเมนู"
                    onclick="(function(){var s=document.getElementById('cfpSidebar'),o=document.getElementById('shellOverlay');if(s){s.classList.toggle('show');}if(o){o.classList.toggle('show');}})()">
                <i class="bi bi-list"></i>
            </button>
            <span class="shell-brand-mini">ระบบบริหารจัดการคาร์บอนองค์กร</span>
        </div>

        <!-- Loading indicator -->
        <div id="frameLoader">
            <span class="loader-dot"></span>
            <span class="loader-dot"></span>
            <span class="loader-dot"></span>
        </div>

        <!-- CONTENT IFRAME -->
        <iframe id="cfpFrame"
                src="<?php echo htmlspecialchars($startPage); ?>"
                title="เนื้อหาระบบ"
                allowfullscreen>
        </iframe>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    /* ─── Elements ─── */
    var frame     = document.getElementById('cfpFrame');
    var loader    = document.getElementById('frameLoader');
    var sidebar   = document.getElementById('cfpSidebar');
    var overlay   = document.getElementById('shellOverlay');
    var hamburger = document.getElementById('btnHamburger');
    var navScroll = document.getElementById('navScroll');

    /* ─── Keys ─── */
    var SCROLL_KEY = 'cfp_sidebar_scroll';
    var PAGE_KEY   = 'cfp_current_page';

    /* =========================================================
       1. LOADING OVERLAY
       ========================================================= */
    function showLoader() { if (loader) { loader.classList.add('show'); } }
    function hideLoader() { if (loader) { loader.classList.remove('show'); } }

    if (frame) {
        frame.addEventListener('load', function () {
            hideLoader();
            try {
                var iUrl = frame.contentWindow.location.href;
                if (iUrl && iUrl !== 'about:blank') {
                    history.replaceState({ cfpHref: iUrl }, '', '?page=' + encodeURIComponent(iUrl));
                    sessionStorage.setItem(PAGE_KEY, iUrl);
                }
            } catch (e) {}
        });
    }

    /* =========================================================
       2. SCROLL — app.php จัดการทั้งหมด (sidebar.php ไม่ยุ่ง)
       ========================================================= */
    function saveSidebarScroll() {
        if (navScroll) { sessionStorage.setItem(SCROLL_KEY, navScroll.scrollTop); }
    }

    if (navScroll) {
        var saved = sessionStorage.getItem(SCROLL_KEY);
        if (saved !== null) { navScroll.scrollTop = parseInt(saved, 10); }
        navScroll.addEventListener('scroll', saveSidebarScroll);
    }
    window.addEventListener('beforeunload', saveSidebarScroll);

    /* =========================================================
       3. NAVIGATE
       ========================================================= */
    function navigateTo(href) {
        if (!href || href === '#') { return; }
        saveSidebarScroll();
        showLoader();
        frame.src = href;
        history.pushState({ cfpHref: href }, '', '?page=' + encodeURIComponent(href));
    }

    /* อัปเดต active link — เลื่อนเฉพาะเมื่อ link อยู่นอก viewport */
    function setActiveLink(link) {
        document.querySelectorAll('.cfp-sidebar a.nav-link, .cfp-sidebar a.nav-dashboard-pill')
            .forEach(function (l) { l.classList.remove('active'); });
        if (!link) { return; }
        link.classList.add('active');
        if (navScroll) {
            var t = link.offsetTop;
            var b = t + link.offsetHeight;
            var st = navScroll.scrollTop;
            var sb = st + navScroll.clientHeight;
            if (t < st) { navScroll.scrollTop = t - 8; }
            else if (b > sb) { navScroll.scrollTop = b - navScroll.clientHeight + 8; }
        }
    }

    /* =========================================================
       4. BIND NAV LINKS
       ========================================================= */
    function bindNavLinks() {
        var links = document.querySelectorAll('.cfp-sidebar a.nav-link, .cfp-sidebar a.nav-dashboard-pill');
        links.forEach(function (link) {
            if (link.getAttribute('data-cfp-logout')) { return; }
            link.addEventListener('click', function (e) {
                var href = link.getAttribute('href');
                if (!href || href === '#' || href.indexOf('javascript') === 0) { return; }
                e.preventDefault();
                setActiveLink(link);
                if (window.innerWidth < 768) {
                    if (sidebar) { sidebar.classList.remove('show'); }
                    if (overlay) { overlay.classList.remove('show'); }
                }
                navigateTo(href);
            });
        });
    }
    bindNavLinks();

    /* =========================================================
       5. HAMBURGER — bind หลัง DOM ready เสมอ
       ========================================================= */
    function bindHamburger() {
        var hbtn = document.getElementById('btnHamburger');
        var sb   = document.getElementById('cfpSidebar');
        var ov   = document.getElementById('shellOverlay');
        if (hbtn && sb) {
            hbtn.addEventListener('click', function (e) {
                e.stopPropagation();
                sb.classList.toggle('show');
                if (ov) { ov.classList.toggle('show'); }
            });
        }
        if (ov) {
            ov.addEventListener('click', function () {
                if (sb) { sb.classList.remove('show'); }
                ov.classList.remove('show');
            });
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindHamburger);
    } else {
        bindHamburger();
    }

    /* =========================================================
       6. BROWSER BACK/FORWARD
       ========================================================= */
    window.addEventListener('popstate', function (e) {
        if (e.state && e.state.cfpHref) {
            showLoader();
            frame.src = e.state.cfpHref;
        }
    });

    /* =========================================================
       7. IFRAME MESSAGE
       ========================================================= */
    window.addEventListener('message', function (e) {
        /* รับเฉพาะ same host (localhost) — ไม่ block port ต่างกัน */
        var data = e.data;
        if (!data || typeof data !== 'object') { return; }

        /* Toggle sidebar จาก hamburger ใน iframe */
        if (data.type === 'cfp_toggle_sidebar') {
            var sb = document.getElementById('cfpSidebar');
            var ov = document.getElementById('shellOverlay');
            if (sb) { sb.classList.toggle('show'); }
            if (ov) { ov.classList.toggle('show'); }
            return;
        }

        if (data.type === 'cfp_navigate') {
            navigateTo(data.href);
            if (data.page) {
                var found = null;
                document.querySelectorAll('.cfp-sidebar a.nav-link').forEach(function (l) {
                    if ((l.getAttribute('data-page') || '') === data.page) { found = l; }
                });
                setActiveLink(found);
            }
        }
        if (data.type === 'cfp_active_page') {
            var matched = null;
            document.querySelectorAll('.cfp-sidebar a.nav-link').forEach(function (l) {
                var base = (l.getAttribute('href') || '').split('/').pop().replace('.php','');
                if (base === data.page) { matched = l; }
            });
            setActiveLink(matched);
        }
    });

    /* =========================================================
       8. RESIZE + MOBILE MODE CLASS
       ========================================================= */
    function applyMobileMode() {
        if (window.innerWidth < 768) {
            document.body.classList.add('mobile-mode');
        } else {
            document.body.classList.remove('mobile-mode');
            var sb = document.getElementById('cfpSidebar');
            var ov = document.getElementById('shellOverlay');
            if (sb) { sb.classList.remove('show'); }
            if (ov) { ov.classList.remove('show'); }
        }
    }
    applyMobileMode();

    window.addEventListener('resize', applyMobileMode);

}());
</script>
</body>
</html>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hamburger Debug Test</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { margin:0; font-family: sans-serif; background:#f0f0f0; }

/* จำลอง cfp-sidebar */
.cfp-sidebar {
    background: #fff;
    width: 280px;
    height: 100vh;
    position: fixed;
    top: 0; left: 0;
    z-index: 1050;
    box-shadow: 2px 0 16px rgba(0,0,0,0.1);
    padding: 20px;
    transition: transform 0.3s ease;
}
@media (max-width: 767px) {
    .cfp-sidebar {
        transform: translateX(-100%);
    }
    .cfp-sidebar.show {
        transform: translateX(0);
    }
}

/* overlay */
.cfp-sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.4);
    z-index: 1040;
}
.cfp-sidebar-overlay.show { display: block; }

/* topbar */
.topbar {
    position: fixed;
    top: 0; left: 0; right: 0;
    height: 56px;
    background: linear-gradient(90deg,#6ECDD8,#B8EDE0);
    display: flex;
    align-items: center;
    padding: 0 16px;
    gap: 12px;
    z-index: 1030;
}
.topbar button {
    background: none; border: none;
    font-size: 1.6rem; cursor: pointer; color: #1B4A52;
    display: flex; align-items: center;
}
.content {
    margin-top: 56px;
    padding: 20px;
}
#debugLog {
    background:#1B3A4A; color:#A8D8C0;
    padding:12px; border-radius:8px;
    font-size:0.85rem; font-family:monospace;
    min-height:100px; white-space:pre-wrap;
}
</style>
</head>
<body>

<!-- Topbar ที่จำลอง (ถ้าอยู่ใน iframe จะต้องใช้ postMessage) -->
<div class="topbar">
    <button id="btnHamburger" aria-label="Toggle Sidebar">
        <i class="bi bi-list"></i>
    </button>
    <span style="font-weight:600;color:#1B4A52;">Hamburger Test Page</span>
</div>

<!-- Sidebar -->
<div class="cfp-sidebar" id="cfpSidebar">
    <h5 style="color:#2AABB8;">✅ Sidebar เปิดได้แล้ว!</h5>
    <p>ถ้าเห็นหน้านี้แสดงว่า hamburger ทำงานถูกต้อง</p>
    <hr>
    <p style="font-size:0.8rem;color:#666;">กด overlay หรือปุ่ม ≡ อีกครั้งเพื่อปิด</p>
</div>

<!-- Overlay -->
<div class="cfp-sidebar-overlay" id="sidebarOverlay"></div>

<!-- Content -->
<div class="content">
    <h4>Debug Log</h4>
    <div id="debugLog">กด hamburger ≡ เพื่อทดสอบ...\n</div>
    <hr>
    <h5>ข้อมูล browser</h5>
    <div id="browserInfo" style="font-size:0.85rem;"></div>
</div>

<script>
var log = document.getElementById('debugLog');
function addLog(msg) {
    log.textContent += new Date().toLocaleTimeString() + ' — ' + msg + '\n';
}

var sidebar = document.getElementById('cfpSidebar');
var overlay = document.getElementById('sidebarOverlay');
var btn     = document.getElementById('btnHamburger');

// แสดง browser info
var info = document.getElementById('browserInfo');
var mq767 = window.matchMedia('(max-width: 767px)').matches;
var cs0   = window.getComputedStyle(sidebar);
info.innerHTML =
    '<b>window.innerWidth:</b> ' + window.innerWidth + 'px<br>' +
    '<b>devicePixelRatio:</b> ' + window.devicePixelRatio + '<br>' +
    '<b>matchMedia max-width:767px:</b> ' + mq767 + '<br>' +
    '<b>sidebar initial transform:</b> ' + cs0.transform + '<br>' +
    '<b>userAgent:</b> ' + navigator.userAgent.substring(0,80) + '...<br>' +
    '<b>sidebar found:</b> ' + (sidebar ? 'YES' : 'NO') + '<br>' +
    '<b>overlay found:</b> ' + (overlay ? 'YES' : 'NO') + '<br>' +
    '<b>btn found:</b> ' + (btn ? 'YES' : 'NO');

addLog('Page loaded. width=' + window.innerWidth + 'px');
addLog('sidebar element: ' + (sidebar ? 'found' : 'NOT FOUND'));
addLog('btn element: ' + (btn ? 'found' : 'NOT FOUND'));

if (btn) {
    btn.addEventListener('click', function(e) {
        addLog('Hamburger clicked!');
        if (!sidebar) { addLog('ERROR: sidebar is null'); return; }

        var before = sidebar.classList.contains('show');
        sidebar.classList.toggle('show');
        overlay.classList.toggle('show');
        var after = sidebar.classList.contains('show');

        addLog('sidebar.show: ' + before + ' → ' + after);

        // ตรวจ computed style
        var cs = window.getComputedStyle(sidebar);
        addLog('computed transform: ' + cs.transform);
        addLog('computed z-index: ' + cs.zIndex);
    });
    addLog('click listener bound OK');
} else {
    addLog('ERROR: btn not found — cannot bind');
}

if (overlay) {
    overlay.addEventListener('click', function() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
        addLog('Overlay clicked — sidebar closed');
    });
}
</script>
</body>
</html>
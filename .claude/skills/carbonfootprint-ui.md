# Carbon Footprint UI Skill

ระบบ Carbon Footprint ภาษาไทย — PHP 8 + MSSQL (sqlsrv) + Bootstrap 5

## ชื่อระบบ

| บริบท | ชื่อที่ใช้ |
|-------|----------|
| ชื่อเต็ม (ภาษาอังกฤษ) | **TRUBB Greenhouse Gas Management System** |
| ชื่อเต็ม (ภาษาไทย) | **ระบบบริหารจัดการการปล่อยก๊าซเรือนกระจก** |
| ชื่อย่อ / Sidebar | **CFP** |
| Login Page | ระบบบริหารจัดการคาร์บอนองค์กร |
| Topbar / Title | ระบบบริหารจัดการคาร์บอนองค์กร |
| รายงาน / เอกสาร | ระบบบริหารจัดการคาร์บอนองค์กร (ISO 14064-1) |
| Browser Tab `<title>` | `{ชื่อหน้า} — ระบบบริหารจัดการคาร์บอนองค์กร` |

ใช้ชื่อนี้สม่ำเสมอทุก component ห้ามใช้ชื่ออื่น

## Design-First Workflow — ปฏิบัติทุกครั้ง

**เมื่อได้รับคำขอออกแบบ UI ใดๆ ต้องทำตามลำดับนี้เสมอ:**

```
1. แสดง Design Preview (Mockup) ก่อน — ใช้ visualize tool
   → แสดงหลายแบบ (3-5 แบบ) พร้อม animate
   → มีปุ่มให้เลือกแต่ละแบบ

2. รอให้ผู้ใช้เลือกแบบที่ต้องการ

3. Generate Code จริง — หลังได้รับการยืนยันเท่านั้น
```

**ห้าม generate code ทันทีโดยไม่แสดง design preview ก่อน**
ยกเว้น: ผู้ใช้ระบุชัดเจนว่า "generate code เลย" หรือ "ไม่ต้องดู design"

## Font Rule — Prompt ทั้งระบบ

ใช้ Font "Prompt" เป็นหลักทุกที่ — ห้ามใช้ font อื่น

```html
<!-- โหลดใน <head> ทุกไฟล์ -->
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
```

```css
/* CSS ทุกไฟล์ */
body {
    font-family: 'Prompt', sans-serif;
}

/* ทุก component ที่มี font-family ต้องระบุ Prompt เสมอ */
.btn, input, select, textarea, .form-control, .form-select {
    font-family: 'Prompt', sans-serif;
}

/* หน้าไหนมีปุ่มเพิ่ม ให้ใช้ class btn-cfp-add*/
```

```javascript
/* SweetAlert2 ต้องระบุ font */
Swal.fire({
    customClass: { popup: 'font-prompt' },
    ...
});
```

```css
/* เพิ่มใน cfp-theme.css */
.font-prompt { font-family: 'Prompt', sans-serif !important; }
```

## Responsive Design — รองรับทุกขนาดหน้าจอ

ระบบต้องใช้งานได้บน **ทุก device** โดย design อาจเปลี่ยนตามขนาดหน้าจอได้

### Breakpoints (Bootstrap 5)

| Breakpoint | ขนาด | Device | แนวทาง |
|-----------|------|--------|--------|
| `xs` | < 576px | มือถือ Portrait | Single column, ซ่อน sidebar |
| `sm` | 576–767px | มือถือ Landscape | Single column |
| `md` | 768–991px | Tablet | Sidebar collapse, 2 col |
| `lg` | 992–1199px | Laptop | Sidebar ปกติ, layout เต็ม |
| `xl` | ≥ 1200px | Desktop/Monitor | Full layout |

### Sidebar — Responsive Pattern

```css
/* Mobile: ซ่อน sidebar ใช้ hamburger แทน */
@media (max-width: 767px) {
    .cfp-sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
        z-index: 1050;
    }
    .cfp-sidebar.show {
        transform: translateX(0);
    }
    .cfp-main {
        margin-left: 0 !important;
    }
    .cfp-sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.4);
        z-index: 1040;
    }
    .cfp-sidebar-overlay.show {
        display: block;
    }
}

/* Tablet: sidebar แคบลง แสดงแค่ icon */
@media (min-width: 768px) and (max-width: 991px) {
    .cfp-sidebar {
        width: 64px;
    }
    .cfp-sidebar .nav-link span,
    .cfp-sidebar .brand div,
    .cfp-sidebar .sidebar-user,
    .cfp-sidebar .nav-section {
        display: none;
    }
    .cfp-sidebar .nav-link {
        justify-content: center;
        padding: 0.6rem;
        margin: 2px 6px;
    }
    .cfp-sidebar .nav-link .bi {
        width: auto;
        font-size: 1.2rem;
    }
    .cfp-main {
        margin-left: 64px;
    }
}
```

### Topbar — Hamburger บน Mobile

```php
/* includes/topbar.php — เพิ่ม hamburger button */
<div class="cfp-topbar">
  <div class="d-flex align-items-center gap-2">
    <!-- Hamburger: แสดงเฉพาะ mobile/tablet -->
    <button class="btn p-1 d-lg-none" id="sidebarToggle" aria-label="เปิดเมนู">
      <i class="bi bi-list" style="font-size:1.4rem;color:var(--cfp-text);"></i>
    </button>
    <div class="page-title">
      <i class="bi bi-<?php echo $pageIcon ?? 'grid-1x2'; ?>"></i>
      <?php echo $pageTitle ?? 'Carbon Footprint'; ?>
    </div>
  </div>
  ...
</div>

<!-- Overlay สำหรับปิด sidebar บน mobile -->
<div class="cfp-sidebar-overlay" id="sidebarOverlay"></div>

<script>
document.getElementById('sidebarToggle').addEventListener('click', function() {
    document.querySelector('.cfp-sidebar').classList.toggle('show');
    document.getElementById('sidebarOverlay').classList.toggle('show');
});
document.getElementById('sidebarOverlay').addEventListener('click', function() {
    document.querySelector('.cfp-sidebar').classList.remove('show');
    this.classList.remove('show');
});
</script>
```

### Layout — แนวทางแต่ละหน้า

```css
/* KPI Cards: 4 col → 2 col → 1 col */
.cfp-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
}
@media (max-width: 991px) {
    .cfp-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 575px) {
    .cfp-kpi-grid { grid-template-columns: 1fr; }
}

/* Table: scroll แนวนอนบน mobile */
.cfp-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* Form: 2 col → 1 col บน mobile */
@media (max-width: 767px) {
    .row.g-3 > [class*="col-md"] {
        grid-column: span 12 !important;
    }
}
```

### Login Page — Responsive

| หน้าจอ | แบบ B (Split) | แบบ E (Hero) |
|--------|--------------|-------------|
| Desktop (lg+) | แสดงทั้ง 2 panel | Hero + Form card |
| Tablet (md) | ซ่อน left panel | Hero เล็กลง + Form |
| Mobile (xs-sm) | แค่ form card กลางหน้า | Hero สั้น + Form เต็มความกว้าง |

```css
/* Login B — ซ่อน left panel บน mobile */
@media (max-width: 767px) {
    .b-left { display: none; }
    .b-right { padding: 2rem 1.5rem; }
}
/* Login E — Hero สั้นลงบน mobile */
@media (max-width: 575px) {
    .e-hero { padding: 20px 16px 44px; }
    .e-hero-title { font-size: 16px; }
    .e-kpi-row { display: none; }
    .e-2col { grid-template-columns: 1fr; }
    .e-form-area { padding: 0 12px 20px; }
}
```

### Design Preview — แสดง Responsive ด้วย

เวลาแสดง design mockup ให้แสดงทั้ง **Desktop + Mobile** คู่กันเสมอ เพื่อให้เห็นว่า layout เปลี่ยนอย่างไร

## Design System

อ่านรายละเอียดเพิ่มเติมใน `references/design-system.md`

## Components

| Component | Reference File |
|-----------|---------------|
| Form (input, select, datepicker) | `references/form.md` |
| Table + DataTables | `references/table.md` |
| Modal / Dialog | `references/modal.md` |
| Dashboard Card / KPI Widget | `references/dashboard.md` |
| Navigation / Sidebar | `references/navigation.md` |
| Login + Auth + Role | `references/auth.md` |
| Workflow / Approval Status | `references/workflow.md` |

## Alert & Notification — SweetAlert2 + Toast

ใช้ **2 แบบ แต่คนละงาน** — ห้ามใช้สลับกัน

| สถานการณ์ | ใช้ |
|-----------|-----|
| ลบข้อมูล, อนุมัติ, ปฏิเสธ | **SweetAlert2** — ต้องรอ user confirm |
| บันทึกสำเร็จ, แก้ไขสำเร็จ | **Toast** — แจ้งแล้วหายไปเอง |
| Error จาก server | **Toast สีแดง** |
| Session หมดอายุ / สำคัญมาก | **SweetAlert2** |

### SweetAlert2 — ยืนยันก่อนลบ/อนุมัติ

```javascript
// ยืนยันลบ
function confirmDelete(id) {
    Swal.fire({
        title: 'ยืนยันการลบ?',
        text: 'ไม่สามารถกู้คืนข้อมูลได้',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC3545',
        cancelButtonColor: '#6C757D',
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true
    }).then(function(result) {
        if (result.isConfirmed) {
            window.location.href = 'delete.php?id=' + id;
        }
    });
}

// ยืนยันอนุมัติ
function confirmApprove(id) {
    Swal.fire({
        title: 'ยืนยันการอนุมัติ?',
        text: 'ข้อมูลจะถูกล็อคและไม่สามารถแก้ไขได้',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2AABB8',
        cancelButtonColor: '#6C757D',
        confirmButtonText: 'อนุมัติ',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true
    }).then(function(result) {
        if (result.isConfirmed) {
            window.location.href = 'approve.php?id=' + id;
        }
    });
}
```

### Toast — แจ้งผลบันทึก (Bootstrap 5 built-in)

**HTML — วางก่อน `</body>` ทุกหน้าที่มี form:**
```html
<!-- Toast Container -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:9999;">
    <div id="toastSuccess" class="toast align-items-center text-white border-0"
         style="background:#4CAF50;" role="alert">
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2">
                <i class="bi bi-check-circle-fill"></i>
                <span id="toastMsg">บันทึกข้อมูลเรียบร้อย</span>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto"
                    data-bs-dismiss="toast"></button>
        </div>
    </div>
    <div id="toastError" class="toast align-items-center text-white border-0"
         style="background:#DC3545;" role="alert">
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span id="toastErrMsg">เกิดข้อผิดพลาด</span>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto"
                    data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>
```

**JavaScript — เรียกใช้:**
```javascript
function showToast(msg, isError) {
    if (isError === undefined) { isError = false; }
    var id  = isError ? 'toastError' : 'toastSuccess';
    var mid = isError ? 'toastErrMsg' : 'toastMsg';
    document.getElementById(mid).textContent = msg;
    var toast = new bootstrap.Toast(document.getElementById(id), { delay: 3000 });
    toast.show();
}

// ใช้งาน
showToast('บันทึกข้อมูลเรียบร้อย');
showToast('เกิดข้อผิดพลาด กรุณาลองใหม่', true);
```

**PHP — trigger Toast หลัง redirect:**
```php
// หลัง INSERT/UPDATE สำเร็จ
$_SESSION['toast'] = array('msg' => 'บันทึกข้อมูลเรียบร้อย', 'type' => 'success');
header('Location: scope1.php');
exit;
```
```javascript
// รับค่าจาก PHP session ใน script block
<?php if (!empty($_SESSION['toast'])) { ?>
    showToast(
        '<?php echo htmlspecialchars($_SESSION['toast']['msg']); ?>',
        <?php echo $_SESSION['toast']['type'] === 'error' ? 'true' : 'false'; ?>
    );
    <?php unset($_SESSION['toast']); ?>
<?php } ?>
```

### CDN ที่ต้องเพิ่ม

```html
<!-- SweetAlert2 — เพิ่มใน <head> เฉพาะหน้าที่มี confirm action -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Toast ใช้ Bootstrap 5 ที่มีอยู่แล้ว ไม่ต้องเพิ่ม -->
```

## HTML Head Template (ใส่ทุกไฟล์)

```html
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Carbon Footprint</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="/carbonfootprint/assets/css/cfp-theme.css" rel="stylesheet">
  <!-- SweetAlert2 เพิ่มเฉพาะหน้าที่มี confirm action -->
  <!-- <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script> -->
</head>
```

## PHP MSSQL Pattern (PHP 8 + sqlsrv)

อ่านใน `references/db.md` — ห้าม hardcode credential ในไฟล์ใดๆ ให้ include config/db.php เสมอ

## Role & Permission

อ่านใน `references/auth.md`

| Role ID | ชื่อ | สิทธิ์ |
|---------|------|--------|
| 1 | DataEntry | บันทึก/แก้ไขข้อมูลของหน่วยงานตัวเอง |
| 2 | Reviewer | ตรวจสอบ + comment ข้อมูลทุกหน่วยงาน |
| 3 | Approver | อนุมัติ/ปฏิเสธ รายเดือน |
| 4 | Admin | จัดการ Master data + User |
| 5 | Viewer | ดูรายงานอย่างเดียว |

ทุกไฟล์ต้อง include `includes/auth_check.php` บรรทัดแรก
# Design System — Carbon Footprint

## Color Palette — Ice Teal Theme (อัปเดต)

> อัปเดตจาก Wave Mint-Blue → **Ice Teal** ตาม design ที่อนุมัติแล้ว
> **ห้ามใช้สีนอกตารางนี้** เพื่อความสม่ำเสมอทั่วระบบ

| ชื่อ | Hex | บทบาท |
|------|-----|--------|
| **Teal (Primary)** | `#2AABB8` | ปุ่มหลัก, active state, icon, link, focus ring |
| **Teal Dark** | `#1A8898` | ปุ่ม hover, sidebar active border |
| **Teal Light** | `#5CC8D8` | gradient, secondary accent |
| **Ice Start** | `#6ECDD8` | Topbar gradient ซ้าย |
| **Ice Mid** | `#85D8E2` | Topbar gradient กลาง |
| **Ice End** | `#B8EDE0` | Topbar gradient ขวา |
| **Mint** | `#A8D8C0` | Chart S3, decoration |
| **Ice BG** | `#EEF6F8` | Page background |
| **Card BG** | `#FFFFFF` | Card, Sidebar, Modal |
| **Hover BG** | `#E4F6F8` | Sidebar hover, row hover |
| **Border** | `#D0E8EE` | Card border, input border, divider |
| **Border Strong** | `#B8D8E4` | Table border, focus highlight |
| **Text Dark** | `#1A3A44` | Body text, heading |
| **Text NavDark** | `#1B4A52` | Topbar title/icon บน bg อ่อน |
| **Text Mid** | `#4A7A88` | Sub-label, table cell |
| **Text Muted** | `#7AAAB8` | Placeholder, caption |
| **Warning** | `#F2A541` | Pending badge, elevation banner |
| **Danger** | `#E05050` | Delete, reject, error |
| **Success** | `#43A047` | บันทึกสำเร็จ, approved |

## Scope Color Coding (ใช้สม่ำเสมอทั่วระบบ)

| Scope | Chart | KPI icon bg | KPI icon color |
|-------|-------|-------------|----------------|
| Scope 1 — Direct | `#2AABB8` | `#E4F7F9` | `#2AABB8` |
| Scope 2 — Energy | `#5CC8D8` | `#FFF3E0` | `#F59E0B` |
| Scope 3 — Indirect | `#A8D8C0` | `#F3EEFF` | `#8B5CF6` |
| รวม Total | — | `#E8F5E9` | `#43A047` |

## Login Page — Blob Background Pattern (Style E — Hero Wave)

```
Background: #C8EAF2 (base teal-ice)
Wave layers: SVG path fill rgba(255,255,255,0.07–0.15) ซ้อนกัน 3-5 ชั้น
Left panel: gradient teal + wave SVG + text สีเข้ม #1A3A44
Form card: ขาวบริสุทธิ์ลอยกลาง, border-radius 16px
```

## Topbar — Ice Teal Gradient + Wave SVG V2 (อัปเดต)

```css
/* CSS */
.cfp-topbar {
  background: linear-gradient(110deg, #6ECDD8 0%, #85D8E2 30%, #9DE5DC 60%, #B8EDE0 100%);
  height: 58px; overflow: hidden;
}
/* title/icon สีเข้ม #1B4A52 (ไม่ใช่ขาว เพราะ bg อ่อน) */
.cfp-topbar .page-title { color: #1B4A52; }
.topbar-subtitle         { color: rgba(27,74,82,0.62); }
/* buttons frosted glass */
.topbar-ic-round { background: rgba(255,255,255,0.28); border: 1px solid rgba(255,255,255,0.45); color: #1B4A52; }
/* avatar ขาวขุ่น ตัวอักษร teal */
.topbar-avatar   { background: rgba(255,255,255,0.88); color: var(--cfp-primary); }
```

```html
<!-- SVG Wave V2 — ฝังใน .cfp-topbar (บน+ล่าง 5 ชั้น) -->
<svg aria-hidden="true"
     style="position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:0;"
     viewBox="0 0 1200 58" preserveAspectRatio="none">
  <path d="M0,32 C200,14 400,50 600,30 C800,10 1000,46 1200,26 L1200,58 L0,58 Z" fill="rgba(255,255,255,0.07)"/>
  <path d="M0,42 C180,24 360,56 600,38 C840,20 1020,52 1200,36 L1200,58 L0,58 Z" fill="rgba(255,255,255,0.10)"/>
  <path d="M0,52 C200,36 420,60 660,46 C900,30 1060,56 1200,46 L1200,58 L0,58 Z" fill="rgba(255,255,255,0.14)"/>
  <path d="M0,0 C220,18 480,-4 740,14 C960,28 1100,6 1200,16 L1200,0 Z"          fill="rgba(255,255,255,0.07)"/>
  <path d="M0,0 C160,10 360,0 600,8 C840,14 1060,2 1200,8 L1200,0 Z"             fill="rgba(255,255,255,0.05)"/>
</svg>
```

```css
/* Responsive topbar */
@media (min-width:768px) and (max-width:991px) { .cfp-topbar { height:52px; } }
@media (max-width:575px) {
  .cfp-topbar { height:48px; }
  .topbar-subtitle    { display:none; }
  .topbar-hide-mobile { display:none !important; }
}
```

## CSS Theme File (`/assets/css/cfp-theme.css`)

```css
:root {
  /* === Wave Mint-Blue Theme === */
  --cfp-primary:        #2AABB8;   /* Teal — ปุ่ม, active, focus */
  --cfp-primary-dark:   #1A8898;   /* Teal dark — hover */
  --cfp-primary-light:  #5CC8D8;   /* Sky — gradient tip */
  --cfp-mint:           #A8D8C0;   /* Mint — gradient start */
  --cfp-powder:         #A8D4E8;   /* Powder blue — gradient end */
  --cfp-lavender:       #C8D8EC;   /* Lavender — far right gradient */
  --cfp-white:          #FFFFFF;
  --cfp-bg:             #EEF6F8;   /* Ice — page background */
  --cfp-card:           #FFFFFF;
  --cfp-border:         #D0E8EE;
  --cfp-border-strong:  #B8D8E4;
  --cfp-hover:          #E4F6F8;
  --cfp-text:           #1A3A44;
  --cfp-text-mid:       #4A7A88;
  --cfp-text-muted:     #7AAAB8;
  --cfp-text-light:     #B0CED8;
  --cfp-warning:        #F2A541;
  --cfp-danger:         #E05050;

  /* Scope gradients */
  --cfp-scope1-from:    #5CC8A0;
  --cfp-scope1-to:      #2AABB8;
  --cfp-scope2-from:    #2AABB8;
  --cfp-scope2-to:      #5CC8D8;
  --cfp-scope3-from:    #7AC8D8;
  --cfp-scope3-to:      #A8D4E8;

  --cfp-sidebar-w:      240px;
}

body {
  font-family: 'Prompt', sans-serif;
  background: var(--cfp-bg);
  color: var(--cfp-text);
  font-size: 0.9rem;
}

/* ===== SIDEBAR — ขาวสะอาด ===== */
.cfp-sidebar {
  background: var(--cfp-white);
  border-right: 1px solid var(--cfp-border);
  min-height: 100vh;
  width: var(--cfp-sidebar-w);
  position: fixed;
  top: 0; left: 0;
  z-index: 1000;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}
.cfp-sidebar .brand {
  padding: 1rem 1rem 0.9rem;
  border-bottom: 1px solid var(--cfp-border);
  color: var(--cfp-text);
  font-size: 0.88rem;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 10px;
  text-decoration: none;
  letter-spacing: -0.01em;
}
.cfp-sidebar .brand .brand-icon {
  width: 32px; height: 32px;
  border-radius: 9px;
  background: var(--cfp-primary);
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; flex-shrink: 0;
  box-shadow: 0 3px 8px rgba(42,171,184,0.3);
}
.cfp-sidebar .brand .brand-sub {
  font-size: 0.62rem; font-weight: 400;
  color: var(--cfp-text-muted);
  display: block; line-height: 1; margin-top: 2px;
}
.cfp-sidebar .sidebar-user {
  margin: 8px 10px;
  padding: 8px 10px;
  background: var(--cfp-bg);
  border: 1px solid var(--cfp-border);
  border-radius: 8px;
  display: flex; align-items: center; gap: 8px;
}
.cfp-sidebar .sidebar-user .user-avatar {
  width: 26px; height: 26px; border-radius: 50%;
  background: var(--cfp-primary); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.62rem; font-weight: 700; flex-shrink: 0;
}
.cfp-sidebar .nav-section {
  font-size: 0.6rem;
  color: var(--cfp-text-light);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  padding: 0.85rem 1rem 0.25rem;
}
.cfp-sidebar .nav-link {
  color: var(--cfp-text-mid);
  padding: 0.5rem 0.9rem;
  font-size: 0.82rem;
  display: flex; align-items: center; gap: 9px;
  transition: background 0.14s, color 0.14s;
  text-decoration: none;
  border-right: 3px solid transparent;
}
.cfp-sidebar .nav-link:hover {
  background: var(--cfp-hover);
  color: var(--cfp-primary);
}
.cfp-sidebar .nav-link.active {
  background: #E4F6F8;
  color: var(--cfp-text);
  font-weight: 600;
  border-right-color: var(--cfp-primary);
}
.cfp-sidebar .nav-link.active .bi,
.cfp-sidebar .nav-link:hover .bi {
  color: var(--cfp-primary);
}
.cfp-sidebar .nav-link .bi {
  font-size: 0.95rem; width: 18px; flex-shrink: 0;
  color: var(--cfp-text-light);
}
.cfp-sidebar .sidebar-footer {
  margin-top: auto;
  padding: 0.4rem 0 0.6rem;
  border-top: 1px solid var(--cfp-border);
}

/* ===== TOPBAR — Wave Gradient ===== */
.cfp-topbar {
  background: linear-gradient(100deg,
    #A8D8C0 0%, #5CC8D8 38%, #A8D4E8 72%, #C8D8EC 100%
  );
  border-bottom: none;
  padding: 0 1.5rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0;
  z-index: 900;
  height: 52px;
  box-shadow: 0 2px 12px rgba(42,100,130,0.14);
  overflow: hidden;
}
/* Wave overlays */
.cfp-topbar::before {
  content: '';
  position: absolute;
  top: -16px; left: 18%; right: -6%;
  height: 60px; background: rgba(255,255,255,0.13);
  border-radius: 50%; transform: rotate(-4deg);
  pointer-events: none;
}
.cfp-topbar::after {
  content: '';
  position: absolute;
  top: -8px; left: -5%; right: 44%;
  height: 48px; background: rgba(255,255,255,0.09);
  border-radius: 50%; transform: rotate(5deg);
  pointer-events: none;
}
.cfp-topbar .page-title {
  font-size: 0.92rem; font-weight: 700;
  color: #1A4050;
  text-shadow: 0 1px 3px rgba(255,255,255,0.35);
  display: flex; align-items: center; gap: 8px;
  position: relative;
}
.cfp-topbar .page-title .bi { color: #1A5060; font-size: 1rem; }
.cfp-topbar .topbar-ic {
  width: 30px; height: 30px; border-radius: 8px;
  background: rgba(255,255,255,0.32);
  backdrop-filter: blur(4px);
  border: 1px solid rgba(255,255,255,0.5);
  display: flex; align-items: center; justify-content: center;
  color: #1A5060; font-size: 0.85rem; cursor: pointer;
  transition: all 0.14s; text-decoration: none;
}
.cfp-topbar .topbar-ic:hover {
  background: rgba(255,255,255,0.52); color: #1A3A44;
}

/* Breadcrumb Subbar — ใต้ topbar */
.cfp-breadcrumb {
  background: #fff;
  border-bottom: 1px solid var(--cfp-border);
  padding: 6px 1.5rem;
  font-size: 0.76rem;
  color: var(--cfp-text-muted);
  display: flex; align-items: center; gap: 6px;
  box-shadow: 0 1px 4px rgba(42,100,130,0.05);
}
.cfp-breadcrumb strong { color: var(--cfp-text); }

/* ===== MAIN CONTENT ===== */
.cfp-main {
  margin-left: var(--cfp-sidebar-w);
  min-height: 100vh;
  display: flex; flex-direction: column;
}
.cfp-content {
  padding: 1.5rem;
  background: var(--cfp-bg);
  flex: 1;
}

/* ===== CARDS ===== */
.cfp-card {
  background: var(--cfp-card);
  border: 1px solid var(--cfp-border);
  border-radius: 12px;
  padding: 1.25rem;
  margin-bottom: 1rem;
  box-shadow: 0 1px 5px rgba(42,100,130,0.06);
}
.cfp-card-header {
  font-size: 0.9rem; font-weight: 700;
  color: var(--cfp-text);
  padding-bottom: 0.75rem; margin-bottom: 0.75rem;
  border-bottom: 1px solid var(--cfp-border);
  display: flex; align-items: center; justify-content: space-between;
}
.cfp-card-header .bi { color: var(--cfp-primary); }
.cfp-card-value {
  font-size: 1.6rem; font-weight: 700;
  color: var(--cfp-text); line-height: 1.2;
  letter-spacing: -0.02em;
}
.cfp-card-unit {
  font-size: 0.72rem; color: var(--cfp-text-muted);
}

/* ===== BUTTONS ===== */
.btn-cfp-primary {
  background: var(--cfp-primary);
  border-color: var(--cfp-primary);
  color: #fff;
  font-family: 'Prompt', sans-serif;
  border-radius: 8px;
}
.btn-cfp-primary:hover, .btn-cfp-primary:focus {
  background: var(--cfp-primary-dark);
  border-color: var(--cfp-primary-dark);
  color: #fff;
  box-shadow: 0 4px 12px rgba(42,171,184,0.3);
}
.btn-cfp-success {
  background: var(--cfp-primary);
  border-color: var(--cfp-primary);
  color: #fff;
  font-family: 'Prompt', sans-serif;
  border-radius: 8px;
}
.btn-cfp-success:hover, .btn-cfp-success:focus {
  background: var(--cfp-primary-dark);
  border-color: var(--cfp-primary-dark);
  color: #fff;
}

/* ===== SweetAlert2 ===== */
/* ใช้ confirmButtonColor: '#2AABB8' ทุกที่ */
.font-prompt { font-family: 'Prompt', sans-serif !important; }

/* ===== BADGES / STATUS ===== */
.badge-draft     { background: #F1F5F5 !important; color: #5A8A98 !important; border: 1px solid #D0E8EE !important; }
.badge-submitted { background: #FFF8E8 !important; color: #8A6A00 !important; border: 1px solid #FFE082 !important; }
.badge-reviewed  { background: #E8F4FF !important; color: #1A4A7A !important; border: 1px solid #B0C8E8 !important; }
.badge-approved  { background: #E4F8F0 !important; color: #1A6A50 !important; border: 1px solid #A0DCC8 !important; }
.badge-closed    { background: #E0F8F4 !important; color: #0A5A48 !important; border: 1px solid #88D8C8 !important; }

/* ===== SCOPE BADGES ===== */
.badge-scope1 { background: #E4F8EE !important; color: #1A6A50 !important; border: 1px solid #A0DCC8 !important; }
.badge-scope2 { background: #E4F6F8 !important; color: #1A6070 !important; border: 1px solid #A0D8E4 !important; }
.badge-scope3 { background: #E0F0F8 !important; color: #1A5070 !important; border: 1px solid #A0C8DC !important; }

/* ===== FORM ===== */
.form-label {
  font-size: 0.85rem; font-weight: 500;
  color: var(--cfp-text); margin-bottom: 4px;
}
.form-control:focus, .form-select:focus {
  border-color: var(--cfp-primary);
  box-shadow: 0 0 0 3px rgba(42,171,184,0.12);
}
.form-required::after { content: ' *'; color: var(--cfp-danger); }

/* ===== TABLE ===== */
.table thead th {
  background: var(--cfp-bg);
  color: var(--cfp-text);
  font-weight: 600; font-size: 0.82rem;
  border-color: var(--cfp-border);
}
.table thead th .bi { color: var(--cfp-primary); }
.table tbody tr:hover td { background: var(--cfp-hover); }
.table td { border-color: var(--cfp-border); color: var(--cfp-text); }

/* ===== MODAL ===== */
.modal-header {
  background: var(--cfp-primary);
  border-radius: 12px 12px 0 0;
  border: none;
}
.modal-title { color: #fff; font-size: 0.9rem; font-weight: 600; }
.modal-content { border: none; border-radius: 12px; }

/* ===== LOGIN PAGE — Style B Blob ===== */
/* Full-screen blob background */
.cfp-login-bg {
  min-height: 100vh;
  background: #C8EAF2;
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
}
/* Blob 1 — mint ใหญ่ขวาล่าง */
.cfp-login-bg .blob1 {
  position: absolute;
  right: -70px; bottom: -70px;
  width: 320px; height: 320px; border-radius: 50%;
  background: radial-gradient(circle, rgba(168,216,192,0.85) 0%, rgba(92,200,216,0.4) 60%, transparent 100%);
  filter: blur(28px); pointer-events: none;
}
/* Blob 2 — ฟ้าซ้ายกลาง */
.cfp-login-bg .blob2 {
  position: absolute;
  left: -60px; top: 25%;
  width: 220px; height: 220px; border-radius: 50%;
  background: radial-gradient(circle, rgba(92,200,216,0.5) 0%, rgba(168,212,232,0.3) 60%, transparent 100%);
  filter: blur(32px); pointer-events: none;
}
/* Blob 3 — ขาวบนขวา */
.cfp-login-bg .blob3 {
  position: absolute;
  right: 15%; top: -40px;
  width: 150px; height: 150px; border-radius: 50%;
  background: radial-gradient(circle, rgba(255,255,255,0.7) 0%, transparent 70%);
  filter: blur(20px); pointer-events: none;
}
/* Form card ลอยกลาง */
.cfp-login-card {
  background: rgba(255,255,255,0.88);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border-radius: 18px;
  border: 1px solid rgba(255,255,255,0.82);
  box-shadow: 0 12px 40px rgba(42,100,130,0.15), 0 2px 8px rgba(42,100,130,0.08);
  padding: 2.25rem 2.5rem;
  width: 100%; max-width: 420px;
  position: relative; z-index: 2;
}
```

## Typography

- **Font**: Prompt (Google Font) — ทุกไฟล์
- **H1** (Page title): `1.25rem`, weight 600, Navy
- **H2** (Section title): `1rem`, weight 600, Navy
- **Body**: `0.9rem`, weight 400, Navy
- **Label**: `0.85rem`, weight 500, Navy
- **Muted**: `0.8rem`, weight 400, Gray `#6C757D`
- **Table header**: `0.82rem`, weight 500, White on Navy bg

## Spacing

- Page padding: `1.5rem`
- Card padding: `1.25rem`
- Section gap: `1rem`
- Form group gap: `0.75rem (mb-3)`
# Database Connection — PHP 8 + sqlsrv (MSSQL)

## config/db.php

```php
<?php
// config/db.php — include ไฟล์นี้ทุกหน้า ห้าม hardcode credential ที่อื่น

define('DB_SERVER',   'localhost');   // หรือ IP MSSQL Server
define('DB_USER',     'cfp_user');
define('DB_PASSWORD', 'your_password');
define('DB_NAME',     'CarbonFootprint');

function getConnection() {
    $serverName = DB_SERVER;
    $connectionInfo = array(
        'Database' => DB_NAME,
        'UID'      => DB_USER,
        'PWD'      => DB_PASSWORD,
        'CharacterSet' => 'UTF-8',
        'TrustServerCertificate' => true  // สำหรับ dev/test เท่านั้น
    );
    $conn = sqlsrv_connect($serverName, $connectionInfo);
    if (!$conn) {
        // Log error แทน die() ใน production
        error_log('DB Connection failed: ' . print_r(sqlsrv_errors(), true));
        die('<div class="alert alert-danger m-3">ไม่สามารถเชื่อมต่อฐานข้อมูลได้</div>');
    }
    return $conn;
}
?>
```

## Query Patterns

### SELECT — ดึงข้อมูลหลายแถว

```php
<?php
require_once '../config/db.php';
$conn = getConnection();

$sql  = "SELECT ActivityID, ActivityName, CO2e FROM CFP_ActivityData WHERE HeaderID = ?";
$params = array($headerID);
$res  = sqlsrv_query($conn, $sql, $params);

$rows = array();
while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $rows[] = $row;
}
sqlsrv_free_stmt($res);
?>
```

### SELECT — ดึงแถวเดียว

```php
<?php
$sql = "SELECT TOP 1 * FROM CFP_User WHERE UserID = ?";
$res = sqlsrv_query($conn, $sql, array($userID));
$user = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
?>
```

### INSERT

```php
<?php
$sql = "INSERT INTO CFP_ActivityData (HeaderID, SiteID, Scope, Quantity, EFID, CO2e, CreatedBy)
        VALUES (?, ?, ?, ?, ?, ?, ?)";
$params = array($headerID, $siteID, $scope, $qty, $efID, $co2e, $_SESSION['user_id']);
$res = sqlsrv_query($conn, $sql, $params);

if ($res === false) {
    $errors[] = 'บันทึกข้อมูลไม่สำเร็จ';
} else {
    $successMsg = 'บันทึกข้อมูลเรียบร้อย';
}
?>
```

### UPDATE

```php
<?php
$sql = "UPDATE CFP_MonthlyHeader
        SET Status = ?, ReviewedBy = ?, ReviewedDate = GETDATE()
        WHERE HeaderID = ?";
$params = array(3, $_SESSION['user_id'], $headerID);
sqlsrv_query($conn, $sql, $params);
?>
```

### DELETE (Soft delete)

```php
<?php
// ใช้ IsActive = 0 แทนการลบจริง
$sql = "UPDATE CFP_ActivityData SET IsActive = 0 WHERE ActivityID = ?";
sqlsrv_query($conn, $sql, array($activityID));
?>
```

### ป้องกัน SQL Injection

**ใช้ Parameterized Query เสมอ** — ห้าม string concatenation กับ user input เด็ดขาด

```php
// ❌ ห้ามทำ
$sql = "SELECT * FROM CFP_User WHERE Username = '" . $_POST['username'] . "'";

// ✅ ถูกต้อง
$sql = "SELECT * FROM CFP_User WHERE Username = ?";
$res = sqlsrv_query($conn, $sql, array($_POST['username']));
```

### XAMPP — ติดตั้ง sqlsrv extension

1. ดาวน์โหลด `php_sqlsrv_83_ts_x64.dll` และ `php_pdo_sqlsrv_83_ts_x64.dll` จาก Microsoft
2. วางใน `C:\xampp\php\ext\`
3. เพิ่มใน `php.ini`:
   ```
   extension=php_sqlsrv_83_ts_x64.dll
   extension=php_pdo_sqlsrv_83_ts_x64.dll
   ```
4. Restart Apache
5. ตรวจสอบด้วย `<?php phpinfo(); ?>` — ค้นหา sqlsrv
# Auth, Login & Role — Carbon Footprint

## Role Matrix

| Role ID | ชื่อ | Dashboard | Data Entry | Calculation | Review | Approve | Master | User Mgmt |
|---------|------|:---------:|:----------:|:-----------:|:------:|:-------:|:------:|:---------:|
| 1 | DataEntry | ✓ | ✓ (หน่วยงานตัวเอง) | - | - | - | - | - |
| 2 | Reviewer | ✓ | - | - | ✓ | - | - | - |
| 3 | Approver | ✓ | - | - | ✓ | ✓ | - | - |
| 4 | Admin | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| 5 | Viewer | ✓ (อ่านอย่างเดียว) | - | - | - | - | - | - |

## Session Variables

```php
// หลัง login สำเร็จ ต้อง set ครบทุกตัว
$_SESSION['user_id']   = $user['UserID'];
$_SESSION['username']  = $user['Username'];
$_SESSION['fullname']  = $user['FullName'];
$_SESSION['role_id']   = $user['Role'];
$_SESSION['site_id']   = $user['SiteID'];
$_SESSION['dept']      = $user['Department'];
```

## includes/auth_check.php — include บรรทัดแรกทุกไฟล์

```php
<?php
session_start();

// ยังไม่ได้ login → redirect
if (empty($_SESSION['user_id'])) {
    header('Location: /carbonfootprint/login.php');
    exit;
}

// ตรวจสิทธิ์ตาม Role (เรียกใช้ใน page ที่ต้องการ)
function requireRole($allowedRoles) {
    if (!in_array($_SESSION['role_id'], (array)$allowedRoles)) {
        header('Location: /carbonfootprint/error_403.php');
        exit;
    }
}

// ตรวจ Role ด้วยตัวเอง
function hasRole($roleID) {
    return $_SESSION['role_id'] == $roleID;
}

function isAdmin()    { return $_SESSION['role_id'] == 4; }
function isApprover() { return $_SESSION['role_id'] >= 3; }
function isReviewer() { return $_SESSION['role_id'] >= 2; }
?>
```

**ตัวอย่างการใช้ใน page:**
```php
<?php
require_once '../includes/auth_check.php';
requireRole(array(3, 4));  // เฉพาะ Approver และ Admin เท่านั้น
$conn = getConnection();
// ... logic
?>
```

## login.php — Full Pattern

```php
<?php
session_start();
require_once 'config/db.php';

// ถ้า login แล้ว → ไป dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard/index.php');
    exit;
}

$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username)) $errors[] = 'กรุณากรอกชื่อผู้ใช้';
    if (empty($password)) $errors[] = 'กรุณากรอกรหัสผ่าน';

    if (empty($errors)) {
        $conn = getConnection();
        $sql  = "SELECT TOP 1 * FROM CFP_User WHERE Username = ? AND IsActive = 1";
        $res  = sqlsrv_query($conn, $sql, array($username));
        $user = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);

        // ใช้ password_verify() — hash ด้วย password_hash() ตอน create user
        if ($user && password_verify($password, $user['PasswordHash'])) {
            session_regenerate_id(true);  // ป้องกัน session fixation
            $_SESSION['user_id']  = $user['UserID'];
            $_SESSION['username'] = $user['Username'];
            $_SESSION['fullname'] = $user['FullName'];
            $_SESSION['role_id']  = $user['Role'];
            $_SESSION['site_id']  = $user['SiteID'];
            $_SESSION['dept']     = $user['Department'];

            // Log การ login
            $logSql = "INSERT INTO CFP_LoginLog (UserID, LoginTime, IP)
                       VALUES (?, GETDATE(), ?)";
            sqlsrv_query($conn, $logSql, array($user['UserID'], $_SERVER['REMOTE_ADDR']));

            header('Location: dashboard/index.php');
            exit;
        } else {
            $errors[] = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>เข้าสู่ระบบ — Carbon Footprint</title>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/cfp-theme.css" rel="stylesheet">
</head>
<body>
<div class="cfp-login-bg">
  <div class="cfp-login-card">

    <!-- Logo -->
    <div class="text-center mb-4">
      <div style="width:64px;height:64px;background:#E8F5E9;border-radius:16px;
                  display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <i class="bi bi-tree-fill" style="font-size:2rem;color:#4CAF50;"></i>
      </div>
      <h1 style="font-size:1.3rem;font-weight:600;color:#1B3A4A;margin:0;">Carbon Footprint</h1>
      <p style="font-size:0.82rem;color:#6C757D;margin:4px 0 0;">ระบบบริหารจัดการก๊าซเรือนกระจก</p>
    </div>

    <!-- Error Alert -->
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger py-2" style="font-size:0.85rem;">
      <i class="bi bi-exclamation-circle me-1"></i>
      <?php echo htmlspecialchars($errors[0]); ?>
    </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" action="">
      <div class="mb-3">
        <label class="form-label">ชื่อผู้ใช้</label>
        <div class="input-group">
          <span class="input-group-text bg-white">
            <i class="bi bi-person" style="color:#1B3A4A;"></i>
          </span>
          <input type="text" name="username" class="form-control"
                 placeholder="กรอกชื่อผู้ใช้"
                 value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                 autofocus required>
        </div>
      </div>
      <div class="mb-4">
        <label class="form-label">รหัสผ่าน</label>
        <div class="input-group">
          <span class="input-group-text bg-white">
            <i class="bi bi-lock" style="color:#1B3A4A;"></i>
          </span>
          <input type="password" name="password" id="password"
                 class="form-control" placeholder="กรอกรหัสผ่าน" required>
          <button type="button" class="input-group-text bg-white border-start-0"
                  onclick="togglePassword()">
            <i class="bi bi-eye" id="eye-icon" style="color:#6C757D;"></i>
          </button>
        </div>
      </div>
      <button type="submit" class="btn btn-cfp-success w-100 py-2" style="font-size:0.95rem;">
        <i class="bi bi-box-arrow-in-right me-2"></i>เข้าสู่ระบบ
      </button>
    </form>

    <p class="text-center mt-3" style="font-size:0.78rem;color:#6C757D;">
      หากลืมรหัสผ่าน กรุณาติดต่อผู้ดูแลระบบ
    </p>
  </div>
</div>

<script>
function togglePassword() {
    var pwd = document.getElementById('password');
    var icon = document.getElementById('eye-icon');
    if (pwd.type === 'password') {
        pwd.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        pwd.type = 'password';
        icon.className = 'bi bi-eye';
    }
}
</script>
</body>
</html>
```

## logout.php

```php
<?php
session_start();
session_destroy();
header('Location: /carbonfootprint/login.php');
exit;
?>
```
# Navigation & Sidebar — Carbon Footprint

## Layout Structure

```
┌──────────────────────────────────────────────────┐
│  .cfp-sidebar (fixed 240px)  │  .cfp-main        │
│                               │  ┌─ .cfp-topbar  │
│  [Logo / Brand]               │  └─ .cfp-content  │
│  [nav-section] หัวข้อ         │                   │
│  [nav-link] เมนู              │   Page content    │
│  ...                          │                   │
└──────────────────────────────────────────────────┘
```

## includes/sidebar.php

```php
<?php
// sidebar.php — ต้อง include auth_check.php ก่อนเรียกใช้
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// เมนูแสดงตาม Role
// role_id: 1=DataEntry, 2=Reviewer, 3=Approver, 4=Admin, 5=Viewer
$roleID = $_SESSION['role_id'];
$siteID = $_SESSION['site_id'];
?>
<nav class="cfp-sidebar d-flex flex-column">

  <!-- Brand -->
  <div class="brand">
    <i class="bi bi-tree-fill leaf-icon"></i>
    Carbon Footprint
  </div>

  <!-- User Info -->
  <div style="padding:0.75rem 1rem; border-bottom:1px solid rgba(255,255,255,0.1);">
    <div style="font-size:0.78rem; color:rgba(255,255,255,0.5); margin-bottom:2px;">
      <?php
        $roleNames = array(1=>'Data Entry',2=>'Reviewer',3=>'Approver',4=>'Admin',5=>'Viewer');
        echo htmlspecialchars($roleNames[$roleID] ?? '');
      ?>
    </div>
    <div style="font-size:0.85rem; color:#fff; font-weight:500;">
      <?php echo htmlspecialchars($_SESSION['fullname']); ?>
    </div>
  </div>

  <!-- เมนูหลัก -->
  <div style="flex:1; padding:0.5rem 0; overflow-y:auto;">

    <!-- Dashboard — ทุก Role -->
    <div class="nav-section">ภาพรวม</div>
    <a href="/carbonfootprint/dashboard/index.php"
       class="nav-link <?php echo ($currentPage=='index')?'active':''; ?>">
      <i class="bi bi-grid-1x2"></i> Dashboard
    </a>

    <?php if (in_array($roleID, array(1,4))): ?>
    <!-- Data Entry -->
    <div class="nav-section">บันทึกข้อมูล</div>
    <a href="/carbonfootprint/data_entry/scope1.php"
       class="nav-link <?php echo ($currentPage=='scope1')?'active':''; ?>">
      <i class="bi bi-fire"></i> Scope 1 — Direct
    </a>
    <a href="/carbonfootprint/data_entry/scope2.php"
       class="nav-link <?php echo ($currentPage=='scope2')?'active':''; ?>">
      <i class="bi bi-lightning"></i> Scope 2 — Energy
    </a>
    <a href="/carbonfootprint/data_entry/scope3.php"
       class="nav-link <?php echo ($currentPage=='scope3')?'active':''; ?>">
      <i class="bi bi-globe"></i> Scope 3 — Indirect
    </a>
    <a href="/carbonfootprint/data_entry/import.php"
       class="nav-link <?php echo ($currentPage=='import')?'active':''; ?>">
      <i class="bi bi-cloud-upload"></i> นำเข้าไฟล์ CSV
    </a>
    <?php endif; ?>

    <?php if (in_array($roleID, array(2,3,4))): ?>
    <!-- Review / Approve -->
    <div class="nav-section">ตรวจสอบ / อนุมัติ</div>
    <?php if (in_array($roleID, array(2,3,4))): ?>
    <a href="/carbonfootprint/workflow/review.php"
       class="nav-link <?php echo ($currentPage=='review')?'active':''; ?>">
      <i class="bi bi-search"></i> ตรวจสอบข้อมูล
    </a>
    <?php endif; ?>
    <?php if (in_array($roleID, array(3,4))): ?>
    <a href="/carbonfootprint/workflow/approve.php"
       class="nav-link <?php echo ($currentPage=='approve')?'active':''; ?>">
      <i class="bi bi-check2-circle"></i> อนุมัติรายเดือน
    </a>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Calculation — Admin เท่านั้น -->
    <?php if ($roleID == 4): ?>
    <div class="nav-section">คำนวณ</div>
    <a href="/carbonfootprint/calculation/calculate.php"
       class="nav-link <?php echo ($currentPage=='calculate')?'active':''; ?>">
      <i class="bi bi-calculator"></i> คำนวณ CO2e
    </a>
    <a href="/carbonfootprint/calculation/validation.php"
       class="nav-link <?php echo ($currentPage=='validation')?'active':''; ?>">
      <i class="bi bi-shield-check"></i> QA Validation
    </a>
    <?php endif; ?>

    <!-- Master Data — Admin -->
    <?php if ($roleID == 4): ?>
    <div class="nav-section">ข้อมูลหลัก</div>
    <a href="/carbonfootprint/master/site.php"
       class="nav-link <?php echo ($currentPage=='site')?'active':''; ?>">
      <i class="bi bi-building"></i> หน่วยงาน / Site
    </a>
    <a href="/carbonfootprint/master/ef_value.php"
       class="nav-link <?php echo ($currentPage=='ef_value')?'active':''; ?>">
      <i class="bi bi-database"></i> ค่า EF
    </a>
    <a href="/carbonfootprint/master/unit.php"
       class="nav-link <?php echo ($currentPage=='unit')?'active':''; ?>">
      <i class="bi bi-rulers"></i> หน่วยวัด
    </a>
    <a href="/carbonfootprint/master/users.php"
       class="nav-link <?php echo ($currentPage=='users')?'active':''; ?>">
      <i class="bi bi-people"></i> ผู้ใช้งาน
    </a>
    <?php endif; ?>

    <!-- รายงาน — ทุก Role -->
    <div class="nav-section">รายงาน</div>
    <a href="/carbonfootprint/report/index.php"
       class="nav-link <?php echo ($currentPage=='report')?'active':''; ?>">
      <i class="bi bi-bar-chart-line"></i> รายงาน CO2e
    </a>

  </div>

  <!-- Footer -->
  <div style="padding:0.75rem 1rem; border-top:1px solid rgba(255,255,255,0.1);">
    <a href="/carbonfootprint/logout.php"
       class="nav-link" style="color:rgba(255,100,100,0.8);">
      <i class="bi bi-box-arrow-left"></i> ออกจากระบบ
    </a>
  </div>

</nav>
```

## includes/topbar.php

```php
<div class="cfp-topbar">
  <div class="page-title">
    <i class="bi bi-<?php echo $pageIcon ?? 'grid-1x2'; ?> me-2"></i>
    <?php echo $pageTitle ?? 'Carbon Footprint'; ?>
  </div>
  <div class="d-flex align-items:center gap-3">
    <!-- เดือน/ปี ปัจจุบัน -->
    <span style="font-size:0.82rem; color:#6C757D;">
      <i class="bi bi-calendar3 me-1"></i>
      <?php echo date('M Y'); ?>
    </span>
    <!-- User Badge -->
    <span class="badge" style="background:#E8F5E9; color:#1B3A4A; font-size:0.8rem; font-weight:500;">
      <i class="bi bi-person-circle me-1"></i>
      <?php echo htmlspecialchars($_SESSION['fullname']); ?>
    </span>
  </div>
</div>
```

## Layout Wrapper (ทุก page ต้องใช้โครงสร้างนี้)

```php
<?php
require_once '../includes/auth_check.php';
require_once '../config/db.php';

// กำหนดตัวแปรสำหรับ topbar
$pageTitle = 'ชื่อหน้า';
$pageIcon  = 'grid-1x2';

// ... PHP Logic ...
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <!-- HTML Head Template จาก SKILL.md หลัก -->
</head>
<body>
<div class="d-flex">

  <?php include '../includes/sidebar.php'; ?>

  <div class="cfp-main w-100">
    <?php include '../includes/topbar.php'; ?>

    <div class="cfp-content">
      <!-- Page Content Here -->
    </div>
  </div>

</div>
<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
</body>
</html>
```
# Dashboard Cards & KPI Widgets — Carbon Footprint

## KPI Card Grid (4 cards)

```php
<!-- ใช้บน dashboard/index.php -->
<div class="row g-3 mb-4">

  <!-- Total CO2e -->
  <div class="col-md-3">
    <div class="cfp-card h-100">
      <div class="cfp-card-title">
        <i class="bi bi-cloud me-1"></i>CO2e รวม (เดือนนี้)
      </div>
      <div class="cfp-card-value"><?php echo number_format($totalCO2e, 2); ?></div>
      <div class="cfp-card-unit">tCO2e</div>
      <div class="mt-2">
        <span class="badge" style="background:#E8F5E9;color:#2E7D32;font-size:0.72rem;">
          <?php echo $co2eTrend >= 0 ? '▲' : '▼'; ?>
          <?php echo abs($co2eTrend); ?>% จากเดือนก่อน
        </span>
      </div>
    </div>
  </div>

  <!-- Scope 1 -->
  <div class="col-md-3">
    <div class="cfp-card h-100" style="border-left:4px solid #1B3A4A;">
      <div class="cfp-card-title">
        <span class="badge badge-scope1 me-1">Scope 1</span> Direct
      </div>
      <div class="cfp-card-value"><?php echo number_format($scope1CO2e, 2); ?></div>
      <div class="cfp-card-unit">tCO2e</div>
      <div class="progress mt-2" style="height:4px;">
        <div class="progress-bar" style="background:#1B3A4A;width:<?php echo $scope1Pct; ?>%"></div>
      </div>
      <div style="font-size:0.72rem;color:#6C757D;margin-top:3px;"><?php echo $scope1Pct; ?>% ของรวม</div>
    </div>
  </div>

  <!-- Scope 2 -->
  <div class="col-md-3">
    <div class="cfp-card h-100" style="border-left:4px solid #4CAF50;">
      <div class="cfp-card-title">
        <span class="badge badge-scope2 me-1">Scope 2</span> Energy
      </div>
      <div class="cfp-card-value"><?php echo number_format($scope2CO2e, 2); ?></div>
      <div class="cfp-card-unit">tCO2e</div>
      <div class="progress mt-2" style="height:4px;">
        <div class="progress-bar" style="background:#4CAF50;width:<?php echo $scope2Pct; ?>%"></div>
      </div>
      <div style="font-size:0.72rem;color:#6C757D;margin-top:3px;"><?php echo $scope2Pct; ?>% ของรวม</div>
    </div>
  </div>

  <!-- Scope 3 -->
  <div class="col-md-3">
    <div class="cfp-card h-100" style="border-left:4px solid #A5D6A7;">
      <div class="cfp-card-title">
        <span class="badge badge-scope3 me-1">Scope 3</span> Indirect
      </div>
      <div class="cfp-card-value"><?php echo number_format($scope3CO2e, 2); ?></div>
      <div class="cfp-card-unit">tCO2e</div>
      <div class="progress mt-2" style="height:4px;">
        <div class="progress-bar" style="background:#A5D6A7;width:<?php echo $scope3Pct; ?>%"></div>
      </div>
      <div style="font-size:0.72rem;color:#6C757D;margin-top:3px;"><?php echo $scope3Pct; ?>% ของรวม</div>
    </div>
  </div>

</div>
```

## Workflow Status Summary Card

```php
<div class="cfp-card mb-4">
  <div style="font-size:0.9rem;font-weight:600;color:#1B3A4A;margin-bottom:12px;">
    <i class="bi bi-clipboard-check me-2"></i>สถานะการส่งข้อมูล — <?php echo date('M Y'); ?>
  </div>
  <div class="row g-2 text-center">
    <?php
    $statusCounts = array(
        array('label'=>'Draft',      'count'=>$cntDraft,     'class'=>'badge-draft',     'icon'=>'pencil'),
        array('label'=>'Submitted',  'count'=>$cntSubmitted, 'class'=>'badge-submitted', 'icon'=>'send'),
        array('label'=>'Reviewed',   'count'=>$cntReviewed,  'class'=>'badge-reviewed',  'icon'=>'eye'),
        array('label'=>'Approved',   'count'=>$cntApproved,  'class'=>'badge-approved',  'icon'=>'check-circle'),
    );
    foreach ($statusCounts as $s): ?>
    <div class="col">
      <div style="background:#F5F7FA;border-radius:8px;padding:12px 8px;">
        <i class="bi bi-<?php echo $s['icon']; ?>" style="font-size:1.3rem;color:#1B3A4A;"></i>
        <div style="font-size:1.4rem;font-weight:600;color:#1B3A4A;"><?php echo $s['count']; ?></div>
        <span class="badge <?php echo $s['class']; ?>" style="font-size:0.7rem;"><?php echo $s['label']; ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
```

## Monthly Filter Bar (ส่วนกรองเดือน/ปี ใช้ทุกหน้า)

```php
<div class="cfp-card mb-3">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-auto">
      <label class="form-label">ปี</label>
      <select name="year" class="form-select form-select-sm" style="width:100px;">
        <?php for ($y = date('Y'); $y >= date('Y')-3; $y--): ?>
        <option value="<?php echo $y; ?>" <?php echo ($filterYear==$y)?'selected':''; ?>>
          <?php echo $y + 543; ?>  <!-- แปลงเป็น พ.ศ. -->
        </option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-auto">
      <label class="form-label">เดือน</label>
      <select name="month" class="form-select form-select-sm" style="width:130px;">
        <?php
        $monthNames = array(1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',
                            5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',
                            9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม');
        foreach ($monthNames as $m => $name): ?>
        <option value="<?php echo $m; ?>" <?php echo ($filterMonth==$m)?'selected':''; ?>>
          <?php echo $name; ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <label class="form-label">หน่วยงาน</label>
      <select name="site_id" class="form-select form-select-sm" style="width:160px;">
        <option value="">— ทั้งหมด —</option>
        <?php foreach ($sites as $site): ?>
        <option value="<?php echo $site['SiteID']; ?>"
                <?php echo ($filterSite==$site['SiteID'])?'selected':''; ?>>
          <?php echo htmlspecialchars($site['SiteName']); ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <button type="submit" class="btn btn-cfp-primary btn-sm">
        <i class="bi bi-funnel me-1"></i>กรอง
      </button>
    </div>
  </form>
</div>
```
# Form Patterns — Carbon Footprint

## Standard Form Card

```php
<div class="cfp-card">
  <div style="font-size:0.95rem;font-weight:600;color:#1B3A4A;margin-bottom:16px;
              padding-bottom:10px;border-bottom:1px solid #E0E0E0;">
    <i class="bi bi-plus-circle me-2 text-success"></i>บันทึกข้อมูลกิจกรรม
  </div>

  <!-- Error/Success Alert -->
  <?php if (!empty($errors)): ?>
  <div class="alert alert-danger py-2 mb-3" style="font-size:0.85rem;">
    <i class="bi bi-exclamation-circle me-1"></i>
    <?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($successMsg)): ?>
  <div class="alert alert-success py-2 mb-3" style="font-size:0.85rem;">
    <i class="bi bi-check-circle me-1"></i>
    <?php echo htmlspecialchars($successMsg); ?>
  </div>
  <?php endif; ?>

  <form method="POST" action="" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

    <div class="row g-3">
      <!-- ตัวอย่าง Field -->
      <div class="col-md-6">
        <label class="form-label form-required">หมวดกิจกรรม</label>
        <select name="category" class="form-select" required>
          <option value="">— เลือกหมวด —</option>
          <option value="stationary">Stationary Combustion</option>
          <option value="mobile">Mobile Combustion</option>
          <option value="fugitive">Fugitive Emissions</option>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label form-required">ปริมาณ</label>
        <div class="input-group">
          <input type="number" name="quantity" class="form-control"
                 placeholder="0.00" step="0.001" min="0"
                 value="<?php echo htmlspecialchars($_POST['quantity'] ?? ''); ?>"
                 required>
          <select name="unit_id" class="form-select" style="max-width:120px;">
            <?php foreach ($units as $u): ?>
            <option value="<?php echo $u['UnitID']; ?>"><?php echo htmlspecialchars($u['UnitCode']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="col-md-6">
        <label class="form-label form-required">ค่า EF</label>
        <select name="ef_id" class="form-select" required>
          <option value="">— เลือก EF —</option>
          <?php foreach ($efValues as $ef): ?>
          <option value="<?php echo $ef['EFID']; ?>"
                  data-ef="<?php echo $ef['EFValue']; ?>">
            <?php echo htmlspecialchars($ef['EFName']); ?>
            (<?php echo $ef['EFValue']; ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label">CO2e (คำนวณอัตโนมัติ)</label>
        <div class="input-group">
          <input type="number" name="co2e" id="co2e_result" class="form-control"
                 placeholder="0.000000" step="0.000001" readonly
                 style="background:#F5F7FA;">
          <span class="input-group-text">tCO2e</span>
        </div>
      </div>

      <div class="col-12">
        <label class="form-label">หลักฐาน / เอกสารอ้างอิง</label>
        <input type="file" name="evidence" class="form-control"
               accept=".pdf,.jpg,.jpeg,.png,.xlsx,.csv">
        <div class="form-text">รองรับ PDF, รูปภาพ, Excel, CSV — ไม่เกิน 10MB</div>
      </div>

      <div class="col-12">
        <label class="form-label">หมายเหตุ</label>
        <textarea name="remark" class="form-control" rows="2"
                  placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"><?php echo htmlspecialchars($_POST['remark'] ?? ''); ?></textarea>
      </div>
    </div>

    <div class="mt-3 d-flex gap-2 justify-content-end">
      <a href="index.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>ยกเลิก
      </a>
      <button type="submit" class="btn btn-cfp-success btn-sm">
        <i class="bi bi-save me-1"></i>บันทึกข้อมูล
      </button>
    </div>
  </form>
</div>
```

## CSRF Token Helper

```php
<?php
// สร้าง token ใน auth_check.php หรือ session start
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ตรวจสอบก่อนประมวลผล POST
function verifyCsrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST['csrf_token']) ||
            $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            die('<div class="alert alert-danger m-3">คำขอไม่ถูกต้อง</div>');
        }
    }
}
?>
```

## Auto-Calculate CO2e (JavaScript)

```javascript
// คำนวณ CO2e = Quantity × EF อัตโนมัติ
document.addEventListener('DOMContentLoaded', function() {
    var qtyInput = document.querySelector('[name="quantity"]');
    var efSelect = document.querySelector('[name="ef_id"]');
    var co2eInput = document.getElementById('co2e_result');

    function calcCO2e() {
        var qty = parseFloat(qtyInput.value) || 0;
        var efOpt = efSelect.options[efSelect.selectedIndex];
        var ef = parseFloat(efOpt.dataset.ef) || 0;
        co2eInput.value = (qty * ef).toFixed(6);
    }

    if (qtyInput) qtyInput.addEventListener('input', calcCO2e);
    if (efSelect) efSelect.addEventListener('change', calcCO2e);
});
```

## Validation Pattern (PHP 8)

```php
<?php
$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $category = trim($_POST['category'] ?? '');
    $quantity  = $_POST['quantity'] ?? '';
    $efID      = (int)($_POST['ef_id'] ?? 0);

    if (empty($category))         $errors[] = 'กรุณาเลือกหมวดกิจกรรม';
    if (!is_numeric($quantity) || $quantity <= 0)
                                   $errors[] = 'กรุณากรอกปริมาณที่ถูกต้อง';
    if ($efID <= 0)               $errors[] = 'กรุณาเลือกค่า EF';

    if (empty($errors)) {
        // คำนวณ CO2e จาก DB
        $efSql = "SELECT EFValue, GWP FROM CFP_EFValue WHERE EFID = ?";
        $efRes = sqlsrv_query($conn, $efSql, array($efID));
        $ef    = sqlsrv_fetch_array($efRes, SQLSRV_FETCH_ASSOC);
        $co2e  = (float)$quantity * (float)$ef['EFValue'] * (float)$ef['GWP'];

        // Insert
        $sql = "INSERT INTO CFP_ActivityData
                (HeaderID, SiteID, Scope, Category, Quantity, EFID, CO2e, CreatedBy)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $params = array($headerID, $_SESSION['site_id'], $scope,
                        $category, $quantity, $efID, $co2e, $_SESSION['user_id']);
        $res = sqlsrv_query($conn, $sql, $params);

        if ($res) {
            $successMsg = 'บันทึกข้อมูลเรียบร้อยแล้ว';
        } else {
            $errors[] = 'เกิดข้อผิดพลาดในการบันทึก';
        }
    }
}
?>
```
# Tab Styles — 5 แบบที่ออกแบบไว้ (เลือกตาม context)

| # | ชื่อ | เหมาะกับ |
|---|------|----------|
| 1 | Breadcrumb Arrow | หน้าที่มี flow ซ้ายไปขวา |
| 2 | Vertical Side Tabs | หน้า compact มี content ยาว |
| 3 | Icon Card Top Tab ✅ | **แนะนำ** สำหรับ org.php, master pages |
| 4 | Neon Glow Pill | หน้าสดใส dashboard |
| 5 | Number Stepper | wizard / form หลายขั้นตอน |

### Style 3: Icon Card Top Tab (แนะนำ)

```css
.tab-nav-btn {
  display: flex; flex-direction: column; align-items: center; gap: 3px;
  padding: 10px 14px; font-size: 10px; color: #7AAAB8;
  border: none; background: transparent; cursor: pointer;
  border-bottom: 3px solid transparent; margin-bottom: -1px;
  font-family: 'Prompt', sans-serif; transition: all .15s;
}
.tab-nav-btn:hover { color: #2AABB8; }
.tab-nav-btn.active { color: #2AABB8; font-weight: 600; border-bottom-color: #2AABB8; }

.tab-icon-box {
  width: 32px; height: 32px; border-radius: 10px;
  background: #EEF6F8; font-size: 15px;
  display: flex; align-items: center; justify-content: center; margin-bottom: 2px;
}
.tab-nav-btn.active .tab-icon-box {
  background: linear-gradient(135deg, #2AABB8, #A8D8C0); color: #fff;
}
```

```html
<!-- Tab bar container -->
<div style="border-bottom:1px solid #E4F2F5;display:flex;padding:0 4px;">
  <button class="tab-nav-btn active" onclick="switchTab('company',this)">
    <div class="tab-icon-box"><i class="bi bi-building"></i></div>
    บริษัท
  </button>
  <button class="tab-nav-btn" onclick="switchTab('division',this)">
    <div class="tab-icon-box"><i class="bi bi-diagram-2"></i></div>
    ฝ่าย
  </button>
</div>
```

### Style 4: Neon Glow Pill

```css
.tab-nav-btn {
  padding: 8px 16px; border-radius: 30px;
  border: 1.5px solid #D0E8EE; background: #fff; color: #7AAAB8;
  font-family: 'Prompt', sans-serif; font-size: 12px; transition: all .18s;
}
.tab-nav-btn.active {
  background: linear-gradient(135deg, #2AABB8, #5CC8D8);
  border-color: transparent; color: #fff; font-weight: 600;
  box-shadow: 0 0 0 4px rgba(42,171,184,0.15), 0 4px 12px rgba(42,171,184,0.3);
}
```

# Table & DataTables — Carbon Footprint

## Standard DataTable

```php
<!-- PHP Logic ดึงข้อมูลแล้วส่งมาใน $rows -->

<div class="cfp-card">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div style="font-size:0.95rem;font-weight:600;color:#1B3A4A;">
      <i class="bi bi-table me-2"></i>รายการข้อมูล Scope 1
    </div>
    <a href="scope1_add.php" class="btn btn-cfp-success btn-sm">
      <i class="bi bi-plus-circle me-1"></i>เพิ่มรายการ
    </a>
  </div>

  <div class="table-responsive">
    <table id="tblScope1" class="table table-bordered table-hover align-middle" style="width:100%">
      <thead>
        <tr>
          <th style="width:40px;">#</th>
          <th>หมวดกิจกรรม</th>
          <th>ชื่อกิจกรรม</th>
          <th class="text-end">ปริมาณ</th>
          <th class="text-end">CO2e (tCO2e)</th>
          <th class="text-center" style="width:100px;">สถานะ</th>
          <th class="text-center" style="width:100px;">จัดการ</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $row): ?>
        <tr>
          <td><?php echo $i + 1; ?></td>
          <td><?php echo htmlspecialchars($row['Category']); ?></td>
          <td><?php echo htmlspecialchars($row['ActivityName']); ?></td>
          <td class="text-end"><?php echo number_format($row['Quantity'], 3); ?></td>
          <td class="text-end fw-500" style="color:#1B3A4A;">
            <?php echo number_format($row['CO2e'], 6); ?>
          </td>
          <td class="text-center">
            <?php echo getStatusBadge($row['Status']); ?>
          </td>
          <td class="text-center">
            <?php if (canEdit($row['Status'], $_SESSION['role_id'])): ?>
            <a href="scope1_edit.php?id=<?php echo $row['ActivityID']; ?>"
               class="btn btn-outline-primary btn-sm py-0 px-2">
              <i class="bi bi-pencil"></i>
            </a>
            <button type="button"
                    class="btn btn-outline-danger btn-sm py-0 px-2"
                    onclick="confirmDelete(<?php echo $row['ActivityID']; ?>)">
              <i class="bi bi-trash"></i>
            </button>
            <?php else: ?>
            <a href="scope1_view.php?id=<?php echo $row['ActivityID']; ?>"
               class="btn btn-outline-secondary btn-sm py-0 px-2">
              <i class="bi bi-eye"></i>
            </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#E8F5E9;">
          <td colspan="4" class="text-end fw-500">รวม CO2e</td>
          <td class="text-end fw-500" style="color:#1B3A4A;">
            <?php echo number_format(array_sum(array_column($rows, 'CO2e')), 4); ?> tCO2e
          </td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal fade" id="modalDelete" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title">ยืนยันการลบ</h6>
      </div>
      <div class="modal-body py-2" style="font-size:0.85rem;">
        ต้องการลบรายการนี้ใช่หรือไม่?
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
        <a id="btnConfirmDelete" href="#" class="btn btn-danger btn-sm">ลบ</a>
      </div>
    </div>
  </div>
</div>

<script>
$(document).ready(function() {
    $('#tblScope1').DataTable({
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json'
        },
        order:      [[0, 'asc']],
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        dom: '<"row align-items-center mb-2"<"col-auto"l><"col"f>>rtip'
    });
});

function confirmDelete(id) {
    document.getElementById('btnConfirmDelete').href = 'scope1_delete.php?id=' + id;
    new bootstrap.Modal(document.getElementById('modalDelete')).show();
}
</script>
```

## Child Row (DataTables) — สำหรับข้อมูลที่มี Sub-detail

```javascript
$(document).ready(function() {
    var table = $('#tblMain').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' }
    });

    // คลิก row เพื่อดูรายละเอียด
    $('#tblMain tbody').on('click', 'tr td:first-child', function() {
        var tr   = $(this).closest('tr');
        var row  = table.row(tr);

        if (row.child.isShown()) {
            row.child.hide();
            tr.removeClass('shown');
        } else {
            var data = row.data();
            row.child(renderDetail(data)).show();
            tr.addClass('shown');
        }
    });

    function renderDetail(data) {
        return '<div style="padding:10px 20px;background:#F5F7FA;">' +
               '<strong>EF ที่ใช้:</strong> ' + data[5] +
               ' &nbsp;|&nbsp; <strong>วิธีบันทึก:</strong> ' + data[6] +
               ' &nbsp;|&nbsp; <strong>บันทึกโดย:</strong> ' + data[7] +
               '</div>';
    }
});
```
# Workflow & Approval — Carbon Footprint

## สถานะ Monthly Approval (L5)

```
1 Draft → 2 Submitted → 3 Reviewed → 4 Approved → 5 Month Close
```

| Status ID | ชื่อ | CSS Class | ทำได้โดย |
|-----------|------|-----------|----------|
| 1 | Draft | `badge-draft` | DataEntry — แก้ไขได้ |
| 2 | Submitted | `badge-submitted` | DataEntry — ส่งตรวจ |
| 3 | Reviewed | `badge-reviewed` | Reviewer — ตรวจแล้ว |
| 4 | Approved | `badge-approved` | Approver — อนุมัติ |
| 5 | Month Close | `badge-closed` | System/Admin — ล็อค |

## PHP Helper Function

```php
<?php
function getStatusBadge($statusID) {
    $statusMap = array(
        1 => array('label' => 'Draft',       'class' => 'badge-draft'),
        2 => array('label' => 'Submitted',    'class' => 'badge-submitted'),
        3 => array('label' => 'Reviewed',     'class' => 'badge-reviewed'),
        4 => array('label' => 'Approved',     'class' => 'badge-approved'),
        5 => array('label' => 'Month Close',  'class' => 'badge-closed'),
    );
    $s = $statusMap[$statusID] ?? array('label'=>'Unknown','class'=>'bg-secondary');
    return '<span class="badge ' . $s['class'] . '">' . $s['label'] . '</span>';
}

function canEdit($statusID, $roleID) {
    // DataEntry แก้ได้เฉพาะ Draft
    if ($roleID == 1) return $statusID == 1;
    // Admin แก้ได้ทุกสถานะยกเว้น Month Close
    if ($roleID == 4) return $statusID < 5;
    return false;
}

function canSubmit($statusID, $roleID) {
    return ($statusID == 1 && in_array($roleID, array(1, 4)));
}

function canReview($statusID, $roleID) {
    return ($statusID == 2 && in_array($roleID, array(2, 3, 4)));
}

function canApprove($statusID, $roleID) {
    return ($statusID == 3 && in_array($roleID, array(3, 4)));
}
?>
```

## Workflow Progress Bar (HTML Component)

```php
<?php
function renderWorkflowProgress($currentStatus) {
    $steps = array(
        1 => 'Draft',
        2 => 'Submitted',
        3 => 'Reviewed',
        4 => 'Approved',
        5 => 'Closed'
    );
    $html = '<div class="cfp-card mb-3">';
    $html .= '<div class="d-flex align-items-center justify-content-between" style="position:relative;">';

    // เส้นเชื่อม
    $html .= '<div style="position:absolute;top:16px;left:10%;right:10%;height:2px;background:#E0E0E0;z-index:0;"></div>';
    $completedLine = min(($currentStatus - 1) / 4 * 80, 80);
    $html .= '<div style="position:absolute;top:16px;left:10%;width:' . $completedLine . '%;height:2px;background:#4CAF50;z-index:1;transition:width 0.3s;"></div>';

    foreach ($steps as $step => $label) {
        $isDone    = $step < $currentStatus;
        $isCurrent = $step == $currentStatus;
        $dotBg     = $isDone ? '#4CAF50' : ($isCurrent ? '#1B3A4A' : '#E0E0E0');
        $textColor = $isCurrent ? '#1B3A4A' : ($isDone ? '#4CAF50' : '#9E9E9E');
        $fontWeight = $isCurrent ? '600' : '400';

        $html .= '<div class="text-center" style="position:relative;z-index:2;flex:1;">';
        $html .= '<div style="width:32px;height:32px;border-radius:50%;background:' . $dotBg . ';
                  color:#fff;display:flex;align-items:center;justify-content:center;
                  margin:0 auto 6px;font-size:0.75rem;font-weight:600;">';
        $html .= $isDone ? '<i class="bi bi-check"></i>' : $step;
        $html .= '</div>';
        $html .= '<div style="font-size:0.72rem;color:' . $textColor . ';font-weight:' . $fontWeight . ';">' . $label . '</div>';
        $html .= '</div>';
    }

    $html .= '</div></div>';
    return $html;
}
?>
```

**ใช้งาน:**
```php
echo renderWorkflowProgress($header['Status']);
```

---

# Changelog

| เวอร์ชัน | การเปลี่ยนแปลง |
|---------|----------------|
| v1 | Wave Mint-Blue theme — topbar gradient mint→powder blue |
| v2 | **Ice Teal theme** — topbar gradient `#6ECDD8→#B8EDE0`, title สี `#1B4A52`, wave SVG V2 (5 ชั้น บน+ล่าง) |
| v2.1 | Sidebar white theme — active `border-left:3px solid #2AABB8` |
| v2.2 | KPI card colors แยกตาม Scope, Chart.js teal palette, Dashboard index.php |
| v2.3 | Tab Styles 5 แบบ, DataTable `lengthMenu` fix, responsive topbar 3 breakpoints |
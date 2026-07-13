<?php
/* ==============================================
   master/scope3category.php
   ตั้งค่า Category Scope 3 ที่องค์กรใช้งาน — Admin (4), SustainAdmin (5)
   Category ทั้ง 15 เป็น fixed list (GHG Protocol) ไม่ผูกกับตาราง master ใดๆ
   ตารางนี้เก็บแค่ CategoryNo ที่ถูก "ปิดใช้งาน" เท่านั้น
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';

requireRole(array(4, 5));

$conn   = getConnection();
$userID = (int)$_SESSION['user_id'];

/* ===== Toast จาก redirect ===== */
$toastMsg  = '';
$toastType = '';
if (!empty($_SESSION['toast'])) {
    $toastMsg  = $_SESSION['toast']['msg'];
    $toastType = $_SESSION['toast']['type'];
    unset($_SESSION['toast']);
}

/* ===== Category Scope 3 ทั้ง 15 ตัว (fixed list ตาม GHG Protocol) ===== */
$scope3Categories = array(
    1  => 'Cat.1 — สินค้าและบริการที่จัดซื้อ (Purchased goods & services)',
    2  => 'Cat.2 — สินทรัพย์ทุน (Capital goods)',
    3  => 'Cat.3 — เชื้อเพลิงและพลังงาน (Fuel & energy related)',
    4  => 'Cat.4 — การขนส่งต้นน้ำ (Upstream transportation)',
    5  => 'Cat.5 — ของเสียจากการดำเนินงาน (Waste generated in operations)',
    6  => 'Cat.6 — การเดินทางเพื่อธุรกิจ (Business travel)',
    7  => 'Cat.7 — การเดินทางของพนักงาน (Employee commuting)',
    8  => 'Cat.8 — สินทรัพย์เช่าต้นน้ำ (Upstream leased assets)',
    9  => 'Cat.9 — การขนส่งปลายน้ำ (Downstream transportation)',
    10 => 'Cat.10 — การแปรรูปผลิตภัณฑ์ที่ขาย (Processing of sold products)',
    11 => 'Cat.11 — การใช้ผลิตภัณฑ์ที่ขาย (Use of sold products)',
    12 => 'Cat.12 — การกำจัดผลิตภัณฑ์หลังใช้งาน (End-of-life treatment)',
    13 => 'Cat.13 — สินทรัพย์เช่าปลายน้ำ (Downstream leased assets)',
    14 => 'Cat.14 — แฟรนไชส์ (Franchises)',
    15 => 'Cat.15 — การลงทุน (Investments)',
);

/* ===== ดึง Category ที่ถูกปิดอยู่ ===== */
$resDis = sqlsrv_query($conn, "SELECT CategoryNo, DisabledBy, DisabledDate FROM CFP_Scope3CategoryDisabled");
$disabledMap = array();
while ($rD = sqlsrv_fetch_array($resDis, SQLSRV_FETCH_ASSOC)) {
    $disabledMap[(int)$rD['CategoryNo']] = $rD;
}

$total    = count($scope3Categories);
$enabled  = $total - count($disabledMap);
$disabled = count($disabledMap);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Category Scope 3 — ระบบบริหารจัดการคาร์บอนองค์กร</title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets/css/cfp-theme.css" rel="stylesheet">

  <style>
    body { font-family: 'Prompt', sans-serif; }
    .font-prompt { font-family: 'Prompt', sans-serif !important; }
    .kpi-icon-box {
      width: 44px; height: 44px;
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.2rem;
      flex-shrink: 0;
    }
    .cat-row {
      display: flex; align-items: center; justify-content: space-between;
      padding: 10px 0; border-bottom: 1px solid var(--cfp-border);
      gap: 10px;
    }
    .cat-row:last-child { border-bottom: none; }
    .cat-label { font-size: 0.85rem; color: var(--cfp-text); }
    .cat-meta { font-size: 0.72rem; color: var(--cfp-text-muted); margin-top: 2px; }
    .cat-row.disabled .cat-label { color: var(--cfp-text-muted); text-decoration: line-through; }
  </style>
</head>
<body>

<div class="d-flex">

  <?php include '../includes/sidebar.php'; ?>

  <div class="cfp-main">

    <?php
    $pageTitle = 'Category Scope 3';
    $pageIcon  = 'list-check';
    include '../includes/topbar.php';
    ?>

    <div class="cfp-content">

      <!-- Page Header -->
      <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
          <h5 class="mb-0 fw-600" style="color:var(--cfp-primary);">
            <i class="bi bi-list-check me-2" style="color:var(--cfp-green);"></i>ตั้งค่า Category Scope 3
          </h5>
          <div style="font-size:0.78rem;color:var(--cfp-text-muted);margin-top:2px;">
            การตั้งค่าข้อมูลพื้นฐาน › Category Scope 3
          </div>
        </div>
      </div>

      <!-- ===== KPI SUMMARY ===== -->
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-4">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#E3F2FD;">
              <i class="bi bi-list-check" style="color:#1565C0;"></i>
            </div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:var(--cfp-primary);line-height:1.1;">
                <?php echo $total; ?>
              </div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">Category ทั้งหมด</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#E8F5E9;">
              <i class="bi bi-check-circle-fill" style="color:#2E7D32;"></i>
            </div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:#2E7D32;line-height:1.1;">
                <?php echo $enabled; ?>
              </div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ใช้งานอยู่</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <div class="cfp-card py-3 px-3 d-flex align-items-center gap-3 mb-0">
            <div class="kpi-icon-box" style="background:#F5F5F5;">
              <i class="bi bi-slash-circle" style="color:#9E9E9E;"></i>
            </div>
            <div>
              <div style="font-size:1.5rem;font-weight:700;color:#9E9E9E;line-height:1.1;">
                <?php echo $disabled; ?>
              </div>
              <div style="font-size:0.75rem;color:var(--cfp-text-muted);">ไม่ได้ใช้งาน</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ===== LIST CARD ===== -->
      <div class="cfp-card">
        <div style="font-size:0.92rem;font-weight:600;color:var(--cfp-primary);margin-bottom:4px;">
          <i class="bi bi-list-check me-2"></i>รายการ Category Scope 3 (15 หมวดตาม GHG Protocol)
        </div>
        <div style="font-size:0.78rem;color:var(--cfp-text-muted);margin-bottom:10px;">
          ปิดใช้งาน Category ที่องค์กรไม่มีข้อมูล — จะไม่แสดงให้เลือกในหน้ากำหนดสิทธิ์ Scope ของ user
        </div>

        <?php foreach ($scope3Categories as $catNo => $catLabel) {
          $isDisabled = isset($disabledMap[$catNo]);
        ?>
        <div class="cat-row <?php echo $isDisabled ? 'disabled' : ''; ?>" id="catRow<?php echo $catNo; ?>">
          <div>
            <div class="cat-label"><?php echo htmlspecialchars($catLabel); ?></div>
            <?php if ($isDisabled && !empty($disabledMap[$catNo]['DisabledDate'])) {
              $dd = $disabledMap[$catNo]['DisabledDate'];
              $ddStr = $dd instanceof DateTime ? $dd->format('d M Y H:i') : '';
            ?>
            <div class="cat-meta">ปิดใช้งานเมื่อ <?php echo htmlspecialchars($ddStr); ?></div>
            <?php } ?>
          </div>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch"
                   id="chkCat<?php echo $catNo; ?>"
                   <?php echo $isDisabled ? '' : 'checked'; ?>
                   onchange="toggleCategory(<?php echo $catNo; ?>, this.checked)"
                   style="width:2.5em;height:1.3em;cursor:pointer;">
          </div>
        </div>
        <?php } ?>
      </div>

    </div>
  </div>
</div>

<!-- Hidden Form -->
<form id="formToggleCat" method="POST" action="scope3category_save.php" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
  <input type="hidden" name="categoryNo" id="fCatNo" value="0">
  <input type="hidden" name="catAction" id="fCatAction" value="disable">
</form>

<!-- Toast -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:1080;">
  <div id="toastSuccess" class="toast align-items-center text-white border-0"
       style="background:#2E7D32;" role="alert">
    <div class="d-flex">
      <div class="toast-body d-flex align-items-center gap-2">
        <i class="bi bi-check-circle-fill"></i>
        <span id="toastMsg">บันทึกข้อมูลเรียบร้อย</span>
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
  <div id="toastError" class="toast align-items-center text-white border-0"
       style="background:#C62828;" role="alert">
    <div class="d-flex">
      <div class="toast-body d-flex align-items-center gap-2">
        <i class="bi bi-x-circle-fill"></i>
        <span id="toastErrMsg">เกิดข้อผิดพลาด</span>
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function toggleCategory(catNo, isChecked) {
    var action = isChecked ? 'enable' : 'disable';
    var label  = isChecked ? 'เปิดใช้งาน' : 'ปิดใช้งาน';

    Swal.fire({
        title: label + ' Category นี้?',
        icon: isChecked ? 'question' : 'warning',
        showCancelButton: true,
        confirmButtonColor: isChecked ? '#4CAF50' : '#DC3545',
        cancelButtonColor: '#6C757D',
        confirmButtonText: label,
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true,
        customClass: { popup: 'font-prompt' }
    }).then(function (result) {
        if (result.isConfirmed) {
            document.getElementById('fCatNo').value = catNo;
            document.getElementById('fCatAction').value = action;
            document.getElementById('formToggleCat').submit();
        } else {
            /* ยกเลิก — คืนค่า checkbox กลับ */
            document.getElementById('chkCat' + catNo).checked = !isChecked;
        }
    });
}

function showToast(msg, isError) {
    var id  = isError ? 'toastError' : 'toastSuccess';
    var mid = isError ? 'toastErrMsg' : 'toastMsg';
    document.getElementById(mid).textContent = msg;
    var toast = new bootstrap.Toast(document.getElementById(id), { delay: 3000 });
    toast.show();
}

<?php if ($toastMsg) { ?>
showToast(
    '<?php echo htmlspecialchars(addslashes($toastMsg)); ?>',
    <?php echo ($toastType === 'error') ? 'true' : 'false'; ?>
);
<?php } ?>
</script>

</body>
</html>

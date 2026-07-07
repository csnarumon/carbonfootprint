<?php
/* ==============================================
   includes/_upload_panel.php  v3
   Thumbnail preview รวมใน dropzone กรอบเดียว
   
   กำหนดก่อน include:
     $uploadAssetType = 'Equipment';
     $uploadAssetID   = (int)$r['EquipmentID'];  // 0 = ยังไม่ได้บันทึก
   ============================================== */
$_uType = $uploadAssetType ?? 'Asset';
$_uID   = (int)($uploadAssetID ?? 0);
?>

<div class="mt-3 pt-3" style="border-top:1px solid var(--cfp-border);">
  <div style="font-size:0.85rem;font-weight:600;color:var(--cfp-primary);margin-bottom:8px;">
    <i class="bi bi-images me-1"></i>รูปภาพ / เอกสารแนบ
  </div>

  <?php if ($_uID === 0) { ?>
  <!-- ยังไม่ได้ save record -->
  <div class="cfp-upload-dropzone" style="display:flex;align-items:center;justify-content:center;gap:10px;border-style:dashed;cursor:default;pointer-events:none;opacity:0.7;">
    <i class="bi bi-images" style="font-size:1.4rem;color:var(--cfp-primary);opacity:0.5;"></i>
    <div>
      <div style="font-size:0.82rem;font-weight:600;color:var(--cfp-primary);">รูปภาพ / เอกสารแนบ</div>
      <div style="font-size:0.74rem;color:var(--cfp-text-muted);">บันทึกข้อมูลก่อน แล้วแก้ไขเพื่ออัปโหลด</div>
    </div>
  </div>
  <?php } else { ?>

  <!-- Dropzone — thumbnail grid อยู่ภายใน -->
  <div class="cfp-upload-dropzone"
       id="dropzone_<?php echo $_uID; ?>">
    <!-- JS inject grid + thumbnail cards + ปุ่มเพิ่ม ที่นี่ -->
  </div>
  <input type="file"
         id="fileInput_<?php echo $_uID; ?>"
         accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
         multiple style="display:none;">

  <div style="font-size:0.7rem;color:var(--cfp-text-muted);margin-top:4px;">
    jpg / png / pdf · สูงสุด <?php echo 5; ?> MB / ไฟล์ · สูงสุด 10 ไฟล์ · ลากวางได้
  </div>

  <script>
  if (typeof cfpUploaders === 'undefined') { var cfpUploaders = {}; }

  cfpUploaders[<?php echo $_uID; ?>] = new CfpUploader({
      assetType  : '<?php echo htmlspecialchars($_uType); ?>',
      assetID    : <?php echo $_uID; ?>,
      dropzoneEl : '#dropzone_<?php echo $_uID; ?>',
      inputEl    : '#fileInput_<?php echo $_uID; ?>',
      uploadUrl  : '../master/upload_handler.php',
      csrfToken  : '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>',
  });

  /* โหลดรูปที่เคยอัปไว้ */
  cfpUploaders[<?php echo $_uID; ?>].loadImages();
  </script>
  <?php } ?>
</div>

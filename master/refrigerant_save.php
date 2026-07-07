<?php
/* master/refrigerant_save.php
   Table: CFP_Cooling — อุปกรณ์ทำความเย็น
   แก้ไข: ไม่ส่ง initialPreview/Config (เหมือน vehicle_save.php)
*/
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4));

$conn   = getConnection();
$userID = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

function jsonOut($success, $msg, $extra = array()) {
    $response = array('success' => $success, 'msg' => $msg);
    if (isset($extra['assetID'])) {
        $response['assetID'] = $extra['assetID'];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function cleanStr($val, $maxLen = 300) {
    return mb_substr(trim($val ?? ''), 0, $maxLen);
}

function isValidFileType($filename) {
    $allowed = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf');
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, $allowed);
}

function handleFiles($conn, $userID, $assetID, $assetType, $subdir) {
    $uploadBaseDir = dirname(__DIR__) . '/uploads/assets/' . $subdir . '/';
    if (!is_dir($uploadBaseDir)) { mkdir($uploadBaseDir, 0755, true); }
    $mimeMap = array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','pdf'=>'application/pdf');
    $fc = 0; $fe = array();
    if (!isset($_FILES['file']['name'])) { return array($fc, $fe); }
    $filenames = $_FILES['file']['name']; $filesize = $_FILES['file']['size']; $tmpname = $_FILES['file']['tmp_name'];
    for ($i = 0; $i < count($filenames); $i++) {
        if (empty($filenames[$i]) || $filesize[$i] == 0) { continue; }
        if (!isValidFileType($filenames[$i])) { $fe[] = 'ไม่รองรับ: '.$filenames[$i]; continue; }
        $ext = strtolower(pathinfo($filenames[$i], PATHINFO_EXTENSION));
        $newName = $assetType . '_' . $assetID . '_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($tmpname[$i], $uploadBaseDir . $newName)) {
            $fc++;
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';
            $rs = sqlsrv_query($conn,"SELECT ISNULL(MAX(SortOrder),0)+1 AS N FROM CFP_AssetImage WHERE AssetType=? AND AssetID=?",array($assetType,$assetID));
            $rowSort = sqlsrv_fetch_array($rs,SQLSRV_FETCH_ASSOC);
            $nextSort = $rowSort ? (int)$rowSort['N'] : 1;
            $rc = sqlsrv_query($conn,"SELECT COUNT(*) AS C FROM CFP_AssetImage WHERE AssetType=? AND AssetID=?",array($assetType,$assetID));
            $rowCount = sqlsrv_fetch_array($rc,SQLSRV_FETCH_ASSOC);
            $isPrimary = ($rowCount && (int)$rowCount['C']==0) ? 1 : 0;
            sqlsrv_query($conn,"INSERT INTO CFP_AssetImage (AssetType,AssetID,FileName,OriginalName,FilePath,FileSize,MimeType,SortOrder,IsPrimary,UploadedBy,UploadedDate) VALUES (?,?,?,?,?,?,?,?,?,?,GETDATE())",
                array($assetType,$assetID,$newName,$filenames[$i],$newName,$filesize[$i],$mime,$nextSort,$isPrimary,$userID));
        } else { $fe[] = 'ย้ายไฟล์ไม่ได้: '.$filenames[$i]; }
    }
    return array($fc, $fe);
}

/* ---------- TOGGLE (แก้ไขให้ใช้ redirect แทน JSON) ---------- */
if ($action === 'toggle') {
    if ($id <= 0) {
        header('Content-Type: text/html; charset=utf-8');
        $_SESSION['toast_msg']  = 'ไม่พบรหัสอุปกรณ์';
        $_SESSION['toast_type'] = 'error';
        header('Location: refrigerant.php'); exit;
    }
    $cur    = (int)($_POST['is_active'] ?? 1);
    $newVal = $cur ? 0 : 1;
    $label  = $newVal ? 'เปิดใช้งาน' : 'ปิดใช้งาน';

    sqlsrv_query($conn,
        "UPDATE CFP_Cooling SET IsActive=?,UpdatedBy=?,UpdatedDate=GETDATE() WHERE CoolingID=?",
        array($newVal, $userID, $id));
    logAction($conn, 'DATA_UPDATE', 'CFP_Cooling', $id, null, null, null, $label . 'อุปกรณ์ทำความเย็น ID=' . $id);

    header('Content-Type: text/html; charset=utf-8');
    $_SESSION['toast_msg']  = $label . 'อุปกรณ์ทำความเย็นเรียบร้อยแล้ว';
    $_SESSION['toast_type'] = 'success';
    header('Location: refrigerant.php'); exit;
}

/* ---------- parse fields ---------- */
$code      = strtoupper(cleanStr($_POST['CoolingCode'] ?? '', 50));
$name      = cleanStr($_POST['CoolingName'] ?? '', 300);
$siteID    = (int)($_POST['SiteID'] ?? 0) ?: null;
$refTypeID = (int)($_POST['RefrigerantTypeID'] ?? 0) ?: null;
$brand     = cleanStr($_POST['Brand'] ?? '', 200);
$model     = cleanStr($_POST['Model'] ?? '', 200);
$serial    = cleanStr($_POST['SerialNo'] ?? '', 100);
$cap       = is_numeric($_POST['Capacity'] ?? '') ? (float)$_POST['Capacity'] : null;
$capUnit   = cleanStr($_POST['CapacityUnit'] ?? 'Btu/hr', 50);
$powerKW   = is_numeric($_POST['PowerKW'] ?? '') ? (float)$_POST['PowerKW'] : null;
$charge    = is_numeric($_POST['RefrigerantCharge'] ?? '') ? (float)$_POST['RefrigerantCharge'] : null;
$install   = !empty($_POST['InstallDate']) ? cleanStr($_POST['InstallDate'], 20) : null;
$loc       = cleanStr($_POST['Location'] ?? '', 300);
$remark    = cleanStr($_POST['Remark'] ?? '', 500);

if (empty($name)) {
    jsonOut(false, 'กรุณากรอกชื่ออุปกรณ์');
}

/* ---------- CREATE ---------- */
if ($action === 'create') {
    if (empty($code)) {
        $rx = sqlsrv_query($conn, "SELECT TOP 1 CoolingCode FROM CFP_Cooling ORDER BY CoolingID DESC");
        $rw = sqlsrv_fetch_array($rx, SQLSRV_FETCH_ASSOC);
        $lastCode = $rw ? $rw['CoolingCode'] : 'COOL-000';
        $code = 'COOL-' . str_pad(
            (preg_match('/(\d+)$/', $lastCode, $m) ? (int)$m[1] + 1 : 1),
            3, '0', STR_PAD_LEFT
        );
    }
    $chk = sqlsrv_query($conn, "SELECT 1 FROM CFP_Cooling WHERE CoolingCode=?", array($code));
    if (sqlsrv_fetch($chk)) { jsonOut(false, 'รหัส "' . $code . '" มีอยู่ในระบบแล้ว'); }

    $res = sqlsrv_query($conn,
        "INSERT INTO CFP_Cooling (CoolingCode,CoolingName,SiteID,RefrigerantTypeID,Brand,Model,SerialNo,Capacity,CapacityUnit,PowerKW,RefrigerantCharge,InstallDate,Location,Remark,IsActive,CreatedBy,CreatedDate)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,GETDATE())",
        array($code,$name,$siteID,$refTypeID,$brand?:null,$model?:null,$serial?:null,
              $cap,$capUnit?:null,$powerKW,$charge,$install,$loc?:null,$remark?:null,$userID));
    if (!$res) {
        $e = sqlsrv_errors();
        jsonOut(false, 'บันทึกไม่สำเร็จ: ' . ($e[0]['message'] ?? ''));
    }
    $rID   = sqlsrv_query($conn, "SELECT @@IDENTITY AS NewID");
    $rw    = sqlsrv_fetch_array($rID, SQLSRV_FETCH_ASSOC);
    $newID = isset($rw['NewID']) ? (int)$rw['NewID'] : 0;
    if ($newID <= 0) { jsonOut(false, 'ไม่สามารถสร้างรหัสอุปกรณ์ได้'); }
    
    list($fc, $fe) = handleFiles($conn, $userID, $newID, 'Refrigerant', 'refrigerant');
    logAction($conn, 'DATA_CREATE', 'CFP_Cooling', $newID, null, null, null, 'เพิ่ม: ' . $code . ' ' . $name);
    
    $msg = 'เพิ่มอุปกรณ์ทำความเย็น "' . $name . '" เรียบร้อยแล้ว (ไฟล์ ' . $fc . ' รายการ)';
    if ($fe) { $msg .= ' มีปัญหา: '.implode('; ',$fe); }
    
    // ✅ ส่งเฉพาะ success, msg, assetID (ไม่ส่ง initialPreview/Config)
    jsonOut(true, $msg, array('assetID' => $newID));
}

/* ---------- UPDATE ---------- */
if ($action === 'update') {
    if ($id <= 0) { jsonOut(false, 'ไม่พบรหัสอุปกรณ์'); }
    $chk = sqlsrv_query($conn,
        "SELECT 1 FROM CFP_Cooling WHERE CoolingCode=? AND CoolingID<>?", array($code, $id));
    if (sqlsrv_fetch($chk)) { jsonOut(false, 'รหัส "' . $code . '" มีอยู่ในระบบแล้ว'); }

    $res = sqlsrv_query($conn,
        "UPDATE CFP_Cooling SET CoolingCode=?,CoolingName=?,SiteID=?,RefrigerantTypeID=?,Brand=?,Model=?,SerialNo=?,Capacity=?,CapacityUnit=?,PowerKW=?,RefrigerantCharge=?,InstallDate=?,Location=?,Remark=?,UpdatedBy=?,UpdatedDate=GETDATE()
         WHERE CoolingID=?",
        array($code,$name,$siteID,$refTypeID,$brand?:null,$model?:null,$serial?:null,
              $cap,$capUnit?:null,$powerKW,$charge,$install,$loc?:null,$remark?:null,$userID,$id));
    if (!$res) {
        $e = sqlsrv_errors();
        jsonOut(false, 'แก้ไขไม่สำเร็จ: ' . ($e[0]['message'] ?? ''));
    }
    
    // จัดการ pending_deletes (ไฟล์ที่ mark ลบ)
    $pendingDeletesRaw = trim($_POST['pending_deletes'] ?? '');
    if ($pendingDeletesRaw !== '') {
        $deleteIDs = array_filter(array_map('intval', explode(',', $pendingDeletesRaw)));
        foreach ($deleteIDs as $delID) {
            if ($delID <= 0) continue;
            $rowDel = sqlsrv_fetch_array(
                sqlsrv_query($conn, "SELECT FileName FROM CFP_AssetImage WHERE ImageID=? AND AssetType='Refrigerant' AND AssetID=?", array($delID, $id)),
                SQLSRV_FETCH_ASSOC
            );
            if ($rowDel) {
                $delFile = dirname(__DIR__) . '/uploads/assets/refrigerant/' . $rowDel['FileName'];
                if (file_exists($delFile)) { @unlink($delFile); }
                sqlsrv_query($conn, "DELETE FROM CFP_AssetImage WHERE ImageID=?", array($delID));
            }
        }
    }
    
    list($fc, $fe) = handleFiles($conn, $userID, $id, 'Refrigerant', 'refrigerant');
    logAction($conn, 'DATA_UPDATE', 'CFP_Cooling', $id, null, null, null, 'แก้ไข: ' . $code . ' ' . $name);
    
    $msg = 'แก้ไขอุปกรณ์ทำความเย็น "' . $name . '" เรียบร้อยแล้ว (ไฟล์ ' . $fc . ' รายการ)';
    if ($fe) { $msg .= ' มีปัญหา: '.implode('; ',$fe); }
    
    // ✅ ส่งเฉพาะ success, msg, assetID (ไม่ส่ง initialPreview/Config)
    jsonOut(true, $msg, array('assetID' => $id));
}

jsonOut(false, 'คำขอไม่ถูกต้อง');
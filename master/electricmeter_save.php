<?php
/* master/electricmeter_save.php
   Table: CFP_ElectricMeter — มิเตอร์ไฟฟ้า
   แก้ไข: ไม่ส่ง initialPreview/Config + pending_deletes
*/
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4, 5));

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

/* ---------- TOGGLE ---------- */
if ($action === 'toggle') {
    if ($id <= 0) {
        header('Content-Type: text/html; charset=utf-8');
        $_SESSION['toast_msg']  = 'ไม่พบรหัสมิเตอร์';
        $_SESSION['toast_type'] = 'error';
        header('Location: electricmeter.php'); exit;
    }
    $cur    = (int)($_POST['is_active'] ?? 1);
    $newVal = $cur ? 0 : 1;
    $label  = $newVal ? 'เปิดใช้งาน' : 'ปิดใช้งาน';

    sqlsrv_query($conn,
        "UPDATE CFP_ElectricMeter SET IsActive=?,UpdatedBy=?,UpdatedDate=GETDATE() WHERE MeterID=?",
        array($newVal, $userID, $id));
    logAction($conn, 'DATA_UPDATE', 'CFP_ElectricMeter', $id, null, null, null, $label . 'มิเตอร์ไฟฟ้า ID=' . $id);

    header('Content-Type: text/html; charset=utf-8');
    $_SESSION['toast_msg']  = $label . 'มิเตอร์ไฟฟ้าเรียบร้อยแล้ว';
    $_SESSION['toast_type'] = 'success';
    header('Location: electricmeter.php'); exit;
}

/* ---------- parse fields ---------- */
$code     = strtoupper(cleanStr($_POST['MeterCode'] ?? '', 50));
$name     = cleanStr($_POST['MeterName'] ?? '', 300);
$siteID   = (int)($_POST['SiteID'] ?? 0) ?: null;
$emTypeID = (int)($_POST['ElectricMeterTypeID'] ?? 0) ?: null;
$eSrcID   = (int)($_POST['ElectricSourceID'] ?? 0) ?: null;
$meterNo  = cleanStr($_POST['MeterNo'] ?? '', 100);
$voltage  = is_numeric($_POST['Voltage'] ?? '') ? (float)$_POST['Voltage'] : null;
$phase    = in_array((int)($_POST['Phase'] ?? 0), array(1, 3)) ? (int)$_POST['Phase'] : null;
$maxLoad  = is_numeric($_POST['MaxLoad'] ?? '') ? (float)$_POST['MaxLoad'] : null;
$install  = !empty($_POST['InstallDate']) ? cleanStr($_POST['InstallDate'], 20) : null;
$loc      = cleanStr($_POST['Location'] ?? '', 300);
$remark   = cleanStr($_POST['Remark'] ?? '', 500);

if (empty($name)) {
    jsonOut(false, 'กรุณากรอกชื่อมิเตอร์ไฟฟ้า');
}

if (empty($code) && $siteID) {
    $rSC = sqlsrv_query($conn, "SELECT SiteCode FROM CFP_Site WHERE SiteID=?", array($siteID));
    $rSCRow = $rSC ? sqlsrv_fetch_array($rSC, SQLSRV_FETCH_ASSOC) : null;
    $sc = $rSCRow ? $rSCRow['SiteCode'] : 'SITE';
    $rMax = sqlsrv_query($conn,
        "SELECT MAX(MeterCode) AS MaxCode FROM CFP_ElectricMeter WHERE MeterCode LIKE ?",
        array('EM-' . $sc . '-%'));
    $rMaxRow = $rMax ? sqlsrv_fetch_array($rMax, SQLSRV_FETCH_ASSOC) : null;
    $nextNum = 1;
    if ($rMaxRow && $rMaxRow['MaxCode']) {
        if (preg_match('/(\d+)$/', $rMaxRow['MaxCode'], $m)) { $nextNum = (int)$m[1] + 1; }
    }
    $code = 'EM-' . $sc . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
}

/* ---------- CREATE ---------- */
if ($action === 'create') {
    $chk = sqlsrv_query($conn, "SELECT 1 FROM CFP_ElectricMeter WHERE MeterCode=?", array($code));
    if (sqlsrv_fetch($chk)) { jsonOut(false, 'รหัส "' . $code . '" มีอยู่ในระบบแล้ว'); }

    $res = sqlsrv_query($conn,
        "INSERT INTO CFP_ElectricMeter (MeterCode,MeterName,SiteID,ElectricMeterTypeID,ElectricSourceID,MeterNo,Voltage,Phase,MaxLoad,InstallDate,Location,Remark,IsActive,CreatedBy,CreatedDate)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,GETDATE())",
        array($code,$name,$siteID,$emTypeID,$eSrcID,$meterNo?:null,
              $voltage,$phase,$maxLoad,$install,$loc?:null,$remark?:null,$userID));
    if (!$res) {
        $e = sqlsrv_errors();
        jsonOut(false, 'บันทึกไม่สำเร็จ: ' . ($e[0]['message'] ?? ''));
    }
    $rID   = sqlsrv_query($conn, "SELECT @@IDENTITY AS NewID");
    $rw    = sqlsrv_fetch_array($rID, SQLSRV_FETCH_ASSOC);
    $newID = isset($rw['NewID']) ? (int)$rw['NewID'] : 0;
    if ($newID <= 0) { jsonOut(false, 'ไม่สามารถสร้างรหัสมิเตอร์ได้'); }
    
    list($fc, $fe) = handleFiles($conn, $userID, $newID, 'ElectricMeter', 'electricmeter');
    logAction($conn, 'DATA_CREATE', 'CFP_ElectricMeter', $newID, null, null, null, 'เพิ่ม: ' . $code . ' ' . $name);
    
    $msg = 'เพิ่มมิเตอร์ไฟฟ้า "' . $name . '" เรียบร้อยแล้ว (ไฟล์ ' . $fc . ' รายการ)';
    if ($fe) { $msg .= ' มีปัญหา: '.implode('; ',$fe); }
    
    jsonOut(true, $msg, array('assetID' => $newID));
}

/* ---------- UPDATE ---------- */
if ($action === 'update') {
    if ($id <= 0) { jsonOut(false, 'ไม่พบรหัสมิเตอร์'); }
    $chk = sqlsrv_query($conn,
        "SELECT 1 FROM CFP_ElectricMeter WHERE MeterCode=? AND MeterID<>?", array($code, $id));
    if (sqlsrv_fetch($chk)) { jsonOut(false, 'รหัส "' . $code . '" มีอยู่ในระบบแล้ว'); }

    $res = sqlsrv_query($conn,
        "UPDATE CFP_ElectricMeter SET MeterCode=?,MeterName=?,SiteID=?,ElectricMeterTypeID=?,ElectricSourceID=?,MeterNo=?,Voltage=?,Phase=?,MaxLoad=?,InstallDate=?,Location=?,Remark=?,UpdatedBy=?,UpdatedDate=GETDATE()
         WHERE MeterID=?",
        array($code,$name,$siteID,$emTypeID,$eSrcID,$meterNo?:null,
              $voltage,$phase,$maxLoad,$install,$loc?:null,$remark?:null,$userID,$id));
    if (!$res) {
        $e = sqlsrv_errors();
        jsonOut(false, 'แก้ไขไม่สำเร็จ: ' . ($e[0]['message'] ?? ''));
    }
    
    // ===== จัดการ pending_deletes =====
    $pendingDeletesRaw = trim($_POST['pending_deletes'] ?? '');
    if ($pendingDeletesRaw !== '') {
        $deleteIDs = array_filter(array_map('intval', explode(',', $pendingDeletesRaw)));
        foreach ($deleteIDs as $delID) {
            if ($delID <= 0) continue;
            $rowDel = sqlsrv_fetch_array(
                sqlsrv_query($conn, "SELECT FileName FROM CFP_AssetImage WHERE ImageID=? AND AssetType='ElectricMeter' AND AssetID=?", array($delID, $id)),
                SQLSRV_FETCH_ASSOC
            );
            if ($rowDel) {
                $delFile = dirname(__DIR__) . '/uploads/assets/electricmeter/' . $rowDel['FileName'];
                if (file_exists($delFile)) { @unlink($delFile); }
                sqlsrv_query($conn, "DELETE FROM CFP_AssetImage WHERE ImageID=?", array($delID));
            }
        }
    }
    
    list($fc, $fe) = handleFiles($conn, $userID, $id, 'ElectricMeter', 'electricmeter');
    logAction($conn, 'DATA_UPDATE', 'CFP_ElectricMeter', $id, null, null, null, 'แก้ไข: ' . $code . ' ' . $name);
    
    $msg = 'แก้ไขมิเตอร์ไฟฟ้า "' . $name . '" เรียบร้อยแล้ว (ไฟล์ ' . $fc . ' รายการ)';
    if ($fe) { $msg .= ' มีปัญหา: '.implode('; ',$fe); }
    
    jsonOut(true, $msg, array('assetID' => $id));
}

jsonOut(false, 'คำขอไม่ถูกต้อง');
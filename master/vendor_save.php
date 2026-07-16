<?php
/* master/vendor_save.php — ทะเบียนชาวสวน Table: CFP_Vendor
   แก้ไข: ใช้ตารางจริง (TransportDist, VendorType, ProductType, TaxID)
*/
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4, 5));
header('Content-Type: application/json; charset=utf-8');

$conn   = getConnection();
$userID = (int)$_SESSION['user_id'];
$action = $_REQUEST['action'] ?? '';
$id     = (int)($_REQUEST['id'] ?? 0);

function jsonOut($success, $msg, $extra = array()) {
    $response = array('success' => $success, 'msg' => $msg);
    if (isset($extra['assetID'])) {
        $response['assetID'] = $extra['assetID'];
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function cleanStr($v, $l = 300) {
    return mb_substr(trim($v ?? ''), 0, $l);
}

function isValidFileType($f) {
    return in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), array('jpg','jpeg','png','gif','webp','pdf'));
}

function handleFiles($conn, $uid, $aid, $atype, $sub) {
    $dir = dirname(__DIR__) . '/uploads/assets/' . $sub . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $mime = array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','pdf'=>'application/pdf');
    $fc = 0; $fe = array();
    if (!isset($_FILES['file']['name'])) return array($fc, $fe);
    $fn = $_FILES['file']['name']; $fs = $_FILES['file']['size']; $ft = $_FILES['file']['tmp_name'];
    for ($i = 0; $i < count($fn); $i++) {
        if (empty($fn[$i]) || $fs[$i] == 0) continue;
        if (!isValidFileType($fn[$i])) { $fe[] = 'ไม่รองรับ: '.$fn[$i]; continue; }
        $ext = strtolower(pathinfo($fn[$i], PATHINFO_EXTENSION));
        $nn = $atype . '_' . $aid . '_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($ft[$i], $dir . $nn)) {
            $fc++;
            $m = $mime[$ext] ?? 'application/octet-stream';
            $rs = sqlsrv_query($conn, "SELECT ISNULL(MAX(SortOrder),0)+1 AS N FROM CFP_AssetImage WHERE AssetType=? AND AssetID=?", array($atype, $aid));
            $rowSort = sqlsrv_fetch_array($rs, SQLSRV_FETCH_ASSOC);
            $ns = $rowSort ? (int)$rowSort['N'] : 1;
            $rc = sqlsrv_query($conn, "SELECT COUNT(*) AS C FROM CFP_AssetImage WHERE AssetType=? AND AssetID=?", array($atype, $aid));
            $rowCount = sqlsrv_fetch_array($rc, SQLSRV_FETCH_ASSOC);
            $ip = ($rowCount && (int)$rowCount['C'] == 0) ? 1 : 0;
            sqlsrv_query($conn,
                "INSERT INTO CFP_AssetImage (AssetType,AssetID,FileName,OriginalName,FilePath,FileSize,MimeType,SortOrder,IsPrimary,UploadedBy,UploadedDate)
                 VALUES (?,?,?,?,?,?,?,?,?,?,GETDATE())",
                array($atype, $aid, $nn, $fn[$i], $nn, $fs[$i], $m, $ns, $ip, $uid)
            );
        } else { $fe[] = 'ย้ายไม่ได้: '.$fn[$i]; }
    }
    return array($fc, $fe);
}

/* ---------- TOGGLE ---------- */
if ($action === 'toggle') {
    if ($id <= 0) {
        header('Content-Type: text/html; charset=utf-8');
        $_SESSION['toast_msg']  = 'ไม่พบรหัสชาวสวน';
        $_SESSION['toast_type'] = 'error';
        header('Location: vendor.php'); exit;
    }
    $cur    = (int)($_REQUEST['is_active'] ?? 1);
    $newVal = $cur ? 0 : 1;
    $label  = $newVal ? 'เปิดใช้งาน' : 'ปิดใช้งาน';

    sqlsrv_query($conn,
        "UPDATE CFP_Vendor SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE VendorID=?",
        array($newVal, $userID, $id)
    );
    logAction($conn, 'DATA_UPDATE', 'CFP_Vendor', $id, null, null, null, $label . 'ชาวสวน ID=' . $id);

    header('Content-Type: text/html; charset=utf-8');
    $_SESSION['toast_msg']  = $label . 'ชาวสวนเรียบร้อยแล้ว';
    $_SESSION['toast_type'] = 'success';
    header('Location: vendor.php'); exit;
}

/* ---------- parse fields ---------- */
$code     = strtoupper(cleanStr($_REQUEST['VendorCode'] ?? '', 50));
$name     = cleanStr($_REQUEST['VendorName'] ?? '', 300);
$contact  = cleanStr($_REQUEST['ContactName'] ?? '', 200);
$phone    = cleanStr($_REQUEST['Phone'] ?? '', 50);
$address  = cleanStr($_REQUEST['Address'] ?? '', 500);
$province = cleanStr($_REQUEST['Province'] ?? '', 100);
$taxID    = cleanStr($_REQUEST['TaxID'] ?? '', 20);
$vendorType = cleanStr($_REQUEST['VendorType'] ?? '', 100);
$productType = cleanStr($_REQUEST['ProductType'] ?? '', 200);
$transportDist = is_numeric($_REQUEST['TransportDist'] ?? '') ? (float)$_REQUEST['TransportDist'] : null;
$remark   = cleanStr($_REQUEST['Remark'] ?? '', 500);
$siteID   = (int)($_REQUEST['SiteID'] ?? 0);

if (empty($code) || empty($name)) {
    jsonOut(false, 'กรุณากรอกรหัสและชื่อชาวสวน');
}
if ($siteID <= 0) {
    jsonOut(false, 'กรุณาเลือก Site');
}

$AT = 'Vendor';

/* ---------- CREATE ---------- */
if ($action === 'create') {
    $chk = sqlsrv_query($conn, "SELECT 1 FROM CFP_Vendor WHERE VendorCode=?", array($code));
    if (sqlsrv_fetch($chk)) { jsonOut(false, 'รหัส "' . $code . '" มีอยู่ในระบบแล้ว'); }

    $res = sqlsrv_query($conn,
        "INSERT INTO CFP_Vendor (VendorCode, VendorName, VendorType, ContactName, Phone, Address,
                                 Province, TaxID, ProductType, TransportDist, Remark, SiteID,
                                 IsActive, CreatedBy, CreatedDate)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,GETDATE())",
        array($code, $name, $vendorType ?: null, $contact ?: null, $phone ?: null,
              $address ?: null, $province ?: null, $taxID ?: null, $productType ?: null,
              $transportDist, $remark ?: null, $siteID, $userID)
    );
    if (!$res) {
        $e = sqlsrv_errors();
        jsonOut(false, 'บันทึกไม่สำเร็จ: ' . ($e[0]['message'] ?? ''));
    }

    $rw = sqlsrv_fetch_array(sqlsrv_query($conn, "SELECT @@IDENTITY AS NewID"), SQLSRV_FETCH_ASSOC);
    $newID = isset($rw['NewID']) ? (int)$rw['NewID'] : 0;
    if ($newID <= 0) { jsonOut(false, 'ไม่สามารถสร้างรหัสได้'); }

    list($fc, $fe) = handleFiles($conn, $userID, $newID, $AT, 'vendor');
    logAction($conn, 'DATA_CREATE', 'CFP_Vendor', $newID, null, null, null, 'เพิ่ม: ' . $code . ' ' . $name);

    $msg = 'เพิ่มชาวสวน "' . $name . '" เรียบร้อยแล้ว (ไฟล์ ' . $fc . ' รายการ)';
    if ($fe) { $msg .= ' มีปัญหา: ' . implode('; ', $fe); }

    jsonOut(true, $msg, array('assetID' => $newID));
}

/* ---------- UPDATE ---------- */
if ($action === 'update') {
    if ($id <= 0) { jsonOut(false, 'ไม่พบรหัส'); }

    $chk = sqlsrv_query($conn, "SELECT 1 FROM CFP_Vendor WHERE VendorCode=? AND VendorID<>?", array($code, $id));
    if (sqlsrv_fetch($chk)) { jsonOut(false, 'รหัส "' . $code . '" มีอยู่ในระบบแล้ว'); }

    $res = sqlsrv_query($conn,
        "UPDATE CFP_Vendor SET
            VendorCode = ?,
            VendorName = ?,
            VendorType = ?,
            ContactName = ?,
            Phone = ?,
            Address = ?,
            Province = ?,
            TaxID = ?,
            ProductType = ?,
            TransportDist = ?,
            Remark = ?,
            SiteID = ?,
            UpdatedBy = ?,
            UpdatedDate = GETDATE()
         WHERE VendorID = ?",
        array($code, $name, $vendorType ?: null, $contact ?: null, $phone ?: null,
              $address ?: null, $province ?: null, $taxID ?: null, $productType ?: null,
              $transportDist, $remark ?: null, $siteID, $userID, $id)
    );
    if (!$res) {
        $e = sqlsrv_errors();
        jsonOut(false, 'แก้ไขไม่สำเร็จ: ' . ($e[0]['message'] ?? ''));
    }

    // ===== จัดการ pending_deletes =====
    $pendingDeletesRaw = trim($_REQUEST['pending_deletes'] ?? '');
    if ($pendingDeletesRaw !== '') {
        $deleteIDs = array_filter(array_map('intval', explode(',', $pendingDeletesRaw)));
        foreach ($deleteIDs as $delID) {
            if ($delID <= 0) continue;
            $rowDel = sqlsrv_fetch_array(
                sqlsrv_query($conn,
                    "SELECT FileName FROM CFP_AssetImage WHERE ImageID=? AND AssetType='Vendor' AND AssetID=?",
                    array($delID, $id)
                ),
                SQLSRV_FETCH_ASSOC
            );
            if ($rowDel) {
                $delFile = dirname(__DIR__) . '/uploads/assets/vendor/' . $rowDel['FileName'];
                if (file_exists($delFile)) { @unlink($delFile); }
                sqlsrv_query($conn, "DELETE FROM CFP_AssetImage WHERE ImageID=?", array($delID));
            }
        }
    }

    list($fc, $fe) = handleFiles($conn, $userID, $id, $AT, 'vendor');
    logAction($conn, 'DATA_UPDATE', 'CFP_Vendor', $id, null, null, null, 'แก้ไข: ' . $code . ' ' . $name);

    $msg = 'แก้ไขชาวสวน "' . $name . '" เรียบร้อยแล้ว (ไฟล์ ' . $fc . ' รายการ)';
    if ($fe) { $msg .= ' มีปัญหา: ' . implode('; ', $fe); }

    jsonOut(true, $msg, array('assetID' => $id));
}

jsonOut(false, 'คำขอไม่ถูกต้อง');
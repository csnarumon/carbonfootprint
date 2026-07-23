<?php
/* ==============================================
   master/upload_handler.php
   รับ AJAX จาก Krajee (สำรอง – ไม่ใช้ใน Flow ปกติ)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? '';
$assetType = $_POST['asset_type'] ?? 'Equipment';
$assetID = (int)($_POST['asset_id'] ?? 0);
$csrfToken = $_POST['csrf_token'] ?? '';

if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['success' => false, 'msg' => 'CSRF token mismatch']);
    exit;
}

$conn = getConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'msg' => 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ']);
    exit;
}

$userID = (int)$_SESSION['user_id'];

function jsonOut($success, $msg = '', $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function isValidFileType($filename) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, $allowed);
}

if ($action !== 'upload' && $assetID <= 0) {
    jsonOut(false, 'Asset ID ไม่ถูกต้อง');
}

$uploadBaseDir = dirname(__DIR__) . '/uploads/assets/' . strtolower($assetType) . '/';
$uploadBaseUrl = '/carbonfootprint/uploads/assets/' . strtolower($assetType) . '/';
if (!is_dir($uploadBaseDir)) mkdir($uploadBaseDir, 0755, true);

if ($action === 'list') {
    $sql = "SELECT ImageID, FileName, OriginalName, FileSize, MimeType, IsPrimary, Caption
            FROM CFP_AssetImage WHERE AssetType = ? AND AssetID = ? ORDER BY IsPrimary DESC, SortOrder ASC";
    $res = sqlsrv_query($conn, $sql, [$assetType, $assetID]);
    $images = [];
    while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
        $images[] = [
            'id'        => (int)$row['ImageID'],
            'caption'   => $row['OriginalName'],
            'size'      => (int)$row['FileSize'],
            'url'       => $uploadBaseUrl . $row['FileName'],
            'isPrimary' => (bool)$row['IsPrimary'],
        ];
    }
    jsonOut(true, '', ['images' => $images]);
}

if ($action === 'upload') {
    if ($assetID <= 0) jsonOut(false, 'ไม่พบ Asset ID');
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonOut(false, 'ไม่พบไฟล์');
    }
    $file = $_FILES['file'];
    $origName = basename($file['name']);
    $size = $file['size'];
    $tmpPath = $file['tmp_name'];
    if (!isValidFileType($origName)) jsonOut(false, 'ประเภทไฟล์ไม่รองรับ');
    if ($size > 5*1024*1024) jsonOut(false, 'ไฟล์เกิน 5 MB');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $newName = $assetType . '_' . $assetID . '_' . uniqid() . '.' . $ext;
    $destPath = $uploadBaseDir . $newName;
    if (!move_uploaded_file($tmpPath, $destPath)) jsonOut(false, 'ไม่สามารถบันทึกไฟล์');
    $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','pdf'=>'application/pdf'];
    $mime = $mimeMap[$ext] ?? 'application/octet-stream';
    $resSort = sqlsrv_query($conn, "SELECT ISNULL(MAX(SortOrder),0)+1 AS NextSort FROM CFP_AssetImage WHERE AssetType=? AND AssetID=?", [$assetType, $assetID]);
    $rowSort = sqlsrv_fetch_array($resSort, SQLSRV_FETCH_ASSOC);
    $nextSort = $rowSort ? (int)$rowSort['NextSort'] : 1;
    $resCount = sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_AssetImage WHERE AssetType=? AND AssetID=?", [$assetType, $assetID]);
    $rowCount = sqlsrv_fetch_array($resCount, SQLSRV_FETCH_ASSOC);
    $isPrimary = ($rowCount && (int)$rowCount['Cnt'] == 0) ? 1 : 0;
    $sql = "INSERT INTO CFP_AssetImage (AssetType, AssetID, FileName, OriginalName, FilePath, FileSize, MimeType, SortOrder, IsPrimary, UploadedBy, UploadedDate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
    $params = [$assetType, $assetID, $newName, $origName, $newName, $size, $mime, $nextSort, $isPrimary, $userID];
    sqlsrv_query($conn, $sql, $params);
    $resID = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS NewID");
    $rowID = sqlsrv_fetch_array($resID, SQLSRV_FETCH_ASSOC);
    $newID = $rowID ? (int)$rowID['NewID'] : 0;
    logAction($conn, 'FILE_UPLOAD', 'CFP_AssetImage', $newID, null, null, null, 'Upload: ' . $origName);
    jsonOut(true, 'อัปโหลดสำเร็จ', [
        'image' => [
            'id'        => $newID,
            'caption'   => $origName,
            'size'      => $size,
            'url'       => $uploadBaseUrl . $newName,
            'isPrimary' => (bool)$isPrimary,
        ]
    ]);
}

if ($action === 'delete') {
    $imageID = (int)($_POST['image_id'] ?? 0);
    if (!$imageID) jsonOut(false, 'ไม่พบ ID รูป');
    $sql = "SELECT FileName FROM CFP_AssetImage WHERE ImageID = ? AND AssetType = ? AND AssetID = ?";
    $res = sqlsrv_query($conn, $sql, [$imageID, $assetType, $assetID]);
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    if (!$row) jsonOut(false, 'ไม่พบรูป');
    $filePath = $uploadBaseDir . $row['FileName'];
    if (file_exists($filePath)) @unlink($filePath);
    sqlsrv_query($conn, "DELETE FROM CFP_AssetImage WHERE ImageID = ?", [$imageID]);
    $resCheck = sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_AssetImage WHERE AssetType=? AND AssetID=?", [$assetType, $assetID]);
    $rowCheck = sqlsrv_fetch_array($resCheck, SQLSRV_FETCH_ASSOC);
    if ($rowCheck && (int)$rowCheck['Cnt'] > 0) {
        sqlsrv_query($conn, "UPDATE CFP_AssetImage SET IsPrimary=1 WHERE ImageID = (SELECT TOP 1 ImageID FROM CFP_AssetImage WHERE AssetType=? AND AssetID=? ORDER BY SortOrder ASC)", [$assetType, $assetID]);
    }
    logAction($conn, 'FILE_DELETE', 'CFP_AssetImage', $imageID, null, null, null, 'Delete: ' . $row['FileName']);
    jsonOut(true, 'ลบไฟล์สำเร็จ');
}

if ($action === 'set_primary') {
    $imageID = (int)($_POST['image_id'] ?? 0);
    if (!$imageID) jsonOut(false, 'ไม่พบ ID รูป');
    sqlsrv_query($conn, "UPDATE CFP_AssetImage SET IsPrimary=0 WHERE AssetType=? AND AssetID=?", [$assetType, $assetID]);
    sqlsrv_query($conn, "UPDATE CFP_AssetImage SET IsPrimary=1 WHERE ImageID=?", [$imageID]);
    logAction($conn, 'SET_PRIMARY', 'CFP_AssetImage', $imageID, null, null, null, 'Set primary');
    jsonOut(true, 'ตั้งรูปหลักสำเร็จ');
}

jsonOut(false, 'คำขอไม่ถูกต้อง');
<?php
/* ==============================================
   master/get_asset_images.php
   ดึงรายการรูปภาพสำหรับ Krajee initialPreview
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
header('Content-Type: application/json; charset=utf-8');

$assetType = $_POST['asset_type'] ?? 'Equipment';
$assetID = (int)($_POST['asset_id'] ?? 0);

if ($assetID <= 0) {
    echo json_encode(['success' => false, 'msg' => 'ไม่พบ Asset ID']);
    exit;
}

$conn = getConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'msg' => 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ']);
    exit;
}

$uploadBaseUrl = '/carbonfootprint/uploads/assets/' . strtolower($assetType) . '/';

$sql = "SELECT ImageID, FileName, OriginalName, FileSize, MimeType, IsPrimary, Caption
        FROM CFP_AssetImage
        WHERE AssetType = ? AND AssetID = ?
        ORDER BY IsPrimary DESC, SortOrder ASC";
$res = sqlsrv_query($conn, $sql, [$assetType, $assetID]);

if (!$res) {
    $errors = sqlsrv_errors();
    error_log("SQL Error (get_asset_images): " . print_r($errors, true));
    echo json_encode(['success' => false, 'msg' => 'เกิดข้อผิดพลาดในการดึงข้อมูล']);
    exit;
}

$images = [];
while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
    $mime = $row['MimeType'] ?? '';
    if ($mime === 'application/pdf') {
        $fileType = 'pdf';
    } elseif (strpos($mime, 'image/') === 0) {
        $fileType = 'image';
    } else {
        $fileType = 'other';
    }
    $images[] = [
        'id'       => (int)$row['ImageID'],
        'caption'  => $row['OriginalName'],
        'size'     => (int)$row['FileSize'],
        'url'      => $uploadBaseUrl . $row['FileName'],
        'isPrimary'=> (bool)$row['IsPrimary'],
        'fileType' => $fileType,
    ];
}

echo json_encode(['success' => true, 'images' => $images]);
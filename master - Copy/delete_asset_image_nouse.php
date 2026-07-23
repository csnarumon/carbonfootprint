<?php
/* ==============================================
   master/delete_asset_image.php
   ลบไฟล์ (ใช้ใน event filepredelete ของ Krajee)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
header('Content-Type: application/json; charset=utf-8');

$fileid = (int)($_POST['fileid'] ?? $_POST['key'] ?? 0); // รับทั้ง fileid (custom) และ key (Krajee default)
$assetType = $_POST['asset_type'] ?? 'Equipment';
$assetID = (int)($_POST['asset_id'] ?? 0);

if (!$fileid || $assetID <= 0) {
    echo json_encode(['success' => false, 'msg' => 'ข้อมูลไม่ครบ']);
    exit;
}

$conn = getConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'msg' => 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ']);
    exit;
}

$uploadBaseDir = dirname(__DIR__) . '/uploads/assets/' . strtolower($assetType) . '/';

// ดึงข้อมูลไฟล์
$sql = "SELECT FileName FROM CFP_AssetImage WHERE ImageID = ? AND AssetType = ? AND AssetID = ?";
$res = sqlsrv_query($conn, $sql, [$fileid, $assetType, $assetID]);
if (!$res) {
    $errors = sqlsrv_errors();
    error_log("SQL Error (delete select): " . print_r($errors, true));
    echo json_encode(['success' => false, 'msg' => 'เกิดข้อผิดพลาดในการดึงข้อมูลไฟล์']);
    exit;
}
$row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
if ($row) {
    $filePath = $uploadBaseDir . $row['FileName'];
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
}

// ลบ record
$resDel = sqlsrv_query($conn, "DELETE FROM CFP_AssetImage WHERE ImageID = ?", [$fileid]);
if (!$resDel) {
    $errors = sqlsrv_errors();
    error_log("SQL Error (delete record): " . print_r($errors, true));
    echo json_encode(['success' => false, 'msg' => 'เกิดข้อผิดพลาดในการลบข้อมูล']);
    exit;
}

// ตั้ง primary ใหม่ถ้าจำเป็น
$resCheck = sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_AssetImage WHERE AssetType=? AND AssetID=?", [$assetType, $assetID]);
if ($resCheck) {
    $rowCheck = sqlsrv_fetch_array($resCheck, SQLSRV_FETCH_ASSOC);
    if ($rowCheck && (int)$rowCheck['Cnt'] > 0) {
        sqlsrv_query($conn, "UPDATE CFP_AssetImage SET IsPrimary=1 WHERE ImageID = (SELECT TOP 1 ImageID FROM CFP_AssetImage WHERE AssetType=? AND AssetID=? ORDER BY SortOrder ASC)", [$assetType, $assetID]);
    }
}

logAction($conn, 'FILE_DELETE', 'CFP_AssetImage', $fileid, null, null, null, 'Delete: ' . ($row ? $row['FileName'] : 'unknown') . ' AssetType=' . $assetType);

echo json_encode(['success' => true, 'msg' => 'ลบไฟล์เรียบร้อยแล้ว']);
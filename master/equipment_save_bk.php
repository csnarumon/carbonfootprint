<?php
/* ==============================================
   master/equipment_save.php
   รับ POST + ไฟล์จาก Krajee (แก้ไขแล้ว - ใช้ @@IDENTITY)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4));

header('Content-Type: application/json; charset=utf-8');

$conn = getConnection();
$userID = (int)$_SESSION['user_id'];

$action = $_REQUEST['action'] ?? '';
$id = (int)($_REQUEST['id'] ?? 0);

// ===== Debug =====
error_log("=== equipment_save.php called ===");
error_log("Action: $action, ID: $id");
error_log("POST: " . print_r($_POST, true));
error_log("FILES: " . print_r($_FILES, true));

// CSRF
$csrfToken = $_REQUEST['csrf_token'] ?? '';
if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    $csrfToken = $_SESSION['csrf_token'];
    error_log("CSRF mismatch - using session token");
}

function jsonOut($success, $msg, $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function cleanStr($val, $maxLen = 300) {
    return mb_substr(trim($val ?? ''), 0, $maxLen);
}

function isValidFileType($filename) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, $allowed);
}

// ==============================================
// CREATE
// ==============================================
if ($action === 'create' || $action === 'add') {
    $code = strtoupper(cleanStr($_REQUEST['EquipmentCode'] ?? '', 50));
    $name = cleanStr($_REQUEST['EquipmentName'] ?? '', 300);
    $typeID = (int)($_REQUEST['EquipmentTypeID'] ?? 0) ?: null;
    $siteID = (int)($_REQUEST['SiteID'] ?? 0) ?: null;
    $fuelTypeID = (int)($_REQUEST['FuelTypeID'] ?? 0) ?: null;
    $combustion = cleanStr($_REQUEST['CombustionType'] ?? 'Stationary', 50);
    $cap = is_numeric($_REQUEST['Capacity'] ?? '') ? (float)$_REQUEST['Capacity'] : null;
    $capUnit = cleanStr($_REQUEST['CapacityUnit'] ?? '', 50);
    $year = is_numeric($_REQUEST['YearInstall'] ?? '') ? (int)$_REQUEST['YearInstall'] : null;
    $remark = cleanStr($_REQUEST['Remark'] ?? '', 500);

    if (empty($code) || empty($name)) {
        jsonOut(false, 'กรุณากรอกรหัสและชื่อเครื่องจักร');
    }

    // ตรวจสอบรหัสซ้ำ
    $chk = sqlsrv_query($conn, "SELECT 1 FROM CFP_Equipment WHERE EquipmentCode = ?", [$code]);
    if (sqlsrv_fetch($chk)) {
        jsonOut(false, 'รหัสเครื่องจักร "' . $code . '" มีอยู่ในระบบแล้ว');
    }

    // ✅ INSERT equipment
    $sql = "INSERT INTO CFP_Equipment
            (EquipmentCode, EquipmentName, EquipmentTypeID, SiteID, FuelTypeID, CombustionType,
             Capacity, CapacityUnit, YearInstall, Remark, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, GETDATE())";
    $params = [$code, $name, $typeID, $siteID, $fuelTypeID, $combustion, $cap, $capUnit, $year, $remark, $userID];
    $res = sqlsrv_query($conn, $sql, $params);
    if (!$res) {
        $errors = sqlsrv_errors();
        error_log("SQL Error (create): " . print_r($errors, true));
        jsonOut(false, 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . ($errors[0]['message'] ?? ''));
    }

    // ✅ ตรวจสอบว่า INSERT สำเร็จ
    $rowsAffected = sqlsrv_rows_affected($res);
    error_log("Rows affected: " . ($rowsAffected === false ? 'false' : $rowsAffected));
    if ($rowsAffected === false || $rowsAffected == 0) {
        jsonOut(false, 'ไม่สามารถเพิ่มข้อมูลได้');
    }

    // ✅ ดึง ID ด้วย @@IDENTITY (ใช้แทน SCOPE_IDENTITY() เพื่อความแน่นอน)
    $sqlID = "SELECT @@IDENTITY AS NewID";
    $resID = sqlsrv_query($conn, $sqlID);
    if (!$resID) {
        $errors = sqlsrv_errors();
        error_log("SQL Error (get ID): " . print_r($errors, true));
        jsonOut(false, 'ไม่สามารถดึงรหัสเครื่องจักรได้');
    }
    $row = sqlsrv_fetch_array($resID, SQLSRV_FETCH_ASSOC);
    if (!$row) {
        error_log("No row returned from @@IDENTITY");
        jsonOut(false, 'ไม่สามารถดึงรหัสเครื่องจักรได้');
    }
    $newID = isset($row['NewID']) ? (int)$row['NewID'] : 0;
    error_log("✅ New EquipmentID (from @@IDENTITY): $newID");

    if ($newID <= 0) {
        error_log("❌ ERROR: Failed to get new EquipmentID");
        jsonOut(false, 'ไม่สามารถสร้างรหัสเครื่องจักรได้');
    }

    // ===== จัดการไฟล์ =====
    $uploadBaseDir = dirname(__DIR__) . '/uploads/assets/equipment/';
    if (!is_dir($uploadBaseDir)) mkdir($uploadBaseDir, 0755, true);
    $uploadBaseUrl = '/carbonfootprint/uploads/assets/equipment/';

    $fileCount = 0;
    $fileErrors = [];

    if (isset($_FILES['file']) && isset($_FILES['file']['name'])) {
        $filenames = $_FILES['file']['name'];
        $filesize  = $_FILES['file']['size'];
        $tmpname   = $_FILES['file']['tmp_name'];

        error_log("📁 Total files: " . count($filenames));

        for ($i = 0; $i < count($filenames); $i++) {
            if (empty($filenames[$i]) || $filesize[$i] == 0) {
                error_log("File $i is empty or size 0");
                continue;
            }

            if (!isValidFileType($filenames[$i])) {
                $fileErrors[] = "ไฟล์ " . $filenames[$i] . " ไม่รองรับ";
                error_log("Invalid file type: " . $filenames[$i]);
                continue;
            }

            $ext = strtolower(pathinfo($filenames[$i], PATHINFO_EXTENSION));
            $newName = 'Equipment_' . $newID . '_' . uniqid() . '.' . $ext;
            $destPath = $uploadBaseDir . $newName;

            error_log("📁 Moving file $i to $destPath");

            if (move_uploaded_file($tmpname[$i], $destPath)) {
                error_log("✅ File moved successfully");
                $fileCount++;

                $mimeMap = [
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png'  => 'image/png',
                    'gif'  => 'image/gif',
                    'webp' => 'image/webp',
                    'pdf'  => 'application/pdf'
                ];
                $mime = $mimeMap[$ext] ?? 'application/octet-stream';

                $resSort = sqlsrv_query($conn, "SELECT ISNULL(MAX(SortOrder),0)+1 AS NextSort FROM CFP_AssetImage WHERE AssetType='Equipment' AND AssetID=?", [$newID]);
                $rowSort = sqlsrv_fetch_array($resSort, SQLSRV_FETCH_ASSOC);
                $nextSort = $rowSort ? (int)$rowSort['NextSort'] : 1;

                $resCount = sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_AssetImage WHERE AssetType='Equipment' AND AssetID=?", [$newID]);
                $rowCount = sqlsrv_fetch_array($resCount, SQLSRV_FETCH_ASSOC);
                $isPrimary = ($rowCount && (int)$rowCount['Cnt'] == 0) ? 1 : 0;

                $sqlImg = "INSERT INTO CFP_AssetImage (AssetType, AssetID, FileName, OriginalName, FilePath, FileSize, MimeType, SortOrder, IsPrimary, UploadedBy, UploadedDate)
                           VALUES ('Equipment', ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
                $paramsImg = [$newID, $newName, $filenames[$i], $newName, $filesize[$i], $mime, $nextSort, $isPrimary, $userID];
                $resImg = sqlsrv_query($conn, $sqlImg, $paramsImg);
                if (!$resImg) {
                    $errors = sqlsrv_errors();
                    error_log("❌ SQL Error (insert image): " . print_r($errors, true));
                    $fileErrors[] = "ไม่สามารถบันทึกข้อมูลไฟล์ " . $filenames[$i];
                } else {
                    error_log("✅ Image record inserted for " . $filenames[$i] . " with AssetID: $newID");
                }
            } else {
                $fileErrors[] = "ไม่สามารถย้ายไฟล์ " . $filenames[$i];
                error_log("❌ move_uploaded_file failed for " . $filenames[$i]);
            }
        }
    } else {
        error_log("No file uploaded or FILES empty");
    }

    // ดึงไฟล์ทั้งหมดเพื่อส่งกลับ
    $finalPreview = [];
    $finalPreviewConfig = [];
    $resImages = sqlsrv_query($conn, "SELECT ImageID, FileName, OriginalName, FileSize FROM CFP_AssetImage WHERE AssetType='Equipment' AND AssetID=? ORDER BY SortOrder ASC", [$newID]);
    while ($rowImg = sqlsrv_fetch_array($resImages, SQLSRV_FETCH_ASSOC)) {
        $finalPreview[] = $uploadBaseUrl . $rowImg['FileName'];
        $finalPreviewConfig[] = [
            'caption' => $rowImg['OriginalName'],
            'size'    => (int)$rowImg['FileSize'],
            'key'     => (int)$rowImg['ImageID']
            // ✅ ไม่มี url/extra → Krajee ไม่ส่ง AJAX ลบเอง (จัดการโดย bindDeleteButtons)
        ];
    }

    logAction($conn, 'DATA_CREATE', 'CFP_Equipment', $newID, null, null, null, 'เพิ่ม: ' . $code . ' ' . $name);

    $msg = 'เพิ่มเครื่องจักร "' . $name . '" เรียบร้อยแล้ว (ไฟล์ ' . $fileCount . ' รายการ)';
    if (!empty($fileErrors)) {
        $msg .= ' แต่มีปัญหา: ' . implode('; ', $fileErrors);
    }

    jsonOut(true, $msg, [
        'assetID' => $newID,
        'initialPreview' => $finalPreview,
        'initialPreviewConfig' => $finalPreviewConfig
    ]);
}

// ==============================================
// UPDATE
// ==============================================
if ($action === 'update' || $action === 'edit') {
    if ($id <= 0) jsonOut(false, 'ไม่พบรหัสเครื่องจักร');

    $code = strtoupper(cleanStr($_REQUEST['EquipmentCode'] ?? '', 50));
    $name = cleanStr($_REQUEST['EquipmentName'] ?? '', 300);
    $typeID = (int)($_REQUEST['EquipmentTypeID'] ?? 0) ?: null;
    $siteID = (int)($_REQUEST['SiteID'] ?? 0) ?: null;
    $fuelTypeID = (int)($_REQUEST['FuelTypeID'] ?? 0) ?: null;
    $combustion = cleanStr($_REQUEST['CombustionType'] ?? 'Stationary', 50);
    $cap = is_numeric($_REQUEST['Capacity'] ?? '') ? (float)$_REQUEST['Capacity'] : null;
    $capUnit = cleanStr($_REQUEST['CapacityUnit'] ?? '', 50);
    $year = is_numeric($_REQUEST['YearInstall'] ?? '') ? (int)$_REQUEST['YearInstall'] : null;
    $remark = cleanStr($_REQUEST['Remark'] ?? '', 500);

    if (empty($code) || empty($name)) {
        jsonOut(false, 'กรุณากรอกรหัสและชื่อเครื่องจักร');
    }

    $chk = sqlsrv_query($conn, "SELECT 1 FROM CFP_Equipment WHERE EquipmentCode = ? AND EquipmentID <> ?", [$code, $id]);
    if (sqlsrv_fetch($chk)) {
        jsonOut(false, 'รหัสเครื่องจักร "' . $code . '" มีอยู่ในระบบแล้ว');
    }

    $sql = "UPDATE CFP_Equipment SET
                EquipmentCode = ?,
                EquipmentName = ?,
                EquipmentTypeID = ?,
                SiteID = ?,
                FuelTypeID = ?,
                CombustionType = ?,
                Capacity = ?,
                CapacityUnit = ?,
                YearInstall = ?,
                Remark = ?,
                UpdatedBy = ?,
                UpdatedDate = GETDATE()
            WHERE EquipmentID = ?";
    $params = [$code, $name, $typeID, $siteID, $fuelTypeID, $combustion, $cap, $capUnit, $year, $remark, $userID, $id];
    $res = sqlsrv_query($conn, $sql, $params);
    if (!$res) {
        $errors = sqlsrv_errors();
        error_log("SQL Error (update): " . print_r($errors, true));
        jsonOut(false, 'เกิดข้อผิดพลาดในการแก้ไขข้อมูล: ' . ($errors[0]['message'] ?? ''));
    }

    // ===== จัดการไฟล์เพิ่ม =====
    $uploadBaseDir = dirname(__DIR__) . '/uploads/assets/equipment/';
    if (!is_dir($uploadBaseDir)) mkdir($uploadBaseDir, 0755, true);
    $uploadBaseUrl = '/carbonfootprint/uploads/assets/equipment/';

    $fileCount = 0;
    $fileErrors = [];

    if (isset($_FILES['file']) && isset($_FILES['file']['name'])) {
        $filenames = $_FILES['file']['name'];
        $filesize  = $_FILES['file']['size'];
        $tmpname   = $_FILES['file']['tmp_name'];

        for ($i = 0; $i < count($filenames); $i++) {
            if (empty($filenames[$i]) || $filesize[$i] == 0) continue;

            if (!isValidFileType($filenames[$i])) {
                $fileErrors[] = "ไฟล์ " . $filenames[$i] . " ไม่รองรับ";
                continue;
            }

            $ext = strtolower(pathinfo($filenames[$i], PATHINFO_EXTENSION));
            $newName = 'Equipment_' . $id . '_' . uniqid() . '.' . $ext;
            $destPath = $uploadBaseDir . $newName;

            if (move_uploaded_file($tmpname[$i], $destPath)) {
                $fileCount++;
                $mimeMap = [
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png'  => 'image/png',
                    'gif'  => 'image/gif',
                    'webp' => 'image/webp',
                    'pdf'  => 'application/pdf'
                ];
                $mime = $mimeMap[$ext] ?? 'application/octet-stream';

                $resSort = sqlsrv_query($conn, "SELECT ISNULL(MAX(SortOrder),0)+1 AS NextSort FROM CFP_AssetImage WHERE AssetType='Equipment' AND AssetID=?", [$id]);
                $rowSort = sqlsrv_fetch_array($resSort, SQLSRV_FETCH_ASSOC);
                $nextSort = $rowSort ? (int)$rowSort['NextSort'] : 1;

                $sqlImg = "INSERT INTO CFP_AssetImage (AssetType, AssetID, FileName, OriginalName, FilePath, FileSize, MimeType, SortOrder, IsPrimary, UploadedBy, UploadedDate)
                           VALUES ('Equipment', ?, ?, ?, ?, ?, ?, ?, 0, ?, GETDATE())";
                $paramsImg = [$id, $newName, $filenames[$i], $newName, $filesize[$i], $mime, $nextSort, $userID];
                $resImg = sqlsrv_query($conn, $sqlImg, $paramsImg);
                if (!$resImg) {
                    $errors = sqlsrv_errors();
                    error_log("SQL Error (insert image update): " . print_r($errors, true));
                    $fileErrors[] = "ไม่สามารถบันทึกข้อมูลไฟล์ " . $filenames[$i];
                }
            } else {
                $fileErrors[] = "ไม่สามารถย้ายไฟล์ " . $filenames[$i];
            }
        }
    }

    // ดึงไฟล์ทั้งหมด
    $finalPreview = [];
    $finalPreviewConfig = [];
    $resImages = sqlsrv_query($conn, "SELECT ImageID, FileName, OriginalName, FileSize FROM CFP_AssetImage WHERE AssetType='Equipment' AND AssetID=? ORDER BY SortOrder ASC", [$id]);
    while ($rowImg = sqlsrv_fetch_array($resImages, SQLSRV_FETCH_ASSOC)) {
        $finalPreview[] = $uploadBaseUrl . $rowImg['FileName'];
        $finalPreviewConfig[] = [
            'caption' => $rowImg['OriginalName'],
            'size'    => (int)$rowImg['FileSize'],
            'key'     => (int)$rowImg['ImageID']
            // ✅ ไม่มี url/extra → Krajee ไม่ส่ง AJAX ลบเอง (จัดการโดย bindDeleteButtons)
        ];
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_Equipment', $id, null, null, null, 'แก้ไข: ' . $code . ' ' . $name);

    $msg = 'แก้ไขเครื่องจักร "' . $name . '" เรียบร้อยแล้ว (ไฟล์ ' . $fileCount . ' รายการ)';
    if (!empty($fileErrors)) {
        $msg .= ' แต่มีปัญหา: ' . implode('; ', $fileErrors);
    }

    jsonOut(true, $msg, [
        'assetID' => $id,
        'initialPreview' => $finalPreview,
        'initialPreviewConfig' => $finalPreviewConfig
    ]);
}

// ==============================================
// TOGGLE
// ==============================================
if ($action === 'toggle') {
    if ($id <= 0) jsonOut(false, 'ไม่พบรหัสเครื่องจักร');
    $cur = (int)($_REQUEST['is_active'] ?? 1);
    $newVal = $cur ? 0 : 1;
    $label = $newVal ? 'เปิดใช้งาน' : 'ปิดใช้งาน';

    $res = sqlsrv_query($conn, "UPDATE CFP_Equipment SET IsActive = ?, UpdatedBy = ?, UpdatedDate = GETDATE() WHERE EquipmentID = ?", [$newVal, $userID, $id]);
    if (!$res) {
        $errors = sqlsrv_errors();
        error_log("SQL Error (toggle): " . print_r($errors, true));
        jsonOut(false, 'เกิดข้อผิดพลาด');
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_Equipment', $id, null, null, null, $label . 'เครื่องจักร ID=' . $id);
    $_SESSION['toast_msg'] = $label . 'เครื่องจักรเรียบร้อยแล้ว';
    $_SESSION['toast_type'] = 'success';
    header('Location: equipment.php');
    exit;
}

jsonOut(true, 'เพิ่มเครื่องจักร "' . $name . '" เรียบร้อยแล้ว', [
    'assetID' => $newID,
    'initialPreview' => $finalPreview,
    'initialPreviewConfig' => $finalPreviewConfig
]);
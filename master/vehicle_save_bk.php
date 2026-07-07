<?php
/* ==============================================
   master/vehicle_save.php
   รับ POST + ไฟล์จาก Krajee FileInput
   รูปแบบเดียวกับ equipment_save.php
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4));

header('Content-Type: application/json; charset=utf-8');

$conn   = getConnection();
$userID = (int)$_SESSION['user_id'];

$action = $_REQUEST['action'] ?? '';
$id     = (int)($_REQUEST['id'] ?? 0);

// CSRF
$csrfToken = $_REQUEST['csrf_token'] ?? '';
if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    $csrfToken = $_SESSION['csrf_token'];
}

function jsonOut($success, $msg, $extra = array()) {
    echo json_encode(array_merge(array('success' => $success, 'msg' => $msg), $extra), JSON_UNESCAPED_UNICODE);
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

// ==============================================
// TOGGLE
// ==============================================
if ($action === 'toggle') {
    if ($id <= 0) {
        // toggle มาจาก form POST ปกติ ไม่ใช่ JSON
        header('Content-Type: text/html; charset=utf-8');
        $_SESSION['toast_msg']  = 'ไม่พบรหัสยานพาหนะ';
        $_SESSION['toast_type'] = 'error';
        header('Location: vehicle.php'); exit;
    }
    $cur    = (int)($_REQUEST['is_active'] ?? 1);
    $newVal = $cur ? 0 : 1;
    $label  = $newVal ? 'เปิดใช้งาน' : 'ปิดใช้งาน';

    sqlsrv_query($conn, "UPDATE CFP_Vehicle SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE VehicleID=?",
        array($newVal, $userID, $id));
    logAction($conn, 'DATA_UPDATE', 'CFP_Vehicle', $id, null, null, null, $label . 'ยานพาหนะ ID=' . $id);

    header('Content-Type: text/html; charset=utf-8');
    $_SESSION['toast_msg']  = $label . 'ยานพาหนะเรียบร้อยแล้ว';
    $_SESSION['toast_type'] = 'success';
    header('Location: vehicle.php'); exit;
}

// ==============================================
// CREATE
// ==============================================
if ($action === 'create' || $action === 'add') {
    $code       = strtoupper(cleanStr($_REQUEST['VehicleCode'] ?? '', 50));
    $name       = cleanStr($_REQUEST['VehicleName'] ?? '', 300);
    $plate      = strtoupper(cleanStr($_REQUEST['LicensePlate'] ?? '', 50));
    $year       = is_numeric($_REQUEST['YearModel'] ?? '') ? (int)$_REQUEST['YearModel'] : null;
    $engine     = is_numeric($_REQUEST['EngineSize'] ?? '') ? (float)$_REQUEST['EngineSize'] : null;
    $typeID     = (int)($_REQUEST['VehicleTypeID'] ?? 0) ?: null;
    $siteID     = (int)($_REQUEST['SiteID'] ?? 0) ?: null;
    $fuelTypeID = (int)($_REQUEST['FuelTypeID'] ?? 0) ?: null;
    $combustion = in_array($_REQUEST['CombustionType'] ?? '', array('Stationary', 'Mobile'))
                    ? $_REQUEST['CombustionType'] : 'Mobile';
    $remark     = cleanStr($_REQUEST['Remark'] ?? '', 500);

    if (empty($code) || empty($name)) {
        jsonOut(false, 'กรุณากรอกรหัสและชื่อยานพาหนะ');
    }

    // ตรวจสอบรหัสซ้ำ
    $chk = sqlsrv_query($conn, "SELECT 1 FROM CFP_Vehicle WHERE VehicleCode=?", array($code));
    if (sqlsrv_fetch($chk)) {
        jsonOut(false, 'รหัสยานพาหนะ "' . $code . '" มีอยู่ในระบบแล้ว');
    }

    // INSERT
    $sql = "INSERT INTO CFP_Vehicle
            (VehicleCode, VehicleName, VehicleTypeID, LicensePlate, SiteID, FuelTypeID,
             CombustionType, EngineSize, YearModel, Remark, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, GETDATE())";
    $params = array($code, $name, $typeID, $plate ?: null, $siteID, $fuelTypeID,
                    $combustion, $engine, $year, $remark ?: null, $userID);
    $res = sqlsrv_query($conn, $sql, $params);
    if (!$res) {
        $errors = sqlsrv_errors();
        jsonOut(false, 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . ($errors[0]['message'] ?? ''));
    }

    // ดึง ID
    $resID = sqlsrv_query($conn, "SELECT @@IDENTITY AS NewID");
    $row   = sqlsrv_fetch_array($resID, SQLSRV_FETCH_ASSOC);
    $newID = isset($row['NewID']) ? (int)$row['NewID'] : 0;

    if ($newID <= 0) {
        jsonOut(false, 'ไม่สามารถสร้างรหัสยานพาหนะได้');
    }

    // จัดการไฟล์
    $uploadBaseDir = dirname(__DIR__) . '/uploads/assets/vehicle/';
    if (!is_dir($uploadBaseDir)) { mkdir($uploadBaseDir, 0755, true); }
    $uploadBaseUrl = '/carbonfootprint/uploads/assets/vehicle/';

    $fileCount  = 0;
    $fileErrors = array();

    if (isset($_FILES['file']) && isset($_FILES['file']['name'])) {
        $filenames = $_FILES['file']['name'];
        $filesize  = $_FILES['file']['size'];
        $tmpname   = $_FILES['file']['tmp_name'];

        for ($i = 0; $i < count($filenames); $i++) {
            if (empty($filenames[$i]) || $filesize[$i] == 0) { continue; }
            if (!isValidFileType($filenames[$i])) {
                $fileErrors[] = 'ไฟล์ ' . $filenames[$i] . ' ไม่รองรับ';
                continue;
            }

            $ext     = strtolower(pathinfo($filenames[$i], PATHINFO_EXTENSION));
            $newName = 'Vehicle_' . $newID . '_' . uniqid() . '.' . $ext;
            $dest    = $uploadBaseDir . $newName;

            if (move_uploaded_file($tmpname[$i], $dest)) {
                $fileCount++;
                $mimeMap = array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
                                 'gif'=>'image/gif','webp'=>'image/webp','pdf'=>'application/pdf');
                $mime = $mimeMap[$ext] ?? 'application/octet-stream';

                $resSort = sqlsrv_query($conn,
                    "SELECT ISNULL(MAX(SortOrder),0)+1 AS NextSort FROM CFP_AssetImage WHERE AssetType='Vehicle' AND AssetID=?",
                    array($newID));
                $rowSort  = sqlsrv_fetch_array($resSort, SQLSRV_FETCH_ASSOC);
                $nextSort = $rowSort ? (int)$rowSort['NextSort'] : 1;

                $resCount = sqlsrv_query($conn,
                    "SELECT COUNT(*) AS Cnt FROM CFP_AssetImage WHERE AssetType='Vehicle' AND AssetID=?",
                    array($newID));
                $rowCount  = sqlsrv_fetch_array($resCount, SQLSRV_FETCH_ASSOC);
                $isPrimary = ($rowCount && (int)$rowCount['Cnt'] == 0) ? 1 : 0;

                $sqlImg = "INSERT INTO CFP_AssetImage
                           (AssetType, AssetID, FileName, OriginalName, FilePath, FileSize, MimeType, SortOrder, IsPrimary, UploadedBy, UploadedDate)
                           VALUES ('Vehicle', ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
                sqlsrv_query($conn, $sqlImg,
                    array($newID, $newName, $filenames[$i], $newName, $filesize[$i], $mime, $nextSort, $isPrimary, $userID));
            } else {
                $fileErrors[] = 'ไม่สามารถย้ายไฟล์ ' . $filenames[$i];
            }
        }
    }

    // ดึงรายการรูปทั้งหมด
    $finalPreview       = array();
    $finalPreviewConfig = array();
    $resImages = sqlsrv_query($conn,
        "SELECT ImageID, FileName, OriginalName, FileSize FROM CFP_AssetImage WHERE AssetType='Vehicle' AND AssetID=? ORDER BY SortOrder ASC",
        array($newID));
    while ($rowImg = sqlsrv_fetch_array($resImages, SQLSRV_FETCH_ASSOC)) {
        $finalPreview[]       = $uploadBaseUrl . $rowImg['FileName'];
        $finalPreviewConfig[] = array(
            'caption' => $rowImg['OriginalName'],
            'size'    => (int)$rowImg['FileSize'],
            'key'     => (int)$rowImg['ImageID']
        );
    }

    logAction($conn, 'DATA_CREATE', 'CFP_Vehicle', $newID, null, null, null, 'เพิ่ม: ' . $code . ' ' . $name);

    $msg = 'เพิ่มยานพาหนะ "' . $name . '" เรียบร้อยแล้ว (ไฟล์ ' . $fileCount . ' รายการ)';
    if (!empty($fileErrors)) { $msg .= ' แต่มีปัญหา: ' . implode('; ', $fileErrors); }

    jsonOut(true, $msg, array(
        'assetID'              => $newID,
        'initialPreview'       => $finalPreview,
        'initialPreviewConfig' => $finalPreviewConfig
    ));
}

// ==============================================
// UPDATE
// ==============================================
if ($action === 'update' || $action === 'edit') {
    if ($id <= 0) { jsonOut(false, 'ไม่พบรหัสยานพาหนะ'); }

    $code       = strtoupper(cleanStr($_REQUEST['VehicleCode'] ?? '', 50));
    $name       = cleanStr($_REQUEST['VehicleName'] ?? '', 300);
    $plate      = strtoupper(cleanStr($_REQUEST['LicensePlate'] ?? '', 50));
    $year       = is_numeric($_REQUEST['YearModel'] ?? '') ? (int)$_REQUEST['YearModel'] : null;
    $engine     = is_numeric($_REQUEST['EngineSize'] ?? '') ? (float)$_REQUEST['EngineSize'] : null;
    $typeID     = (int)($_REQUEST['VehicleTypeID'] ?? 0) ?: null;
    $siteID     = (int)($_REQUEST['SiteID'] ?? 0) ?: null;
    $fuelTypeID = (int)($_REQUEST['FuelTypeID'] ?? 0) ?: null;
    $combustion = in_array($_REQUEST['CombustionType'] ?? '', array('Stationary', 'Mobile'))
                    ? $_REQUEST['CombustionType'] : 'Mobile';
    $remark     = cleanStr($_REQUEST['Remark'] ?? '', 500);

    if (empty($code) || empty($name)) {
        jsonOut(false, 'กรุณากรอกรหัสและชื่อยานพาหนะ');
    }

    // ตรวจรหัสซ้ำ (ยกเว้นตัวเอง)
    $chk = sqlsrv_query($conn, "SELECT 1 FROM CFP_Vehicle WHERE VehicleCode=? AND VehicleID<>?", array($code, $id));
    if (sqlsrv_fetch($chk)) {
        jsonOut(false, 'รหัสยานพาหนะ "' . $code . '" มีอยู่ในระบบแล้ว');
    }

    $sql = "UPDATE CFP_Vehicle SET
                VehicleCode    = ?,
                VehicleName    = ?,
                VehicleTypeID  = ?,
                LicensePlate   = ?,
                SiteID         = ?,
                FuelTypeID     = ?,
                CombustionType = ?,
                EngineSize     = ?,
                YearModel      = ?,
                Remark         = ?,
                UpdatedBy      = ?,
                UpdatedDate    = GETDATE()
            WHERE VehicleID = ?";
    $params = array($code, $name, $typeID, $plate ?: null, $siteID, $fuelTypeID,
                    $combustion, $engine, $year, $remark ?: null, $userID, $id);
    $res = sqlsrv_query($conn, $sql, $params);
    if (!$res) {
        $errors = sqlsrv_errors();
        jsonOut(false, 'เกิดข้อผิดพลาดในการแก้ไขข้อมูล: ' . ($errors[0]['message'] ?? ''));
    }

    // จัดการไฟล์เพิ่ม
    $uploadBaseDir = dirname(__DIR__) . '/uploads/assets/vehicle/';
    if (!is_dir($uploadBaseDir)) { mkdir($uploadBaseDir, 0755, true); }
    $uploadBaseUrl = '/carbonfootprint/uploads/assets/vehicle/';

    $fileCount  = 0;
    $fileErrors = array();

    if (isset($_FILES['file']) && isset($_FILES['file']['name'])) {
        $filenames = $_FILES['file']['name'];
        $filesize  = $_FILES['file']['size'];
        $tmpname   = $_FILES['file']['tmp_name'];

        for ($i = 0; $i < count($filenames); $i++) {
            if (empty($filenames[$i]) || $filesize[$i] == 0) { continue; }
            if (!isValidFileType($filenames[$i])) {
                $fileErrors[] = 'ไฟล์ ' . $filenames[$i] . ' ไม่รองรับ';
                continue;
            }

            $ext     = strtolower(pathinfo($filenames[$i], PATHINFO_EXTENSION));
            $newName = 'Vehicle_' . $id . '_' . uniqid() . '.' . $ext;
            $dest    = $uploadBaseDir . $newName;

            if (move_uploaded_file($tmpname[$i], $dest)) {
                $fileCount++;
                $mimeMap = array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
                                 'gif'=>'image/gif','webp'=>'image/webp','pdf'=>'application/pdf');
                $mime = $mimeMap[$ext] ?? 'application/octet-stream';

                $resSort = sqlsrv_query($conn,
                    "SELECT ISNULL(MAX(SortOrder),0)+1 AS NextSort FROM CFP_AssetImage WHERE AssetType='Vehicle' AND AssetID=?",
                    array($id));
                $rowSort  = sqlsrv_fetch_array($resSort, SQLSRV_FETCH_ASSOC);
                $nextSort = $rowSort ? (int)$rowSort['NextSort'] : 1;

                $sqlImg = "INSERT INTO CFP_AssetImage
                           (AssetType, AssetID, FileName, OriginalName, FilePath, FileSize, MimeType, SortOrder, IsPrimary, UploadedBy, UploadedDate)
                           VALUES ('Vehicle', ?, ?, ?, ?, ?, ?, ?, 0, ?, GETDATE())";
                sqlsrv_query($conn, $sqlImg,
                    array($id, $newName, $filenames[$i], $newName, $filesize[$i], $mime, $nextSort, $userID));
            } else {
                $fileErrors[] = 'ไม่สามารถย้ายไฟล์ ' . $filenames[$i];
            }
        }
    }

    // ดึงรายการรูปทั้งหมด
    $finalPreview       = array();
    $finalPreviewConfig = array();
    $resImages = sqlsrv_query($conn,
        "SELECT ImageID, FileName, OriginalName, FileSize FROM CFP_AssetImage WHERE AssetType='Vehicle' AND AssetID=? ORDER BY SortOrder ASC",
        array($id));
    while ($rowImg = sqlsrv_fetch_array($resImages, SQLSRV_FETCH_ASSOC)) {
        $finalPreview[]       = $uploadBaseUrl . $rowImg['FileName'];
        $finalPreviewConfig[] = array(
            'caption' => $rowImg['OriginalName'],
            'size'    => (int)$rowImg['FileSize'],
            'key'     => (int)$rowImg['ImageID']
        );
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_Vehicle', $id, null, null, null, 'แก้ไข: ' . $code . ' ' . $name);

    $msg = 'แก้ไขยานพาหนะ "' . $name . '" เรียบร้อยแล้ว (ไฟล์ ' . $fileCount . ' รายการ)';
    if (!empty($fileErrors)) { $msg .= ' แต่มีปัญหา: ' . implode('; ', $fileErrors); }

    jsonOut(true, $msg, array(
        'assetID'              => $id,
        'initialPreview'       => $finalPreview,
        'initialPreviewConfig' => $finalPreviewConfig
    ));
}

jsonOut(false, 'คำขอไม่ถูกต้อง');

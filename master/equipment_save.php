<?php
/* ==============================================
   master/equipment_save.php
   รับ POST + ไฟล์จาก Krajee (ไม่ส่ง initialPreview/Config)
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(4));

header('Content-Type: application/json; charset=utf-8');

$conn = getConnection();
$userID = (int)$_SESSION['user_id'];

$action = $_REQUEST['action'] ?? '';
$id = (int)($_REQUEST['id'] ?? 0);

// CSRF
$csrfToken = $_REQUEST['csrf_token'] ?? '';
if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    error_log("CSRF mismatch in equipment_save.php");
}

function jsonOut($success, $msg, $extra = []) {
    $response = ['success' => $success, 'msg' => $msg];
    if (isset($extra['assetID'])) {
        $response['assetID'] = $extra['assetID'];
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
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
// TOGGLE
// ==============================================
if ($action === 'toggle') {
    if ($id <= 0) {
        header('Content-Type: text/html; charset=utf-8');
        $_SESSION['toast_msg']  = 'ไม่พบรหัสเครื่องจักร';
        $_SESSION['toast_type'] = 'error';
        header('Location: equipment.php'); exit;
    }
    $cur    = (int)($_REQUEST['is_active'] ?? 1);
    $newVal = $cur ? 0 : 1;
    $label  = $newVal ? 'เปิดใช้งาน' : 'ปิดใช้งาน';

    sqlsrv_query($conn, "UPDATE CFP_Equipment SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE EquipmentID=?", array($newVal, $userID, $id));
    logAction($conn, 'DATA_UPDATE', 'CFP_Equipment', $id, null, null, null, $label . 'เครื่องจักร ID=' . $id);

    header('Content-Type: text/html; charset=utf-8');
    $_SESSION['toast_msg']  = $label . 'เครื่องจักรเรียบร้อยแล้ว';
    $_SESSION['toast_type'] = 'success';
    header('Location: equipment.php'); exit;
}

// ==============================================
// CREATE
// ==============================================
if ($action === 'create' || $action === 'add') {
    $code   = strtoupper(cleanStr($_REQUEST['EquipmentCode'] ?? '', 50));
    $name   = cleanStr($_REQUEST['EquipmentName'] ?? '', 300);
    $siteID = (int)($_REQUEST['SiteID'] ?? 0) ?: null;
    $typeID     = (int)($_REQUEST['EquipmentTypeID'] ?? 0) ?: null;
    $fuelTypeID = (int)($_REQUEST['FuelTypeID'] ?? 0) ?: null;
    $combustion = cleanStr($_REQUEST['CombustionType'] ?? 'Stationary', 50);
    $cap     = is_numeric($_REQUEST['Capacity'] ?? '') ? (float)$_REQUEST['Capacity'] : null;
    $capUnit = cleanStr($_REQUEST['CapacityUnit'] ?? '', 50);
    $year    = is_numeric($_REQUEST['YearInstall'] ?? '') ? (int)$_REQUEST['YearInstall'] : null;
    $remark  = cleanStr($_REQUEST['Remark'] ?? '', 500);

    if (empty($name)) {
        jsonOut(false, 'กรุณากรอกชื่อเครื่องจักร');
    }

    if (empty($code) && $siteID) {
        $rSC = sqlsrv_query($conn, "SELECT SiteCode FROM CFP_Site WHERE SiteID=?", array($siteID));
        $rSCRow = $rSC ? sqlsrv_fetch_array($rSC, SQLSRV_FETCH_ASSOC) : null;
        $sc = $rSCRow ? $rSCRow['SiteCode'] : 'SITE';
        $rMax = sqlsrv_query($conn,
            "SELECT MAX(EquipmentCode) AS MaxCode FROM CFP_Equipment WHERE EquipmentCode LIKE ?",
            array('EQ-' . $sc . '-%'));
        $rMaxRow = $rMax ? sqlsrv_fetch_array($rMax, SQLSRV_FETCH_ASSOC) : null;
        $nextNum = 1;
        if ($rMaxRow && $rMaxRow['MaxCode']) {
            if (preg_match('/(\d+)$/', $rMaxRow['MaxCode'], $m)) { $nextNum = (int)$m[1] + 1; }
        }
        $code = 'EQ-' . $sc . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
    }

    $chk = sqlsrv_query($conn, "SELECT 1 FROM CFP_Equipment WHERE EquipmentCode = ?", [$code]);
    if (sqlsrv_fetch($chk)) {
        jsonOut(false, 'รหัสเครื่องจักร "' . $code . '" มีอยู่ในระบบแล้ว');
    }

    $sql = "INSERT INTO CFP_Equipment
            (EquipmentCode, EquipmentName, EquipmentTypeID, SiteID, FuelTypeID, CombustionType,
             Capacity, CapacityUnit, YearInstall, Remark, IsActive, CreatedBy, CreatedDate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, GETDATE())";
    $params = [$code, $name, $typeID, $siteID, $fuelTypeID, $combustion, $cap, $capUnit, $year, $remark, $userID];
    $res = sqlsrv_query($conn, $sql, $params);
    if (!$res) {
        $errors = sqlsrv_errors();
        jsonOut(false, 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . ($errors[0]['message'] ?? ''));
    }

    $rowsAffected = sqlsrv_rows_affected($res);
    if ($rowsAffected === false || $rowsAffected == 0) {
        jsonOut(false, 'ไม่สามารถเพิ่มข้อมูลได้');
    }

    $sqlID = "SELECT @@IDENTITY AS NewID";
    $resID = sqlsrv_query($conn, $sqlID);
    if (!$resID) {
        jsonOut(false, 'ไม่สามารถดึงรหัสเครื่องจักรได้');
    }
    $row = sqlsrv_fetch_array($resID, SQLSRV_FETCH_ASSOC);
    $newID = isset($row['NewID']) ? (int)$row['NewID'] : 0;
    if ($newID <= 0) {
        jsonOut(false, 'ไม่สามารถสร้างรหัสเครื่องจักรได้');
    }

    // จัดการไฟล์
    $uploadBaseDir = dirname(__DIR__) . '/uploads/assets/equipment/';
    if (!is_dir($uploadBaseDir)) mkdir($uploadBaseDir, 0755, true);

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
            $newName = 'Equipment_' . $newID . '_' . uniqid() . '.' . $ext;
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

                $resSort = sqlsrv_query($conn, "SELECT ISNULL(MAX(SortOrder),0)+1 AS NextSort FROM CFP_AssetImage WHERE AssetType='Equipment' AND AssetID=?", [$newID]);
                $rowSort = sqlsrv_fetch_array($resSort, SQLSRV_FETCH_ASSOC);
                $nextSort = $rowSort ? (int)$rowSort['NextSort'] : 1;

                $resCount = sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM CFP_AssetImage WHERE AssetType='Equipment' AND AssetID=?", [$newID]);
                $rowCount = sqlsrv_fetch_array($resCount, SQLSRV_FETCH_ASSOC);
                $isPrimary = ($rowCount && (int)$rowCount['Cnt'] == 0) ? 1 : 0;

                $sqlImg = "INSERT INTO CFP_AssetImage (AssetType, AssetID, FileName, OriginalName, FilePath, FileSize, MimeType, SortOrder, IsPrimary, UploadedBy, UploadedDate)
                           VALUES ('Equipment', ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
                $paramsImg = [$newID, $newName, $filenames[$i], $newName, $filesize[$i], $mime, $nextSort, $isPrimary, $userID];
                sqlsrv_query($conn, $sqlImg, $paramsImg);
            } else {
                $fileErrors[] = "ไม่สามารถย้ายไฟล์ " . $filenames[$i];
            }
        }
    }

    logAction($conn, 'DATA_CREATE', 'CFP_Equipment', $newID, null, null, null, 'เพิ่ม: ' . $code . ' ' . $name);

    $msg = 'เพิ่มเครื่องจักร "' . $name . '" เรียบร้อยแล้ว (ไฟล์ ' . $fileCount . ' รายการ)';
    if (!empty($fileErrors)) {
        $msg .= ' แต่มีปัญหา: ' . implode('; ', $fileErrors);
    }

    // ✅ ส่งเฉพาะ success, msg, assetID (ไม่ส่ง initialPreview/Config)
    jsonOut(true, $msg, ['assetID' => $newID]);
}

// ==============================================
// UPDATE
// ==============================================
if ($action === 'update' || $action === 'edit') {
    if ($id <= 0) jsonOut(false, 'ไม่พบรหัสเครื่องจักร');

    $code       = strtoupper(cleanStr($_REQUEST['EquipmentCode'] ?? '', 50));
    $name       = cleanStr($_REQUEST['EquipmentName'] ?? '', 300);
    $siteID     = (int)($_REQUEST['SiteID'] ?? 0) ?: null;
    $typeID     = (int)($_REQUEST['EquipmentTypeID'] ?? 0) ?: null;
    $fuelTypeID = (int)($_REQUEST['FuelTypeID'] ?? 0) ?: null;
    $combustion = cleanStr($_REQUEST['CombustionType'] ?? 'Stationary', 50);
    $cap     = is_numeric($_REQUEST['Capacity'] ?? '') ? (float)$_REQUEST['Capacity'] : null;
    $capUnit = cleanStr($_REQUEST['CapacityUnit'] ?? '', 50);
    $year    = is_numeric($_REQUEST['YearInstall'] ?? '') ? (int)$_REQUEST['YearInstall'] : null;
    $remark  = cleanStr($_REQUEST['Remark'] ?? '', 500);

    if (empty($name)) {
        jsonOut(false, 'กรุณากรอกชื่อเครื่องจักร');
    }

    if (empty($code) && $siteID) {
        $rSC = sqlsrv_query($conn, "SELECT SiteCode FROM CFP_Site WHERE SiteID=?", array($siteID));
        $rSCRow = $rSC ? sqlsrv_fetch_array($rSC, SQLSRV_FETCH_ASSOC) : null;
        $sc = $rSCRow ? $rSCRow['SiteCode'] : 'SITE';
        $rMax = sqlsrv_query($conn,
            "SELECT MAX(EquipmentCode) AS MaxCode FROM CFP_Equipment WHERE EquipmentCode LIKE ? AND EquipmentID <> ?",
            array('EQ-' . $sc . '-%', $id));
        $rMaxRow = $rMax ? sqlsrv_fetch_array($rMax, SQLSRV_FETCH_ASSOC) : null;
        $nextNum = 1;
        if ($rMaxRow && $rMaxRow['MaxCode']) {
            if (preg_match('/(\d+)$/', $rMaxRow['MaxCode'], $m)) { $nextNum = (int)$m[1] + 1; }
        }
        $code = 'EQ-' . $sc . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
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
        jsonOut(false, 'เกิดข้อผิดพลาดในการแก้ไขข้อมูล: ' . ($errors[0]['message'] ?? ''));
    }

    // จัดการ pending_deletes
    $pendingDeletesRaw = trim($_REQUEST['pending_deletes'] ?? '');
    if ($pendingDeletesRaw !== '') {
        $deleteIDs = array_filter(array_map('intval', explode(',', $pendingDeletesRaw)));
        foreach ($deleteIDs as $delID) {
            if ($delID <= 0) continue;
            $resDel = sqlsrv_query($conn,
                "SELECT FileName FROM CFP_AssetImage WHERE ImageID=? AND AssetType='Equipment' AND AssetID=?",
                [$delID, $id]
            );
            if ($resDel && ($rowDel = sqlsrv_fetch_array($resDel, SQLSRV_FETCH_ASSOC))) {
                $filePath = dirname(__DIR__) . '/uploads/assets/equipment/' . $rowDel['FileName'];
                if (file_exists($filePath)) { @unlink($filePath); }
                sqlsrv_query($conn,
                    "DELETE FROM CFP_AssetImage WHERE ImageID=? AND AssetType='Equipment' AND AssetID=?",
                    [$delID, $id]
                );
            }
        }
    }

    // จัดการไฟล์เพิ่ม
    $uploadBaseDir = dirname(__DIR__) . '/uploads/assets/equipment/';
    if (!is_dir($uploadBaseDir)) mkdir($uploadBaseDir, 0755, true);

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
                sqlsrv_query($conn, $sqlImg, $paramsImg);
            } else {
                $fileErrors[] = "ไม่สามารถย้ายไฟล์ " . $filenames[$i];
            }
        }
    }

    logAction($conn, 'DATA_UPDATE', 'CFP_Equipment', $id, null, null, null, 'แก้ไข: ' . $code . ' ' . $name);

    $msg = 'แก้ไขเครื่องจักร "' . $name . '" เรียบร้อยแล้ว (ไฟล์ ' . $fileCount . ' รายการ)';
    if (!empty($fileErrors)) {
        $msg .= ' แต่มีปัญหา: ' . implode('; ', $fileErrors);
    }

    // ✅ ส่งเฉพาะ success, msg, assetID (ไม่ส่ง initialPreview/Config)
    jsonOut(true, $msg, ['assetID' => $id]);
}

// ถ้าไม่มีการกระทำที่ถูกต้อง
jsonOut(false, 'คำขอไม่ถูกต้อง');
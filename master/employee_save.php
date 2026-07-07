<?php
/* master/employee_save.php — ทะเบียนพนักงาน (การเดินทาง) Table: CFP_Employee
   แก้ไข: เพิ่ม CSRF check + File Upload + pending_deletes
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

function cleanStr($val, $len = 300) {
    return mb_substr(trim($val ?? ''), 0, $len);
}

function isValidFileType($f) {
    return in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'));
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

// ===== CSRF Check =====
$csrfToken = $_REQUEST['csrf_token'] ?? '';
if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    jsonOut(false, 'CSRF token ไม่ถูกต้อง กรุณาลองใหม่');
}

/* ---- Toggle ---- */
if ($action === 'toggle') {
    if ($id <= 0) {
        header('Content-Type: text/html; charset=utf-8');
        $_SESSION['toast_msg']  = 'ไม่พบรหัสพนักงาน';
        $_SESSION['toast_type'] = 'error';
        header('Location: employee.php');
        exit;
    }
    $cur = (int)($_POST['is_active'] ?? 1);
    $nv  = $cur ? 0 : 1;
    $label = $nv ? 'เปิดใช้งาน' : 'ปิดใช้งาน';

    $res = sqlsrv_query($conn,
        "UPDATE CFP_Employee SET IsActive=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE EmployeeID=?",
        array($nv, $userID, $id)
    );
    if ($res === false) {
        $e = sqlsrv_errors();
        $_SESSION['toast_msg']  = 'เกิดข้อผิดพลาด: ' . ($e[0]['message'] ?? '');
        $_SESSION['toast_type'] = 'error';
    } else {
        logAction($conn, 'DATA_UPDATE', 'CFP_Employee', $id, null, null, null,
            $label . ' EmployeeID=' . $id);
        $_SESSION['toast_msg']  = $label . 'พนักงานเรียบร้อยแล้ว';
        $_SESSION['toast_type'] = 'success';
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Location: employee.php');
    exit;
}

/* ---- Read & Validate fields ---- */
$code      = strtoupper(cleanStr($_REQUEST['EmployeeCode'] ?? '', 50));
$name      = cleanStr($_REQUEST['FullName'] ?? '', 200);
$siteID    = (int)($_REQUEST['SiteID']     ?? 0) ?: null;
$deptID    = (int)($_REQUEST['DeptID']     ?? 0) ?: null;
$posID     = (int)($_REQUEST['PositionID'] ?? 0) ?: null;
$commute   = cleanStr($_REQUEST['CommuteType'] ?? '', 100);
$vtypeID   = (int)($_REQUEST['VehicleTypeID'] ?? 0) ?: null;
$distKm    = is_numeric($_REQUEST['CommuteDistKm']    ?? '') ? (float)$_REQUEST['CommuteDistKm']    : null;
$workDays  = is_numeric($_REQUEST['WorkDaysPerMonth']  ?? '') ? (int)$_REQUEST['WorkDaysPerMonth']    : null;
$email     = cleanStr($_REQUEST['Email']   ?? '', 200);
$phone     = cleanStr($_REQUEST['Phone']   ?? '', 50);
$remark    = cleanStr($_REQUEST['Remark']  ?? '', 500);

if (empty($code) || empty($name)) {
    jsonOut(false, 'กรุณากรอกรหัสพนักงานและชื่อ-นามสกุล');
}

$AT = 'Employee';

/* ---- CREATE ---- */
if ($action === 'create') {
    $chk = sqlsrv_query($conn, "SELECT 1 FROM CFP_Employee WHERE EmployeeCode=?", array($code));
    if (sqlsrv_fetch($chk)) {
        jsonOut(false, 'รหัสพนักงาน "' . $code . '" มีอยู่แล้ว');
    }

    $res = sqlsrv_query($conn,
        "INSERT INTO CFP_Employee
            (EmployeeCode, FullName, SiteID, DeptID, PositionID,
             CommuteType, CommuteDistKm, WorkDaysPerMonth, VehicleTypeID,
             Email, Phone, Remark, IsActive, CreatedBy, CreatedDate)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,GETDATE())",
        array($code, $name, $siteID, $deptID, $posID,
              $commute ?: null, $distKm, $workDays, $vtypeID,
              $email ?: null, $phone ?: null, $remark ?: null, $userID)
    );

    if ($res === false) {
        $e = sqlsrv_errors();
        jsonOut(false, 'บันทึกไม่สำเร็จ: ' . ($e[0]['message'] ?? ''));
    }

    $rw    = sqlsrv_fetch_array(sqlsrv_query($conn, "SELECT @@IDENTITY AS NewID"), SQLSRV_FETCH_ASSOC);
    $newID = isset($rw['NewID']) ? (int)$rw['NewID'] : 0;
    if ($newID <= 0) {
        jsonOut(false, 'ไม่สามารถสร้างรหัสได้');
    }

    // ===== จัดการไฟล์ =====
    list($fc, $fe) = handleFiles($conn, $userID, $newID, $AT, 'employee');

    logAction($conn, 'DATA_CREATE', 'CFP_Employee', $newID, null, null, null,
        'เพิ่ม: ' . $code . ' ' . $name);

    $msg = 'เพิ่มพนักงาน "' . $name . '" เรียบร้อยแล้ว ';
    if ($fe) { $msg .= ' มีปัญหา: ' . implode('; ', $fe); }

    jsonOut(true, $msg, array('assetID' => $newID));
}

/* ---- UPDATE ---- */
if ($action === 'update') {
    if ($id <= 0) {
        jsonOut(false, 'ไม่พบรหัสพนักงาน');
    }

    $chk = sqlsrv_query($conn,
        "SELECT 1 FROM CFP_Employee WHERE EmployeeCode=? AND EmployeeID<>?",
        array($code, $id)
    );
    if (sqlsrv_fetch($chk)) {
        jsonOut(false, 'รหัสพนักงาน "' . $code . '" มีอยู่แล้ว');
    }

    $res = sqlsrv_query($conn,
        "UPDATE CFP_Employee SET
            EmployeeCode=?, FullName=?, SiteID=?, DeptID=?, PositionID=?,
            CommuteType=?, CommuteDistKm=?, WorkDaysPerMonth=?, VehicleTypeID=?,
            Email=?, Phone=?, Remark=?,
            UpdatedBy=?, UpdatedDate=GETDATE()
         WHERE EmployeeID=?",
        array($code, $name, $siteID, $deptID, $posID,
              $commute ?: null, $distKm, $workDays, $vtypeID,
              $email ?: null, $phone ?: null, $remark ?: null,
              $userID, $id)
    );

    if ($res === false) {
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
                    "SELECT FileName FROM CFP_AssetImage WHERE ImageID=? AND AssetType='Employee' AND AssetID=?",
                    array($delID, $id)
                ),
                SQLSRV_FETCH_ASSOC
            );
            if ($rowDel) {
                $delFile = dirname(__DIR__) . '/uploads/assets/employee/' . $rowDel['FileName'];
                if (file_exists($delFile)) { @unlink($delFile); }
                sqlsrv_query($conn, "DELETE FROM CFP_AssetImage WHERE ImageID=?", array($delID));
            }
        }
    }

    // ===== จัดการไฟล์เพิ่ม =====
    list($fc, $fe) = handleFiles($conn, $userID, $id, $AT, 'employee');

    logAction($conn, 'DATA_UPDATE', 'CFP_Employee', $id, null, null, null,
        'แก้ไข: ' . $code . ' ' . $name);

    $msg = 'แก้ไขพนักงาน "' . $name . '" เรียบร้อยแล้ว ';
    if ($fe) { $msg .= ' มีปัญหา: ' . implode('; ', $fe); }

    jsonOut(true, $msg, array('assetID' => $id));
}

jsonOut(false, 'คำขอไม่ถูกต้อง');
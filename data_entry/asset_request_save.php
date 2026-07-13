<?php
/* =============================================================
   data_entry/asset_request_save.php
   รับคำขอ "เพิ่มทรัพย์สินใหม่" จาก Data Entry ตอนไม่มีทรัพย์สินให้เลือก
   Admin เป็นคนไปสร้างทะเบียนจริงเองที่หน้า master ที่เกี่ยวข้อง
   ============================================================= */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(1, 4, 5));
header('Content-Type: application/json; charset=utf-8');

$conn   = getConnection();
$userID = getEffectiveUserID();

function jsonOut($s, $m, $e = array()) {
    echo json_encode(array_merge(array('success' => $s, 'msg' => $m), $e), JSON_UNESCAPED_UNICODE);
    exit;
}

if (isViewingAs()) { jsonOut(false, 'อยู่ในโหมด View-as (Read-only) — ส่งคำขอไม่ได้'); }

/* Data Entry เท่านั้นที่ส่งคำขอได้ (Admin/SustainAdmin ไม่ควรขอตัวเอง) */
if (getEffectiveRole() !== 1) { jsonOut(false, 'ไม่มีสิทธิ์ส่งคำขอ'); }

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body) { jsonOut(false, 'รูปแบบข้อมูลไม่ถูกต้อง'); }
if (empty($body['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $body['csrf_token'])) {
    jsonOut(false, 'CSRF ไม่ถูกต้อง');
}

$scopeNo   = (int)($body['scopeNo'] ?? 0);
$assetType = trim($body['assetType'] ?? '');
$siteID    = (int)($body['siteID'] ?? 0);
$reqName   = mb_substr(trim($body['requestedName'] ?? ''), 0, 300);
$remark    = mb_substr(trim($body['remark'] ?? ''), 0, 500);
/* ── Details: เก็บฟิลด์ดิบ (key/value) ไว้ pre-fill กลับเข้าฟอร์ม master ทีหลัง — ไม่ใช่ข้อความ Remark ที่รวมกันแล้ว ── */
$details = null;
if (!empty($body['details']) && is_array($body['details'])) {
    $cleanDetails = array();
    foreach ($body['details'] as $k => $v) {
        $k = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$k);
        if ($k === '') { continue; }
        $cleanDetails[$k] = mb_substr(trim((string)$v), 0, 300);
    }
    if (!empty($cleanDetails)) { $details = json_encode($cleanDetails, JSON_UNESCAPED_UNICODE); }
}

/* จำกัด AssetType ให้ตรงกับที่ระบบรู้จักเท่านั้น (dropdown ตายตัว — กัน string แปลกปลอม) */
$validTypesByScope = array(
    1 => array('Equipment', 'Vehicle', 'Refrigerant', 'WaterMeter'),
    2 => array('ElectricMeter'),
    3 => array('Vendor', 'Waste', 'Employee'),
);

if (!in_array($scopeNo, array(1, 2, 3))) { jsonOut(false, 'Scope ไม่ถูกต้อง'); }
if (!in_array($assetType, $validTypesByScope[$scopeNo])) { jsonOut(false, 'ประเภททรัพย์สินไม่ถูกต้อง'); }
if ($siteID <= 0) { jsonOut(false, 'ไม่พบ Site'); }
if ($reqName === '') { jsonOut(false, 'กรุณาระบุชื่อ/รายละเอียดทรัพย์สินที่ต้องการ'); }

$res = sqlsrv_query($conn,
    "INSERT INTO CFP_AssetRequest (ScopeNo, AssetType, SiteID, RequestedName, Remark, Details, RequestedBy)
     VALUES (?, ?, ?, ?, ?, ?, ?)",
    array($scopeNo, $assetType, $siteID, $reqName, $remark ?: null, $details, $userID));

if ($res === false) {
    $e = sqlsrv_errors();
    jsonOut(false, 'บันทึกคำขอไม่สำเร็จ: ' . ($e[0]['message'] ?? ''));
}

logAction($conn, 'ASSET_REQUEST', 'CFP_AssetRequest', null, $siteID, null, 'Scope' . $scopeNo,
    'ขอเพิ่ม ' . $assetType . ': ' . $reqName);

jsonOut(true, 'ส่งคำขอเรียบร้อยแล้ว รอ Admin ดำเนินการ');

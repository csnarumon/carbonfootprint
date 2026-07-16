<?php
/* data_entry/evidence_save.php
   แนบ/ลบไฟล์หลักฐาน (EvidenceFile) ของ CFP_ActivityData — ใช้ร่วมกับ Scope1/Scope2
   actions: upload | delete
   จำกัด 1 ไฟล์ต่อ 1 แถวข้อมูล (ไฟล์ใหม่จะแทนที่ไฟล์เดิม)
*/
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(1,4,5));
header('Content-Type: application/json; charset=utf-8');
$conn   = getConnection();
$userID = (int)$_SESSION['user_id'];
$canEdit = (getEffectiveRole() === 1) && !isViewingAs();

function jsonOut($s, $m, $e = array()) {
    echo json_encode(array_merge(array('success' => $s, 'msg' => $m), $e), JSON_UNESCAPED_UNICODE);
    exit;
}

if (isViewingAs()) { jsonOut(false, 'อยู่ในโหมด View-as (Read-only) — แนบไฟล์ไม่ได้'); }
if (!$canEdit) { jsonOut(false, 'ไม่มีสิทธิ์แนบไฟล์'); }

if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    jsonOut(false, 'CSRF ไม่ถูกต้อง');
}

$action     = $_POST['action'] ?? '';
$activityID = (int)($_POST['activity_id'] ?? 0);
$scope      = in_array($_POST['scope'] ?? '', array('scope1', 'scope2', 'scope3')) ? $_POST['scope'] : 'scope1';

if ($activityID <= 0) { jsonOut(false, 'กรุณาบันทึกร่างข้อมูลแถวนี้ก่อนแนบไฟล์'); }

/* ตรวจว่าแถวนี้เป็นของผู้ใช้จริง (join ผ่าน Header เพื่อกัน user ยิง ActivityID ของคนอื่น) */
$resChk = sqlsrv_query($conn,
    "SELECT a.ActivityID, a.EvidenceFile, h.Status
     FROM CFP_ActivityData a
     JOIN CFP_MonthlyHeader h ON h.HeaderID = a.HeaderID
     WHERE a.ActivityID=? AND a.IsActive=1", array($activityID));
$row = $resChk ? sqlsrv_fetch_array($resChk, SQLSRV_FETCH_ASSOC) : null;
if (!$row) { jsonOut(false, 'ไม่พบข้อมูลแถวนี้'); }
if ((int)$row['Status'] > 0) { jsonOut(false, 'ข้อมูลถูกส่งอนุมัติแล้ว ไม่สามารถแก้ไขไฟล์แนบได้'); }

$uploadDir = dirname(__DIR__) . '/uploads/evidence/' . $scope . '/';
if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }

if ($action === 'upload') {
    if (empty($_FILES['file']['name']) || $_FILES['file']['size'] == 0) {
        jsonOut(false, 'กรุณาเลือกไฟล์');
    }
    $allowed = array('jpg', 'jpeg', 'png', 'pdf');
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        jsonOut(false, 'ไม่รองรับไฟล์ประเภทนี้ (รองรับเฉพาะ .jpg .png .pdf)');
    }
    if ($_FILES['file']['size'] > 10 * 1024 * 1024) {
        jsonOut(false, 'ไฟล์ใหญ่เกินไป (สูงสุด 10 MB)');
    }

    /* ลบไฟล์เดิมถ้ามี (จำกัด 1 ไฟล์ต่อแถว) */
    if (!empty($row['EvidenceFile'])) {
        $oldFile = $uploadDir . $row['EvidenceFile'];
        if (file_exists($oldFile)) { @unlink($oldFile); }
    }

    $newName = 'EVD_' . $activityID . '_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir . $newName)) {
        jsonOut(false, 'ย้ายไฟล์ไม่สำเร็จ');
    }

    $r = sqlsrv_query($conn,
        "UPDATE CFP_ActivityData SET EvidenceFile=?, UpdatedBy=?, UpdatedDate=GETDATE() WHERE ActivityID=?",
        array($newName, $userID, $activityID));
    if (!$r) { jsonOut(false, 'บันทึกไฟล์ไม่สำเร็จ'); }

    logAction($conn, 'DATA_UPDATE', 'CFP_ActivityData', $activityID, null, null, null, 'แนบไฟล์หลักฐาน: ' . $newName);
    jsonOut(true, 'แนบไฟล์เรียบร้อยแล้ว', array('fileName' => $newName));
}

if ($action === 'delete') {
    if (!empty($row['EvidenceFile'])) {
        $oldFile = $uploadDir . $row['EvidenceFile'];
        if (file_exists($oldFile)) { @unlink($oldFile); }
    }
    $r = sqlsrv_query($conn,
        "UPDATE CFP_ActivityData SET EvidenceFile=NULL, UpdatedBy=?, UpdatedDate=GETDATE() WHERE ActivityID=?",
        array($userID, $activityID));
    if (!$r) { jsonOut(false, 'ลบไฟล์ไม่สำเร็จ'); }

    logAction($conn, 'DATA_UPDATE', 'CFP_ActivityData', $activityID, null, null, null, 'ลบไฟล์หลักฐาน');
    jsonOut(true, 'ลบไฟล์เรียบร้อยแล้ว');
}

jsonOut(false, 'คำขอไม่ถูกต้อง');

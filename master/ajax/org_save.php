<?php
/* ==============================================
   master/ajax/org_save.php
   รับ POST FormData — CRUD ทุก entity องค์กร
   entity: company | division | department | section | position
   action: create | update | delete
   ============================================== */
header('Content-Type: application/json; charset=utf-8');

require_once '../../includes/auth_check.php';
require_once '../../config/db.php';

requireRole(array(4));

/* CSRF */
$csrf = $_POST['csrf_token'] ?? '';
if (empty($csrf) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    echo json_encode(array('success' => false, 'msg' => 'คำขอไม่ถูกต้อง (CSRF)'));
    exit;
}

$conn   = getConnection();
$action = trim($_POST['action'] ?? '');
$entity = trim($_POST['entity'] ?? '');

/* ===== Helper ===== */
function intOrNull($v) {
    $n = (int)$v;
    return $n > 0 ? $n : null;
}
function strOrNull($v) {
    $s = trim($v ?? '');
    return $s !== '' ? $s : null;
}
function ok($msg)  { echo json_encode(array('success' => true,  'msg' => $msg)); exit; }
function err($msg) { echo json_encode(array('success' => false, 'msg' => $msg)); exit; }

/* ===== Config ต่อ Entity ===== */
$cfg = array(
    'site' => array(
        'table'   => 'CFP_Site',
        'pk'      => 'SiteID',
        'label'   => 'Site',
        'fields'  => array('SiteCode','SiteName','ScopeGroup'),
        'req'     => array('SiteCode','SiteName'),
    ),
    'company' => array(
        'table'   => 'CFP_Company',
        'pk'      => 'CompanyID',
        'label'   => 'บริษัท',
        'fields'  => array('CompanyCode','CompanyName'),
        'req'     => array('CompanyCode','CompanyName'),
    ),
    'division' => array(
        'table'   => 'CFP_Division',
        'pk'      => 'DivisionID',
        'label'   => 'ฝ่าย',
        'fields'  => array('CompanyID','SiteID','DivisionCode','DivisionName','DivisionType','SortOrder'),
        'req'     => array('CompanyID','DivisionCode','DivisionName','DivisionType'),
    ),
    'department' => array(
        'table'   => 'CFP_Department',
        'pk'      => 'DeptID',
        'label'   => 'แผนก',
        /* ไม่มี DeptCode ใน fields — จะ inject เข้าหลัง generate */
        'fields'  => array('DivisionID','DeptName','SortOrder'),
        'req'     => array('DivisionID','DeptName'),
    ),
    'section' => array(
        'table'   => 'CFP_Section',
        'pk'      => 'SectionID',
        'label'   => 'หน่วยงาน',
        'fields'  => array('DeptID','SectionName'),
        'req'     => array('DeptID','SectionName'),
    ),
    'position' => array(
        'table'   => 'CFP_Position',
        'pk'      => 'PositionID',
        'label'   => 'ตำแหน่ง',
        'fields'  => array('CompanyID','PositionName'),
        'req'     => array('CompanyID','PositionName'),
    ),
);

if (!isset($cfg[$entity])) { err('ไม่รู้จัก entity: ' . $entity); }
$c = $cfg[$entity];

/* ===== DELETE (Soft) ===== */
if ($action === 'delete') {
    $id = intOrNull($_POST['id'] ?? 0);
    if (!$id) { err('ไม่พบ ID'); }

    $childMap = array(
        'company'    => array('CFP_Division',    'CompanyID'),
        'division'   => array('CFP_Department',  'DivisionID'),
        'department' => array('CFP_Section',     'DeptID'),
    );
    if (isset($childMap[$entity])) {
        list($childTable, $childFK) = $childMap[$entity];
        $chk = sqlsrv_query($conn,
            "SELECT COUNT(1) AS cnt FROM $childTable WHERE $childFK=? AND IsActive=1",
            array($id));
        $row = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);
        if ((int)($row['cnt'] ?? 0) > 0) {
            err('ไม่สามารถลบได้ เพราะมีข้อมูลย่อยที่ยังใช้งานอยู่');
        }
    }

    $res = sqlsrv_query($conn,
        "UPDATE {$c['table']} SET IsActive=0 WHERE {$c['pk']}=?",
        array($id));
    if ($res === false) { err('เกิดข้อผิดพลาดในการลบ'); }

    logAction($conn, 'ORG_DELETE', $c['table'], $id, null, null, null,
              'ลบ ' . $c['label'] . ' ID=' . $id);
    ok('ลบ' . $c['label'] . 'เรียบร้อยแล้ว');
}

/* ===== Validate required ===== */
foreach ($c['req'] as $f) {
    if (empty($_POST[$f])) {
        err('กรุณากรอก ' . $f);
    }
}

/* ===== Build values array ===== */
$intFields = array('CompanyID','DivisionID','DeptID','SiteID','SortOrder');
$vals = array();
foreach ($c['fields'] as $f) {
    if (in_array($f, $intFields)) {
        $vals[$f] = intOrNull($_POST[$f] ?? '');
    } else {
        $vals[$f] = strOrNull($_POST[$f] ?? '');
    }
}

/* ===== CREATE ===== */
if ($action === 'create') {

    /* --- Auto-generate DeptCode --- */
    if ($entity === 'department') {
        $rCode = sqlsrv_query($conn,
            "SELECT MAX(CAST(SUBSTRING(DeptCode, 6, 10) AS INT)) AS MaxNum
             FROM CFP_Department
             WHERE DeptCode LIKE 'DEPT-%'
               AND ISNUMERIC(SUBSTRING(DeptCode, 6, 10)) = 1");
        $lastNum = 0;
        if ($rCode) {
            $rowCode = sqlsrv_fetch_array($rCode, SQLSRV_FETCH_ASSOC);
            $lastNum = (int)($rowCode['MaxNum'] ?? 0);
        }
        $vals['DeptCode'] = 'DEPT-' . str_pad($lastNum + 1, 3, '0', STR_PAD_LEFT);
    }

    /* --- Auto-generate SectionCode --- */
    if ($entity === 'section') {
        $rCode = sqlsrv_query($conn,
            "SELECT MAX(CAST(SUBSTRING(SectionCode, 5, 10) AS INT)) AS MaxNum
             FROM CFP_Section
             WHERE SectionCode LIKE 'SEC-%'
               AND ISNUMERIC(SUBSTRING(SectionCode, 5, 10)) = 1");
        $lastNum = 0;
        if ($rCode) {
            $rowCode = sqlsrv_fetch_array($rCode, SQLSRV_FETCH_ASSOC);
            $lastNum = (int)($rowCode['MaxNum'] ?? 0);
        }
        $vals['SectionCode'] = 'SEC-' . str_pad($lastNum + 1, 3, '0', STR_PAD_LEFT);
    }

    /* --- Auto-generate PositionCode --- */
    if ($entity === 'position') {
        $rCode = sqlsrv_query($conn,
            "SELECT MAX(CAST(SUBSTRING(PositionCode, 5, 10) AS INT)) AS MaxNum
             FROM CFP_Position
             WHERE PositionCode LIKE 'POS-%'
               AND ISNUMERIC(SUBSTRING(PositionCode, 5, 10)) = 1");
        $lastNum = 0;
        if ($rCode) {
            $rowCode = sqlsrv_fetch_array($rCode, SQLSRV_FETCH_ASSOC);
            $lastNum = (int)($rowCode['MaxNum'] ?? 0);
        }
        $vals['PositionCode'] = 'POS-' . str_pad($lastNum + 1, 3, '0', STR_PAD_LEFT);
    }

    /* ตรวจ Code ซ้ำเฉพาะ entity ที่กรอกเอง */
    $codeField = '';
    if ($entity === 'company')  { $codeField = 'CompanyCode'; }
    if ($entity === 'division') { $codeField = 'DivisionCode'; }
    if ($entity === 'site')     { $codeField = 'SiteCode'; }

    if ($codeField && isset($vals[$codeField])) {
        $chk = sqlsrv_query($conn,
            "SELECT COUNT(1) AS cnt FROM {$c['table']} WHERE $codeField=? AND IsActive=1",
            array($vals[$codeField]));
        $rowChk = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);
        if ((int)($rowChk['cnt'] ?? 0) > 0) {
            err('รหัส ' . $vals[$codeField] . ' มีในระบบแล้ว');
        }
    }

    $cols   = implode(',', array_keys($vals));
    $params = array_values($vals);
    $marks  = implode(',', array_fill(0, count($params), '?'));

    $res = sqlsrv_query($conn,
        "INSERT INTO {$c['table']} ($cols,IsActive) VALUES ($marks,1)",
        $params);
    if ($res === false) {
        error_log('org_save CREATE: ' . print_r(sqlsrv_errors(), true));
        err('เกิดข้อผิดพลาดในการบันทึก');
    }

    $newID = 0;
    $r2 = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS NewID");
    if ($r2) {
        $nr    = sqlsrv_fetch_array($r2, SQLSRV_FETCH_ASSOC);
        $newID = (int)($nr['NewID'] ?? 0);
    }
    logAction($conn, 'ORG_CREATE', $c['table'], $newID, null, null, null,
              'เพิ่ม' . $c['label'], null, json_encode($vals));
    ok('เพิ่ม' . $c['label'] . 'เรียบร้อยแล้ว');
}

/* ===== UPDATE ===== */
if ($action === 'update') {
    $id = intOrNull($_POST['id'] ?? 0);
    if (!$id) { err('ไม่พบ ID'); }

    $sets   = array();
    $params = array();
    foreach ($vals as $f => $v) {
        $sets[]   = "$f=?";
        $params[] = $v;
    }
    $params[] = $id;

    $res = sqlsrv_query($conn,
        "UPDATE {$c['table']} SET " . implode(',', $sets) . " WHERE {$c['pk']}=?",
        $params);
    if ($res === false) {
        error_log('org_save UPDATE: ' . print_r(sqlsrv_errors(), true));
        err('เกิดข้อผิดพลาดในการอัปเดต');
    }
    logAction($conn, 'ORG_UPDATE', $c['table'], $id, null, null, null,
              'แก้ไข' . $c['label'] . ' ID=' . $id, null, json_encode($vals));
    ok('แก้ไข' . $c['label'] . 'เรียบร้อยแล้ว');
}

err('action ไม่ถูกต้อง');
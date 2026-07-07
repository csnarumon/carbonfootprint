<?php
/* master/wastedisposalsite_import.php
   A: ชื่อสถานที่*  B: ที่อยู่  C: จังหวัด  D: คำอธิบาย  E: ลำดับแสดง */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
require_once '../includes/excel_import_helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

define('CODE_PREFIX', 'WDS');
requireRole(array(4, 5));
verifyCsrf();
$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: wastedisposalsite.php');
    exit;
}
function generateSiteCode($conn, $prefix) {
    $res = sqlsrv_query($conn, "SELECT MAX(CAST(SUBSTRING(SiteCode, LEN(?) + 2, 10) AS INT)) AS MaxNum FROM CFP_WasteDisposalSite WHERE SiteCode LIKE ? + '-%'", array($prefix, $prefix));
    $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    $n = ($row && $row['MaxNum'] !== null) ? ((int)$row['MaxNum'] + 1) : 1;
    return $prefix . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) { redirectWithToast('ไม่พบไฟล์ที่อัปโหลด', 'error'); }
$fileTmp = $_FILES['import_file']['tmp_name'];
$fileName = $_FILES['import_file']['name'];
if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'xlsx') { redirectWithToast('รองรับเฉพาะไฟล์ .xlsx', 'error'); }
if ($_FILES['import_file']['size'] > 5*1024*1024) { redirectWithToast('ไฟล์มีขนาดเกิน 5 MB', 'error'); }

$rows = readExcelRows($fileTmp);
if ($rows === false || count($rows) === 0) { redirectWithToast('ไม่สามารถอ่านไฟล์ Excel ได้หรือไม่มีข้อมูล', 'error'); }

$res = sqlsrv_query($conn, "SELECT SiteName FROM CFP_WasteDisposalSite");
$existingNames = array();
while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $existingNames[$r['SiteName']] = true; }

$successCount = 0; $failCount = 0; $skipCount = 0;
$errors = array(); $seenInFile = array(); $rowNum = 1;

foreach ($rows as $row) {
    $rowNum++;
    $name     = excelCell($row, 0);
    $address  = excelCell($row, 1);
    $province = excelCell($row, 2);
    $desc     = excelCell($row, 3);
    $sort     = excelCell($row, 4);
    if ($name === '') { continue; }
    $sort = ($sort !== '' && is_numeric($sort)) ? (int)$sort : 99;
    if (isset($existingNames[$name]) || isset($seenInFile[$name])) { $skipCount++; continue; }
    $code = generateSiteCode($conn, CODE_PREFIX);
    $r = sqlsrv_query($conn, "INSERT INTO CFP_WasteDisposalSite (SiteCode,SiteName,Address,Province,Description,SortOrder,IsActive,CreatedBy,CreatedDate) VALUES (?,?,?,?,?,?,1,?,GETDATE())",
        array($code,$name,($address!==''?$address:null),($province!==''?$province:null),($desc!==''?$desc:null),$sort,(int)$_SESSION['user_id']));
    if ($r === false) { $failCount++; $errors[] = 'แถวที่ '.$rowNum.': บันทึกไม่สำเร็จ ('.$name.')'; continue; }
    $seenInFile[$name] = true;
    $successCount++;
}

$totalRows = count($rows);
logImportResult($conn, 'CFP_WasteDisposalSite', $fileName, $totalRows, $successCount, $failCount, $skipCount, $errors);
if ($successCount > 0) { logAction($conn, 'DATA_IMPORT', 'CFP_WasteDisposalSite', null, null, null, null, 'Import: สำเร็จ '.$successCount.'/'.$totalRows.' แถว'); }
$_SESSION['import_result'] = array('success'=>$successCount,'fail'=>$failCount,'skip'=>$skipCount,'errors'=>array_slice($errors,0,20));
header('Location: wastedisposalsite.php');
exit;

<?php
/* ==============================================
   master/ef_value_import.php
   นำเข้าค่า Emission Factor จาก Excel Template
   คอลัมน์ตาม EF_Import_Template.xlsx:
   A: EFName*  B: Scope*  C: GasType*  D: EFValue*
   E: GWP*     F: Unit    G: YearApply* H: SourceCode
   I: Category J: RefItemCode  K: Remark
   ============================================== */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
require_once '../includes/excel_import_helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

requireRole(array(4, 5));
verifyCsrf();

$conn = getConnection();

function redirectWithToast($msg, $type = 'success') {
    $_SESSION['toast'] = array('msg' => $msg, 'type' => $type);
    header('Location: ef_value.php');
    exit;
}

/* สร้างรหัส EF อัตโนมัติ */
function generateEFCode($conn, $scope) {
    $scopeShort = str_replace('Scope', 'S', $scope);
    $prefix     = 'EF-' . $scopeShort;
    $res = sqlsrv_query($conn,
        "SELECT MAX(CAST(SUBSTRING(EFCode, LEN(?) + 2, 10) AS INT)) AS MaxNum
         FROM CFP_EFValue WHERE EFCode LIKE ? + '-%'",
        array($prefix, $prefix));
    $row     = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
    $nextNum = ($row && $row['MaxNum'] !== null) ? ((int)$row['MaxNum'] + 1) : 1;
    return $prefix . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
}

/* ===== ตรวจไฟล์ ===== */
if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
    redirectWithToast('ไม่พบไฟล์ที่อัปโหลด', 'error');
}

$fileTmp = $_FILES['import_file']['tmp_name'];
$fileExt = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));

if ($fileExt !== 'xlsx') {
    redirectWithToast('รองรับเฉพาะไฟล์ .xlsx เท่านั้น', 'error');
}
if ($_FILES['import_file']['size'] > 5 * 1024 * 1024) {
    redirectWithToast('ไฟล์มีขนาดเกิน 5 MB', 'error');
}

/* ===== อ่าน Excel — ข้ามแถว header (แถว 1-5 เป็น header/ตัวอย่าง) ===== */
$allRows = readExcelRows($fileTmp);
if ($allRows === false) {
    redirectWithToast('ไม่สามารถอ่านไฟล์ Excel ได้', 'error');
}

/* กรองแถวว่างและแถว header ออก
   readExcelRows() ข้าม row 1 แล้ว — template มี header row 4-5 และ example row 6-10
   ดังนั้นข้ามไป 9 แถวแรก (index 0-8) */
$rows = array();
foreach ($allRows as $idx => $row) {
    /* ข้ามแถว header/sub-header/ตัวอย่าง (9 แถวแรก) */
    if ($idx < 9) { continue; }
    /* ข้ามแถว label "เริ่มกรอก" */
    $firstCell = excelCell($row, 0);
    if (strpos($firstCell, '▼') !== false) { continue; }
    if ($firstCell === '') { continue; }
    $rows[] = $row;
}

if (count($rows) === 0) {
    redirectWithToast('ไม่พบข้อมูลในไฟล์ กรุณาตรวจสอบและกรอกข้อมูลตั้งแต่แถวที่ 12 เป็นต้นไป', 'error');
}

/* ===== โหลด lookup tables ===== */
/* EFSource: SourceCode → SourceID */
$sourceLookup = array();
$resSrc = sqlsrv_query($conn, "SELECT SourceID, SourceCode FROM CFP_EFSource WHERE IsActive=1");
while ($r = sqlsrv_fetch_array($resSrc, SQLSRV_FETCH_ASSOC)) {
    $sourceLookup[strtoupper(trim($r['SourceCode']))] = (int)$r['SourceID'];
}

/* ActivityItem: ItemCode → ItemID */
$itemLookup = array();
$resItem = sqlsrv_query($conn, "SELECT ItemID, ItemCode FROM CFP_ActivityItem WHERE IsActive=1");
while ($r = sqlsrv_fetch_array($resItem, SQLSRV_FETCH_ASSOC)) {
    $itemLookup[strtoupper(trim($r['ItemCode']))] = (int)$r['ItemID'];
}

/* EFName ที่มีอยู่แล้ว (เช็คซ้ำด้วย EFName + YearApply + Scope) */
$existingKeys = array();
$resEx = sqlsrv_query($conn, "SELECT EFName, YearApply, Scope FROM CFP_EFValue");
while ($r = sqlsrv_fetch_array($resEx, SQLSRV_FETCH_ASSOC)) {
    $key = strtolower(trim($r['EFName'])) . '|' . $r['YearApply'] . '|' . $r['Scope'];
    $existingKeys[$key] = true;
}

/* valid values */
$validScopes   = array('Scope1', 'Scope2', 'Scope3');
$validGasTypes = array('CO2', 'CH4', 'N2O', 'HFCs', 'CO2e');

/* ===== ประมวลผล ===== */
$successCount = 0;
$skipCount    = 0;
$failCount    = 0;
$errors       = array();
$seenInFile   = array();
$rowNum       = 11; /* เริ่มแถวจริงที่ 12 ใน Excel */

foreach ($rows as $row) {
    $rowNum++;

    /* อ่าน columns A-K */
    $efName     = excelCell($row, 0);   // A: EFName
    $scope      = excelCell($row, 1);   // B: Scope
    $gasType    = excelCell($row, 2);   // C: GasType
    $efValue    = excelCell($row, 3);   // D: EFValue
    $gwp        = excelCell($row, 4);   // E: GWP
    $unit       = excelCell($row, 5);   // F: Unit
    $yearApply  = excelCell($row, 6);   // G: YearApply
    $sourceCode = excelCell($row, 7);   // H: SourceCode
    $category   = excelCell($row, 8);   // I: Category
    $refCode    = excelCell($row, 9);   // J: RefItemCode
    $remark     = excelCell($row, 10);  // K: Remark

    /* ข้ามแถวว่าง */
    if ($efName === '') { continue; }

    /* ===== Validate จำเป็น ===== */
    $rowErrors = array();

    if (!in_array($scope, $validScopes)) {
        $rowErrors[] = 'Scope ต้องเป็น Scope1/Scope2/Scope3 (ได้รับ: "' . $scope . '")';
    }
    if (!in_array($gasType, $validGasTypes)) {
        $rowErrors[] = 'GasType ต้องเป็น CO2/CH4/N2O/HFCs/CO2e (ได้รับ: "' . $gasType . '")';
    }
    if ($efValue === '' || !is_numeric($efValue) || (float)$efValue < 0) {
        $rowErrors[] = 'EFValue ต้องเป็นตัวเลขที่ไม่ติดลบ';
    }
    if ($gwp === '' || !is_numeric($gwp) || (float)$gwp <= 0) {
        $rowErrors[] = 'GWP ต้องเป็นตัวเลขที่มากกว่า 0';
    }
    if ($yearApply === '' || !is_numeric($yearApply) || (int)$yearApply < 2000) {
        $rowErrors[] = 'YearApply ต้องเป็นปี ค.ศ. ที่ถูกต้อง เช่น 2024';
    }

    if (!empty($rowErrors)) {
        $failCount++;
        $errors[] = 'แถวที่ ' . $rowNum . ' (' . $efName . '): ' . implode(', ', $rowErrors);
        continue;
    }

    /* ===== เช็คซ้ำ ===== */
    $dupKey = strtolower(trim($efName)) . '|' . (int)$yearApply . '|' . $scope;
    if (isset($existingKeys[$dupKey]) || isset($seenInFile[$dupKey])) {
        $skipCount++;
        continue;
    }

    /* ===== Lookup SourceID ===== */
    $sourceID = null;
    if ($sourceCode !== '') {
        $sourceID = $sourceLookup[strtoupper(trim($sourceCode))] ?? null;
        if ($sourceID === null) {
            /* ไม่พบ source — warning แต่ยังบันทึก */
            $errors[] = 'แถวที่ ' . $rowNum . ' (' . $efName . '): ไม่พบ SourceCode "' . $sourceCode . '" — บันทึกโดยไม่ผูก Source';
        }
    }

    /* ===== Lookup RefID ===== */
    $refID    = null;
    $refTable = null;
    if ($refCode !== '') {
        $refID = $itemLookup[strtoupper(trim($refCode))] ?? null;
        if ($refID !== null) {
            $refTable = 'CFP_ActivityItem';
        } else {
            $errors[] = 'แถวที่ ' . $rowNum . ' (' . $efName . '): ไม่พบ RefItemCode "' . $refCode . '" — บันทึกโดยไม่ผูก Activity';
        }
    }

    /* ===== Generate EFCode ===== */
    $code = generateEFCode($conn, $scope);

    /* ===== Insert ===== */
    $sql = "INSERT INTO CFP_EFValue
            (EFCode, EFName, EFValue, GWP, Unit, Scope, GasType,
             Category, YearApply, SourceID, RefID, RefTable,
             IsActive, CreatedBy, CreatedDate)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,GETDATE())";
    $r = sqlsrv_query($conn, $sql, array(
        $code,
        trim($efName),
        (float)$efValue,
        (float)$gwp,
        ($unit !== '' ? $unit : null),
        $scope,
        $gasType,
        ($category !== '' ? $category : null),
        (int)$yearApply,
        $sourceID,
        $refID,
        $refTable,
        (int)$_SESSION['user_id']
    ));

    if ($r === false) {
        /* retry race condition */
        $code = generateEFCode($conn, $scope);
        $r = sqlsrv_query($conn, $sql, array(
            $code, trim($efName), (float)$efValue, (float)$gwp,
            ($unit !== '' ? $unit : null), $scope, $gasType,
            ($category !== '' ? $category : null), (int)$yearApply,
            $sourceID, $refID, $refTable, (int)$_SESSION['user_id']
        ));
    }

    if ($r === false) {
        $failCount++;
        $errors[] = 'แถวที่ ' . $rowNum . ' (' . $efName . '): บันทึกไม่สำเร็จ';
        continue;
    }

    $seenInFile[$dupKey] = true;
    $successCount++;
}

/* ===== Log ===== */
$fileName = $_FILES['import_file']['name'];
$totalRows = count($rows);
logImportResult($conn, 'CFP_EFValue', $fileName, $totalRows, $successCount, $failCount, $skipCount, $errors);

if ($successCount > 0) {
    logAction($conn, 'DATA_IMPORT', 'CFP_EFValue', null, null, null, null,
        'Import EF: สำเร็จ ' . $successCount . ' / ทั้งหมด ' . $totalRows . ' แถว');
}

/* ===== ส่งผลลัพธ์กลับ ===== */
$_SESSION['import_result'] = array(
    'success' => $successCount,
    'fail'    => $failCount,
    'skip'    => $skipCount,
    'errors'  => array_slice($errors, 0, 20),
);

header('Location: ef_value.php');
exit;

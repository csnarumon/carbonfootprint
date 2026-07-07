<?php
/* ==============================================
   includes/excel_import_helper.php
   ฟังก์ชันกลางสำหรับอ่านไฟล์ Excel (.xlsx)
   ใช้ร่วมกันได้ทุกหน้า Master Data ที่มี Import Excel
   ต้อง require_once __DIR__ . '/../vendor/autoload.php' ก่อนใช้งาน
   ============================================== */

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * อ่านไฟล์ Excel (.xlsx) แถวแรกเป็น Header แล้วคืนค่าเป็น array ของแถวข้อมูล
 * แต่ละแถวคืนเป็น array แบบ index เรียงตามคอลัมน์ (0, 1, 2, ...)
 *
 * @param  string $filePath path ไฟล์ที่ upload มา (จาก $_FILES['xxx']['tmp_name'])
 * @return array  array ของแถว (ไม่รวม Header แถวแรก) หรือ false ถ้าอ่านไม่ได้
 */
function readExcelRows($filePath) {
    try {
        $spreadsheet = IOFactory::load($filePath);
        $sheet       = $spreadsheet->getActiveSheet();
        $allRows     = $sheet->toArray(null, true, true, false);

        /* ตัด Header แถวแรกออก */
        array_shift($allRows);

        return $allRows;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * ตัด whitespace หัว-ท้าย และแปลง null เป็น string ว่าง
 * กัน Error ตอน trim(null) บน PHP 8.1+
 */
function excelCell($row, $index) {
    if (!isset($row[$index]) || $row[$index] === null) {
        return '';
    }
    return trim((string)$row[$index]);
}

/**
 * บันทึกผลการ Import ลง CFP_ImportLog
 *
 * @param resource $conn
 * @param string   $importType  เช่น 'CFP_VehicleType'
 * @param string   $fileName
 * @param int      $total
 * @param int      $success
 * @param int      $fail
 * @param int      $skip
 * @param array    $errors      array ของข้อความ error (จะถูก JSON encode)
 */
function logImportResult($conn, $importType, $fileName, $total, $success, $fail, $skip, $errors = array()) {
    $status = ($fail > 0) ? 'PARTIAL' : 'SUCCESS';
    if ($success === 0 && $fail > 0) { $status = 'FAILED'; }

    $errorDetail = !empty($errors) ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null;

    $sql = "INSERT INTO CFP_ImportLog
            (ImportType, FileName, TotalRows, SuccessRows, FailRows, SkipRows,
             Status, ErrorDetail, ImportedBy, ImportedDate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";

    sqlsrv_query($conn, $sql, array(
        $importType, $fileName, $total, $success, $fail, $skip,
        $status, $errorDetail, (int)$_SESSION['user_id']
    ));
}

<?php
/* =============================================================
   data_entry/asset_request_options.php
   ดึง dropdown ตัวเลือก (ประเภท/แหล่งที่มา ฯลฯ) ตาม AssetType
   ใช้ตอนเปิดฟอร์ม "ขอเพิ่มทรัพย์สินใหม่" ให้ตรงกับข้อมูลจริงที่ Admin ต้องกรอก
   ============================================= */
require_once '../includes/auth_check.php';
require_once '../config/db.php';
requireRole(array(1, 4, 5));
header('Content-Type: application/json; charset=utf-8');

$conn      = getConnection();
$assetType = trim($_GET['assetType'] ?? '');

function fetchList($conn, $sql) {
    $out = array();
    $res = sqlsrv_query($conn, $sql);
    if ($res) {
        while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) { $out[] = $r; }
    }
    return $out;
}

$groups = array();

switch ($assetType) {
    case 'Equipment':
        $groups['equipmentType'] = fetchList($conn, "SELECT TypeID AS id, TypeName AS name FROM CFP_EquipmentType WHERE IsActive=1 ORDER BY SortOrder, TypeName");
        $groups['fuelType']      = fetchList($conn, "SELECT TypeID AS id, TypeName AS name FROM CFP_FuelType WHERE IsActive=1 ORDER BY SortOrder, TypeName");
        break;
    case 'Vehicle':
        $groups['vehicleType'] = fetchList($conn, "SELECT TypeID AS id, TypeName AS name FROM CFP_VehicleType WHERE IsActive=1 ORDER BY SortOrder, TypeName");
        $groups['fuelType']    = fetchList($conn, "SELECT TypeID AS id, TypeName AS name FROM CFP_FuelType WHERE IsActive=1 ORDER BY SortOrder, TypeName");
        break;
    case 'Refrigerant':
        $groups['refrigerantType'] = fetchList($conn, "SELECT TypeID AS id, TypeName AS name FROM CFP_RefrigerantType WHERE IsActive=1 ORDER BY SortOrder, TypeName");
        break;
    case 'WaterMeter':
        $groups['waterMeterType']  = fetchList($conn, "SELECT TypeID AS id, TypeName AS name FROM CFP_WaterMeterType WHERE IsActive=1 ORDER BY SortOrder, TypeName");
        $groups['waterSourceType'] = fetchList($conn, "SELECT SourceID AS id, SourceName AS name FROM CFP_WaterSourceType WHERE IsActive=1 ORDER BY SortOrder, SourceName");
        break;
    case 'ElectricMeter':
        $groups['electricMeterType']  = fetchList($conn, "SELECT TypeID AS id, TypeName AS name FROM CFP_ElectricMeterType WHERE IsActive=1 ORDER BY SortOrder, TypeName");
        $groups['electricSourceType'] = fetchList($conn, "SELECT SourceID AS id, SourceName AS name FROM CFP_ElectricSourceType WHERE IsActive=1 ORDER BY SortOrder, SourceName");
        break;
    case 'Waste':
        $groups['wasteType']       = fetchList($conn, "SELECT TypeID AS id, TypeName AS name FROM CFP_WasteType WHERE IsActive=1 ORDER BY SortOrder, TypeName");
        $groups['disposalMethod']  = fetchList($conn, "SELECT MethodID AS id, MethodName AS name FROM CFP_WasteDisposalMethod WHERE IsActive=1 ORDER BY SortOrder, MethodName");
        $groups['disposalSite']    = fetchList($conn, "SELECT SiteID AS id, SiteName AS name FROM CFP_WasteDisposalSite WHERE IsActive=1 ORDER BY SortOrder, SiteName");
        break;
    case 'Vendor':
    case 'Employee':
        /* ไม่มี dropdown จาก DB — ใช้ฟิลด์ข้อความ/ตัวเลือกคงที่ฝั่ง JS แทน */
        break;
    default:
        echo json_encode(array('success' => false, 'msg' => 'ประเภทไม่ถูกต้อง'));
        exit;
}

echo json_encode(array('success' => true, 'groups' => $groups), JSON_UNESCAPED_UNICODE);

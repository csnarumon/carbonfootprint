<?php
/* ==============================================
   includes/notif_check.php
   AJAX endpoint — รีเฟรชกระดิ่งแจ้งเตือนโดยไม่ reload หน้า
   ============================================== */
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/notif_status.php';

header('Content-Type: application/json; charset=utf-8');

$conn   = getConnection();
$roleID = getEffectiveRole();
$userID = getEffectiveUserID();

$data = cfpGetNotifications($conn, $roleID, $userID);

$scopeColor = array(1 => '#43A047', 2 => '#7C3AED', 3 => '#F59E0B');
$itemsOut = array();
foreach ($data['items'] as $ni) {
    $itemsOut[] = array(
        'itemName'      => $ni['ItemName'] ?? 'ไม่มีรายการ',
        'scopeNo'       => (int)($ni['ScopeNo'] ?? 0),
        'scopeColor'    => $scopeColor[(int)($ni['ScopeNo'] ?? 0)] ?? '#2AABB8',
        'siteName'      => $ni['SiteName'] ?? '',
        'submitterName' => $ni['SubmitterName'] ?? '',
        'submittedDate' => ($ni['SubmittedDate'] instanceof DateTime) ? $ni['SubmittedDate']->format('d M Y') : '',
    );
}

$assetItemsOut = array();
foreach ($data['assetRequestItems'] as $ar) {
    $assetItemsOut[] = array(
        'assetType'     => $ar['AssetType'] ?? '',
        'scopeNo'       => (int)($ar['ScopeNo'] ?? 0),
        'requestedName' => $ar['RequestedName'] ?? '',
        'siteName'      => $ar['SiteName'] ?? '',
        'requesterName' => $ar['RequesterName'] ?? '',
        'createdDate'   => ($ar['CreatedDate'] instanceof DateTime) ? $ar['CreatedDate']->format('d M Y') : '',
    );
}

$fulfilledItemsOut = array();
foreach ($data['fulfilledItems'] as $fr) {
    $fulfilledItemsOut[] = array(
        'assetType'     => $fr['AssetType'] ?? '',
        'scopeNo'       => (int)($fr['ScopeNo'] ?? 0),
        'requestedName' => $fr['RequestedName'] ?? '',
        'siteName'      => $fr['SiteName'] ?? '',
        'closedByName'  => $fr['ClosedByName'] ?? '',
        'closedDate'    => ($fr['ClosedDate'] instanceof DateTime) ? $fr['ClosedDate']->format('d M Y') : '',
    );
}

echo json_encode(array(
    'success'           => true,
    'count'             => $data['count'],
    'approvalCount'     => $data['approvalCount'],
    'items'             => $itemsOut,
    'assetRequestCount' => $data['assetRequestCount'],
    'assetRequestItems' => $assetItemsOut,
    'fulfilledCount'    => $data['fulfilledCount'],
    'fulfilledItems'    => $fulfilledItemsOut,
), JSON_UNESCAPED_UNICODE);

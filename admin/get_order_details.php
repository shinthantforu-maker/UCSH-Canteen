<?php
require_once '../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$orderId = intval($_GET['orderId'] ?? 0);

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit();
}

// Get order info
$orderStmt = $conn->prepare("SELECT o.*, u.username FROM orders o LEFT JOIN users u ON o.userId = u.userId WHERE o.orderId = ?");
$orderStmt->bind_param("i", $orderId);
$orderStmt->execute();
$order = $orderStmt->get_result()->fetch_assoc();
$orderStmt->close();

if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Order not found']);
    exit();
}

// Rejected item IDs
$rejectedItemIds = [];
if (!empty($order['rejected_items']) && $order['rejected_items'] !== 'all') {
    $rejectedItemIds = array_map('intval', explode(',', $order['rejected_items']));
}

// =============================================
// Get order items WITH selected_options
// =============================================
$itemsStmt = $conn->prepare("
    SELECT oi.*, m.itemName 
    FROM order_items oi 
    JOIN menu_items m ON oi.itemId = m.itemId 
    WHERE oi.orderId = ?
");
$itemsStmt->bind_param("i", $orderId);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
$items = [];

while ($row = $itemsResult->fetch_assoc()) {
    // is_rejected flag
    $row['is_rejected'] = in_array(intval($row['itemId']), $rejectedItemIds) ? true : false;
    
    // Parse options
    $row['options'] = [];
    $row['options_text'] = '';
    
    if (!empty($row['selected_options'])) {
        $opts = json_decode($row['selected_options'], true);
        if (is_array($opts)) {
            foreach ($opts as $opt) {
                if (is_array($opt) && !empty($opt['optionName'])) {
                    $row['options'][] = [
                        'optionName' => $opt['optionName'],
                        'extraPoints' => (int)($opt['extraPoints'] ?? 0)
                    ];
                }
            }
        }
    }
    
    // Build options text
    if (!empty($row['options'])) {
        $optNames = array_map(function($o) {
            $name = $o['optionName'];
            if ($o['extraPoints'] > 0) {
                $name .= ' (+' . number_format($o['extraPoints']) . ')';
            }
            return $name;
        }, $row['options']);
        $row['options_text'] = implode(', ', $optNames);
    }
    
    $items[] = $row;
}
$itemsStmt->close();

// Format createdAt
$order['createdAt'] = date('d/m/Y, h:i:s A', strtotime($order['createdAt']));

// Rejected items names
if (!empty($order['rejected_items']) && $order['rejected_items'] !== 'all') {
    $rejectedIds = explode(',', $order['rejected_items']);
    $placeholders = implode(',', array_fill(0, count($rejectedIds), '?'));
    $types = str_repeat('i', count($rejectedIds));
    $rejStmt = $conn->prepare("SELECT itemName FROM menu_items WHERE itemId IN ($placeholders)");
    $rejStmt->bind_param($types, ...$rejectedIds);
    $rejStmt->execute();
    $rejResult = $rejStmt->get_result();
    $rejNames = [];
    while ($r = $rejResult->fetch_assoc()) {
        $rejNames[] = $r['itemName'];
    }
    $rejStmt->close();
    $order['rejected_items'] = implode(', ', $rejNames);
}

echo json_encode([
    'success' => true,
    'order' => $order,
    'items' => $items
]);

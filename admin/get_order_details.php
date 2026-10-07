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

// ✅ Get rejected item IDs (for marking)
$rejectedItemIds = [];
if (!empty($order['rejected_items']) && $order['rejected_items'] !== 'all') {
    $rejectedItemIds = array_map('intval', explode(',', $order['rejected_items']));
}

// Get order items (INCLUDE rejected items - we'll mark them in frontend)
$itemsStmt = $conn->prepare("SELECT oi.*, m.itemName FROM order_items oi JOIN menu_items m ON oi.itemId = m.itemId WHERE oi.orderId = ?");
$itemsStmt->bind_param("i", $orderId);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
$items = [];
while ($row = $itemsResult->fetch_assoc()) {
    // ✅ Add is_rejected flag
    $row['is_rejected'] = in_array(intval($row['itemId']), $rejectedItemIds) ? true : false;
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
?>

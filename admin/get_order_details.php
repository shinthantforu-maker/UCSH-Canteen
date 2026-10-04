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

// =============================================
// FETCH ORDER
// =============================================
$stmt = $conn->prepare("
    SELECT o.*, u.username 
    FROM orders o 
    LEFT JOIN users u ON o.userId = u.userId 
    WHERE o.orderId = ?
");
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Order not found']);
    exit();
}

// =============================================
// FETCH ORDER ITEMS
// =============================================
$itemsStmt = $conn->prepare("
    SELECT oi.itemId, oi.quantity, oi.price, m.itemName 
    FROM order_items oi 
    JOIN menu_items m ON oi.itemId = m.itemId 
    WHERE oi.orderId = ?
");
$itemsStmt->bind_param("i", $orderId);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();

$items = [];
while ($row = $itemsResult->fetch_assoc()) {
    $items[] = $row;
}
$itemsStmt->close();

echo json_encode([
    'success' => true,
    'order' => $order,
    'items' => $items
]);
?>
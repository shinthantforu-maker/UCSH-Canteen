<?php
header('Content-Type: application/json');
require_once 'db.php';

if (!isset($_GET['queue']) || empty(trim($_GET['queue']))) {
    echo json_encode(['success' => false, 'message' => 'Queue number is required.']);
    exit();
}

$inputQueue = trim($_GET['queue']);
$cleanInput = preg_replace('/[^0-9]/', '', $inputQueue);

$foundOrder = null;

// First, try to find by queue_number
$stmt = $conn->prepare("SELECT orderId, status, queue_number FROM orders WHERE queue_number = ?");
$stmt->bind_param("s", $inputQueue);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
    $foundOrder = $res->fetch_assoc();
}
$stmt->close();

// If not found, try by orderId
if (!$foundOrder && !empty($cleanInput)) {
    $stmt = $conn->prepare("SELECT orderId, status, queue_number FROM orders WHERE orderId = ?");
    $stmt->bind_param("i", $cleanInput);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $foundOrder = $res->fetch_assoc();
    }
    $stmt->close();
}

if ($foundOrder) {
    // ✅ CHANGED: pending + confirmed → Ordered
    $statusMap = [
        'pending' => 'Ordered',
        'ordered' => 'Ordered',
        'confirmed' => 'Ordered',
        'cooking' => 'Cooking',
        'pickup' => 'Pickup',
        'completed' => 'Completed',
        'rejected' => 'Rejected'
    ];
    
    $rawStatus = strtolower($foundOrder['status']);
    $displayStatus = $statusMap[$rawStatus] ?? ucfirst($rawStatus);

    echo json_encode([
        'success' => true,
        'queueNumber' => $foundOrder['queue_number'] ?? 'Q-' . str_pad($foundOrder['orderId'], 3, '0', STR_PAD_LEFT),
        'status' => $displayStatus
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Order not found.'
    ]);
}
?>
<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? 0;

if ($userId > 0) {
    // Get user's pending orders
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM orders WHERE userId = ? AND status IN ('pending', 'confirmed', 'cooking', 'pickup')");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $count = $data['count'] ?? 0;
    $stmt->close();
    
    echo json_encode([
        'has_pending' => $count > 0,
        'count' => $count
    ]);
} else {
    echo json_encode(['has_pending' => false, 'count' => 0]);
}
?>
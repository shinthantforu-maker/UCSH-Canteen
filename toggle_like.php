<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_POST['itemId'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$item_id = intval($_POST['itemId']);
$action = $_POST['action'] ?? 'add';

if ($action === 'add') {
    // Duplicate မဖြစ်အောင် IGNORE သို့မဟုတ် စစ်ပြီးမှ ထည့်ခြင်း
    $stmt = $conn->prepare("INSERT IGNORE INTO liked_items (userId, itemId) VALUES (?, ?)");
    $stmt->bind_param("ii", $user_id, $item_id);
    $stmt->execute();
    $stmt->close();
} else {
    $stmt = $conn->prepare("DELETE FROM liked_items WHERE userId = ? AND itemId = ?");
    $stmt->bind_param("ii", $user_id, $item_id);
    $stmt->execute();
    $stmt->close();
}

echo json_encode(['status' => 'success']);
?>
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized"
    ]);
    exit;
}

$userId = $_SESSION['user_id'];

$sql = "
SELECT
    n.notificationId,
    n.orderId,
    n.title,
    n.content,
    n.isRead,

    o.orderType,
    o.pickupTime,
    o.totalAmount,
    o.createdAt,

    u.username

FROM notifications n

LEFT JOIN orders o
ON n.orderId = o.orderId

LEFT JOIN users u
ON o.userId = u.userId

WHERE n.userId = ?

ORDER BY n.notificationId DESC
LIMIT 20
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();

$result = $stmt->get_result();

$notifications = [];

while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

$stmt->close();

$countStmt = $conn->prepare("
SELECT COUNT(*) total
FROM notifications
WHERE userId=? AND isRead=0
");

$countStmt->bind_param("i", $userId);
$countStmt->execute();

$count = $countStmt->get_result()->fetch_assoc()['total'];

$countStmt->close();

echo json_encode([
    "status" => "success",
    "unread_count" => (int)$count,
    "notifications" => $notifications
]);
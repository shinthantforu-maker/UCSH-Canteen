<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "status"=>"error"
    ]);
    exit;
}

$userId=$_SESSION['user_id'];

$sql="
SELECT
n.notificationId,
n.orderId,
n.title,
n.content,
n.isRead,

o.orderType,
o.pickupTime,
o.totalAmount,
o.points_used,
o.createdAt,
o.status,

u.username

FROM notifications n

LEFT JOIN orders o
ON n.orderId=o.orderId

LEFT JOIN users u
ON o.userId=u.userId

WHERE n.userId=?

ORDER BY n.notificationId DESC
LIMIT 20
";

$stmt=$conn->prepare($sql);
$stmt->bind_param("i",$userId);
$stmt->execute();

$result=$stmt->get_result();

$list=[];

while($row=$result->fetch_assoc()){
    $list[]=$row;
}

$count=0;

$c=$conn->prepare("SELECT COUNT(*) total FROM notifications WHERE userId=? AND isRead=0");
$c->bind_param("i",$userId);
$c->execute();
$count=$c->get_result()->fetch_assoc()['total'];

echo json_encode([
    "status"=>"success",
    "unread_count"=>$count,
    "notifications"=>$list
]);
?>
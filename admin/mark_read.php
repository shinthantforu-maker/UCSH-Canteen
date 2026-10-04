<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "status"=>"error"
    ]);
    exit;
}

if(!isset($_POST['notificationId'])){
    echo json_encode([
        "status"=>"invalid"
    ]);
    exit;
}

$notificationId=(int)$_POST['notificationId'];
$userId=$_SESSION['user_id'];

$stmt=$conn->prepare("
UPDATE notifications
SET isRead=1
WHERE notificationId=? AND userId=?
");

$stmt->bind_param("ii",$notificationId,$userId);

if($stmt->execute()){

    echo json_encode([
        "status"=>"success"
    ]);

}else{

    echo json_encode([
        "status"=>"error"
    ]);

}

$stmt->close();
?>
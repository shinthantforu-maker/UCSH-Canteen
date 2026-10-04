<?php
require_once 'db.php';
header('Content-Type: application/json');

// ============================================================
// 1. TRACK ORDER - NO AUTH REQUIRED (GUEST CAN TRACK)
// ============================================================
$action = $_GET['action'] ?? '';

if ($action === 'track_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $queueNumber = trim($_POST['queue'] ?? '');
    
    if (empty($queueNumber)) {
        echo json_encode(['status' => 'error', 'message' => 'Queue number is required.']);
        exit();
    }

    $stmt = $conn->prepare("SELECT orderId, queue_number, status, pickupTime, orderType, totalAmount, points_used, specialRequest, rejectionReason, createdAt FROM orders WHERE queue_number = ?");
    $stmt->bind_param("s", $queueNumber);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $order = $result->fetch_assoc();
        echo json_encode(['status' => 'success', 'order' => $order]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Order not found.']);
    }
    $stmt->close();
    exit();
}

// ============================================================
// 2. ALL OTHER ACTIONS - AUTH REQUIRED
// ============================================================
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

// 2. Toggle Like
if ($action === 'toggle_like' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemId = intval($_POST['itemId'] ?? 0);
    
    $check = $conn->prepare("SELECT likeId FROM liked_items WHERE userId = ? AND itemId = ?");
    $check->bind_param("ii", $user_id, $itemId);
    $check->execute();
    $res = $check->get_result();

    if ($res->num_rows > 0) {
        $del = $conn->prepare("DELETE FROM liked_items WHERE userId = ? AND itemId = ?");
        $del->bind_param("ii", $user_id, $itemId);
        $del->execute();
        $liked = false;
    } else {
        $ins = $conn->prepare("INSERT INTO liked_items (userId, itemId) VALUES (?, ?)");
        $ins->bind_param("ii", $user_id, $itemId);
        $ins->execute();
        $liked = true;
    }

    $countRes = $conn->query("SELECT COUNT(*) as count FROM liked_items WHERE userId = $user_id");
    $likeCount = $countRes->fetch_assoc()['count'];

    echo json_encode(['status' => 'success', 'liked' => $liked, 'likeCount' => $likeCount]);
    exit();
}

// 3. Add to Cart
if ($action === 'add_to_cart' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemId = intval($_POST['itemId'] ?? 0);
    $qty = intval($_POST['quantity'] ?? 1);

    // Get item points
    $itemStmt = $conn->prepare("SELECT points FROM menu_items WHERE itemId = ? AND isAvailable = 1");
    $itemStmt->bind_param("i", $itemId);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();
    $itemData = $itemResult->fetch_assoc();
    $itemPoints = $itemData['points'] ?? 0;
    $itemStmt->close();

    if ($itemPoints <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Item not available.']);
        exit();
    }


    // In api.php - track_order section
// In api.php - track_order section
if ($action === 'track_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $queueNumber = trim($_POST['queue'] ?? '');
    
    if (empty($queueNumber)) {
        echo json_encode(['status' => 'error', 'message' => 'Queue number is required.']);
        exit();
    }

    // SELECT specialRequest ပါ
    $stmt = $conn->prepare("SELECT orderId, queue_number, status, pickupTime, orderType, totalAmount, points_used, specialRequest, rejectionReason, createdAt FROM orders WHERE queue_number = ?");
    $stmt->bind_param("s", $queueNumber);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $order = $result->fetch_assoc();
        echo json_encode(['status' => 'success', 'order' => $order]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Order not found.']);
    }
    $stmt->close();
    exit();
}
    // Get user points
    $userStmt = $conn->prepare("SELECT points FROM users WHERE userId = ?");
    $userStmt->bind_param("i", $user_id);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $userData = $userResult->fetch_assoc();
    $userPoints = $userData['points'] ?? 0;
    $userStmt->close();

    $totalPointsNeeded = $itemPoints * $qty;

    if ($userPoints < $totalPointsNeeded) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Point မလုံလောက်ပါ။ လိုအပ်သော Point: ' . number_format($totalPointsNeeded) . '၊ သင့်တွင်: ' . number_format($userPoints)
        ]);
        exit();
    }

    $check = $conn->prepare("SELECT cartId, quantity FROM cart WHERE userId = ? AND itemId = ?");
    $check->bind_param("ii", $user_id, $itemId);
    $check->execute();
    $res = $check->get_result();

    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $newQty = $row['quantity'] + $qty;
        $newTotalPoints = $itemPoints * $newQty;
        if ($userPoints < $newTotalPoints) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Point မလုံလောက်ပါ။ စုစုပေါင်းလိုအပ်သော Point: ' . number_format($newTotalPoints)
            ]);
            exit();
        }
        $upd = $conn->prepare("UPDATE cart SET quantity = ? WHERE cartId = ?");
        $upd->bind_param("ii", $newQty, $row['cartId']);
        $upd->execute();
    } else {
        $ins = $conn->prepare("INSERT INTO cart (userId, itemId, quantity) VALUES (?, ?, ?)");
        $ins->bind_param("iii", $user_id, $itemId, $qty);
        $ins->execute();
    }

    $countRes = $conn->query("SELECT SUM(quantity) as count FROM cart WHERE userId = $user_id");
    $cartCount = $countRes->fetch_assoc()['count'] ?? 0;

    echo json_encode(['status' => 'success', 'cartCount' => $cartCount]);
    exit();
}
?>
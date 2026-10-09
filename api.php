<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

$user_id = $_SESSION['user_id'] ?? 0;
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// =============================================
// 1. GET ITEM OPTIONS
// =============================================
if ($action === 'get_item_options') {
    if (!$user_id) {
        echo json_encode(['status' => 'error', 'message' => 'Please login first']);
        exit;
    }
    
    $itemId = intval($_GET['itemId'] ?? 0);
    $groups = $itemId > 0 ? getItemOptionGroups($conn, $itemId, true) : [];
    
    echo json_encode([
        'has_options' => count($groups) > 0,
        'groups' => $groups
    ]);
    exit;
}

// =============================================
// 2. ADD TO CART (with options)
// =============================================
if ($action === 'add_to_cart') {
    if (!$user_id) {
        echo json_encode(['status' => 'error', 'message' => 'Please login first']);
        exit;
    }
    
    $itemId = intval($_POST['itemId'] ?? 0);
    $quantity = max(1, intval($_POST['quantity'] ?? 1));
    $selectedRaw = $_POST['options'] ?? '[]';
    $selected = json_decode($selectedRaw, true) ?: [];
    
    if ($itemId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid item']);
        exit;
    }
    
    // Item fetch
    $stmt = $conn->prepare("SELECT * FROM menu_items WHERE itemId = ? AND isAvailable = 1");
    $stmt->bind_param("i", $itemId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$item) {
        echo json_encode(['status' => 'error', 'message' => 'Item not available']);
        exit;
    }
    
    // Validate options
    $groups = getItemOptionGroups($conn, $itemId, true);
    $validatedOptions = [];
    $extraPoints = 0;
    
    $selectedByGroup = [];
    foreach ($selected as $sel) {
        $gid = intval($sel['groupId'] ?? 0);
        $oid = intval($sel['optionId'] ?? 0);
        if ($gid > 0 && $oid > 0) {
            $selectedByGroup[$gid][] = $oid;
        }
    }
    
    foreach ($groups as $g) {
        $picked = $selectedByGroup[$g['groupId']] ?? [];
        
        if ($g['isRequired'] && empty($picked)) {
            echo json_encode([
                'status' => 'error',
                'message' => "'{$g['groupName']}' ကို ရွေးပါ"
            ]);
            exit;
        }
        
        foreach ($picked as $oid) {
            foreach ($g['options'] as $opt) {
                if ($opt['optionId'] == $oid) {
                    $validatedOptions[] = [
                        'groupId' => (int)$g['groupId'],
                        'optionId' => (int)$opt['optionId'],
                        'optionName' => $opt['optionName'],
                        'extraPoints' => (int)$opt['extraPoints']
                    ];
                    $extraPoints += (int)$opt['extraPoints'];
                    break;
                }
            }
        }
    }
    
    $unitPrice = (int)$item['points'] + $extraPoints;
    $totalNeeded = $unitPrice * $quantity;
    
    // User points
    $uStmt = $conn->prepare("SELECT points FROM users WHERE userId = ?");
    $uStmt->bind_param("i", $user_id);
    $uStmt->execute();
    $userPoints = (int)($uStmt->get_result()->fetch_assoc()['points'] ?? 0);
    $uStmt->close();
    
    // Existing cart total
    $cartTotalStmt = $conn->prepare("
        SELECT c.quantity, c.selected_options, m.points 
        FROM cart c 
        JOIN menu_items m ON c.itemId = m.itemId 
        WHERE c.userId = ?
    ");
    $cartTotalStmt->bind_param("i", $user_id);
    $cartTotalStmt->execute();
    $cartRes = $cartTotalStmt->get_result();
    $existingTotal = 0;
    while ($row = $cartRes->fetch_assoc()) {
        $rowExtra = calcExtraPoints($row['selected_options']);
        $existingTotal += ((int)$row['points'] + $rowExtra) * (int)$row['quantity'];
    }
    $cartTotalStmt->close();
    
    if (($existingTotal + $totalNeeded) > $userPoints) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Point မလုံလောက်ပါ။ လိုအပ်ချက်: ' . number_format($existingTotal + $totalNeeded) . '၊ သင့်တွင်: ' . number_format($userPoints)
        ]);
        exit;
    }
    
    // Signature for merge
    $sigIds = array_map(fn($o) => $o['optionId'], $validatedOptions);
    sort($sigIds);
    $signature = implode('-', $sigIds);
    $optionsJson = empty($validatedOptions) ? null : json_encode($validatedOptions, JSON_UNESCAPED_UNICODE);
    
    // Merge check
    if ($optionsJson === null) {
        $mergeStmt = $conn->prepare("
            SELECT cartId, quantity FROM cart 
            WHERE userId = ? AND itemId = ? AND selected_options IS NULL
        ");
        $mergeStmt->bind_param("ii", $user_id, $itemId);
    } else {
        $mergeStmt = $conn->prepare("
            SELECT cartId, quantity FROM cart 
            WHERE userId = ? AND itemId = ? AND selected_options = ?
        ");
        $mergeStmt->bind_param("iis", $user_id, $itemId, $optionsJson);
    }
    $mergeStmt->execute();
    $existing = $mergeStmt->get_result()->fetch_assoc();
    $mergeStmt->close();
    
    if ($existing) {
        $newQty = (int)$existing['quantity'] + $quantity;
        $upd = $conn->prepare("UPDATE cart SET quantity = ? WHERE cartId = ?");
        $upd->bind_param("ii", $newQty, $existing['cartId']);
        $upd->execute();
        $upd->close();
        echo json_encode(['status' => 'success', 'message' => 'Cart ထဲသို့ ထည့်ပြီးပါပြီ', 'merged' => true]);
        exit;
    }
    
    // Insert new
    if ($optionsJson === null) {
        $ins = $conn->prepare("INSERT INTO cart (userId, itemId, quantity) VALUES (?, ?, ?)");
        $ins->bind_param("iii", $user_id, $itemId, $quantity);
    } else {
        $ins = $conn->prepare("INSERT INTO cart (userId, itemId, quantity, selected_options) VALUES (?, ?, ?, ?)");
        $ins->bind_param("iiis", $user_id, $itemId, $quantity, $optionsJson);
    }
    
    if (!$ins->execute()) {
        echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $conn->error]);
        exit;
    }
    $ins->close();
    
    echo json_encode(['status' => 'success', 'message' => 'Cart ထဲသို့ ထည့်ပြီးပါပြီ']);
    exit;
}

// =============================================
// 3. TRACK ORDER ✅ NEW
// =============================================
if ($action === 'track_order') {
    $queueInput = trim($_POST['queue'] ?? $_GET['queue'] ?? '');
    
    if (empty($queueInput)) {
        echo json_encode(['status' => 'error', 'message' => 'Queue နံပါတ် ထည့်ပါ']);
        exit;
    }
    
    $cleanInput = preg_replace('/[^0-9]/', '', $queueInput);
    
    $foundOrder = null;
    
    // Find by queue_number
    $stmt = $conn->prepare("
        SELECT o.*, u.username,
               (SELECT GROUP_CONCAT(CONCAT(m.itemName, ' (', oi.quantity, ')') SEPARATOR ', ') 
                FROM order_items oi JOIN menu_items m ON oi.itemId = m.itemId 
                WHERE oi.orderId = o.orderId) as items
        FROM orders o 
        LEFT JOIN users u ON o.userId = u.userId
        WHERE o.queue_number = ?
    ");
    $stmt->bind_param("s", $queueInput);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $foundOrder = $res->fetch_assoc();
    }
    $stmt->close();
    
    // Find by orderId
    if (!$foundOrder && !empty($cleanInput)) {
        $stmt = $conn->prepare("
            SELECT o.*, u.username,
                   (SELECT GROUP_CONCAT(CONCAT(m.itemName, ' (', oi.quantity, ')') SEPARATOR ', ') 
                    FROM order_items oi JOIN menu_items m ON oi.itemId = m.itemId 
                    WHERE oi.orderId = o.orderId) as items
            FROM orders o 
            LEFT JOIN users u ON o.userId = u.userId
            WHERE o.orderId = ?
        ");
        $stmt->bind_param("i", $cleanInput);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $foundOrder = $res->fetch_assoc();
        }
        $stmt->close();
    }
    
    if ($foundOrder) {
        echo json_encode([
            'status' => 'success',
            'order' => [
                'orderId' => $foundOrder['orderId'],
                'queue_number' => $foundOrder['queue_number'],
                'status' => strtolower($foundOrder['status']),
                'orderType' => $foundOrder['orderType'],
                'pickupTime' => $foundOrder['pickupTime'],
                'points_used' => $foundOrder['points_used'],
                'totalAmount' => $foundOrder['totalAmount'] ?? 0,
                'specialRequest' => $foundOrder['specialRequest'] ?? '',
                'rejectionReason' => $foundOrder['rejectionReason'] ?? '',
                'rejected_items' => $foundOrder['rejected_items'] ?? '',
                'items' => $foundOrder['items'] ?? '',
                'username' => $foundOrder['username'] ?? 'Guest'
            ]
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'ဒီ Queue နံပါတ်ဖြင့် Order မရှိပါ။'
        ]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action']);

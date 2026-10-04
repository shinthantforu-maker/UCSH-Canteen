<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: guest.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] == '1';

// =============================================
// HANDLE CANCEL ORDER (with Delivery Fee Refund)
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    header('Content-Type: application/json');
    
    $orderId = intval($_POST['orderId']);
    
    $checkStmt = $conn->prepare("SELECT points_used, status, orderType, deliveryFee FROM orders WHERE orderId = ? AND userId = ?");
    $checkStmt->bind_param("ii", $orderId, $user_id);
    $checkStmt->execute();
    $orderData = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if (!$orderData) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit();
    }
    
    if (strtolower($orderData['status']) !== 'ordered') {
        echo json_encode(['success' => false, 'message' => 'Cooking စပြီးရင် Cancel လုပ်လို့မရပါ']);
        exit();
    }
    
    // ✅ Point + Delivery Fee ပြန်အမ်း
    $refundAmount = $orderData['points_used'];
    
    $refundStmt = $conn->prepare("UPDATE users SET points = points + ? WHERE userId = ?");
    $refundStmt->bind_param("ii", $refundAmount, $user_id);
    $refundStmt->execute();
    $refundStmt->close();
    
    $cancelStmt = $conn->prepare("UPDATE orders SET status = 'rejected', rejectionReason = 'User cancelled', deliveryStatus = 'pending' WHERE orderId = ?");
    $cancelStmt->bind_param("i", $orderId);
    $cancelStmt->execute();
    $cancelStmt->close();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Order cancelled. ' . number_format($refundAmount) . ' Points ပြန်ရပါပြီ။'
    ]);
    exit();
}

// =============================================
// HANDLE RATING SUBMIT
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_rating') {
    header('Content-Type: application/json');
    
    $itemId = intval($_POST['itemId']);
    $orderId = intval($_POST['orderId']);
    $rating = intval($_POST['rating']);
    
    if ($rating < 1 || $rating > 5) {
        echo json_encode(['success' => false, 'message' => 'Invalid rating']);
        exit();
    }
    
    $checkStmt = $conn->prepare("SELECT status FROM orders WHERE orderId = ? AND userId = ?");
    $checkStmt->bind_param("ii", $orderId, $user_id);
    $checkStmt->execute();
    $orderCheck = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if (!$orderCheck || strtolower($orderCheck['status']) !== 'completed') {
        echo json_encode(['success' => false, 'message' => 'Completed Order မှသာ Rating ပေးလို့ရပါတယ်']);
        exit();
    }
    
    $dupStmt = $conn->prepare("SELECT ratingId FROM ratings WHERE userId = ? AND orderId = ? AND itemId = ?");
    $dupStmt->bind_param("iii", $user_id, $orderId, $itemId);
    $dupStmt->execute();
    $dupStmt->store_result();
    
    if ($dupStmt->num_rows > 0) {
        $dupStmt->close();
        echo json_encode(['success' => false, 'message' => 'Rating ပေးပြီးသားပါ']);
        exit();
    }
    $dupStmt->close();
    
    $stmt = $conn->prepare("INSERT INTO ratings (userId, itemId, orderId, rating) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiii", $user_id, $itemId, $orderId, $rating);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed']);
    }
    $stmt->close();
    exit();
}

// =============================================
// GET USER POINTS
// =============================================
$userStmt = $conn->prepare("SELECT points FROM users WHERE userId = ?");
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$currentPoints = $userStmt->get_result()->fetch_assoc()['points'] ?? 0;
$userStmt->close();

// Cart Count
$cart_count = 0;
$cart_stmt = $conn->prepare("SELECT SUM(quantity) as total FROM cart WHERE userId = ?");
if ($cart_stmt) {
    $cart_stmt->bind_param("i", $user_id);
    $cart_stmt->execute();
    $cart_count = $cart_stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $cart_stmt->close();
}

// Like Count
$like_count = 0;
$like_stmt = $conn->prepare("SELECT COUNT(*) as total FROM liked_items WHERE userId = ?");
if ($like_stmt) {
    $like_stmt->bind_param("i", $user_id);
    $like_stmt->execute();
    $like_count = $like_stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $like_stmt->close();
}

// Fetch Orders
$orders = $conn->query("SELECT o.*, 
                        (SELECT GROUP_CONCAT(CONCAT(m.itemName, ' (', oi.quantity, ')') SEPARATOR ', ') 
                         FROM order_items oi JOIN menu_items m ON oi.itemId = m.itemId WHERE oi.orderId = o.orderId) as items,
                        (SELECT GROUP_CONCAT(CONCAT(m.itemName, ' (', oi.quantity, ')') SEPARATOR ', ') 
                         FROM order_items oi JOIN menu_items m ON oi.itemId = m.itemId WHERE oi.orderId = o.orderId AND FIND_IN_SET(oi.itemId, o.rejected_items)) as rejected_item_names
                        FROM orders o WHERE o.userId = $user_id ORDER BY o.orderId DESC");
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order History - UCSH Canteen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Myanmar:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --brand-color: #1EAFBD; --brand-hover: #17939F; --brand-light: #EBF8F9; }
        body { font-family: 'Plus Jakarta Sans', 'Noto Sans Myanmar', sans-serif; background-color: #F8FAFC; color: #1E293B; }
        .bg-brand-light { background-color: var(--brand-light) !important; }
        .text-brand { color: var(--brand-color) !important; }
        .btn-brand { background: var(--brand-color); color: white; border: none; transition: all 0.25s ease; }
        .btn-brand:hover { background: var(--brand-hover); color: white; transform: translateY(-2px); }
        .navbar-custom { background-color: #FFFFFF; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03); }
        .nav-icon-btn { position: relative; color: #64748B; font-size: 1.15rem; padding: 8px 12px; border-radius: 12px; transition: all 0.2s ease; text-decoration: none; }
        .nav-icon-btn:hover { background-color: var(--brand-light); color: var(--brand-color); }
        .nav-badge { position: absolute; top: 2px; right: 2px; font-size: 0.65rem; background-color: #FF4757; }
        .history-card { background: #ffffff; border-radius: 16px; border: 1px solid #E2E8F0; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04); transition: all 0.2s ease; }
        .history-card:hover { box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06); }
        .fs-7 { font-size: 0.75rem; }
        .points-nav { background: linear-gradient(135deg, #fef3c7, #fde68a); border-radius: 8px; padding: 4px 14px; font-weight: 700; color: #92400e; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 4px; border: 1px solid #fcd34d; }
        .timeline { display: flex; justify-content: space-between; margin: 20px 0; position: relative; padding: 0 10px; }
        .timeline::before { content: ''; position: absolute; top: 18px; left: 8%; right: 8%; height: 3px; background: #E2E8F0; z-index: 0; }
        .timeline-step { position: relative; z-index: 1; text-align: center; flex: 1; }
        .timeline-icon { width: 36px; height: 36px; border-radius: 50%; background: #E2E8F0; color: #94A3B8; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; transition: all 0.4s ease; margin: 0 auto; }
        .timeline-step.active .timeline-icon { background: var(--brand-color); color: white; box-shadow: 0 0 0 4px rgba(30,175,189,0.2); animation: pulse 1.5s infinite; }
        .timeline-step.completed .timeline-icon { background: #28a745; color: white; }
        .timeline-label { font-size: 0.7rem; color: #64748B; margin-top: 6px; font-weight: 500; }
        .timeline-step.active .timeline-label { color: var(--brand-color); font-weight: 700; }
        .timeline-step.completed .timeline-label { color: #28a745; font-weight: 600; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(30,175,189,0.4); } 70% { box-shadow: 0 0 0 10px rgba(30,175,189,0); } 100% { box-shadow: 0 0 0 0 rgba(30,175,189,0); } }
        .item-list { display: flex; flex-wrap: wrap; gap: 4px 8px; }
        .item-list .item { padding: 2px 6px; border-radius: 4px; background: #f8f9fa; border: 1px solid #e9ecef; font-size: 0.85rem; }
        .item-list .item.rejected { background: #fff5f5; border-color: #dc3545; }
        .item-list .item.accepted { background: #f0fff4; border-color: #28a745; }
        .rejected-item { color: #dc3545; text-decoration: line-through; opacity: 0.8; }
        .rejected-item .reject-badge { background: #dc3545; color: white; font-size: 0.55rem; padding: 1px 6px; border-radius: 8px; margin-left: 3px; text-decoration: none; font-weight: 600; }
        .accepted-item { color: #28a745; }
        .accepted-item .accept-badge { background: #28a745; color: white; font-size: 0.55rem; padding: 1px 6px; border-radius: 8px; margin-left: 3px; text-decoration: none; font-weight: 600; }
        .partial-reject-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 6px 10px; border-radius: 4px; margin-top: 6px; }
        .reject-reason-box { background: #fff5f5; border-left: 4px solid #dc3545; padding: 6px 10px; border-radius: 4px; margin-top: 6px; }
        .rating-section { background: #f8fafc; border-radius: 12px; padding: 12px; margin-top: 12px; border: 1px dashed #cbd5e0; }
        .rating-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #f1f5f9; }
        .rating-row:last-child { border-bottom: none; }
        .star-rating { display: inline-flex; gap: 2px; }
        .star-rating i { font-size: 22px; cursor: pointer; transition: all 0.2s; color: #cbd5e0; }
        .star-rating i:hover { transform: scale(1.2); }
        .star-rating i.fa-solid.text-warning { color: #ffc107 !important; }
        .star-rating.rated i { cursor: not-allowed; }
        .star-rating.rated i:hover { transform: none; }

        <?php if ($isAjax): ?>
        .navbar-custom, .container > .d-flex:first-child { display: none !important; }
        <?php endif; ?>
    </style>
</head>
<body class="pb-5">

<nav class="navbar navbar-expand-lg sticky-top navbar-custom py-2">
    <div class="container">
        <a class="navbar-brand fw-bold fs-4 text-brand d-flex align-items-center me-3" href="index.php">
            <i class="fa-solid fa-utensils me-2"></i>UCSH Canteen
        </a>
        <div class="collapse navbar-collapse" id="navbarIcons">
            <div class="d-flex align-items-center ms-auto gap-1 mt-3 mt-lg-0 justify-content-around">
                <a href="index.php" class="nav-icon-btn text-decoration-none"><i class="fa-solid fa-house"></i><span class="d-lg-none ms-2 small">Home</span></a>
                   <a href="track.php" class="nav-icon-btn text-decoration-none" title="Track Order">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        <span class="d-lg-none ms-2 small">Queue</span>
                    </a>
                <a href="history.php" class="nav-icon-btn text-decoration-none text-brand"><i class="fa-solid fa-receipt"></i><span class="d-lg-none ms-2 small">History</span></a>
                <span class="points-nav"><i class="fa-solid fa-coins text-warning"></i><?= number_format($currentPoints) ?></span>
                <a href="likes.php" class="nav-icon-btn text-decoration-none"><i class="fa-regular fa-heart"></i><span class="badge rounded-pill nav-badge"><?= $like_count ?></span></a>
                <a href="cart.php" class="nav-icon-btn text-decoration-none"><i class="fa-solid fa-cart-shopping"></i><span class="badge rounded-pill nav-badge"><?= $cart_count ?></span></a>
                <!-- ============================================= -->
                    <!-- USER DROPDOWN                                -->
                    <!-- ============================================= -->
                    <div class="dropdown ms-lg-2">
                        <a href="#" class="nav-icon-btn text-decoration-none d-flex align-items-center gap-2" data-bs-toggle="dropdown">
                            <i class="fa-regular fa-user-circle fs-5"></i>
                            <span class="fw-medium small d-none d-lg-inline"><?= htmlspecialchars($_SESSION['username'] ?? 'Account') ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg rounded-3 mt-2">
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <li><a class="dropdown-item py-2" href="profile.php"><i class="fa-regular fa-id-card me-2 text-brand"></i>Profile</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item py-2 text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                            <?php else: ?>
                                <li><a class="dropdown-item py-2" href="login.php"><i class="fa-solid fa-right-to-bracket me-2 text-brand"></i>Login</a></li>
                                <li><a class="dropdown-item py-2" href="register.php"><i class="fa-solid fa-user-plus me-2 text-brand"></i>Register</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
            </div>
        </div>
    </div>
</nav>

<div class="container my-4" style="max-width: 850px;">
    
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-white border shadow-sm rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                <i class="fa-solid fa-arrow-left text-dark"></i>
            </a>
            <h5 class="fw-bold mb-0 text-dark">My Order History</h5>
        </div>
        <span class="badge bg-white text-secondary border shadow-sm fw-normal px-3 py-2 rounded-pill fs-7">
            <i class="fa-solid fa-sync me-1 text-brand" id="refreshIcon"></i><?= $orders ? $orders->num_rows : 0 ?> Orders
        </span>
    </div>

    <?php if ($orders && $orders->num_rows > 0): ?>
        <?php while ($ord = $orders->fetch_assoc()): 
            $st = strtolower($ord['status']);
            $badgeClass = 'bg-secondary';
            
            if ($st === 'ordered' || $st === 'cooking') $badgeClass = 'bg-info text-dark';
            elseif ($st === 'pickup') $badgeClass = 'bg-primary';
            elseif ($st === 'completed') $badgeClass = 'bg-success';
            elseif ($st === 'rejected') $badgeClass = 'bg-danger';
            elseif ($st === 'partial_rejected') $badgeClass = 'bg-warning text-dark';
            
            $allItems = [];
            $rejectedItems = [];
            $itemStatusMap = [];
            
            if (!empty($ord['items'])) {
                $allItems = explode(', ', $ord['items']);
                $rejectedNames = !empty($ord['rejected_item_names']) ? explode(', ', $ord['rejected_item_names']) : [];
                
                foreach ($allItems as $item) {
                    $isRejected = false;
                    foreach ($rejectedNames as $rejected) {
                        if (strpos($item, $rejected) !== false || strpos($rejected, $item) !== false) {
                            $isRejected = true;
                            break;
                        }
                    }
                    $itemStatusMap[$item] = $isRejected ? 'rejected' : 'accepted';
                    if ($isRejected) $rejectedItems[] = $item;
                }
            }
        ?>
            <div class="history-card p-3 p-md-4 mb-3" data-order-card="<?= $ord['orderId'] ?>">
                
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                            <span class="badge bg-dark text-white fw-bold"><?= $ord['queue_number'] ?></span>
                            <span class="badge <?= $badgeClass ?> text-uppercase" data-order-status="<?= $ord['orderId'] ?>"><?= $ord['status'] ?></span>
                            <?php if ($ord['orderType'] === 'delivery'): ?>
                                <span class="badge bg-warning text-dark"><i class="fa-solid fa-motorcycle"></i> Delivery</span>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted">
                            <i class="fa-regular fa-calendar me-1"></i><?= date('d M Y, h:i A', strtotime($ord['createdAt'])) ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="fw-bold text-warning"><?= number_format($ord['points_used'] ?? 0) ?> Points</div>
                        <div class="small text-muted"><?= strtoupper($ord['orderType']) ?> • <?= $ord['pickupTime'] ?></div>
                    </div>
                </div>

                <?php if ($st !== 'rejected' && $st !== 'partial_rejected'): ?>
                    <div class="timeline" data-timeline="<?= $ord['orderId'] ?>">
                        <div class="timeline-step <?= in_array($st, ['ordered','cooking','pickup','completed']) ? ($st === 'ordered' ? 'active' : 'completed') : '' ?>">
                            <div class="timeline-icon"><i class="fa-solid fa-check"></i></div>
                            <div class="timeline-label">Ordered</div>
                        </div>
                        <div class="timeline-step <?= in_array($st, ['cooking','pickup','completed']) ? ($st === 'cooking' ? 'active' : 'completed') : '' ?>">
                            <div class="timeline-icon"><i class="fa-solid fa-fire"></i></div>
                            <div class="timeline-label">Cooking</div>
                        </div>
                        <div class="timeline-step <?= in_array($st, ['pickup','completed']) ? ($st === 'pickup' ? 'active' : 'completed') : '' ?>">
                            <div class="timeline-icon"><i class="fa-solid fa-bag-shopping"></i></div>
                            <div class="timeline-label">Pickup</div>
                        </div>
                        <div class="timeline-step <?= $st === 'completed' ? 'completed' : '' ?>">
                            <div class="timeline-icon"><i class="fa-solid fa-flag-checkered"></i></div>
                            <div class="timeline-label">Completed</div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mt-2 pt-2 border-top">
                    <div class="small text-muted mb-1 fw-bold">Ordered Items:</div>
                    <div class="item-list">
                        <?php foreach ($allItems as $item): 
                            $isRejected = $itemStatusMap[$item] === 'rejected';
                        ?>
                            <span class="item <?= $isRejected ? 'rejected' : 'accepted' ?>">
                                <?php if ($isRejected): ?>
                                    <span class="rejected-item"><i class="fa-solid fa-circle-xmark me-1"></i><?= htmlspecialchars($item) ?><span class="reject-badge">REJECTED</span></span>
                                <?php else: ?>
                                    <span class="accepted-item"><i class="fa-solid fa-circle-check me-1"></i><?= htmlspecialchars($item) ?><span class="accept-badge">ACCEPTED</span></span>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <?php if (!empty($ord['specialRequest'])): ?>
                    <div class="mt-1 small text-muted"><i class="fa-regular fa-note-sticky me-1"></i>Special: <?= htmlspecialchars($ord['specialRequest']) ?></div>
                <?php endif; ?>
                
                <?php if ($st === 'partial_rejected'): ?>
                    <div class="partial-reject-box mt-2">
                        <small class="text-warning fw-bold d-block"><i class="fa-solid fa-triangle-exclamation me-1"></i>Partially Rejected</small>
                        <?php if (!empty($ord['rejectionReason'])): ?>
                            <div class="mt-1 pt-1 border-top"><small class="text-muted">Reason: <?= htmlspecialchars($ord['rejectionReason']) ?></small></div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($st === 'rejected' && !empty($ord['rejectionReason'])): ?>
                    <div class="reject-reason-box mt-2">
                        <small class="text-danger fw-bold d-block"><i class="fa-solid fa-circle-exclamation me-1"></i>Rejected</small>
                        <span class="text-dark"><?= htmlspecialchars($ord['rejectionReason']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($st === 'ordered'): ?>
                    <div class="mt-3 pt-3 border-top cancel-section" data-cancel-section="<?= $ord['orderId'] ?>">
                        <button class="btn btn-sm btn-outline-danger rounded-3" onclick="cancelOrder(<?= $ord['orderId'] ?>)">
                            <i class="fa-solid fa-xmark me-1"></i>Cancel Order
                        </button>
                    </div>
                <?php endif; ?>

                <!-- ✅ Track Delivery Button -->
                <?php if ($ord['orderType'] === 'delivery' && in_array($st, ['cooking', 'pickup'])): ?>
                    <div class="mt-3">
                        <a href="track_delivery.php?orderId=<?= $ord['orderId'] ?>" class="btn btn-sm btn-warning rounded-3">
                            <i class="fa-solid fa-motorcycle me-1"></i>Track Delivery 🛵
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($st === 'completed'): 
                    $itemsStmt = $conn->prepare("
                        SELECT oi.itemId, m.itemName, 
                               (SELECT rating FROM ratings WHERE userId = ? AND orderId = ? AND itemId = oi.itemId) as my_rating
                        FROM order_items oi JOIN menu_items m ON oi.itemId = m.itemId WHERE oi.orderId = ?
                    ");
                    $itemsStmt->bind_param("iii", $user_id, $ord['orderId'], $ord['orderId']);
                    $itemsStmt->execute();
                    $itemsResult = $itemsStmt->get_result();
                    if ($itemsResult->num_rows > 0):
                ?>
                    <div class="rating-section" data-rating-section="<?= $ord['orderId'] ?>">
                        <small class="fw-bold d-block mb-2"><i class="fa-solid fa-star text-warning me-1"></i>Rate the Items:</small>
                        <?php while ($item = $itemsResult->fetch_assoc()): 
                            $hasRated = !empty($item['my_rating']);
                        ?>
                            <div class="rating-row">
                                <small class="fw-medium"><?= htmlspecialchars($item['itemName']) ?></small>
                                <div class="star-rating <?= $hasRated ? 'rated' : '' ?>" data-item-id="<?= $item['itemId'] ?>" data-order-id="<?= $ord['orderId'] ?>">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="<?= ($hasRated && $i <= $item['my_rating']) ? 'fa-solid text-warning' : 'fa-regular' ?> fa-star" data-star="<?= $i ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php endif; $itemsStmt->close(); endif; ?>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="text-center py-5 bg-white rounded-4 shadow-sm">
            <i class="fa-solid fa-receipt fa-3x text-muted opacity-50 mb-3"></i>
            <h6 class="text-secondary fw-bold">မှာယူထားသော Order များ မရှိသေးပါ။</h6>
            <a href="index.php" class="btn btn-brand btn-sm mt-3 px-4 py-2 rounded-3">မီနူး သို့သွားရန်</a>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function cancelOrder(orderId) {
    Swal.fire({
        title: 'Cancel Order?',
        text: 'Points တွေ ပြန်အမ်းပေးပါမယ်။ (Delivery Fee ပါ)',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Cancel',
        cancelButtonText: 'No'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('history.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=cancel_order&orderId=' + orderId
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Cancelled!', data.message, 'success')
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
        }
    });
}

function bindStarRating() {
    document.querySelectorAll('.star-rating:not(.rated)').forEach(function(container) {
        if (container.dataset.bound) return;
        container.dataset.bound = '1';
        
        const stars = container.querySelectorAll('i');
        
        stars.forEach(function(star) {
            star.addEventListener('mouseenter', function() {
                const hoverValue = parseInt(this.getAttribute('data-star'));
                stars.forEach(function(s, index) {
                    if (index < hoverValue) {
                        s.classList.remove('fa-regular');
                        s.classList.add('fa-solid', 'text-warning');
                    } else {
                        s.classList.add('fa-regular');
                        s.classList.remove('fa-solid', 'text-warning');
                    }
                });
            });

            star.addEventListener('click', function() {
                const itemId = container.getAttribute('data-item-id');
                const orderId = container.getAttribute('data-order-id');
                const rating = parseInt(this.getAttribute('data-star'));
                
                Swal.fire({
                    title: 'Confirm Rating?',
                    html: `ဒီ Item ကို <strong>${rating} Star</strong> ပေးမှာသေချာပါသလား?<br><small class="text-danger">တစ်ခါပေးပြီးရင် ပြန်ပြင်လို့မရပါ။</small>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#1EAFBD',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Confirm',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        fetch('history.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: `action=submit_rating&itemId=${itemId}&orderId=${orderId}&rating=${rating}`
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                stars.forEach(function(s, index) {
                                    if (index < rating) {
                                        s.classList.remove('fa-regular');
                                        s.classList.add('fa-solid', 'text-warning');
                                    } else {
                                        s.classList.add('fa-regular');
                                        s.classList.remove('fa-solid', 'text-warning');
                                    }
                                });
                                container.classList.add('rated');
                                Swal.fire({icon: 'success', title: 'Thank you!', timer: 1500, showConfirmButton: false});
                            } else {
                                Swal.fire('Error', data.message, 'error');
                            }
                        });
                    } else {
                        stars.forEach(function(s) {
                            s.classList.add('fa-regular');
                            s.classList.remove('fa-solid', 'text-warning');
                        });
                    }
                });
            });
        });

        container.addEventListener('mouseleave', function() {
            if (!container.classList.contains('rated')) {
                stars.forEach(function(s) {
                    s.classList.add('fa-regular');
                    s.classList.remove('fa-solid', 'text-warning');
                });
            }
        });
    });
}

bindStarRating();

let isRefreshing = false;

function checkForUpdates() {
    if (isRefreshing) return;
    isRefreshing = true;

    fetch(window.location.href + '?ajax=1')
        .then(response => response.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            
            doc.querySelectorAll('[data-order-status]').forEach(function(newEl) {
                const orderId = newEl.getAttribute('data-order-status');
                const oldEl = document.querySelector(`[data-order-status="${orderId}"]`);
                if (oldEl && oldEl.textContent.trim() !== newEl.textContent.trim()) {
                    oldEl.style.transition = 'all 0.2s';
                    oldEl.style.transform = 'scale(1.2)';
                    oldEl.style.background = '#ffc107';
                    setTimeout(() => {
                        oldEl.textContent = newEl.textContent;
                        oldEl.className = newEl.className;
                        oldEl.style.transform = 'scale(1)';
                        setTimeout(() => oldEl.style.background = '', 200);
                    }, 100);
                }
            });

            doc.querySelectorAll('[data-timeline]').forEach(function(newTimeline) {
                const orderId = newTimeline.getAttribute('data-timeline');
                const oldTimeline = document.querySelector(`[data-timeline="${orderId}"]`);
                if (oldTimeline && oldTimeline.innerHTML.trim() !== newTimeline.innerHTML.trim()) {
                    oldTimeline.innerHTML = newTimeline.innerHTML;
                }
            });
        })
        .catch(err => {})
        .finally(() => { 
            isRefreshing = false; 
            const icon = document.getElementById('refreshIcon');
            if (icon) {
                icon.style.transition = 'transform 0.3s';
                icon.style.transform = 'rotate(360deg)';
                setTimeout(() => icon.style.transform = 'rotate(0deg)', 300);
            }
        });
}

document.addEventListener('DOMContentLoaded', function() {
    checkForUpdates();
    setInterval(checkForUpdates, 300);
});

document.addEventListener('visibilitychange', function() {
    if (!document.hidden) checkForUpdates();
});
</script>
</body>
</html>
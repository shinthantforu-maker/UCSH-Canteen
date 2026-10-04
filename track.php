<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

$isLoggedIn = isset($_SESSION['user_id']);
$user_id = $isLoggedIn ? $_SESSION['user_id'] : 0;

// Get user points if logged in
$currentPoints = 0;
$cart_count = 0;
$like_count = 0;

if ($isLoggedIn) {
    $userStmt = $conn->prepare("SELECT points FROM users WHERE userId = ?");
    $userStmt->bind_param("i", $user_id);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $userData = $userResult->fetch_assoc();
    $currentPoints = $userData['points'] ?? 0;
    $userStmt->close();

    $cart_count_stmt = $conn->prepare("SELECT SUM(quantity) as total FROM cart WHERE userId = ?");
    if ($cart_count_stmt) {
        $cart_count_stmt->bind_param("i", $user_id);
        $cart_count_stmt->execute();
        $cart_count_res = $cart_count_stmt->get_result()->fetch_assoc();
        $cart_count = $cart_count_res['total'] ?? 0;
        $cart_count_stmt->close();
    }

    $like_stmt = $conn->prepare("SELECT COUNT(*) as total FROM liked_items WHERE userId = ?");
    if ($like_stmt) {
        $like_stmt->bind_param("i", $user_id);
        $like_stmt->execute();
        $like_res = $like_stmt->get_result()->fetch_assoc();
        $like_count = $like_res['total'] ?? 0;
        $like_stmt->close();
    }
}

// Fetch Orders with items and rejected items
$orders = null;
if ($isLoggedIn) {
    $orders = $conn->query("SELECT o.*, 
                            (SELECT GROUP_CONCAT(CONCAT(m.itemName, ' (', oi.quantity, ')') SEPARATOR ', ') 
                             FROM order_items oi 
                             JOIN menu_items m ON oi.itemId = m.itemId 
                             WHERE oi.orderId = o.orderId) as items,
                            (SELECT GROUP_CONCAT(CONCAT(m.itemName, ' (', oi.quantity, ')') SEPARATOR ', ') 
                             FROM order_items oi 
                             JOIN menu_items m ON oi.itemId = m.itemId 
                             WHERE oi.orderId = o.orderId 
                             AND FIND_IN_SET(oi.itemId, o.rejected_items)) as rejected_item_names,
                            (SELECT GROUP_CONCAT(CONCAT(m.itemId, ':', m.itemName, ' (', oi.quantity, ')') SEPARATOR '|') 
                             FROM order_items oi 
                             JOIN menu_items m ON oi.itemId = m.itemId 
                             WHERE oi.orderId = o.orderId) as items_with_id
                            FROM orders o 
                            WHERE o.userId = $user_id 
                            ORDER BY o.orderId DESC");
}
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Orders - UCSH Canteen</title>
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
        .btn-brand:hover { background: var(--brand-hover); color: white; transform: translateY(-2px); box-shadow: 0 4px 15px rgba(30, 175, 189, 0.3); }
        .navbar-custom { background-color: #FFFFFF; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03); }
        .nav-icon-btn { position: relative; color: #64748B; font-size: 1.15rem; padding: 8px 12px; border-radius: 12px; transition: all 0.2s ease; text-decoration: none; }
        .nav-icon-btn:hover { background-color: var(--brand-light); color: var(--brand-color); }
        .nav-badge { position: absolute; top: 2px; right: 2px; font-size: 0.65rem; background-color: #FF4757; }
        .search-box { max-width: 380px; }
        .search-box .form-control { border-radius: 20px; padding-left: 40px; border: 1px solid #E2E8F0; background-color: #F8FAFC; }
        .search-box .search-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94A3B8; }
        .track-card { background: #ffffff; border-radius: 20px; border: 1px solid #E2E8F0; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05); position: relative; overflow: hidden; transition: transform 0.2s ease; }
        .track-card::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 6px; background: linear-gradient(90deg, var(--brand-color) 0%, #17939F 100%); }
        .tracking-steps { display: flex; justify-content: space-between; position: relative; margin: 20px 0; padding: 0 10px; }
        .tracking-steps::before { content: ''; position: absolute; top: 15px; left: 30px; right: 30px; height: 3px; background: #E2E8F0; z-index: 1; }
        .step-item { position: relative; z-index: 2; text-align: center; flex: 1; }
        .step-icon { width: 34px; height: 34px; border-radius: 50%; background: #F1F5F9; color: #94A3B8; display: flex; align-items: center; justify-content: center; margin: 0 auto 6px auto; font-size: 0.85rem; border: 2px solid #E2E8F0; transition: all 0.3s ease; }
        .step-item.active .step-icon { background: var(--brand-color); color: #fff; border-color: var(--brand-color); box-shadow: 0 0 10px rgba(30, 175, 189, 0.4); }
        .step-item.completed .step-icon { background: #10B981; color: #fff; border-color: #10B981; }
        .step-label { font-size: 0.65rem; color: #64748B; font-weight: 600; }
        .step-item.active .step-label { color: var(--brand-color); }
        .fs-7 { font-size: 0.75rem; }
        .points-nav { background: linear-gradient(135deg, #fef3c7, #fde68a); border-radius: 8px; padding: 4px 14px; font-weight: 700; color: #92400e; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 4px; border: 1px solid #fcd34d; }
        .tracker-input { max-width: 400px; margin: 0 auto; }
        .swal-custom-popup { width: 320px !important; padding: 1.25rem !important; border-radius: 16px !important; }
        
        .reject-reason-box {
            background: #fff5f5;
            border-left: 4px solid #dc3545;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 8px;
        }
        
        .partial-reject-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 8px;
        }
        
        /* ✅ Rejected Item - Strike-through + Red */
        .rejected-item {
            color: #dc3545;
            text-decoration: line-through;
            opacity: 0.8;
            display: inline-block;
            margin-right: 8px;
            font-weight: 500;
        }
        
        .rejected-item .reject-badge {
            background: #dc3545;
            color: white;
            font-size: 0.6rem;
            padding: 1px 8px;
            border-radius: 10px;
            margin-left: 4px;
            text-decoration: none;
            font-weight: 600;
        }
        
        /* ✅ Accepted Item - Green */
        .accepted-item {
            color: #28a745;
            display: inline-block;
            margin-right: 8px;
            font-weight: 500;
        }
        
        .accepted-item .accept-badge {
            background: #28a745;
            color: white;
            font-size: 0.6rem;
            padding: 1px 8px;
            border-radius: 10px;
            margin-left: 4px;
            text-decoration: none;
            font-weight: 600;
        }
        
        .item-list {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 12px;
        }
        
        .item-list .item {
            padding: 2px 8px;
            border-radius: 4px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
        }
        
        .item-list .item.rejected {
            background: #fff5f5;
            border-color: #dc3545;
        }
        
        .item-list .item.accepted {
            background: #f0fff4;
            border-color: #28a745;
        }
        
        .progress-bar-animated {
            transition: width 0.8s ease;
        }

        /* ============================================= */
        /* ✅ REJECTED ITEMS SUMMARY BOX                 */
        /* ============================================= */
        .reject-summary-box {
            background: #fff5f5;
            border: 1px solid #dc3545;
            border-radius: 8px;
            padding: 8px 12px;
            margin-top: 6px;
        }
        .reject-summary-box .reject-title {
            color: #dc3545;
            font-weight: 600;
            font-size: 0.75rem;
        }
        .reject-summary-box .reject-items {
            color: #dc3545;
            font-size: 0.85rem;
        }
        .reject-summary-box .reject-reason {
            color: #6c757d;
            font-size: 0.75rem;
            margin-top: 2px;
        }
    </style>
</head>
<body class="pb-5">

<nav class="navbar navbar-expand-lg sticky-top navbar-custom py-2">
    <div class="container">
        <a class="navbar-brand fw-bold fs-4 text-brand d-flex align-items-center me-3" href="<?= $isLoggedIn ? 'index.php' : 'guest.php' ?>">
            <i class="fa-solid fa-utensils me-2"></i>UCSH Canteen
        </a>
        <div class="search-box position-relative flex-grow-1 mx-lg-4 my-2 my-lg-0">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="text" id="searchInput" class="form-control form-control-sm py-2" placeholder="အစားအသောက်များ ရှာဖွေပါ...">
        </div>
        <button class="navbar-toggler border-0 p-1" type="button" data-bs-toggle="collapse" data-bs-target="#navbarIcons">
            <i class="fa-solid fa-bars fs-4 text-dark"></i>
        </button>
        <div class="collapse navbar-collapse" id="navbarIcons">
            <div class="d-flex align-items-center ms-auto gap-1 mt-3 mt-lg-0 justify-content-around">
                <?php if ($isLoggedIn): ?>
                    <a href="index.php" class="nav-icon-btn text-decoration-none" title="Home">
                        <i class="fa-solid fa-house"></i>
                        <span class="d-lg-none ms-2 small">Home</span>
                    </a>
                    <a href="track.php" class="nav-icon-btn text-decoration-none" title="Track Order">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        <span class="d-lg-none ms-2 small">Queue</span>
                    </a>
                    <a href="history.php" class="nav-icon-btn text-decoration-none text-brand" title="Order History">
                        <i class="fa-solid fa-receipt"></i>
                        <span class="d-lg-none ms-2 small">History</span>
                    </a>
                    <span class="points-nav"><i class="fa-solid fa-coins text-warning"></i> <?= number_format($currentPoints) ?></span>
                    <a href="likes.php" class="nav-icon-btn text-decoration-none" title="Liked Items">
                        <i class="fa-regular fa-heart"></i>
                        <span class="badge rounded-pill nav-badge"><?= $like_count ?></span>
                        <span class="d-lg-none ms-2 small">Likes</span>
                    </a>
                    <a href="cart.php" class="nav-icon-btn text-decoration-none" title="Cart">
                        <i class="fa-solid fa-cart-shopping"></i>
                        <span class="badge rounded-pill nav-badge"><?= $cart_count ?></span>
                        <span class="d-lg-none ms-2 small">Cart</span>
                    </a>
                    <div class="dropdown ms-lg-2">
                        <a href="#" class="nav-icon-btn text-decoration-none d-flex align-items-center gap-2" data-bs-toggle="dropdown">
                            <i class="fa-regular fa-user-circle fs-5"></i>
                            <span class="fw-medium small d-none d-lg-inline"><?= htmlspecialchars($_SESSION['username'] ?? 'Account') ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg rounded-3 mt-2">
                            <li><a class="dropdown-item py-2" href="profile.php"><i class="fa-regular fa-id-card me-2 text-brand"></i>Dashboard</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2 text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a href="login.php" class="btn btn-outline-secondary btn-sm px-3 rounded-pill fw-medium">Login</a>
                    <a href="register.php" class="btn btn-brand btn-sm px-3 rounded-pill fw-medium">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<div class="container my-4" style="max-width: 750px;">

    <!-- Tracking Section -->
    <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
        <div class="text-center mb-3">
            <h5 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-magnifying-glass text-brand me-2"></i>Track Your Order
            </h5>
            <p class="text-secondary small mb-0">သင့် Queue နံပါတ်ကို ရိုက်ထည့်၍ အခြေအနေစစ်ဆေးပါ</p>
        </div>
        <div class="d-flex justify-content-center gap-2 tracker-input">
            <input type="text" id="trackQueueInput" class="form-control text-center rounded-3 text-uppercase fw-bold" placeholder="e.g. Q1010" required>
            <button onclick="trackOrder()" class="btn btn-brand px-4 rounded-3 text-nowrap">
                <i class="fa-solid fa-magnifying-glass me-1"></i> Track
            </button>
        </div>
        
        <div id="trackingResult" class="d-none mt-4 pt-3 border-top">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold text-dark fs-6" id="displayQueueNo">Queue: #-</span>
                <span class="badge rounded-pill px-3 py-2 fs-7" id="displayStatusText">Ordered</span>
            </div>
            <div class="progress rounded-pill mb-3" style="height: 12px; background-color: #E2E8F0;">
                <div id="statusProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-brand" 
                     role="progressbar" style="width: 0%; transition: width 0.8s ease;"></div>
            </div>
            <div id="trackDetails" class="small text-muted"></div>
            
            <!-- ============================================= -->
            <!-- ✅ ITEMS WITH REJECTED STATUS                -->
            <!-- ============================================= -->
            <div id="trackItems" class="mt-2"></div>
            
            <!-- ============================================= -->
            <!-- ✅ REJECT REASON & REJECTED ITEMS SUMMARY    -->
            <!-- ============================================= -->
            <div id="trackRejectSummary" class="mt-2"></div>
            
            <div class="row text-center fs-7 text-muted fw-medium mt-2">
                <div class="col-3" id="step-ordered"><i class="fa-solid fa-circle-check mb-1 d-block"></i>Ordered</div>
                <div class="col-3" id="step-cooking"><i class="fa-solid fa-fire-burner mb-1 d-block"></i>Cooking</div>
                <div class="col-3" id="step-pickup"><i class="fa-solid fa-bag-shopping mb-1 d-block"></i>Pickup</div>
                <div class="col-3" id="step-completed"><i class="fa-solid fa-flag-checkered mb-1 d-block"></i>Completed</div>
            </div>
        </div>
    </div>

    <!-- User's Orders -->
    <?php if ($isLoggedIn): ?>
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-list-check text-brand me-2"></i>My Orders</h5>
            <span class="badge bg-white text-secondary border shadow-sm fw-normal px-3 py-2 rounded-pill fs-7">
                <?= $orders ? $orders->num_rows : 0 ?> Orders
            </span>
        </div>

        <?php if ($orders && $orders->num_rows > 0): ?>
            <?php while ($ord = $orders->fetch_assoc()): 
                $status = $ord['status'] ?? 'ordered';
                $queueNumber = $ord['queue_number'] ?? 'Q-' . str_pad($ord['orderId'], 3, '0', STR_PAD_LEFT);
                $st = strtolower($status);
                $badgeClass = 'bg-secondary';
                if ($st === 'ordered' || $st === 'cooking') $badgeClass = 'bg-info text-dark';
                elseif ($st === 'pickup') $badgeClass = 'bg-primary';
                elseif ($st === 'completed') $badgeClass = 'bg-success';
                elseif ($st === 'rejected') $badgeClass = 'bg-danger';
                elseif ($st === 'partial_rejected') $badgeClass = 'bg-warning text-dark';
                
                // Parse items
                $allItems = [];
                $rejectedItems = [];
                $acceptedItems = [];
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
                        if ($isRejected) {
                            $rejectedItems[] = $item;
                            $itemStatusMap[$item] = 'rejected';
                        } else {
                            $acceptedItems[] = $item;
                            $itemStatusMap[$item] = 'accepted';
                        }
                    }
                }
                
                // ✅ Check if there are any rejected items
                $hasRejectedItems = !empty($rejectedItems);
            ?>
                <div class="track-card p-4 mb-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-dark text-white fw-bold"><?= $queueNumber ?></span>
                                <span class="badge <?= $badgeClass ?> text-uppercase"><?= $status ?></span>
                            </div>
                            <small class="text-muted"><i class="fa-regular fa-clock me-1"></i><?= date('d M Y, h:i A', strtotime($ord['createdAt'])) ?></small>
                        </div>
                        <div class="text-end">
                            <span class="fw-bold text-warning"><?= number_format($ord['points_used'] ?? 0) ?> Points</span>
                            <div class="small text-muted"><?= strtoupper($ord['orderType']) ?> • <?= $ord['pickupTime'] ?></div>
                        </div>
                    </div>
                    
                    <!-- ============================================= -->
                    <!-- ✅ ITEMS DISPLAY - CLEAR REJECTED STATUS    -->
                    <!-- ============================================= -->
                    <div class="mt-2 pt-2 border-top">
                        <small class="text-muted fw-bold">Ordered Items:</small>
                        <div class="item-list mt-1">
                            <?php if (!empty($allItems)): ?>
                                <?php foreach ($allItems as $item): 
                                    $isRejected = isset($itemStatusMap[$item]) && $itemStatusMap[$item] === 'rejected';
                                ?>
                                    <span class="item <?= $isRejected ? 'rejected' : 'accepted' ?>">
                                        <?php if ($isRejected): ?>
                                            <span class="rejected-item">
                                                <i class="fa-solid fa-circle-xmark me-1"></i>
                                                <?= htmlspecialchars($item) ?>
                                                <span class="reject-badge">REJECTED</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="accepted-item">
                                                <i class="fa-solid fa-circle-check me-1"></i>
                                                <?= htmlspecialchars($item) ?>
                                                <span class="accept-badge">ACCEPTED</span>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted">No items</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($ord['specialRequest'])): ?>
                        <div class="mt-1"><small class="text-muted">Special: <?= htmlspecialchars($ord['specialRequest']) ?></small></div>
                    <?php endif; ?>
                    
                    <!-- ============================================= -->
                    <!-- ✅ REJECT SUMMARY - ALWAYS SHOW IF REJECTED  -->
                    <!-- ============================================= -->
                    <?php if ($hasRejectedItems || !empty($ord['rejectionReason'])): ?>
                        <?php 
                            // Determine if partial or full
                            $isPartial = $hasRejectedItems && !empty($acceptedItems);
                            $boxClass = $isPartial ? 'partial-reject-box' : 'reject-reason-box';
                            $icon = $isPartial ? 'fa-triangle-exclamation text-warning' : 'fa-circle-exclamation text-danger';
                            $title = $isPartial ? 'Partially Rejected' : 'Rejected';
                        ?>
                        <div class="<?= $boxClass ?>">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid <?= $icon ?>"></i>
                                <small class="<?= $isPartial ? 'text-warning' : 'text-danger' ?> fw-bold"><?= $title ?></small>
                            </div>
                            <?php if (!empty($rejectedItems)): ?>
                                <div class="mt-1">
                                    <small class="text-danger fw-bold">❌ Rejected Items:</small>
                                    <small class="text-danger d-block"><?= htmlspecialchars(implode(', ', $rejectedItems)) ?></small>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($acceptedItems) && $isPartial): ?>
                                <div class="mt-1">
                                    <small class="text-success fw-bold">✅ Accepted Items:</small>
                                    <small class="text-success d-block"><?= htmlspecialchars(implode(', ', $acceptedItems)) ?></small>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($ord['rejectionReason'])): ?>
                                <div class="mt-1 pt-1 border-top">
                                    <small class="text-muted">📝 Reason: <?= htmlspecialchars($ord['rejectionReason']) ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="text-center py-4 bg-white rounded-4 shadow-sm">
                <i class="fa-solid fa-receipt fa-2x text-muted opacity-50 mb-2"></i>
                <p class="text-muted small mb-0">မှာယူထားသော Order များ မရှိသေးပါ။</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<script>
function trackOrder() {
    let queueNo = document.getElementById('trackQueueInput').value.trim();
    if (!queueNo) {
        Swal.fire({ icon: 'warning', title: 'Queue နံပါတ် ထည့်ပါ', confirmButtonColor: '#1EAFBD' });
        return;
    }

    let formData = new FormData();
    formData.append('queue', queueNo);

    fetch('api.php?action=track_order', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        let resultBox = document.getElementById('trackingResult');
        
        if (data.status === 'success') {
            resultBox.classList.remove('d-none');
            let order = data.order;
            
            document.getElementById('displayQueueNo').innerText = 'Queue: #' + order.queue_number;
            
            let statusText = order.status.toUpperCase();
            let statusClass = 'bg-secondary';
            if (order.status === 'ordered' || order.status === 'cooking') statusClass = 'bg-info text-dark';
            else if (order.status === 'pickup') statusClass = 'bg-primary';
            else if (order.status === 'completed') statusClass = 'bg-success';
            else if (order.status === 'rejected') statusClass = 'bg-danger';
            else if (order.status === 'partial_rejected') statusClass = 'bg-warning text-dark';
            
            let statusBadge = document.getElementById('displayStatusText');
            statusBadge.innerText = statusText;
            statusBadge.className = 'badge rounded-pill px-3 py-2 fs-7 ' + statusClass;

            let details = `<div class="row g-2 mt-2">
                <div class="col-6"><strong>Type:</strong> ${order.orderType}</div>
                <div class="col-6"><strong>Pickup:</strong> ${order.pickupTime}</div>
                <div class="col-6"><strong>Points Used:</strong> ${Number(order.points_used).toLocaleString()}</div>
                <div class="col-6"><strong>Total:</strong> ${Number(order.totalAmount).toLocaleString()} Points</div>
            </div>`;
            if (order.specialRequest) {
                details += `<div class="mt-2"><small><strong>Special:</strong> ${order.specialRequest}</small></div>`;
            }
            document.getElementById('trackDetails').innerHTML = details;

            // =============================================
            // ✅ SHOW ITEMS WITH REJECTED STATUS
            // =============================================
            let itemsHtml = '';
            let rejectedItemsList = [];
            let allItemsList = [];
            
            if (order.items) {
                allItemsList = order.items.split(', ');
                let rejectedIds = order.rejected_items ? order.rejected_items.split(',').map(id => id.trim()) : [];
                
                itemsHtml = `<div class="mt-2 pt-2 border-top"><small class="text-muted fw-bold">Items:</small><div class="item-list mt-1">`;
                allItemsList.forEach(item => {
                    let isRejected = false;
                    rejectedIds.forEach(rejId => {
                        if (item.includes('(' + rejId + ')')) {
                            isRejected = true;
                            rejectedItemsList.push(item);
                        }
                    });
                    if (isRejected) {
                        itemsHtml += `<span class="item rejected"><span class="rejected-item"><i class="fa-solid fa-circle-xmark me-1"></i>${item} <span class="reject-badge">REJECTED</span></span></span>`;
                    } else {
                        itemsHtml += `<span class="item accepted"><span class="accepted-item"><i class="fa-solid fa-circle-check me-1"></i>${item} <span class="accept-badge">ACCEPTED</span></span></span>`;
                    }
                });
                itemsHtml += `</div></div>`;
                document.getElementById('trackItems').innerHTML = itemsHtml;
            }
            
            // =============================================
            // ✅ REJECT SUMMARY - ALWAYS SHOW IF REJECTED
            // =============================================
            let rejectSummaryHtml = '';
            let hasRejection = (order.rejectionReason && order.rejectionReason.trim() !== '') || rejectedItemsList.length > 0;
            
            if (hasRejection) {
                let isPartial = rejectedItemsList.length > 0 && rejectedItemsList.length < allItemsList.length;
                let iconClass = isPartial ? 'fa-triangle-exclamation text-warning' : 'fa-circle-exclamation text-danger';
                let titleText = isPartial ? 'Partially Rejected' : 'Rejected';
                let boxClass = isPartial ? 'partial-reject-box' : 'reject-summary-box';
                
                // Override box class for better display
                if (isPartial) {
                    rejectSummaryHtml = `<div class="partial-reject-box mt-2">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa-solid fa-triangle-exclamation text-warning"></i>
                            <small class="text-warning fw-bold">Partially Rejected</small>
                        </div>`;
                } else {
                    rejectSummaryHtml = `<div class="reject-summary-box mt-2">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa-solid fa-circle-exclamation text-danger"></i>
                            <span class="reject-title">Rejected</span>
                        </div>`;
                }
                
                if (rejectedItemsList.length > 0) {
                    rejectSummaryHtml += `<div class="reject-items">❌ ${rejectedItemsList.join(', ')}</div>`;
                }
                
                // Show accepted items if partial
                if (isPartial) {
                    let acceptedItemsList = allItemsList.filter(item => !rejectedItemsList.includes(item));
                    if (acceptedItemsList.length > 0) {
                        rejectSummaryHtml += `<div class="mt-1"><small class="text-success fw-bold">✅ Accepted Items:</small>`;
                        rejectSummaryHtml += `<small class="text-success d-block">${acceptedItemsList.join(', ')}</small></div>`;
                    }
                }
                
                if (order.rejectionReason && order.rejectionReason.trim() !== '') {
                    rejectSummaryHtml += `<div class="reject-reason mt-1 pt-1 border-top">📝 ${order.rejectionReason}</div>`;
                }
                
                rejectSummaryHtml += `</div>`;
                document.getElementById('trackRejectSummary').innerHTML = rejectSummaryHtml;
            } else {
                document.getElementById('trackRejectSummary').innerHTML = '';
            }

            // Progress Bar
            let progressBar = document.getElementById('statusProgressBar');
            
            ['step-ordered', 'step-cooking', 'step-pickup', 'step-completed'].forEach(id => {
                document.getElementById(id).classList.remove('text-brand', 'fw-bold');
                document.getElementById(id).classList.add('text-muted');
            });

            let progress = 0;
            
            switch (order.status) {
                case 'ordered':
                    progress = 25;
                    document.getElementById('step-ordered').classList.add('text-brand', 'fw-bold');
                    break;
                case 'cooking':
                    progress = 50;
                    document.getElementById('step-cooking').classList.add('text-brand', 'fw-bold');
                    document.getElementById('step-ordered').classList.add('completed');
                    break;
                case 'pickup':
                    progress = 75;
                    document.getElementById('step-pickup').classList.add('text-brand', 'fw-bold');
                    document.getElementById('step-ordered').classList.add('completed');
                    document.getElementById('step-cooking').classList.add('completed');
                    break;
                case 'completed':
                    progress = 100;
                    document.getElementById('step-completed').classList.add('text-brand', 'fw-bold');
                    document.getElementById('step-ordered').classList.add('completed');
                    document.getElementById('step-cooking').classList.add('completed');
                    document.getElementById('step-pickup').classList.add('completed');
                    break;
                case 'rejected':
                    progress = 0;
                    statusBadge.innerText = 'REJECTED';
                    statusBadge.className = 'badge rounded-pill px-3 py-2 fs-7 bg-danger';
                    break;
                case 'partial_rejected':
                    progress = 15;
                    statusBadge.innerText = 'PARTIAL REJECTED';
                    statusBadge.className = 'badge rounded-pill px-3 py-2 fs-7 bg-warning text-dark';
                    document.getElementById('step-ordered').classList.add('text-brand', 'fw-bold');
                    break;
                default:
                    progress = 10;
                    document.getElementById('step-ordered').classList.add('text-brand', 'fw-bold');
            }
            
            progressBar.style.width = progress + '%';
            progressBar.setAttribute('aria-valuenow', progress);
            
            if (order.status === 'completed') {
                progressBar.classList.remove('bg-brand');
                progressBar.classList.add('bg-success');
            } else {
                progressBar.classList.remove('bg-success');
                progressBar.classList.add('bg-brand');
            }

        } else {
            Swal.fire({ 
                icon: 'error', 
                title: 'မတွေ့ရှိပါ', 
                text: data.message || 'ဒီ Queue နံပါတ်ဖြင့် Order မရှိပါ။',
                confirmButtonColor: '#1EAFBD',
                customClass: { popup: 'swal-custom-popup' }
            });
            resultBox.classList.add('d-none');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        Swal.fire({ 
            icon: 'error', 
            title: 'Error', 
            text: 'Something went wrong. Please try again.',
            confirmButtonColor: '#1EAFBD',
            customClass: { popup: 'swal-custom-popup' }
        });
    });
}

document.getElementById('trackQueueInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') trackOrder();
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
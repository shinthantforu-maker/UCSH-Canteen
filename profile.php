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

// Get User Details
$userStmt = $conn->prepare("SELECT * FROM users WHERE userId = ?");
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$userData = $userResult->fetch_assoc();
$userStmt->close();

$username = $userData['username'] ?? '';
$phoneNumber = $userData['phoneNumber'] ?? '';
$currentPoints = $userData['points'] ?? 0;
$role = $userData['role'] ?? 'Customer';
$joinedDate = $userData['createdAt'] ?? '';

// Get Cart Count
$cart_count = 0;
$cart_stmt = $conn->prepare("SELECT SUM(quantity) as total FROM cart WHERE userId = ?");
if ($cart_stmt) {
    $cart_stmt->bind_param("i", $user_id);
    $cart_stmt->execute();
    $cart_res = $cart_stmt->get_result()->fetch_assoc();
    $cart_count = $cart_res['total'] ?? 0;
    $cart_stmt->close();
}

// Get Like Count
$like_count = 0;
$like_stmt = $conn->prepare("SELECT COUNT(*) as total FROM liked_items WHERE userId = ?");
if ($like_stmt) {
    $like_stmt->bind_param("i", $user_id);
    $like_stmt->execute();
    $like_res = $like_stmt->get_result()->fetch_assoc();
    $like_count = $like_res['total'] ?? 0;
    $like_stmt->close();
}

// Get Total Orders
$orderStmt = $conn->prepare("SELECT COUNT(*) as total FROM orders WHERE userId = ?");
$orderStmt->bind_param("i", $user_id);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();
$totalOrders = $orderResult->fetch_assoc()['total'] ?? 0;
$orderStmt->close();

// Get Total Points Used (excluding rejected orders)
$usedStmt = $conn->prepare("SELECT SUM(points_used) as total FROM orders WHERE userId = ? AND status != 'rejected'");
$usedStmt->bind_param("i", $user_id);
$usedStmt->execute();
$usedResult = $usedStmt->get_result();
$totalPointsUsed = $usedResult->fetch_assoc()['total'] ?? 0;
$usedStmt->close();

// Get Recent Orders (excluding rejected)
$orders = $conn->query("SELECT o.*, 
                         (SELECT GROUP_CONCAT(CONCAT(m.itemName, ' (', oi.quantity, ')') SEPARATOR ', ') 
                          FROM order_items oi 
                          JOIN menu_items m ON oi.itemId = m.itemId 
                          WHERE oi.orderId = o.orderId) as items 
                         FROM orders o 
                         WHERE o.userId = $user_id AND o.status != 'rejected'
                         ORDER BY o.orderId DESC LIMIT 5");

// Get Points History (excluding rejected)
$pointsHistory = $conn->query("SELECT orderId, queue_number, points_used, totalAmount, status, createdAt 
                               FROM orders 
                               WHERE userId = $user_id AND points_used > 0 AND status != 'rejected'
                               ORDER BY orderId DESC LIMIT 10");
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Dashboard - UCSH Canteen</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Myanmar:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root { 
            --brand-color: #1EAFBD; 
            --brand-hover: #17939F;
            --brand-light: #EBF8F9;
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Noto Sans Myanmar', sans-serif;
            background-color: #F8FAFC;
            color: #1E293B;
        }

        .bg-brand-light { background-color: var(--brand-light) !important; }
        .text-brand { color: var(--brand-color) !important; }

        .navbar-custom {
            background-color: #FFFFFF;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        }
        .nav-icon-btn {
            position: relative;
            color: #64748B;
            font-size: 1.15rem;
            padding: 8px 12px;
            border-radius: 12px;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .nav-icon-btn:hover {
            background-color: var(--brand-light);
            color: var(--brand-color);
        }
        .nav-badge {
            position: absolute;
            top: 2px;
            right: 2px;
            font-size: 0.65rem;
            background-color: #FF4757;
        }

        .search-box { max-width: 380px; }
        .search-box .form-control {
            border-radius: 20px;
            padding-left: 40px;
            border: 1px solid #E2E8F0;
            background-color: #F8FAFC;
        }
        .search-box .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #94A3B8;
        }

        .points-nav {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-radius: 8px;
            padding: 4px 14px;
            font-weight: 700;
            color: #92400e;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px solid #fcd34d;
        }

        .profile-card {
            background: white;
            border-radius: 20px;
            border: 1px solid #E2E8F0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        }

        .stat-box {
            background: #F8FAFC;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            border: 1px solid #E2E8F0;
            transition: all 0.2s ease;
        }
        .stat-box:hover {
            border-color: var(--brand-color);
            background: var(--brand-light);
        }
        .stat-box .number {
            font-size: 1.8rem;
            font-weight: 700;
        }

        /* ============================================= */
        /* POINTS CARD WITH SCHOOL LOGO                   */
        /* ============================================= */
        .points-card-visa {
            background: linear-gradient(135deg, #0d1b2a 0%, #1a3a4a 100%);
            border-radius: 20px;
            padding: 28px;
            color: white;
            position: relative;
            overflow: hidden;
            min-height: 200px;
            box-shadow: 0 12px 40px rgba(13, 27, 42, 0.3);
        }
        .points-card-visa::before {
            content: '';
            position: absolute;
            top: -60%;
            right: -10%;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.03);
        }
        .points-card-visa::after {
            content: '';
            position: absolute;
            bottom: -40%;
            left: -10%;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: rgba(30, 175, 189, 0.08);
        }
        .points-card-visa .card-content {
            position: relative;
            z-index: 2;
        }
        .points-card-visa .school-logo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.2);
            object-fit: cover;
            background: rgba(255,255,255,0.1);
            padding: 4px;
        }
        .points-card-visa .balance-amount {
            font-size: 2.5rem;
            font-weight: 700;
            letter-spacing: 1px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        .points-card-visa .card-number {
            font-family: 'Courier New', monospace;
            letter-spacing: 2px;
            font-size: 0.9rem;
            opacity: 0.7;
        }
        .points-card-visa .chip-icon {
            font-size: 1.8rem;
            opacity: 0.4;
            color: #FFD700;
        }
        .points-card-visa .visa-icon {
            font-size: 2.5rem;
            opacity: 0.6;
        }

        .history-item {
            background: #F8FAFC;
            border-radius: 10px;
            padding: 10px 14px;
            border-left: 3px solid var(--brand-color);
            transition: all 0.2s ease;
        }
        .history-item:hover {
            background: var(--brand-light);
        }

        .fs-7 { font-size: 0.75rem; }
    </style>
</head>
<body class="pb-5">

<?php include 'nav.php'; ?>

<div class="container my-4" style="max-width: 900px;">

    <!-- Page Title -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h5 class="fw-bold text-dark m-0"><i class="fa-regular fa-id-card text-brand me-2"></i>My Dashboard</h5>
            <small class="text-muted">သင့်အကောင့်အချက်အလက်များ</small>
        </div>
        <span class="badge bg-white text-secondary border shadow-sm fw-normal px-3 py-2 rounded-pill fs-7">
            <i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($username) ?>
        </span>
    </div>

    <!-- Points Card (Visa Style with School Logo) -->
    <div class="points-card-visa mb-4">
        <div class="card-content">
            <div class="d-flex justify-content-between align-items-start">
                <div class="d-flex align-items-center gap-3">
                    <!-- SCHOOL LOGO - school.jpg -->
                    <img src="uploads/school.jpg" alt="School Logo" class="school-logo" 
                         onerror="this.src='https://via.placeholder.com/60/1EAFBD/FFFFFF?text=UCSH'">
                    <div>
                        <small style="opacity: 0.6; font-size: 10px; letter-spacing: 1px;">UCSH CANTEEN</small>
                        <h5 class="fw-bold mb-0 text-white" style="letter-spacing: 0.5px;">POINTS CARD</h5>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-wifi fs-5" style="opacity: 0.3;"></i>
                    <i class="fa-regular fa-circle fs-6" style="opacity: 0.2;"></i>
                    <i class="fa-regular fa-circle fs-6" style="opacity: 0.2;"></i>
                </div>
            </div>
            
            <div class="my-3">
                <small style="opacity: 0.5; font-size: 10px; letter-spacing: 1px;">BALANCE</small>
                <div class="balance-amount"><?= number_format($currentPoints) ?></div>
                <div class="card-number mt-1">•••• •••• •••• <?= str_pad(substr($phoneNumber, -4), 4, '0', STR_PAD_LEFT) ?></div>
            </div>
            
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <small style="opacity: 0.5; font-size: 9px; letter-spacing: 0.5px;">CARD HOLDER</small>
                    <div class="fw-bold small" style="letter-spacing: 0.5px;"><?= htmlspecialchars(strtoupper($username)) ?></div>
                </div>
                <!-- <div>
                    <small style="opacity: 0.5; font-size: 9px; letter-spacing: 0.5px;">EXPIRY</small>
                    <div class="fw-bold small">12/28</div>
                </div> -->
                <div>
                   <small style="opacity: 0.5; font-size: 9px; letter-spacing: 0.5px;">contact</small>
                    <div class="fw-bold small">ucshcanteen@gmail.com</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-box">
                <div class="text-muted small fw-bold">Points Balance</div>
                <div class="number text-warning"><?= number_format($currentPoints) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-box">
                <div class="text-muted small fw-bold">Points Used</div>
                <div class="number text-brand"><?= number_format($totalPointsUsed) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-box">
                <div class="text-muted small fw-bold">Total Orders</div>
                <div class="number text-dark"><?= $totalOrders ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-box">
                <div class="text-muted small fw-bold">Member Since</div>
                <div class="number fs-6 text-dark"><?= date('M Y', strtotime($joinedDate)) ?></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Left Column: Profile Info -->
        <div class="col-md-5">
            <div class="profile-card p-4">
                <h6 class="fw-bold text-dark mb-3">
                    <i class="fa-regular fa-user text-brand me-2"></i>Profile Information
                </h6>
                <div class="mb-3 pb-3 border-bottom">
                    <small class="text-muted d-block">Username</small>
                    <span class="fw-bold"><?= htmlspecialchars($username) ?></span>
                </div>
                <div class="mb-3 pb-3 border-bottom">
                    <small class="text-muted d-block">Phone Number</small>
                    <span class="fw-bold"><?= htmlspecialchars($phoneNumber) ?></span>
                </div>
                <div class="mb-3 pb-3 border-bottom">
                    <small class="text-muted d-block">Role</small>
                    <span class="badge <?= $role === 'Admin' ? 'bg-danger' : 'bg-info text-dark' ?>"><?= $role ?></span>
                </div>
                <div>
                    <small class="text-muted d-block">Member Since</small>
                    <span class="fw-bold"><?= date('d M Y, h:i A', strtotime($joinedDate)) ?></span>
                </div>
                <div class="mt-3 pt-3 border-top">
                    <a href="logout.php" class="btn btn-outline-danger btn-sm w-100 rounded-3">
                        <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
                    </a>
                </div>
            </div>
        </div>

        <!-- Right Column: Points History (Excluding Rejected) -->
        <div class="col-md-7">
            <div class="profile-card p-4">
                <h6 class="fw-bold text-dark mb-3">
                    <i class="fa-solid fa-clock-rotate-left text-brand me-2"></i>Recent Points History
                </h6>
                <?php if ($pointsHistory && $pointsHistory->num_rows > 0): ?>
                    <?php while ($ph = $pointsHistory->fetch_assoc()): 
                        $st = strtolower($ph['status']);
                        $badgeClass = 'bg-secondary';
                        if ($st === 'pending') $badgeClass = 'bg-warning text-dark';
                        elseif ($st === 'confirmed' || $st === 'cooking') $badgeClass = 'bg-info text-dark';
                        elseif ($st === 'pickup') $badgeClass = 'bg-primary';
                        elseif ($st === 'completed') $badgeClass = 'bg-success';
                        // Rejected orders are excluded from this list
                    ?>
                        <div class="history-item mb-2 d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold small text-dark">
                                    <?= $ph['queue_number'] ?? 'Q-' . str_pad($ph['orderId'], 3, '0', STR_PAD_LEFT) ?>
                                    <span class="badge <?= $badgeClass ?> ms-1"><?= $ph['status'] ?></span>
                                </div>
                                <small class="text-muted"><?= date('d M Y, h:i A', strtotime($ph['createdAt'])) ?></small>
                            </div>
                            <div class="text-end">
                                <span class="fw-bold text-danger">-<?= number_format($ph['points_used']) ?></span>
                                <div><small class="text-muted"><?= number_format($ph['totalAmount']) ?> total</small></div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fa-regular fa-clock fa-2x opacity-25 mb-2 d-block"></i>
                        <small>Point သုံးစွဲမှုများ မရှိသေးပါ။</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Orders (Excluding Rejected) -->
    <div class="profile-card p-4 mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold text-dark m-0">
                <i class="fa-solid fa-receipt text-brand me-2"></i>Recent Orders
            </h6>
            <a href="history.php" class="text-brand small text-decoration-none fw-bold">View All <i class="fa-solid fa-arrow-right ms-1"></i></a>
        </div>
        <?php if ($orders && $orders->num_rows > 0): ?>
            <?php while ($ord = $orders->fetch_assoc()): 
                $st = strtolower($ord['status']);
                $badgeClass = 'bg-secondary';
                if ($st === 'pending') $badgeClass = 'bg-warning text-dark';
                elseif ($st === 'confirmed' || $st === 'cooking') $badgeClass = 'bg-info text-dark';
                elseif ($st === 'pickup') $badgeClass = 'bg-primary';
                elseif ($st === 'completed') $badgeClass = 'bg-success';
                
                $queueNum = $ord['queue_number'] ?? 'Q-' . str_pad($ord['orderId'], 3, '0', STR_PAD_LEFT);
            ?>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <div>
                        <span class="badge bg-dark text-white"><?= $queueNum ?></span>
                        <span class="badge <?= $badgeClass ?>"><?= $ord['status'] ?></span>
                        <small class="text-muted ms-2"><?= date('d M Y', strtotime($ord['createdAt'])) ?></small>
                    </div>
                    <div>
                        <span class="fw-bold text-warning"><?= number_format($ord['points_used'] ?? 0) ?> pts</span>
                        <a href="track.php" class="btn btn-sm btn-light border rounded-3 ms-2">
                            <i class="fa-solid fa-eye text-brand"></i>
                        </a>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="text-center py-3 text-muted">
                <small>မှာယူထားသော Order များ မရှိသေးပါ။</small>
            </div>
        <?php endif; ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
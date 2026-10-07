<?php
require_once '../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] == '1';

// =============================================
// HANDLE ADD POINTS
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_points') {
    $phone = trim($_POST['phone']);
    $points_to_add = intval($_POST['points']);
    
    if (empty($phone) || $points_to_add <= 0) {
        $_SESSION['add_points_msg'] = "ဖုန်းနံပါတ်နှင့် Point ပမာဏကို မှန်ကန်စွာ ဖြည့်ပါ။";
        $_SESSION['add_points_type'] = "danger";
    } else {
        $checkUser = $conn->prepare("SELECT userId, username, phoneNumber, points FROM users WHERE phoneNumber = ?");
        $checkUser->bind_param("s", $phone);
        $checkUser->execute();
        $userResult = $checkUser->get_result();
        
        if ($userResult->num_rows > 0) {
            $found_user = $userResult->fetch_assoc();
            $newPoints = $found_user['points'] + $points_to_add;
            
            $updateStmt = $conn->prepare("UPDATE users SET points = ? WHERE userId = ?");
            $updateStmt->bind_param("ii", $newPoints, $found_user['userId']);
            if ($updateStmt->execute()) {
                $_SESSION['add_points_msg'] = $found_user['username'] . " အတွက် " . number_format($points_to_add) . " Points ထည့်ပြီးပါပြီ။";
                $_SESSION['add_points_type'] = "success";
                $_SESSION['found_user'] = $found_user;
                $_SESSION['found_user']['points'] = $newPoints;
            }
            $updateStmt->close();
        } else {
            $_SESSION['add_points_msg'] = "ဤဖုန်းနံပါတ်ဖြင့် User မရှိပါ။";
            $_SESSION['add_points_type'] = "danger";
        }
        $checkUser->close();
    }
    header("Location: admin.php");
    exit();
}

$add_points_msg = $_SESSION['add_points_msg'] ?? "";
$add_points_type = $_SESSION['add_points_type'] ?? "";
$found_user = $_SESSION['found_user'] ?? null;
unset($_SESSION['add_points_msg'], $_SESSION['add_points_type'], $_SESSION['found_user']);

// =============================================
// HANDLE ORDER STATUS UPDATE
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $orderId = intval($_POST['orderId']);
    $newStatus = trim($_POST['status']);
    $rejectionReason = trim($_POST['rejectionReason'] ?? '');
    $rejectedItems = trim($_POST['rejected_items'] ?? '');
    
    $allowed_statuses = ['ordered', 'cooking', 'pickup', 'completed', 'rejected', 'partial_rejected'];
    if (in_array($newStatus, $allowed_statuses)) {
        
        if ($newStatus === 'rejected' || $newStatus === 'partial_rejected') {
            $itemStmt = $conn->prepare("SELECT COUNT(*) as total FROM order_items WHERE orderId = ?");
            $itemStmt->bind_param("i", $orderId);
            $itemStmt->execute();
            $totalItems = $itemStmt->get_result()->fetch_assoc()['total'] ?? 0;
            $itemStmt->close();
            
            $rejectedCount = 0;
            $rejectedItemIds = [];
            if (!empty($rejectedItems) && $rejectedItems !== 'all') {
                $rejectedItemIds = explode(',', $rejectedItems);
                $rejectedCount = count($rejectedItemIds);
            } elseif ($rejectedItems === 'all') {
                $rejectedCount = $totalItems;
            }
            
            if ($rejectedCount >= $totalItems && $totalItems > 0) {
                $finalStatus = 'rejected';
            } elseif ($rejectedCount > 0 && $rejectedCount < $totalItems) {
                $finalStatus = 'partial_rejected';
            } else {
                $finalStatus = 'ordered';
            }
            
            if ($finalStatus === 'rejected' || $finalStatus === 'partial_rejected') {
                $orderStmt = $conn->prepare("SELECT userId, points_used, deliveryFee FROM orders WHERE orderId = ?");
                $orderStmt->bind_param("i", $orderId);
                $orderStmt->execute();
                $orderData = $orderStmt->get_result()->fetch_assoc();
                $orderStmt->close();
                
                if ($orderData && $orderData['userId']) {
                    $userId = $orderData['userId'];
                    $pointsToRefund = 0;
                    
                    if ($rejectedItems === 'all') {
                        $pointsToRefund = $orderData['points_used'];
                    } elseif (!empty($rejectedItemIds)) {
                        $placeholders = implode(',', array_fill(0, count($rejectedItemIds), '?'));
                        $types = str_repeat('i', count($rejectedItemIds));
                        
                        $refundStmt = $conn->prepare("SELECT SUM(price * quantity) as total FROM order_items WHERE orderId = ? AND itemId IN ($placeholders)");
                        $params = array_merge([$orderId], $rejectedItemIds);
                        $refundStmt->bind_param("i" . $types, ...$params);
                        $refundStmt->execute();
                        $pointsToRefund = $refundStmt->get_result()->fetch_assoc()['total'] ?? 0;
                        $refundStmt->close();
                    }
                    
                    if ($finalStatus === 'rejected' && $orderData['deliveryFee'] > 0) {
                        $pointsToRefund += $orderData['deliveryFee'];
                    }
                    
                    if ($pointsToRefund > 0) {
                        $refundUpdateStmt = $conn->prepare("UPDATE users SET points = points + ? WHERE userId = ?");
                        $refundUpdateStmt->bind_param("ii", $pointsToRefund, $userId);
                        $refundUpdateStmt->execute();
                        $refundUpdateStmt->close();
                        
                        $newPointsUsed = $orderData['points_used'] - $pointsToRefund;
                        if ($newPointsUsed < 0) $newPointsUsed = 0;
                        
                        $updatePointsStmt = $conn->prepare("UPDATE orders SET points_used = ? WHERE orderId = ?");
                        $updatePointsStmt->bind_param("ii", $newPointsUsed, $orderId);
                        $updatePointsStmt->execute();
                        $updatePointsStmt->close();
                    }
                }
            }
            
            if (empty($rejectionReason)) {
                $rejectionReason = $finalStatus === 'partial_rejected' ? "ပစ္စည်းအချို့ကို ပယ်ချလိုက်ပါသည်။" : "အော်ဒါကို ပယ်ချလိုက်ပါသည်။";
            }
            
            $updateStatusStmt = $conn->prepare("UPDATE orders SET status = ?, rejectionReason = ?, rejected_items = ? WHERE orderId = ?");
            $updateStatusStmt->bind_param("sssi", $finalStatus, $rejectionReason, $rejectedItems, $orderId);
            $updateStatusStmt->execute();
            $updateStatusStmt->close();
        } else {
            $updateStatusStmt = $conn->prepare("UPDATE orders SET status = ? WHERE orderId = ?");
            $updateStatusStmt->bind_param("si", $newStatus, $orderId);
            $updateStatusStmt->execute();
            $updateStatusStmt->close();
        }
        
        // Delivery status auto update
        $deliveryCheckStmt = $conn->prepare("SELECT orderType FROM orders WHERE orderId = ?");
        $deliveryCheckStmt->bind_param("i", $orderId);
        $deliveryCheckStmt->execute();
        $deliveryCheck = $deliveryCheckStmt->get_result()->fetch_assoc();
        $deliveryCheckStmt->close();
        
        if ($deliveryCheck && $deliveryCheck['orderType'] === 'delivery') {
            $deliveryStatusUpdate = null;
            if ($newStatus === 'cooking') $deliveryStatusUpdate = 'preparing';
            elseif ($newStatus === 'pickup') $deliveryStatusUpdate = 'on_the_way';
            elseif ($newStatus === 'completed') $deliveryStatusUpdate = 'delivered';
            elseif ($newStatus === 'rejected') $deliveryStatusUpdate = 'pending';
            
            if ($deliveryStatusUpdate) {
                $updStmt = $conn->prepare("UPDATE orders SET deliveryStatus = ? WHERE orderId = ?");
                $updStmt->bind_param("si", $deliveryStatusUpdate, $orderId);
                $updStmt->execute();
                $updStmt->close();
            }
        }
    }
    header("Location: admin.php");
    exit();
}

// =============================================
// STATISTICS
// =============================================

// ✅ 7-Day Points (User တွေကို ရောင်းထားတဲ့ Point)
$totalPoints = $conn->query("
    SELECT COALESCE(SUM(points_used), 0) as totalPoints 
    FROM orders 
    WHERE createdAt >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND status NOT IN ('rejected')
")->fetch_assoc()['totalPoints'] ?? 0;

// ✅ Today's Points
$totalPointsUsed = $conn->query("
    SELECT COALESCE(SUM(points_used), 0) as todayPoints 
    FROM orders 
    WHERE DATE(createdAt) = CURDATE()
    AND status NOT IN ('rejected')
")->fetch_assoc()['todayPoints'] ?? 0;

$status_result = $conn->query("SELECT status, COUNT(*) as count FROM orders GROUP BY status");
$orderStatusCounts = [];
while ($row = $status_result->fetch_assoc()) {
    $orderStatusCounts[strtolower($row['status'])] = $row['count'];
}

$activeOrders = ($orderStatusCounts['ordered'] ?? 0) + ($orderStatusCounts['cooking'] ?? 0) + 
                ($orderStatusCounts['pickup'] ?? 0) + ($orderStatusCounts['partial_rejected'] ?? 0);

$top_item_result = $conn->query("SELECT mi.itemName, SUM(oi.quantity) as totalQty FROM order_items oi JOIN menu_items mi ON oi.itemId = mi.itemId GROUP BY oi.itemId ORDER BY totalQty DESC LIMIT 1");
$topSeller = $top_item_result->fetch_assoc()['itemName'] ?? 'မရှိသေးပါ။';

// CHART DATA
$chartDays = [];
$chartOrderCounts = [];
$chartPointsUsed = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartDays[] = date('d M', strtotime($date));
    
    $dayStmt = $conn->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(points_used), 0) as pts FROM orders WHERE DATE(createdAt) = ?");
    $dayStmt->bind_param("s", $date);
    $dayStmt->execute();
    $dayData = $dayStmt->get_result()->fetch_assoc();
    $chartOrderCounts[] = (int)$dayData['cnt'];
    $chartPointsUsed[] = (int)$dayData['pts'];
    $dayStmt->close();
}

$topItems = [];
$topItemsCount = [];
$topStmt = $conn->query("SELECT m.itemName, SUM(oi.quantity) as total FROM order_items oi JOIN menu_items m ON oi.itemId = m.itemId GROUP BY oi.itemId ORDER BY total DESC LIMIT 5");
while ($row = $topStmt->fetch_assoc()) {
    $topItems[] = $row['itemName'];
    $topItemsCount[] = (int)$row['total'];
}

$statusLabels = [];
$statusChartCounts = [];
foreach ($orderStatusCounts as $status => $count) {
    $statusLabels[] = ucfirst(str_replace('_', ' ', $status));
    $statusChartCounts[] = (int)$count;
}

$catLabels = [];
$catCounts = [];
$catStmt = $conn->query("SELECT mi.category, SUM(oi.quantity) as total FROM order_items oi JOIN menu_items mi ON oi.itemId = mi.itemId GROUP BY mi.category");
while ($row = $catStmt->fetch_assoc()) {
    $catLabels[] = $row['category'];
    $catCounts[] = (int)$row['total'];
}

// FETCH ORDERS
$orders_query = "SELECT o.*, u.username, 
                (SELECT GROUP_CONCAT(CONCAT(m.itemId, ':', m.itemName, ' (', oi.quantity, ')') SEPARATOR '|') 
                 FROM order_items oi JOIN menu_items m ON oi.itemId = m.itemId WHERE oi.orderId = o.orderId) as items_with_id,
                (SELECT GROUP_CONCAT(CONCAT(m.itemName, ' (', oi.quantity, ')') SEPARATOR ', ') 
                 FROM order_items oi JOIN menu_items m ON oi.itemId = m.itemId WHERE oi.orderId = o.orderId) as items 
                FROM orders o 
                LEFT JOIN users u ON o.userId = u.userId 
                ORDER BY o.orderId DESC LIMIT 20";
$orders_result = $conn->query($orders_query);

// AJAX MODE
if ($isAjax) {
    ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="stat-card"><div class="stat-label">📊 7-Day Points</div><div class="stat-number text-warning" data-points-stat="totalPoints"><?= number_format($totalPoints) ?></div></div></div>
        <div class="col-md-3"><div class="stat-card"><div class="stat-label">📅 Today's Points</div><div class="stat-number text-brand" data-points-stat="todayPoints"><?= number_format($totalPointsUsed) ?></div></div></div>
        <div class="col-md-3"><div class="stat-card"><div class="stat-label">Active Orders</div><div class="stat-number text-dark" data-stat="activeOrders"><?= $activeOrders ?></div></div></div>
        <div class="col-md-3"><div class="stat-card"><div class="stat-label">Top Seller</div><div class="stat-number fs-3 text-dark text-truncate" data-stat="topSeller"><?= htmlspecialchars($topSeller) ?></div></div></div>
    </div>
    <table class="table table-hover align-middle mb-0">
        <tbody id="ordersTableBody">
            <?php if ($orders_result && $orders_result->num_rows > 0): ?>
                <?php $serial = 1; while ($ord = $orders_result->fetch_assoc()): 
                    $st = strtolower($ord['status']);
                    $badgeClass = 'bg-secondary';
                    if ($st === 'ordered' || $st === 'cooking') $badgeClass = 'bg-info text-dark';
                    elseif ($st === 'pickup') $badgeClass = 'bg-primary';
                    elseif ($st === 'completed') $badgeClass = 'bg-success';
                    elseif ($st === 'rejected') $badgeClass = 'bg-danger';
                    elseif ($st === 'partial_rejected') $badgeClass = 'bg-warning text-dark';
                    
                    $queueNum = $ord['queue_number'] ?? 'Q-' . str_pad($ord['orderId'], 3, '0', STR_PAD_LEFT);
                    $items = $ord['items'] ?? 'No items';
                    $disableSelect = ($st === 'rejected' || $st === 'completed');
                ?>
                    <tr data-order-row="<?= $ord['orderId'] ?>">
                        <td class="fw-bold text-dark"><?= $serial++ ?></td>
                        <td><span class="badge bg-dark text-white fw-bold"><?= $queueNum ?></span></td>
                        <td><?= htmlspecialchars($ord['username'] ?? 'Guest') ?></td>
                        <td><small class="text-truncate d-inline-block" style="max-width: 150px;"><?= htmlspecialchars($items) ?></small></td>
                        <td>
                            <span class="badge bg-light text-dark border"><?= strtoupper($ord['orderType']) ?></span>
                            <?php if ($ord['orderType'] === 'delivery' && !empty($ord['deliveryAddress'])): ?>
                                <div style="font-size: 0.65rem; color: #64748B; margin-top: 2px;">📍 <?= htmlspecialchars($ord['deliveryAddress']) ?></div>
                                <div style="font-size: 0.65rem; color: #FFC107; font-weight: 700;">+<?= number_format($ord['deliveryFee']) ?> pts</div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-warning text-dark"><?= number_format($ord['points_used'] ?? 0) ?></span></td>
                        <td><span class="badge <?= $badgeClass ?> text-uppercase" data-status-badge><?= $ord['status'] ?></span></td>
                        <td>
                            <div class="status-actions">
                                <form method="POST" action="admin.php" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="orderId" value="<?= $ord['orderId'] ?>">
                                    <input type="hidden" name="rejectionReason" value="">
                                    <input type="hidden" name="rejected_items" value="">
                                    <select name="status" class="form-select form-select-sm status-select" 
                                            <?= $disableSelect ? 'disabled' : '' ?> 
                                            onchange="this.form.submit()">
                                        <option value="ordered" <?= $st === 'ordered' ? 'selected' : '' ?>>Ordered</option>
                                        <option value="cooking" <?= $st === 'cooking' ? 'selected' : '' ?>>Cooking</option>
                                        <option value="pickup" <?= $st === 'pickup' ? 'selected' : '' ?>>Pickup</option>
                                        <option value="completed" <?= $st === 'completed' ? 'selected' : '' ?>>Completed</option>
                                    </select>
                                </form>
                                <?php if ($st === 'ordered'): ?>
                                    <button class="btn-reject" onclick="showRejectModal(<?= $ord['orderId'] ?>, '<?= addslashes($ord['items_with_id']) ?>')"><i class="fa-solid fa-ban me-1"></i>Reject</button>
                                <?php else: ?>
                                    <button class="btn-reject" disabled><i class="fa-solid fa-ban me-1"></i>Reject</button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <button class="btn-eye" onclick="showOrderDetails(<?= $ord['orderId'] ?>)" title="View Details">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
    exit();
}
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UCSH Canteen - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Myanmar:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root { --brand-color: #1EAFBD; --brand-hover: #17939F; --brand-light: #EBF8F9; }
        body { font-family: 'Poppins', 'Noto Sans Myanmar', sans-serif; background-color: #F8FAFC; }
        .sidebar { width: 260px; background: #FFFFFF; min-height: 100vh; border-right: 1px solid #E2E8F0; }
        .nav-link-custom { color: #64748B; padding: 12px 20px; border-radius: 10px; font-weight: 500; display: flex; align-items: center; gap: 12px; text-decoration: none; margin-bottom: 5px; transition: all 0.2s; }
        .nav-link-custom:hover, .nav-link-custom.active { background-color: var(--brand-light); color: var(--brand-color); }
        .text-brand { color: var(--brand-color) !important; }
        .bg-brand { background-color: var(--brand-color) !important; }
        .btn-brand { background-color: var(--brand-color); color: white; border: none; }
        .btn-brand:hover { background-color: var(--brand-hover); color: white; }
        .stat-card { background: #FFFFFF; border-radius: 16px; border: 1px solid #E2E8F0; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .stat-card .stat-number { font-size: 2rem; font-weight: 700; }
        .stat-card .stat-label { font-size: 0.75rem; color: #64748B; font-weight: 500; }
        .add-points-box { background: linear-gradient(135deg, #f8f9fa, #e9ecef); border-radius: 16px; padding: 20px; border: 1px solid #E2E8F0; }
        .user-result-card { background: white; border-radius: 12px; padding: 15px; border-left: 4px solid var(--brand-color); }
        .user-result-card.success { border-color: #28a745; }
        .user-result-card.danger { border-color: #dc3545; }
        .status-actions { display: flex; gap: 4px; align-items: center; flex-wrap: nowrap; }
        .status-actions .btn-reject { background: #dc3545; color: white; border: none; padding: 4px 12px; border-radius: 6px; font-size: 0.7rem; font-weight: 600; white-space: nowrap; }
        .status-actions .btn-reject:hover:not(:disabled) { background: #c82333; }
        .status-actions .btn-reject:disabled { background: #6c757d; cursor: not-allowed; opacity: 0.6; }
        .status-actions .status-select { min-width: 110px; padding: 3px 8px; font-size: 0.75rem; border-radius: 6px; border: 1px solid #ced4da; }
        
        .new-order-flash { animation: flashGreen 1.5s ease-in-out; }
        @keyframes flashGreen { 0% { background-color: #d4edda; } 50% { background-color: #c3e6cb; } 100% { background-color: transparent; } }
        .new-order-row { animation: slideIn 0.5s ease-out; }
        @keyframes slideIn { from { transform: translateX(-20px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

        .voice-btn-on { background: #1EAFBD; color: white; border: none; }
        .voice-btn-off { background: #e2e8f0; color: #64748B; border: none; }

        .btn-eye {
            background: transparent;
            border: 1px solid #1EAFBD;
            color: #1EAFBD;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            transition: all 0.2s;
            cursor: pointer;
        }
        .btn-eye:hover {
            background: #1EAFBD;
            color: white;
            transform: scale(1.1);
        }
    </style>
</head>
<body>

<div class="d-flex">
    <div class="sidebar p-3 d-flex flex-column">
        <a href="admin.php" class="d-flex align-items-center gap-2 text-decoration-none text-brand fw-bold fs-4 mb-4 px-2">
            <i class="fa-solid fa-utensils"></i> UCSH Admin
        </a>
        <div class="nav flex-column mb-auto">
            <a href="admin.php" class="nav-link-custom active"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
            <a href="menu.php" class="nav-link-custom"><i class="fa-solid fa-bowl-food"></i> Manage Menu</a>
            <a href="users.php" class="nav-link-custom"><i class="fa-solid fa-users"></i> Users</a>
            <a href="announcements.php" class="nav-link-custom"><i class="fa-solid fa-bullhorn"></i> Announcements</a>
        </div>
        <hr class="text-muted">
        <div><a href="../logout.php" class="nav-link-custom text-danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></div>
    </div>

    <div class="flex-grow-1 p-3 p-md-4 overflow-hidden">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold m-0 text-dark">Admin Dashboard</h4>
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-sm rounded-pill voice-btn-on" id="voiceToggleBtn" onclick="toggleVoice()">
                    <i class="fa-solid fa-volume-high me-1" id="voiceIcon"></i>
                    <span id="voiceText">Voice ON</span>
                </button>
                <span class="text-muted small">
                    <i class="fa-solid fa-circle text-success me-1" style="font-size: 8px;"></i>
                    Live <span id="refreshIndicator"></span>
                </span>
            </div>
        </div>

        <!-- Add Points -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="add-points-box">
                    <h6 class="fw-bold mb-3"><i class="fa-solid fa-coins text-warning me-2"></i>Add Points to User</h6>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="action" value="add_points">
                        <div class="row g-2">
                            <div class="col-12 col-sm-5"><input type="text" name="phone" class="form-control form-control-sm" placeholder="Phone Number" required></div>
                            <div class="col-12 col-sm-4"><input type="number" name="points" class="form-control form-control-sm" placeholder="Points" required min="1"></div>
                            <div class="col-12 col-sm-3"><button type="submit" class="btn btn-brand btn-sm w-100">Add</button></div>
                        </div>
                    </form>
                    <?php if ($add_points_msg): ?>
                        <div class="user-result-card mt-3 <?= $add_points_type === 'success' ? 'success' : 'danger' ?>">
                            <i class="fa-solid <?= $add_points_type === 'success' ? 'fa-check-circle text-success' : 'fa-exclamation-circle text-danger' ?> me-2"></i>
                            <span class="<?= $add_points_type === 'success' ? 'text-success' : 'text-danger' ?>"><?= $add_points_msg ?></span>
                            <?php if ($found_user && $add_points_type === 'success'): ?>
                                <hr class="my-2">
                                <div class="d-flex justify-content-between">
                                    <div><strong><?= htmlspecialchars($found_user['username']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($found_user['phoneNumber']) ?></small></div>
                                    <div class="text-end"><span class="text-warning fw-bold"><?= number_format($found_user['points']) ?> Points</span></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-6">
                <div class="add-points-box" style="background: linear-gradient(135deg, #EBF8F9, #FFFFFF);">
                    <h6 class="fw-bold mb-3"><i class="fa-solid fa-bowl-food text-brand me-2"></i>Quick Actions</h6>
                    <div class="d-grid gap-2">
                        <a href="menu.php" class="btn btn-brand rounded-3 fw-medium"><i class="fa-solid fa-plus me-2"></i>Add Menu Item</a>
                        <a href="users.php" class="btn btn-outline-secondary rounded-3 fw-medium"><i class="fa-solid fa-users me-2"></i>Manage Users</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3"><div class="stat-card"><div class="stat-label">📊 7 ရက်အတွင်းရောင်းရသော points </div><div class="stat-number text-warning" data-points-stat="totalPoints"><?= number_format($totalPoints) ?></div></div></div>
            <div class="col-md-3"><div class="stat-card"><div class="stat-label">📅 ယနေ့ရောင်းရသော Points</div><div class="stat-number text-brand" data-points-stat="todayPoints"><?= number_format($totalPointsUsed) ?></div></div></div>
            <div class="col-md-3"><div class="stat-card"><div class="stat-label">Active Orders</div><div class="stat-number text-dark" data-stat="activeOrders"><?= $activeOrders ?></div></div></div>
            <div class="col-md-3"><div class="stat-card"><div class="stat-label">Top Seller</div><div class="stat-number fs-3 text-dark text-truncate" data-stat="topSeller"><?= htmlspecialchars($topSeller) ?></div></div></div>
        </div>

        <!-- Charts -->
        <div class="row g-3 mb-4">
            <div class="col-lg-8">
                <div class="stat-card">
                    <h6 class="fw-bold mb-3"><i class="fa-solid fa-chart-line text-brand me-2"></i>Last 7 Days Sales</h6>
                    <div style="height: 280px;"><canvas id="salesChart"></canvas></div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="stat-card">
                    <h6 class="fw-bold mb-3"><i class="fa-solid fa-chart-pie text-brand me-2"></i>Order Status</h6>
                    <div style="height: 280px;"><canvas id="statusChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-lg-7">
                <div class="stat-card">
                    <h6 class="fw-bold mb-3"><i class="fa-solid fa-chart-bar text-brand me-2"></i>Top 5 Selling Items</h6>
                    <div style="height: 250px;"><canvas id="topItemsChart"></canvas></div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="stat-card">
                    <h6 class="fw-bold mb-3"><i class="fa-solid fa-chart-pie text-brand me-2"></i>Sales by Category</h6>
                    <div style="height: 250px;"><canvas id="categoryChart"></canvas></div>
                </div>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold m-0 text-dark"><i class="fa-solid fa-list-check text-brand me-2"></i>Recent Orders</h6>
                <span class="badge bg-success-subtle text-success"><i class="fa-solid fa-circle" style="font-size: 6px;"></i> Live</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="border-0 text-secondary small fw-bold">#</th>
                            <th class="border-0 text-secondary small fw-bold">Queue</th>
                            <th class="border-0 text-secondary small fw-bold">Customer</th>
                            <th class="border-0 text-secondary small fw-bold">Items</th>
                            <th class="border-0 text-secondary small fw-bold">Type</th>
                            <th class="border-0 text-secondary small fw-bold">Points</th>
                            <th class="border-0 text-secondary small fw-bold">Status</th>
                            <th class="border-0 text-secondary small fw-bold">Action</th>
                            <th class="border-0 text-secondary small fw-bold">Details</th>
                        </tr>
                    </thead>
                    <tbody id="ordersTableBody">
                        <?php 
                        $orders_result->data_seek(0);
                        if ($orders_result && $orders_result->num_rows > 0): 
                            $serial = 1; 
                            while ($ord = $orders_result->fetch_assoc()): 
                                $st = strtolower($ord['status']);
                                $badgeClass = 'bg-secondary';
                                if ($st === 'ordered' || $st === 'cooking') $badgeClass = 'bg-info text-dark';
                                elseif ($st === 'pickup') $badgeClass = 'bg-primary';
                                elseif ($st === 'completed') $badgeClass = 'bg-success';
                                elseif ($st === 'rejected') $badgeClass = 'bg-danger';
                                elseif ($st === 'partial_rejected') $badgeClass = 'bg-warning text-dark';
                                
                                $queueNum = $ord['queue_number'] ?? 'Q-' . str_pad($ord['orderId'], 3, '0', STR_PAD_LEFT);
                                $items = $ord['items'] ?? 'No items';
                                $disableSelect = ($st === 'rejected' || $st === 'completed');
                        ?>
                            <tr data-order-row="<?= $ord['orderId'] ?>">
                                <td class="fw-bold text-dark"><?= $serial++ ?></td>
                                <td><span class="badge bg-dark text-white fw-bold"><?= $queueNum ?></span></td>
                                <td><?= htmlspecialchars($ord['username'] ?? 'Guest') ?></td>
                                <td><small class="text-truncate d-inline-block" style="max-width: 150px;"><?= htmlspecialchars($items) ?></small></td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= strtoupper($ord['orderType']) ?></span>
                                    <?php if ($ord['orderType'] === 'delivery' && !empty($ord['deliveryAddress'])): ?>
                                        <div style="font-size: 0.65rem; color: #64748B; margin-top: 2px;">📍 <?= htmlspecialchars($ord['deliveryAddress']) ?></div>
                                        <div style="font-size: 0.65rem; color: #FFC107; font-weight: 700;">+<?= number_format($ord['deliveryFee']) ?> pts</div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-warning text-dark"><?= number_format($ord['points_used'] ?? 0) ?></span></td>
                                <td><span class="badge <?= $badgeClass ?> text-uppercase" data-status-badge><?= $ord['status'] ?></span></td>
                                <td>
                                    <div class="status-actions">
                                        <form method="POST" action="admin.php" class="d-inline">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="orderId" value="<?= $ord['orderId'] ?>">
                                            <input type="hidden" name="rejectionReason" value="">
                                            <input type="hidden" name="rejected_items" value="">
                                            <select name="status" class="form-select form-select-sm status-select" 
                                                    <?= $disableSelect ? 'disabled' : '' ?> 
                                                    onchange="this.form.submit()">
                                                <option value="ordered" <?= $st === 'ordered' ? 'selected' : '' ?>>Ordered</option>
                                                <option value="cooking" <?= $st === 'cooking' ? 'selected' : '' ?>>Cooking</option>
                                                <option value="pickup" <?= $st === 'pickup' ? 'selected' : '' ?>>Pickup</option>
                                                <option value="completed" <?= $st === 'completed' ? 'selected' : '' ?>>Completed</option>
                                            </select>
                                        </form>
                                        <?php if ($st === 'ordered'): ?>
                                            <button class="btn-reject" onclick="showRejectModal(<?= $ord['orderId'] ?>, '<?= addslashes($ord['items_with_id']) ?>')"><i class="fa-solid fa-ban me-1"></i>Reject</button>
                                        <?php else: ?>
                                            <button class="btn-reject" disabled><i class="fa-solid fa-ban me-1"></i>Reject</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <button class="btn-eye" onclick="showOrderDetails(<?= $ord['orderId'] ?>)" title="View Details">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Order Details Modal -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header bg-light border-0">
                <h6 class="fw-bold m-0">
                    <i class="fa-solid fa-receipt text-brand me-2"></i>
                    Order Details: <span id="detailsQueueNum" class="badge bg-dark text-white ms-1">Q-000</span>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="small text-muted">Customer</div>
                        <div class="fw-bold" id="detailsCustomer">-</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted">Type</div>
                        <div class="fw-bold" id="detailsOrderType">-</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted">Pickup Time</div>
                        <div class="fw-bold" id="detailsPickupTime">-</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted">Status</div>
                        <div><span class="badge bg-secondary" id="detailsStatus">-</span></div>
                    </div>
                </div>
                
                <h6 class="fw-bold mb-2">
                    <i class="fa-solid fa-utensils text-brand me-1"></i>Ordered Items
                </h6>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-bordered">
                        <thead class="bg-light">
                            <tr>
                                <th>Item</th>
                                <th class="text-center">Qty</th>
                                <th class="text-end">Price</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody id="detailsItemsBody">
                            <tr><td colspan="4" class="text-center text-muted">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
                
                <div id="detailsSpecialRequestBox" class="mb-3" style="display: none;">
                    <h6 class="fw-bold mb-2">
                        <i class="fa-solid fa-comment-dots text-brand me-1"></i>Special Request
                    </h6>
                    <div class="bg-light p-3 rounded-3 border" id="detailsSpecialRequest">-</div>
                </div>
                
                <div id="detailsDeliveryBox" class="mb-3" style="display: none;">
                    <h6 class="fw-bold mb-2">
                        <i class="fa-solid fa-box text-brand me-1"></i>Delivery Info
                    </h6>
                    <div class="bg-light p-3 rounded-3 border">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Address:</span>
                            <span class="fw-bold" id="detailsDeliveryAddress">-</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Delivery Fee:</span>
                            <span class="fw-bold text-warning" id="detailsDeliveryFee">+0 pts</span>
                        </div>
                    </div>
                </div>
                
                <div id="detailsRejectionBox" class="mb-3" style="display: none;">
                    <h6 class="fw-bold mb-2">
                        <i class="fa-solid fa-circle-exclamation text-danger me-1"></i>Rejection Info
                    </h6>
                    <div class="bg-danger-subtle p-3 rounded-3 border border-danger">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Reason:</span>
                            <span class="fw-bold text-danger" id="detailsRejectionReason">-</span>
                        </div>
                        <div class="d-flex justify-content-between" id="detailsRejectedItemsBox" style="display: none;">
                            <span class="text-muted">Rejected Items:</span>
                            <span class="fw-bold text-danger" id="detailsRejectedItems">-</span>
                        </div>
                    </div>
                </div>
                
                <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                    <h6 class="fw-bold mb-0">Total Points Used:</h6>
                    <h5 class="fw-bold text-warning mb-0" id="detailsTotalPoints">0 Points</h5>
                </div>
                
                <div class="text-muted small mt-3 text-end">
                    <i class="fa-regular fa-calendar me-1"></i>
                    <span id="detailsCreatedAt">-</span>
                </div>
                
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-secondary btn-sm rounded-3 px-4" data-bs-dismiss="modal">ပိတ်မည်</button>
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header bg-light border-0">
                <h6 class="fw-bold m-0"><i class="fa-solid fa-ban text-danger me-2"></i>Reject Order Items</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small mb-3">ပယ်ချလိုသော ပစ္စည်းများကို ရွေးပါ။</p>
                <div id="rejectItemsList" style="max-height: 150px; overflow-y: auto; background: #f8f9fa; border-radius: 8px; padding: 10px; margin-bottom: 12px;"></div>
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="document.getElementById('rejectReasonInput').value='ပစ္စည်းကုန်သွားသောကြောင့် မှာယူ၍မရတော့ပါ'"><i class="fa-solid fa-box-open me-1"></i> ပစ္စည်းကုန်သွားပါ</button>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Rejection Reason</label>
                    <textarea id="rejectReasonInput" class="form-control rounded-3" rows="2" placeholder="ဥပမာ - ပစ္စည်းမကျန်တော့ပါ..."></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-secondary btn-sm rounded-3 px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm rounded-3 px-4" onclick="confirmReject()"><i class="fa-solid fa-ban me-1"></i>Reject</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// CHARTS
new Chart(document.getElementById('salesChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($chartDays) ?>,
        datasets: [
            { label: 'Orders', data: <?= json_encode($chartOrderCounts) ?>, borderColor: '#1EAFBD', backgroundColor: 'rgba(30,175,189,0.15)', tension: 0.4, fill: true, pointBackgroundColor: '#1EAFBD', pointRadius: 5, borderWidth: 3, yAxisID: 'y' },
            { label: 'Points', data: <?= json_encode($chartPointsUsed) ?>, borderColor: '#ffc107', backgroundColor: 'rgba(255,193,7,0.1)', tension: 0.4, fill: false, pointBackgroundColor: '#ffc107', pointRadius: 5, borderWidth: 3, borderDash: [5,5], yAxisID: 'y1' }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'top', labels: { boxWidth: 12, padding: 15 } } },
        scales: { y: { type: 'linear', position: 'left', beginAtZero: true, ticks: { stepSize: 1, color: '#1EAFBD' }, title: { display: true, text: 'Orders', color: '#1EAFBD' } },
                 y1: { type: 'linear', position: 'right', beginAtZero: true, ticks: { color: '#ffc107' }, title: { display: true, text: 'Points', color: '#ffc107' }, grid: { drawOnChartArea: false } } } }
});

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: { labels: <?= json_encode($statusLabels) ?>, datasets: [{ data: <?= json_encode($statusChartCounts) ?>, backgroundColor: ['#0dcaf0','#0d6efd','#6c757d','#198754','#dc3545','#ffc107'], borderWidth: 3, borderColor: '#fff', hoverOffset: 10 }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 }, padding: 10 } } } }
});

new Chart(document.getElementById('topItemsChart'), {
    type: 'bar',
    data: { labels: <?= json_encode($topItems) ?>, datasets: [{ label: 'Quantity', data: <?= json_encode($topItemsCount) ?>, backgroundColor: ['#1EAFBD','#17939F','#0F5860','#5CD6E0','#B8F0F5'], borderRadius: 8, barThickness: 40 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } }, x: { grid: { display: false } } } }
});

new Chart(document.getElementById('categoryChart'), {
    type: 'pie',
    data: { labels: <?= json_encode($catLabels) ?>, datasets: [{ data: <?= json_encode($catCounts) ?>, backgroundColor: ['#1EAFBD','#ffc107','#dc3545','#0d6efd','#198754'], borderWidth: 3, borderColor: '#fff', hoverOffset: 15 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } } } }
});

// AUDIO UNLOCK
let audioUnlocked = false;
let globalAudioContext = null;

function unlockAudio() {
    if (audioUnlocked) return;
    try {
        globalAudioContext = new (window.AudioContext || window.webkitAudioContext)();
        if (globalAudioContext.state === 'suspended') globalAudioContext.resume();
        audioUnlocked = true;
    } catch(e) {}
}

document.addEventListener('click', unlockAudio, { once: false });
document.addEventListener('keydown', unlockAudio, { once: false });
document.addEventListener('touchstart', unlockAudio, { once: false });

document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        try {
            if (!globalAudioContext) globalAudioContext = new (window.AudioContext || window.webkitAudioContext)();
            const osc = globalAudioContext.createOscillator();
            const gain = globalAudioContext.createGain();
            osc.connect(gain);
            gain.connect(globalAudioContext.destination);
            gain.gain.value = 0.001;
            osc.frequency.value = 440;
            osc.start();
            osc.stop(globalAudioContext.currentTime + 0.01);
            audioUnlocked = true;
        } catch(e) {}
    }, 300);
});

// VOICE NOTIFICATION
let voiceEnabled = true;
let lastVoiceTime = 0;

function toggleVoice() {
    voiceEnabled = !voiceEnabled;
    const icon = document.getElementById('voiceIcon');
    const text = document.getElementById('voiceText');
    const btn = document.getElementById('voiceToggleBtn');
    
    if (voiceEnabled) {
        icon.className = 'fa-solid fa-volume-high me-1';
        text.textContent = 'Voice ON';
        btn.className = 'btn btn-sm rounded-pill voice-btn-on';
        speakEnglish('Voice enabled');
    } else {
        icon.className = 'fa-solid fa-volume-xmark me-1';
        text.textContent = 'Voice OFF';
        btn.className = 'btn btn-sm rounded-pill voice-btn-off';
        window.speechSynthesis.cancel();
    }
}

function playNotificationSound(orderCount = 1) {
    if (!voiceEnabled) return;
    const now = Date.now();
    if (now - lastVoiceTime < 5000) return;
    lastVoiceTime = now;
    
    playBellSound();
    setTimeout(() => {
        if (orderCount === 1) speakEnglish('Hello, new order');
        else speakEnglish('Hello, ' + orderCount + ' new orders');
    }, 1500);
}

function playBellSound() {
    try {
        if (!globalAudioContext) globalAudioContext = new (window.AudioContext || window.webkitAudioContext)();
        if (globalAudioContext.state === 'suspended') globalAudioContext.resume();
        
        const ctx = globalAudioContext;
        const bellTones = [
            { freq: 523.25, delay: 0, duration: 1.8, vol: 0.5 },
            { freq: 1046.50, delay: 0, duration: 1.5, vol: 0.35 },
            { freq: 1567.98, delay: 0, duration: 1.2, vol: 0.25 }
        ];
        
        bellTones.forEach(tone => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'triangle';
            osc.frequency.value = tone.freq;
            osc.connect(gain);
            gain.connect(ctx.destination);
            
            const start = ctx.currentTime + tone.delay;
            const end = start + tone.duration;
            gain.gain.setValueAtTime(0, start);
            gain.gain.linearRampToValueAtTime(tone.vol, start + 0.005);
            gain.gain.exponentialRampToValueAtTime(tone.vol * 0.5, start + 0.1);
            gain.gain.exponentialRampToValueAtTime(0.001, end);
            
            osc.start(start);
            osc.stop(end);
        });
    } catch(e) {}
}

function speakEnglish(text) {
    if (!('speechSynthesis' in window)) return;
    window.speechSynthesis.cancel();
    const voices = window.speechSynthesis.getVoices();
    const girlVoiceNames = ['Google UK English Female', 'Google US English', 'Microsoft Zira - English (United States)', 'Samantha'];
    let selectedVoice = null;
    for (let name of girlVoiceNames) {
        selectedVoice = voices.find(v => v.name.includes(name));
        if (selectedVoice) break;
    }
    if (!selectedVoice) selectedVoice = voices.find(v => v.lang === 'en-US');
    
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = 'en-US';
    if (selectedVoice) utterance.voice = selectedVoice;
    utterance.pitch = 1.4;
    utterance.rate = 0.95;
    utterance.volume = 1.0;
    window.speechSynthesis.speak(utterance);
}

if ('speechSynthesis' in window) {
    window.speechSynthesis.onvoiceschanged = function() { window.speechSynthesis.getVoices(); };
    window.speechSynthesis.getVoices();
}

// SHOW ORDER DETAILS
function showOrderDetails(orderId) {
    document.getElementById('detailsItemsBody').innerHTML = 
        '<tr><td colspan="4" class="text-center text-muted"><i class="fa-solid fa-spinner fa-spin me-2"></i>Loading...</td></tr>';
    
    fetch('get_order_details.php?orderId=' + orderId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                Swal.fire('Error', data.message || 'Cannot load order details', 'error');
                return;
            }
            
            const order = data.order;
            const items = data.items;
            
            document.getElementById('detailsQueueNum').textContent = order.queue_number || 'Q-' + orderId;
            document.getElementById('detailsCustomer').textContent = order.username || 'Guest';
            document.getElementById('detailsOrderType').textContent = (order.orderType || 'dine_in').replace('_', ' ').toUpperCase();
            document.getElementById('detailsPickupTime').textContent = order.pickupTime || '-';
            
            const statusBadge = document.getElementById('detailsStatus');
            statusBadge.textContent = order.status.toUpperCase();
            const st = order.status.toLowerCase();
            let badgeClass = 'bg-secondary';
            if (st === 'ordered' || st === 'cooking') badgeClass = 'bg-info text-dark';
            else if (st === 'pickup') badgeClass = 'bg-primary';
            else if (st === 'completed') badgeClass = 'bg-success';
            else if (st === 'rejected') badgeClass = 'bg-danger';
            else if (st === 'partial_rejected') badgeClass = 'bg-warning text-dark';
            statusBadge.className = 'badge ' + badgeClass;
            
            // ✅ Items Table with Rejected Marking
            let itemsHtml = '';
            let totalPoints = 0;
            let acceptedTotal = 0;
            let rejectedTotal = 0;
            
            if (items && items.length > 0) {
                items.forEach(item => {
                    const itemTotal = item.price * item.quantity;
                    totalPoints += itemTotal;
                    
                    if (item.is_rejected) {
                        // ✅ REJECTED ITEM - Red Line Through + Badge
                        rejectedTotal += itemTotal;
                        itemsHtml += `
                            <tr style="opacity: 0.7; background: #fff5f5;">
                                <td>
                                    <span style="text-decoration: line-through; text-decoration-color: #dc3545; text-decoration-thickness: 2px; color: #dc3545;">
                                        <i class="fa-solid fa-circle-xmark me-1"></i>
                                        ${item.itemName}
                                    </span>
                                    <span class="badge bg-danger ms-2" style="font-size: 0.6rem;">REJECTED</span>
                                </td>
                                <td class="text-center">
                                    <span style="text-decoration: line-through; text-decoration-color: #dc3545; color: #dc3545;">${item.quantity}</span>
                                </td>
                                <td class="text-end">
                                    <span style="text-decoration: line-through; text-decoration-color: #dc3545; color: #dc3545;">${Number(item.price).toLocaleString()} pts</span>
                                </td>
                                <td class="text-end">
                                    <span class="fw-bold" style="text-decoration: line-through; text-decoration-color: #dc3545; color: #dc3545;">${Number(itemTotal).toLocaleString()} pts</span>
                                </td>
                            </tr>
                        `;
                    } else {
                        // ✅ ACCEPTED ITEM - Normal
                        acceptedTotal += itemTotal;
                        itemsHtml += `
                            <tr>
                                <td>
                                    <i class="fa-solid fa-circle-check text-success me-1"></i>
                                    ${item.itemName}
                                </td>
                                <td class="text-center">${item.quantity}</td>
                                <td class="text-end">${Number(item.price).toLocaleString()} pts</td>
                                <td class="text-end fw-bold">${Number(itemTotal).toLocaleString()} pts</td>
                            </tr>
                        `;
                    }
                });
                
                // ✅ Add Summary Row if there are rejected items
                if (rejectedTotal > 0) {
                    itemsHtml += `
                        <tr style="background: #f8f9fa; border-top: 2px solid #dee2e6;">
                            <td colspan="3" class="text-end fw-bold text-success" style="font-size: 0.85rem;">
                                <i class="fa-solid fa-circle-check me-1"></i>Accepted Total:
                            </td>
                            <td class="text-end fw-bold text-success">${Number(acceptedTotal).toLocaleString()} pts</td>
                        </tr>
                        <tr style="background: #fff5f5;">
                            <td colspan="3" class="text-end fw-bold text-danger" style="font-size: 0.85rem;">
                                <i class="fa-solid fa-circle-xmark me-1"></i>Rejected Total:
                            </td>
                            <td class="text-end fw-bold text-danger" style="text-decoration: line-through; text-decoration-color: #dc3545;">${Number(rejectedTotal).toLocaleString()} pts</td>
                        </tr>
                        <tr style="background: #f0fdf4;">
                            <td colspan="3" class="text-end fw-bold text-dark" style="font-size: 0.9rem;">
                                <i class="fa-solid fa-coins text-warning me-1"></i>Actual Total:
                            </td>
                            <td class="text-end fw-bold text-dark" style="font-size: 0.95rem;">${Number(acceptedTotal).toLocaleString()} pts</td>
                        </tr>
                    `;
                }
            } else {
                itemsHtml = '<tr><td colspan="4" class="text-center text-muted">No items</td></tr>';
            }
            
            document.getElementById('detailsItemsBody').innerHTML = itemsHtml;
            
            if (order.specialRequest && order.specialRequest.trim() !== '') {
                document.getElementById('detailsSpecialRequest').textContent = order.specialRequest;
                document.getElementById('detailsSpecialRequestBox').style.display = 'block';
            } else {
                document.getElementById('detailsSpecialRequestBox').style.display = 'none';
            }
            
            if (order.orderType === 'delivery' && order.deliveryAddress) {
                document.getElementById('detailsDeliveryAddress').textContent = order.deliveryAddress;
                document.getElementById('detailsDeliveryFee').textContent = '+' + Number(order.deliveryFee).toLocaleString() + ' pts';
                document.getElementById('detailsDeliveryBox').style.display = 'block';
            } else {
                document.getElementById('detailsDeliveryBox').style.display = 'none';
            }
            
            if ((st === 'rejected' || st === 'partial_rejected') && order.rejectionReason) {
                document.getElementById('detailsRejectionReason').textContent = order.rejectionReason;
                
                if (order.rejected_items && order.rejected_items !== 'all') {
                    document.getElementById('detailsRejectedItems').textContent = order.rejected_items;
                    document.getElementById('detailsRejectedItemsBox').style.display = 'flex';
                } else {
                    document.getElementById('detailsRejectedItemsBox').style.display = 'none';
                }
                
                document.getElementById('detailsRejectionBox').style.display = 'block';
            } else {
                document.getElementById('detailsRejectionBox').style.display = 'none';
            }
            
            document.getElementById('detailsTotalPoints').textContent = 
                Number(order.points_used).toLocaleString() + ' Points';
            
            document.getElementById('detailsCreatedAt').textContent = order.createdAt || '-';
            
            new bootstrap.Modal(document.getElementById('orderDetailsModal')).show();
        })
        .catch(err => {
            console.error('Error:', err);
            Swal.fire('Error', 'Cannot load order details', 'error');
        });
}

// AUTO REFRESH + NEW ORDER DETECTION
let isRefreshing = false;

function getKnownOrderIds() {
    try {
        const stored = localStorage.getItem('knownOrderIds');
        return stored ? JSON.parse(stored) : [];
    } catch(e) { return []; }
}

function saveKnownOrderIds(ids) {
    try { localStorage.setItem('knownOrderIds', JSON.stringify(ids)); } catch(e) {}
}

function initializeOrderIds(callback) {
    fetch('admin.php?ajax=1')
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const ids = Array.from(doc.querySelectorAll('#ordersTableBody tr[data-order-row]')).map(tr => tr.getAttribute('data-order-row'));
            saveKnownOrderIds(ids);
            if (callback) callback();
        })
        .catch(err => { if (callback) callback(); });
}

document.addEventListener('DOMContentLoaded', function() {
    initializeOrderIds(function() {
        setInterval(checkAdminUpdates, 3000);
    });
});

document.addEventListener('visibilitychange', function() {
    if (!document.hidden) checkAdminUpdates();
});

function checkAdminUpdates() {
    if (isRefreshing) return;
    isRefreshing = true;

    fetch('admin.php?ajax=1')
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            
            doc.querySelectorAll('[data-points-stat]').forEach(function(n) {
                const name = n.getAttribute('data-points-stat');
                const o = document.querySelector(`[data-points-stat="${name}"]`);
                if (o && o.textContent.trim() !== n.textContent.trim()) {
                    o.textContent = n.textContent;
                    o.style.transition = 'all 0.3s';
                    o.style.transform = 'scale(1.15)';
                    o.style.color = '#ffc107';
                    setTimeout(() => { o.style.transform = 'scale(1)'; setTimeout(() => o.style.color = '', 300); }, 200);
                }
            });
            
            doc.querySelectorAll('[data-stat]').forEach(function(n) {
                const name = n.getAttribute('data-stat');
                const o = document.querySelector(`[data-stat="${name}"]`);
                if (o && o.textContent.trim() !== n.textContent.trim()) {
                    o.textContent = n.textContent;
                    o.style.transition = 'all 0.3s';
                    o.style.transform = 'scale(1.1)';
                    setTimeout(() => o.style.transform = 'scale(1)', 300);
                }
            });
            
            const newRows = doc.querySelectorAll('#ordersTableBody tr[data-order-row]');
            const newOrderIds = Array.from(newRows).map(tr => tr.getAttribute('data-order-row'));
            const knownOrderIds = getKnownOrderIds();
            const addedOrders = newOrderIds.filter(id => !knownOrderIds.includes(id));
            
            if (addedOrders.length > 0 && knownOrderIds.length > 0) {
                playNotificationSound(addedOrders.length);
                showNewOrderToast(addedOrders.length);
                saveKnownOrderIds(newOrderIds);
                
                const oldBody = document.getElementById('ordersTableBody');
                oldBody.innerHTML = doc.querySelector('#ordersTableBody').innerHTML;
                
                const card = oldBody.closest('.card');
                card.classList.add('new-order-flash');
                setTimeout(() => card.classList.remove('new-order-flash'), 1500);
                
                addedOrders.forEach(id => {
                    const row = document.querySelector(`tr[data-order-row="${id}"]`);
                    if (row) row.classList.add('new-order-row');
                });
            } else {
                if (newOrderIds.length !== knownOrderIds.length) saveKnownOrderIds(newOrderIds);
                doc.querySelectorAll('tr[data-order-row]').forEach(function(nR) {
                    const id = nR.getAttribute('data-order-row');
                    const oR = document.querySelector(`tr[data-order-row="${id}"]`);
                    if (!oR) return;
                    const nS = nR.querySelector('[data-status-badge]');
                    const oS = oR.querySelector('[data-status-badge]');
                    if (nS && oS && nS.textContent.trim() !== oS.textContent.trim()) {
                        oS.textContent = nS.textContent;
                        oS.className = nS.className;
                        oS.style.transition = 'all 0.3s';
                        oS.style.transform = 'scale(1.2)';
                        setTimeout(() => oS.style.transform = 'scale(1)', 300);
                    }
                });
            }
            
            const ind = document.getElementById('refreshIndicator');
            if (ind) ind.textContent = '• ' + new Date().toLocaleTimeString();
        })
        .catch(err => {})
        .finally(() => { isRefreshing = false; });
}

function showNewOrderToast(count) {
    Swal.fire({
        icon: 'info',
        title: '🔔 New Order!',
        html: `<strong>${count}</strong> new order${count > 1 ? 's' : ''} received.`,
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 4000,
        timerProgressBar: true,
        background: '#1EAFBD',
        color: '#FFFFFF'
    });
}

// REJECT MODAL
var currentOrderId = 0;

function showRejectModal(orderId, itemsWithId) {
    currentOrderId = orderId;
    var container = document.getElementById('rejectItemsList');
    container.innerHTML = '';
    document.getElementById('rejectReasonInput').value = '';
    
    if (!itemsWithId || itemsWithId === 'null' || itemsWithId === '') {
        var allDiv = document.createElement('div');
        allDiv.className = 'form-check mb-2';
        allDiv.innerHTML = `<input class="form-check-input" type="checkbox" id="selectAllItems" checked onchange="toggleAllItems()"><label class="form-check-label fw-bold" for="selectAllItems">အားလုံး ပယ်ချမည်</label>`;
        container.appendChild(allDiv);
    } else {
        var parts = itemsWithId.split('|');
        var itemsList = [];
        parts.forEach(function(part) {
            var d = part.split(':');
            if (d.length == 2) itemsList.push({id: d[0], name: d[1]});
        });
        
        if (itemsList.length > 0) {
            var allDiv = document.createElement('div');
            allDiv.className = 'form-check mb-2 border-bottom pb-2';
            allDiv.innerHTML = `<input class="form-check-input" type="checkbox" id="selectAllItems" checked onchange="toggleAllItems()"><label class="form-check-label fw-bold" for="selectAllItems">Select All</label>`;
            container.appendChild(allDiv);
            
            itemsList.forEach(function(item) {
                var div = document.createElement('div');
                div.className = 'form-check ms-3';
                div.innerHTML = `<input class="form-check-input item-checkbox" type="checkbox" value="${item.id}" checked><label class="form-check-label">${item.name}</label>`;
                container.appendChild(div);
            });
        }
    }
    
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function toggleAllItems() {
    var checked = document.getElementById('selectAllItems').checked;
    document.querySelectorAll('.item-checkbox').forEach(cb => cb.checked = checked);
}

function confirmReject() {
    var reason = document.getElementById('rejectReasonInput').value.trim();
    if (!reason) { 
        Swal.fire({icon: 'warning', title: 'Reason Required', text: 'ပယ်ချရသည့် အကြောင်းရင်း ထည့်ပါ', confirmButtonColor: '#1EAFBD'}); 
        return; 
    }
    
    var selectedItems = [];
    document.querySelectorAll('.item-checkbox:checked').forEach(cb => selectedItems.push(cb.value));
    
    var rejectedItems = 'all';
    if (selectedItems.length > 0) {
        rejectedItems = selectedItems.join(',');
    }
    
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'admin.php';
    
    var inputs = [
        {name: 'action', value: 'update_status'},
        {name: 'orderId', value: currentOrderId},
        {name: 'status', value: 'rejected'},
        {name: 'rejectionReason', value: reason},
        {name: 'rejected_items', value: rejectedItems}
    ];
    
    inputs.forEach(function(item) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = item.name;
        input.value = item.value;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}
</script>
</body>
</html>

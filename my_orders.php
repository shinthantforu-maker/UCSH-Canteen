<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: guest.php");
    exit();
}

$userId = $_SESSION['user_id'];

// =============================================
// HANDLE CANCEL ORDER
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    header('Content-Type: application/json');
    
    $orderId = intval($_POST['orderId']);
    
    $checkStmt = $conn->prepare("SELECT points_used, status FROM orders WHERE orderId = ? AND userId = ?");
    $checkStmt->bind_param("ii", $orderId, $userId);
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
    
    $refundStmt = $conn->prepare("UPDATE users SET points = points + ? WHERE userId = ?");
    $refundStmt->bind_param("ii", $orderData['points_used'], $userId);
    $refundStmt->execute();
    $refundStmt->close();
    
    $cancelStmt = $conn->prepare("UPDATE orders SET status = 'rejected', rejectionReason = 'User cancelled' WHERE orderId = ?");
    $cancelStmt->bind_param("i", $orderId);
    $cancelStmt->execute();
    $cancelStmt->close();
    
    echo json_encode(['success' => true, 'message' => 'Order cancelled']);
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
    
    $stmt = $conn->prepare("INSERT INTO ratings (userId, itemId, orderId, rating) 
                            VALUES (?, ?, ?, ?) 
                            ON DUPLICATE KEY UPDATE rating = ?");
    $stmt->bind_param("iiiii", $userId, $itemId, $orderId, $rating, $rating);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed']);
    }
    $stmt->close();
    exit();
}

// =============================================
// FETCH ORDERS
// =============================================
$ordersQuery = "SELECT o.*, 
                (SELECT GROUP_CONCAT(CONCAT(m.itemName, ' (x', oi.quantity, ')') SEPARATOR ', ') 
                 FROM order_items oi 
                 JOIN menu_items m ON oi.itemId = m.itemId 
                 WHERE oi.orderId = o.orderId) as items 
                FROM orders o 
                WHERE o.userId = ? 
                ORDER BY o.orderId DESC";
$stmt = $conn->prepare($ordersQuery);
$stmt->bind_param("i", $userId);
$stmt->execute();
$ordersResult = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - UCSH Canteen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Myanmar:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --brand-color: #1EAFBD; --brand-hover: #17939F; --brand-light: #EBF8F9; }
        body { font-family: 'Poppins', 'Noto Sans Myanmar', sans-serif; background-color: #F8FAFC; }
        .text-brand { color: var(--brand-color) !important; }
        .btn-brand { background-color: var(--brand-color); color: white; border: none; }
        .btn-brand:hover { background-color: var(--brand-hover); color: white; }
        .order-card { background: white; border-radius: 16px; padding: 20px; margin-bottom: 16px; border: 1px solid #E2E8F0; box-shadow: 0 2px 4px rgba(0,0,0,0.02); transition: all 0.3s; }
        .order-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .timeline { display: flex; justify-content: space-between; margin: 20px 0; position: relative; }
        .timeline::before { content: ''; position: absolute; top: 15px; left: 10%; right: 10%; height: 3px; background: #E2E8F0; z-index: 0; }
        .timeline-step { position: relative; z-index: 1; text-align: center; flex: 1; }
        .timeline-icon { width: 32px; height: 32px; border-radius: 50%; background: #E2E8F0; color: #94A3B8; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; }
        .timeline-step.active .timeline-icon { background: var(--brand-color); color: white; }
        .timeline-step.completed .timeline-icon { background: #28a745; color: white; }
        .timeline-label { font-size: 0.7rem; color: #64748B; margin-top: 4px; }
        .star-rating i { font-size: 20px; cursor: pointer; transition: all 0.2s; }
        .star-rating i:hover { transform: scale(1.2); }
    </style>
</head>
<body class="pb-5">

<?php include 'nav.php'; ?>

<div class="container my-4" style="max-width: 900px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold text-dark"><i class="fa-solid fa-receipt text-brand me-2"></i>My Orders</h4>
        <span class="badge bg-white text-secondary border px-3 py-2 rounded-pill">
            <i class="fa-solid fa-sync me-1" id="refreshIcon"></i> Auto-refresh: ON
        </span>
    </div>

    <?php if ($ordersResult && $ordersResult->num_rows > 0): ?>
        <?php while ($ord = $ordersResult->fetch_assoc()): 
            $st = strtolower($ord['status']);
            $badgeClass = 'bg-secondary';
            if ($st === 'ordered') $badgeClass = 'bg-info text-dark';
            elseif ($st === 'cooking') $badgeClass = 'bg-primary';
            elseif ($st === 'pickup') $badgeClass = 'bg-warning text-dark';
            elseif ($st === 'completed') $badgeClass = 'bg-success';
            elseif ($st === 'rejected') $badgeClass = 'bg-danger';
            elseif ($st === 'partial_rejected') $badgeClass = 'bg-warning text-dark';
        ?>
            <div class="order-card" data-order-card="<?= $ord['orderId'] ?>">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h6 class="fw-bold mb-1">
                            <span class="badge bg-dark text-white"><?= htmlspecialchars($ord['queue_number']) ?></span>
                        </h6>
                        <small class="text-muted"><?= date('d M Y, h:i A', strtotime($ord['createdAt'])) ?></small>
                    </div>
                    <span class="badge <?= $badgeClass ?> text-uppercase" data-order-status="<?= $ord['orderId'] ?>">
                        <?= $ord['status'] ?>
                    </span>
                </div>

                <!-- Timeline (For non-rejected orders) -->
                <?php if ($st !== 'rejected' && $st !== 'partial_rejected'): ?>
                    <div class="timeline">
                        <div class="timeline-step <?= in_array($st, ['ordered','cooking','pickup','completed']) ? 'completed' : '' ?>">
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

                <div class="mb-2">
                    <small class="text-muted d-block mb-1"><i class="fa-solid fa-utensils me-1"></i>Items:</small>
                    <div class="fw-medium"><?= htmlspecialchars($ord['items'] ?? 'No items') ?></div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <small class="text-muted">Points Used:</small>
                        <div class="fw-bold text-warning"><?= number_format($ord['points_used']) ?></div>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Pickup Time:</small>
                        <div class="fw-bold"><?= htmlspecialchars($ord['pickupTime']) ?></div>
                    </div>
                </div>

                <?php if (!empty($ord['specialRequest'])): ?>
                    <div class="p-2 bg-light rounded-3 mb-3">
                        <small class="text-muted"><i class="fa-solid fa-comment me-1"></i><?= htmlspecialchars($ord['specialRequest']) ?></small>
                    </div>
                <?php endif; ?>

                <?php if ($st === 'rejected' && !empty($ord['rejectionReason'])): ?>
                    <div class="p-2 bg-danger-subtle rounded-3 mb-3">
                        <small class="text-danger fw-bold"><i class="fa-solid fa-ban me-1"></i>Rejected: <?= htmlspecialchars($ord['rejectionReason']) ?></small>
                    </div>
                <?php endif; ?>

                <!-- Cancel Button -->
                <?php if ($st === 'ordered'): ?>
                    <button class="btn btn-sm btn-outline-danger rounded-3" onclick="cancelOrder(<?= $ord['orderId'] ?>)">
                        <i class="fa-solid fa-xmark me-1"></i>Cancel Order
                    </button>
                <?php endif; ?>

                <!-- Rating Section (Only for completed) -->
                <?php if ($st === 'completed'): 
                    $itemsStmt = $conn->prepare("
                        SELECT oi.itemId, m.itemName, 
                               (SELECT rating FROM ratings WHERE userId = ? AND orderId = ? AND itemId = oi.itemId) as my_rating
                        FROM order_items oi 
                        JOIN menu_items m ON oi.itemId = m.itemId 
                        WHERE oi.orderId = ?
                    ");
                    $itemsStmt->bind_param("iii", $userId, $ord['orderId'], $ord['orderId']);
                    $itemsStmt->execute();
                    $itemsResult = $itemsStmt->get_result();
                    if ($itemsResult->num_rows > 0):
                ?>
                    <div class="mt-3 p-3 bg-light rounded-3">
                        <small class="fw-bold d-block mb-2">
                            <i class="fa-solid fa-star text-warning me-1"></i>Rate the Items:
                        </small>
                        <?php while ($item = $itemsResult->fetch_assoc()): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <small class="fw-medium"><?= htmlspecialchars($item['itemName']) ?></small>
                                <div class="star-rating" data-item-id="<?= $item['itemId'] ?>" data-order-id="<?= $ord['orderId'] ?>">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="<?= ($item['my_rating'] && $i <= $item['my_rating']) ? 'fa-solid text-warning' : 'fa-regular text-secondary' ?> fa-star" 
                                           data-star="<?= $i ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php endif; $itemsStmt->close(); endif; ?>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="text-center py-5 bg-white rounded-4">
            <i class="fa-solid fa-receipt fa-3x text-muted mb-3"></i>
            <h6 class="text-muted">No orders yet</h6>
            <a href="index.php" class="btn btn-brand mt-3 px-4">Order Now</a>
        </div>
    <?php endif; ?>
</div>

<script>
// =============================================
// CANCEL ORDER
// =============================================
function cancelOrder(orderId) {
    Swal.fire({
        title: 'Cancel Order?',
        text: 'Points တွေ ပြန်အမ်းပေးပါမယ်။',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Cancel',
        cancelButtonText: 'No'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('my_orders.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=cancel_order&orderId=' + orderId
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Cancelled!', 'Points ပြန်ရပါပြီ။', 'success')
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
        }
    });
}

// =============================================
// STAR RATING
// =============================================
document.querySelectorAll('.star-rating').forEach(function(container) {
    container.querySelectorAll('i').forEach(function(star) {
        star.addEventListener('click', function() {
            const itemId = container.getAttribute('data-item-id');
            const orderId = container.getAttribute('data-order-id');
            const rating = this.getAttribute('data-star');
            
            fetch('my_orders.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=submit_rating&itemId=${itemId}&orderId=${orderId}&rating=${rating}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    container.querySelectorAll('i').forEach(function(s) {
                        const sVal = s.getAttribute('data-star');
                        if (sVal <= rating) {
                            s.classList.remove('fa-regular', 'text-secondary');
                            s.classList.add('fa-solid', 'text-warning');
                        } else {
                            s.classList.remove('fa-solid', 'text-warning');
                            s.classList.add('fa-regular', 'text-secondary');
                        }
                    });
                }
            });
        });
    });
});

// =============================================
// AUTO REFRESH (10 seconds)
// =============================================
setInterval(function() {
    const icon = document.getElementById('refreshIcon');
    icon.style.transition = 'transform 0.5s';
    icon.style.transform = 'rotate(360deg)';
    setTimeout(() => icon.style.transform = 'rotate(0deg)', 500);

    fetch(window.location.href)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            // Update Status Badges
            doc.querySelectorAll('[data-order-status]').forEach(function(newEl) {
                const orderId = newEl.getAttribute('data-order-status');
                const oldEl = document.querySelector(`[data-order-status="${orderId}"]`);
                if (oldEl && oldEl.textContent.trim() !== newEl.textContent.trim()) {
                    oldEl.textContent = newEl.textContent;
                    oldEl.className = newEl.className;
                    oldEl.style.transition = 'background 0.5s';
                    oldEl.style.transform = 'scale(1.1)';
                    setTimeout(() => oldEl.style.transform = 'scale(1)', 500);
                }
            });
        })
        .catch(err => console.log('Auto-refresh:', err));
}, 10000);
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../db.php';

if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$message = "";
$message_type = "";

// =============================================
// ORDER STATUS UPDATE
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $orderId = intval($_POST['orderId']);

    if ($_POST['action'] === 'update_status') {
        $newStatus = trim($_POST['status']);
        $rejectionReason = trim($_POST['rejectionReason'] ?? '');

        $orderStmt = $conn->prepare("SELECT userId, points_used, status FROM orders WHERE orderId = ?");
        $orderStmt->bind_param("i", $orderId);
        $orderStmt->execute();
        $orderResult = $orderStmt->get_result();
        $orderData = $orderResult->fetch_assoc();
        $orderStmt->close();

        $currentStatus = $orderData['status'] ?? '';
        $userId = $orderData['userId'] ?? 0;
        $pointsUsed = $orderData['points_used'] ?? 0;

        if ($newStatus === 'rejected' && $currentStatus !== 'rejected' && $userId > 0 && $pointsUsed > 0) {
            $refundStmt = $conn->prepare("UPDATE users SET points = points + ? WHERE userId = ?");
            $refundStmt->bind_param("ii", $pointsUsed, $userId);
            $refundStmt->execute();
            $refundStmt->close();
        }

        $stmt = $conn->prepare("UPDATE orders SET status = ?, rejectionReason = ? WHERE orderId = ?");
        $stmt->bind_param("ssi", $newStatus, $rejectionReason, $orderId);
        
        if ($stmt->execute()) {
            $message = "Order #{$orderId} ၏ အခြေအနေကို အောင်မြင်စွာ ပြောင်းလဲပြီးပါပြီ။";
            if ($newStatus === 'rejected' && $pointsUsed > 0) {
                $message .= " User အား " . number_format($pointsUsed) . " Points ပြန်အပ်ပြီးပါပြီ။";
            }
            $message_type = "success";
        } else {
            $message = "အခြေအနေပြောင်းလဲရာတွင် အမှားအယွင်းရှိနေပါသည်။";
            $message_type = "danger";
        }
        $stmt->close();
    }
}

// Fetch Orders
$orders_query = "SELECT o.*, u.username, 
                  (SELECT GROUP_CONCAT(CONCAT(m.itemName, ' (', oi.quantity, ')') SEPARATOR ', ') 
                   FROM order_items oi 
                   JOIN menu_items m ON oi.itemId = m.itemId 
                   WHERE oi.orderId = o.orderId) as items 
                 FROM orders o 
                 LEFT JOIN users u ON o.userId = u.userId 
                 ORDER BY o.orderId DESC";
$orders_result = $conn->query($orders_query);
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UCSH Admin - Orders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Myanmar:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --brand-color: #1EAFBD; --brand-hover: #17939F; --brand-light: #EBF8F9; }
        body { font-family: 'Poppins', 'Noto Sans Myanmar', sans-serif; background-color: #F8FAFC; }
        .sidebar { width: 260px; background: #FFFFFF; min-height: 100vh; border-right: 1px solid #E2E8F0; }
        .nav-link-custom { color: #64748B; padding: 12px 20px; border-radius: 10px; font-weight: 500; display: flex; align-items: center; gap: 12px; text-decoration: none; margin-bottom: 5px; transition: all 0.2s; }
        .nav-link-custom:hover, .nav-link-custom.active { background-color: var(--brand-light); color: var(--brand-color); }
        .text-brand { color: var(--brand-color) !important; }
        .bg-brand { background-color: var(--brand-color) !important; }
        .status-select { min-width: 120px; }
        .order-items-cell { max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    </style>
</head>
<body>

<div class="d-flex">
    <div class="sidebar p-3 d-flex flex-column" id="sidebar">
        <a href="admin.php" class="d-flex align-items-center gap-2 text-decoration-none text-brand fw-bold fs-4 mb-4 px-2">
            <i class="fa-solid fa-utensils"></i> UCSH Admin
        </a>
        <div class="nav flex-column mb-auto">
            <a href="admin.php" class="nav-link-custom"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
            <a href="menu.php" class="nav-link-custom"><i class="fa-solid fa-bowl-food"></i> Manage Menu</a>
            <a href="orders.php" class="nav-link-custom active"><i class="fa-solid fa-list-check"></i> Orders</a>
            <a href="users.php" class="nav-link-custom"><i class="fa-solid fa-users"></i> Users</a>
            <a href="announcements.php" class="nav-link-custom"><i class="fa-solid fa-bullhorn"></i> Announcements</a>
        </div>
        <hr class="text-muted">
        <div><a href="../logout.php" class="nav-link-custom text-danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></div>
    </div>

    <div class="flex-grow-1 p-3 p-md-4 overflow-hidden">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold m-0 text-dark">Order Management</h4>
            <span class="text-muted small">Total Orders: <?= $orders_result ? $orders_result->num_rows : 0 ?></span>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show rounded-3" role="alert">
                <i class="fa-solid fa-circle-info me-2"></i><?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold m-0 text-dark"><i class="fa-solid fa-receipt text-brand me-2"></i>All Orders</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="border-0 text-secondary small fw-bold">#</th>
                            <th class="border-0 text-secondary small fw-bold">Queue</th>
                            <th class="border-0 text-secondary small fw-bold">Customer</th>
                            <th class="border-0 text-secondary small fw-bold">Order Items</th>
                            <th class="border-0 text-secondary small fw-bold">Type</th>
                            <th class="border-0 text-secondary small fw-bold">Points</th>
                            <th class="border-0 text-secondary small fw-bold">Status</th>
                            <th class="border-0 text-secondary small fw-bold">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($orders_result && $orders_result->num_rows > 0): ?>
                            <?php $serial = 1; while ($ord = $orders_result->fetch_assoc()): 
                                $st = strtolower($ord['status']);
                                $badgeClass = 'bg-secondary';
                                // ✅ CHANGED: ordered + cooking → bg-info, no pending
                                if ($st === 'ordered' || $st === 'cooking') $badgeClass = 'bg-info text-dark';
                                elseif ($st === 'pickup') $badgeClass = 'bg-primary';
                                elseif ($st === 'completed') $badgeClass = 'bg-success';
                                elseif ($st === 'rejected') $badgeClass = 'bg-danger';
                                
                                $queueNum = $ord['queue_number'] ?? 'Q-' . str_pad($ord['orderId'], 3, '0', STR_PAD_LEFT);
                                $items = $ord['items'] ?? 'No items';
                            ?>
                                <tr>
                                    <td class="fw-bold text-dark"><?= $serial++ ?></td>
                                    <td><span class="badge bg-dark text-white fw-bold"><?= $queueNum ?></span></td>
                                    <td><?= htmlspecialchars($ord['username'] ?? 'Guest') ?></td>
                                    <td class="order-items-cell" title="<?= htmlspecialchars($items) ?>"><?= htmlspecialchars($items) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= strtoupper($ord['orderType']) ?></span></td>
                                    <td><span class="badge bg-warning text-dark"><?= number_format($ord['points_used'] ?? 0) ?></span></td>
                                    <td>
                                        <span class="badge <?= $badgeClass ?> text-uppercase"><?= $ord['status'] ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 align-items-center">
                                            <form method="POST" action="orders.php" class="d-inline" id="orderForm<?= $ord['orderId'] ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="orderId" value="<?= $ord['orderId'] ?>">
                                                <input type="hidden" name="rejectionReason" id="rejectionReason<?= $ord['orderId'] ?>" value="">
                                                <select name="status" class="form-select form-select-sm status-select" 
                                                    onchange="if(this.value==='rejected'){ 
                                                        Swal.fire({
                                                            title: 'ပယ်ချသည့် အကြောင်းပြချက် (Rejection Reason)',
                                                            input: 'textarea',
                                                            inputPlaceholder: 'ဥပမာ - ပစ္စည်းမကျန်တော့ပါ...',
                                                            showCancelButton: true,
                                                            confirmButtonText: 'ပယ်ချမည်',
                                                            cancelButtonText: 'မလုပ်တော့ပါ',
                                                            confirmButtonColor: '#dc3545',
                                                            inputValidator: (value) => {
                                                                if (!value) {
                                                                    return 'ကျေးဇူးပြု၍ အကြောင်းပြချက် ရိုက်ထည့်ပါ!';
                                                                }
                                                            }
                                                        }).then((result) => {
                                                            if (result.isConfirmed) {
                                                                document.getElementById('rejectionReason<?= $ord['orderId'] ?>').value = result.value;
                                                                document.getElementById('orderForm<?= $ord['orderId'] ?>').submit();
                                                            } else {
                                                                this.value = '<?= $st ?>';
                                                            }
                                                        });
                                                    } else { 
                                                        this.form.submit(); 
                                                    }">
                                                    <!-- ✅ CHANGED: ordered instead of confirmed -->
                                                    <option value="ordered" <?= $st === 'ordered' ? 'selected' : '' ?>>Ordered</option>
                                                    <option value="cooking" <?= $st === 'cooking' ? 'selected' : '' ?>>Cooking</option>
                                                    <option value="pickup" <?= $st === 'pickup' ? 'selected' : '' ?>>Pickup</option>
                                                    <option value="completed" <?= $st === 'completed' ? 'selected' : '' ?>>Completed</option>
                                                    <option value="rejected" <?= $st === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                                </select>
                                            </form>
                                            <button class="btn btn-sm btn-light border text-brand rounded-3" data-bs-toggle="modal" data-bs-target="#detailModal<?= $ord['orderId'] ?>" title="See Details">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Detail Modal -->
                                <div class="modal fade" id="detailModal<?= $ord['orderId'] ?>" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content rounded-4 border-0">
                                            <div class="modal-header bg-light border-0">
                                                <h6 class="fw-bold m-0"><i class="fa-solid fa-receipt text-brand me-2"></i>Order Details #<?= $ord['orderId'] ?></h6>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body p-4">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span class="text-muted">Queue:</span>
                                                    <span class="fw-bold"><?= $queueNum ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span class="text-muted">Customer:</span>
                                                    <span class="fw-bold"><?= htmlspecialchars($ord['username'] ?? 'Guest') ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span class="text-muted">Order Items:</span>
                                                    <span class="fw-bold"><?= htmlspecialchars($items) ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span class="text-muted">Type:</span>
                                                    <span class="fw-bold"><?= strtoupper($ord['orderType']) ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span class="text-muted">Pickup Time:</span>
                                                    <span class="fw-bold"><?= $ord['pickupTime'] ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span class="text-muted">Points Used:</span>
                                                    <span class="fw-bold text-warning"><?= number_format($ord['points_used'] ?? 0) ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span class="text-muted">Total:</span>
                                                    <span class="fw-bold text-danger"><?= number_format($ord['totalAmount']) ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span class="text-muted">Status:</span>
                                                    <span class="badge <?= $badgeClass ?>"><?= $ord['status'] ?></span>
                                                </div>
                                                
                                                <div class="mt-3 pt-3 border-top">
                                                    <div class="d-flex justify-content-between">
                                                        <span class="text-muted">Special Request:</span>
                                                        <?php if (!empty($ord['specialRequest'])): ?>
                                                            <span class="fw-bold text-dark"><?= htmlspecialchars($ord['specialRequest']) ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted fst-italic">No special request</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                
                                                <?php if ($st === 'rejected' && !empty($ord['rejectionReason'])): ?>
                                                    <div class="mt-3 pt-3 border-top">
                                                        <div class="reject-reason-box">
                                                            <small class="text-danger fw-bold d-block mb-1">
                                                                <i class="fa-solid fa-circle-exclamation me-1"></i>Rejection Reason:
                                                            </small>
                                                            <div class="fw-medium text-dark"><?= htmlspecialchars($ord['rejectionReason']) ?></div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer border-0 bg-light">
                                                <button type="button" class="btn btn-secondary btn-sm rounded-3" data-bs-dismiss="modal">ပိတ်မည်</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">အော်ဒါများ မရှိသေးပါ။</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
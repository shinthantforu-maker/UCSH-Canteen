<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: guest.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$error_msg = "";

// =============================================
// Get User Points
// =============================================
$userStmt = $conn->prepare("SELECT points FROM users WHERE userId = ?");
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userData = $userStmt->get_result()->fetch_assoc();
$currentPoints = $userData['points'] ?? 0;
$userStmt->close();

// Cart Count
$cart_count = 0;
$cart_count_stmt = $conn->prepare("SELECT SUM(quantity) as total FROM cart WHERE userId = ?");
if ($cart_count_stmt) {
    $cart_count_stmt->bind_param("i", $user_id);
    $cart_count_stmt->execute();
    $cart_count_res = $cart_count_stmt->get_result()->fetch_assoc();
    $cart_count = $cart_count_res['total'] ?? 0;
    $cart_count_stmt->close();
}

// Like Count
$like_count = 0;
$like_stmt = $conn->prepare("SELECT COUNT(*) as total FROM liked_items WHERE userId = ?");
if ($like_stmt) {
    $like_stmt->bind_param("i", $user_id);
    $like_stmt->execute();
    $like_res = $like_stmt->get_result()->fetch_assoc();
    $like_count = $like_res['total'] ?? 0;
    $like_stmt->close();
}

// =============================================
// Handle Cart Actions
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_qty') {
        $cartId = intval($_POST['cartId']);
        $qty = max(1, intval($_POST['quantity']));
        
        // Get item + options
        $cartItemStmt = $conn->prepare("SELECT m.points, c.selected_options FROM cart c JOIN menu_items m ON c.itemId = m.itemId WHERE c.cartId = ? AND c.userId = ?");
        $cartItemStmt->bind_param("ii", $cartId, $user_id);
        $cartItemStmt->execute();
        $cartItemData = $cartItemStmt->get_result()->fetch_assoc();
        $cartItemStmt->close();
        
        if ($cartItemData) {
            $extra = calcExtraPoints($cartItemData['selected_options']);
            $unitPrice = (int)$cartItemData['points'] + $extra;
            $newTotalForThis = $unitPrice * $qty;
            
            // Calculate cart total (excluding this item's old qty)
            $oldQtyStmt = $conn->prepare("SELECT quantity FROM cart WHERE cartId = ?");
            $oldQtyStmt->bind_param("i", $cartId);
            $oldQtyStmt->execute();
            $oldQty = (int)$oldQtyStmt->get_result()->fetch_assoc()['quantity'];
            $oldQtyStmt->close();
            
            $cartAllStmt = $conn->prepare("SELECT c.quantity, c.selected_options, m.points FROM cart c JOIN menu_items m ON c.itemId = m.itemId WHERE c.userId = ?");
            $cartAllStmt->bind_param("i", $user_id);
            $cartAllStmt->execute();
            $cartAllRes = $cartAllStmt->get_result();
            $otherTotal = 0;
            while ($row = $cartAllRes->fetch_assoc()) {
                $rowExtra = calcExtraPoints($row['selected_options']);
                $rowUnit = (int)$row['points'] + $rowExtra;
                $otherTotal += $rowUnit * (int)$row['quantity'];
            }
            $cartAllStmt->close();
            
            // otherTotal includes this item's old value — subtract it, add new
            $oldThisValue = $unitPrice * $oldQty;
            $newCartTotal = $otherTotal - $oldThisValue + $newTotalForThis;
            
            if ($currentPoints < $newCartTotal) {
                $error_msg = "Point မလုံလောက်ပါ။ လိုအပ်ချက်: " . number_format($newCartTotal) . "၊ သင့်တွင်: " . number_format($currentPoints);
            } else {
                $stmt = $conn->prepare("UPDATE cart SET quantity = ? WHERE cartId = ? AND userId = ?");
                $stmt->bind_param("iii", $qty, $cartId, $user_id);
                $stmt->execute();
                $stmt->close();
                header("Location: cart.php");
                exit();
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        $cartId = intval($_POST['cartId']);
        $stmt = $conn->prepare("DELETE FROM cart WHERE cartId = ? AND userId = ?");
        $stmt->bind_param("ii", $cartId, $user_id);
        $stmt->execute();
        $stmt->close();
        header("Location: cart.php");
        exit();
    }
}

// =============================================
// Handle Order Submission
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    
    $orderType = $_POST['orderType'] ?? 'dine_in';
    $pickupTime = $_POST['pickupTime'] ?? '12:00 PM';
    $specialRequest = isset($_POST['specialRequest']) ? trim($_POST['specialRequest']) : '';

    // Refresh points
    $userStmt = $conn->prepare("SELECT points FROM users WHERE userId = ?");
    $userStmt->bind_param("i", $user_id);
    $userStmt->execute();
    $currentPoints = $userStmt->get_result()->fetch_assoc()['points'];
    $userStmt->close();

    // =============================================
    // Fetch cart with options
    // =============================================
    $cartRes = $conn->query("SELECT c.cartId, c.quantity, c.selected_options, m.points, m.itemId, m.itemName FROM cart c JOIN menu_items m ON c.itemId = m.itemId WHERE c.userId = $user_id");
    
    $grandTotal = 0;
    $itemsToOrder = [];
    $itemNames = [];

    while ($row = $cartRes->fetch_assoc()) {
        $extras = calcExtraPoints($row['selected_options']);
        $unitPrice = (int)$row['points'] + $extras;
        $lineTotal = $unitPrice * (int)$row['quantity'];
        $grandTotal += $lineTotal;
        
        $row['unit_price'] = $unitPrice;
        $row['line_total'] = $lineTotal;
        $itemsToOrder[] = $row;
        
        // Format name with options
        $formattedName = formatItemWithOptions($row['itemName'], $row['selected_options']);
        $itemNames[] = $formattedName . ' (x' . $row['quantity'] . ')';
    }

    // Delivery data
    $deliveryLocationId = intval($_POST['deliveryLocation'] ?? 0);
    $deliveryAddress = null;
    $deliveryFee = 0;
    $deliveryLat = null;
    $deliveryLng = null;
    $deliveryStatus = 'pending';

    if ($orderType === 'delivery' && $deliveryLocationId > 0) {
        $locStmt = $conn->prepare("SELECT * FROM delivery_locations WHERE id = ?");
        $locStmt->bind_param("i", $deliveryLocationId);
        $locStmt->execute();
        $locData = $locStmt->get_result()->fetch_assoc();
        $locStmt->close();
        
        if ($locData) {
            $deliveryAddress = $locData['locationName'];
            $deliveryFee = $locData['deliveryFee'];
            $deliveryLat = $locData['latitude'];
            $deliveryLng = $locData['longitude'];
            $deliveryStatus = 'preparing';
        }
    }

    $grandTotal = $grandTotal + $deliveryFee;

    if ($currentPoints < $grandTotal) {
        $error_msg = "Point မလုံလောက်ပါ။ လိုအပ်ချက်: " . number_format($grandTotal) . "၊ သင့်တွင်: " . number_format($currentPoints);
    } elseif ($grandTotal <= 0) {
        $error_msg = "Cart ထဲတွင် ပစ္စည်းမရှိပါ။";
    } else {
        
        $conn->begin_transaction();
        
        try {
            // Insert order
            $tempQueue = "Q000";
            $stmt = $conn->prepare("INSERT INTO orders (queue_number, userId, orderType, pickupTime, specialRequest, totalAmount, points_used, status, deliveryAddress, deliveryFee, deliveryLat, deliveryLng, deliveryStatus) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $userId = $user_id;
            $totalAmt = $grandTotal;
            $pointsUsed = $grandTotal;
            $status = 'ordered';
            
            $stmt->bind_param("sisssiissidds", 
                $tempQueue, $userId, $orderType, $pickupTime, $specialRequest, 
                $totalAmt, $pointsUsed, $status, 
                $deliveryAddress, $deliveryFee, $deliveryLat, $deliveryLng, $deliveryStatus
            );
            $stmt->execute();
            $orderId = $stmt->insert_id;
            $stmt->close();

            // Queue number
            $queueNumber = "Q" . $orderId . rand(10, 99);
            $updateQStmt = $conn->prepare("UPDATE orders SET queue_number = ? WHERE orderId = ?");
            $updateQStmt->bind_param("si", $queueNumber, $orderId);
            $updateQStmt->execute();
            $updateQStmt->close();

            // Insert order items WITH selected_options
            $itemStmt = $conn->prepare("INSERT INTO order_items (orderId, itemId, quantity, price, selected_options) VALUES (?, ?, ?, ?, ?)");
            
            foreach ($itemsToOrder as $item) {
                $itemStmt->bind_param("iiids", 
                    $orderId, 
                    $item['itemId'], 
                    $item['quantity'], 
                    $item['unit_price'],
                    $item['selected_options']
                );
                $itemStmt->execute();
            }
            $itemStmt->close();

            // Deduct points
            $newPoints = $currentPoints - $grandTotal;
            $updateStmt = $conn->prepare("UPDATE users SET points = ? WHERE userId = ?");
            $updateStmt->bind_param("ii", $newPoints, $user_id);
            $updateStmt->execute();
            $updateStmt->close();

            // Clear cart
            $conn->query("DELETE FROM cart WHERE userId = $user_id");

            $conn->commit();

            // Voucher data
            $orderData = [
                'orderId' => $orderId,
                'queueNumber' => $queueNumber,
                'totalAmount' => $grandTotal,
                'pointsUsed' => $grandTotal,
                'pickupTime' => $pickupTime,
                'orderType' => $orderType,
                'deliveryAddress' => $deliveryAddress,
                'deliveryFee' => $deliveryFee,
                'items' => implode(', ', $itemNames)
            ];
            $_SESSION['voucher_data'] = $orderData;

            header("Location: index.php?show_voucher=1");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Order မှာယူရာတွင် အမှားအယွင်း: " . $e->getMessage();
        }
    }
}

// =============================================
// Fetch Cart Items WITH selected_options
// =============================================
$cartItems = $conn->query("SELECT c.cartId, c.quantity, c.selected_options, m.itemName, m.points, m.image FROM cart c JOIN menu_items m ON c.itemId = m.itemId WHERE c.userId = $user_id");

// Delivery locations
$deliveryLocations = $conn->query("SELECT * FROM delivery_locations ORDER BY deliveryFee ASC");
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - UCSH Canteen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

        .btn-brand { 
            background: var(--brand-color); 
            color: white; 
            border: none; 
            transition: all 0.25s ease;
        }
        .btn-brand:hover { 
            background: var(--brand-hover); 
            color: white; 
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(30, 175, 189, 0.3);
        }

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

        .qty-box {
            display: inline-flex;
            align-items: center;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            background-color: #FFFFFF;
            overflow: hidden;
        }
        .qty-btn {
            border: none;
            background: #F1F5F9;
            color: #475569;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            transition: background 0.2s;
        }
        .qty-btn:hover { background: #E2E8F0; }
        .qty-input {
            width: 40px;
            border: none;
            text-align: center;
            font-weight: 600;
            font-size: 0.9rem;
            color: #1E293B;
        }

        .fs-7 { font-size: 0.75rem; }
        .points-display {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-radius: 12px;
            padding: 12px;
            text-align: center;
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
        
        .pickup-time-select option:disabled {
            color: #999;
            background-color: #f5f5f5;
        }

        .order-type-option {
            background: white;
            border: 2px solid #E2E8F0;
            border-radius: 12px;
            padding: 10px 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
        }

        .order-type-option i { color: #94A3B8; }
        .order-type-option .fw-bold { color: #64748B; }

        .order-type-option:hover {
            border-color: #1EAFBD;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 175, 189, 0.15);
        }

        .order-type-option.active {
            border-color: #1EAFBD;
            background: #EBF8F9;
            box-shadow: 0 4px 12px rgba(30, 175, 189, 0.2);
        }

        .order-type-option.active i { color: #1EAFBD; }
        .order-type-option.active .fw-bold { color: #1EAFBD; }

        /* 🎯 ITEM OPTIONS BADGE */
        .item-options-badge {
            background: #EBF8F9;
            color: #0F5860;
            border: 1px solid #B8F0F5;
            font-size: 0.68rem;
            padding: 2px 8px;
            border-radius: 20px;
            display: inline-block;
            margin-top: 3px;
            margin-right: 3px;
        }
        .item-options-badge i {
            color: #1EAFBD;
        }
    </style>
</head>
<body class="pb-5">

<?php include 'nav.php'; ?>

<div class="container my-4" style="max-width: 800px;">
    
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-white border shadow-sm rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                <i class="fa-solid fa-arrow-left text-dark"></i>
            </a>
            <h5 class="fw-bold mb-0 text-dark">Your Cart</h5>
        </div>
        <span class="badge bg-white text-secondary border shadow-sm fw-normal px-3 py-2 rounded-pill fs-7">
            <i class="fa-solid fa-cart-shopping me-1 text-brand"></i><?= $cartItems ? $cartItems->num_rows : 0 ?> Items
        </span>
    </div>

    <div class="points-display mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <span class="fw-bold text-dark"><i class="fa-solid fa-coins text-warning me-2"></i>သင့်လက်ရှိ Point</span>
            <span class="fw-bold fs-5 text-dark"><?= number_format($currentPoints) ?> Points</span>
        </div>
    </div>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3" role="alert">
            <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($cartItems && $cartItems->num_rows > 0): ?>
        <?php 
        // Collect items first for grand total calc
        $cartItemsArr = [];
        while ($row = $cartItems->fetch_assoc()) {
            $cartItemsArr[] = $row;
        }
        ?>
        
        <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 mb-4">
            
            <div class="d-none d-md-flex text-muted small fw-bold pb-2 border-bottom mb-3">
                <div style="flex: 2;">Item</div>
                <div class="text-center" style="flex: 1;">Points</div>
                <div class="text-center" style="flex: 1;">Qty</div>
                <div class="text-end" style="flex: 1;">Total</div>
                <div style="width: 40px;"></div>
            </div>

            <?php 
            $grandTotal = 0;
            foreach ($cartItemsArr as $item): 
                $extras = calcExtraPoints($item['selected_options']);
                $unitPrice = (int)$item['points'] + $extras;
                $subtotal = $unitPrice * (int)$item['quantity'];
                $grandTotal += $subtotal;
                
                $imgPath = 'https://via.placeholder.com/80?text=No+Image';
                if (!empty($item['image'])) {
                    if (file_exists($item['image'])) {
                        $imgPath = $item['image'];
                    } elseif (file_exists('uploads/' . basename($item['image']))) {
                        $imgPath = 'uploads/' . basename($item['image']);
                    }
                }
                
                // Get option names
                $optionNames = getOptionNamesFromJson($item['selected_options']);
            ?>
                <div class="d-flex align-items-center justify-content-between border-bottom py-3 gap-2">
                    
                    <div class="d-flex align-items-center gap-3" style="flex: 2;">
                        <img src="<?= $imgPath ?>" class="rounded-3 shadow-sm" style="width: 60px; height: 60px; object-fit: cover;">
                        <div>
                            <h6 class="fw-bold text-dark mb-0"><?= htmlspecialchars($item['itemName']) ?></h6>
                            
                            <?php if (!empty($optionNames)): ?>
                                <div class="mt-1">
                                    <?php foreach ($optionNames as $optName): ?>
                                        <span class="item-options-badge">
                                            <i class="fa-solid fa-check me-1"></i><?= htmlspecialchars($optName) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <small class="text-muted d-md-none d-block mt-1">
                                <?= number_format($unitPrice) ?> Points
                                <?php if ($extras > 0): ?>
                                    <span class="text-warning">(includes +<?= number_format($extras) ?>)</span>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>

                    <div class="text-center d-none d-md-block" style="flex: 1;">
                        <span class="fw-medium text-dark"><?= number_format($unitPrice) ?></span>
                        <?php if ($extras > 0): ?>
                            <br><small class="text-warning">+<?= number_format($extras) ?></small>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex justify-content-center" style="flex: 1;">
                        <form method="POST" id="qtyForm<?= $item['cartId'] ?>">
                            <input type="hidden" name="action" value="update_qty">
                            <input type="hidden" name="cartId" value="<?= $item['cartId'] ?>">
                            <div class="qty-box shadow-sm">
                                <button type="button" class="qty-btn" onclick="adjustQty(<?= $item['cartId'] ?>, -1)">-</button>
                                <input type="text" name="quantity" id="qtyInput<?= $item['cartId'] ?>" value="<?= $item['quantity'] ?>" readonly class="qty-input">
                                <button type="button" class="qty-btn" onclick="adjustQty(<?= $item['cartId'] ?>, 1)">+</button>
                            </div>
                        </form>
                    </div>

                    <div class="text-end fw-bold text-brand" style="flex: 1;">
                        <?= number_format($subtotal) ?>
                    </div>

                    <div class="text-end" style="width: 35px;">
                        <form method="POST" onsubmit="return confirm('ဒီ item ကို ဖျက်မှာလား?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="cartId" value="<?= $item['cartId'] ?>">
                            <button type="submit" class="btn btn-link text-danger p-0" title="Remove">
                                <i class="fa-solid fa-trash-can fs-6"></i>
                            </button>
                        </form>
                    </div>

                </div>
            <?php endforeach; ?>

            <div class="mt-4 pt-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted">Items Total</span>
                    <span class="fw-medium text-dark" id="itemsTotal"><?= number_format($grandTotal) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1" id="deliveryFeeRow" style="display: none;">
                    <span class="text-muted">Delivery Fee</span>
                    <span class="fw-medium text-warning" id="deliveryFeeTotal">+0</span>
                </div>
                <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                    <h6 class="fw-bold text-dark mb-0">Points to Deduct</h6>
                    <h5 class="fw-bold text-danger mb-0" id="grandTotalDisplay"><?= number_format($grandTotal) ?> Points</h5>
                </div>
            </div>

        </div>

        <button type="button" class="btn btn-brand w-100 py-3 rounded-4 fw-bold fs-6 shadow-sm" data-bs-toggle="modal" data-bs-target="#checkoutModal">
            Checkout (Points ဖြင့် မှာယူမည်) <i class="fa-solid fa-arrow-right ms-2"></i>
        </button>

    <?php else: ?>
        <div class="text-center py-5 bg-white rounded-4 shadow-sm">
            <i class="fa-solid fa-cart-flatbed-empty fa-3x text-muted opacity-50 mb-3"></i>
            <h6 class="text-secondary fw-bold">Cart ထဲတွင် အစားအသောက်များ မရှိသေးပါ။</h6>
            <a href="index.php" class="btn btn-brand btn-sm mt-3 px-4 py-2 rounded-3">မီနူး သို့သွားရန်</a>
        </div>
    <?php endif; ?>

</div>

<!-- Checkout Modal -->
<div class="modal fade" id="checkoutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="fw-bold text-dark mb-0">Points ဖြင့် မှာယူမည်</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <form method="POST" action="cart.php" id="checkoutForm">
                <div class="modal-body p-4">
                    
                    <div class="points-display mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-dark"><i class="fa-solid fa-coins text-warning me-2"></i>သင့်လက်ရှိ Point</span>
                            <span class="fw-bold fs-5 text-dark"><?= number_format($currentPoints) ?> Points</span>
                        </div>
                    </div>

                    <div class="alert alert-info rounded-3 border-0 mb-3" id="infoAlert">
                        <small><i class="fa-solid fa-info-circle me-1"></i> ဤအော်ဒါအတွက် <strong><?= number_format($grandTotal) ?> Points</strong> ကို သင့် Point မှ အလိုအလျောက် ဖြတ်တောက်မည် ဖြစ်ပါသည်။</small>
                    </div>

                    <!-- Order Type -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">မှာယူမည့် ပုံစံ</label>
                        <div class="row g-2">
                            <div class="col-4">
                                <div class="order-type-option active" onclick="selectOrderType('dine_in', this)">
                                    <input type="radio" name="orderType" value="dine_in" checked hidden>
                                    <div class="text-center">
                                        <i class="fa-solid fa-utensils fs-4"></i>
                                        <div class="fw-bold" style="font-size: 0.7rem; margin-top: 4px;">Dine In</div>
                                        <div class="text-muted" style="font-size: 0.6rem;">ဆိုင်တွင်စားမည်</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="order-type-option" onclick="selectOrderType('pickup', this)">
                                    <input type="radio" name="orderType" value="pickup" hidden>
                                    <div class="text-center">
                                        <i class="fa-solid fa-store fs-4"></i>
                                        <div class="fw-bold" style="font-size: 0.7rem; margin-top: 4px;">Pickup</div>
                                        <div class="text-muted" style="font-size: 0.6rem;">လာရောက်ယူဆောင်မည်</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="order-type-option" onclick="selectOrderType('delivery', this)">
                                    <input type="radio" name="orderType" value="delivery" hidden>
                                    <div class="text-center">
                                        <i class="fa-solid fa-motorcycle fs-4"></i>
                                        <div class="fw-bold" style="font-size: 0.7rem; margin-top: 4px;">Delivery</div>
                                        <div class="text-muted" style="font-size: 0.6rem;">ဖြင့်မှာယူမည်</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Delivery Address -->
                    <div class="mb-3" id="deliveryAddressBox" style="display: none;">
                        <label class="form-label small fw-bold text-muted">
                            <i class="fa-solid fa-location-dot text-danger me-1"></i>ပို့ဆောင်ရမည့်နေရာ
                        </label>
                        <select name="deliveryLocation" id="deliveryLocation" class="form-select rounded-3 py-2" onchange="updateDeliveryFee()">
                            <option value="">-- နေရာ ရွေးပါ --</option>
                            <?php 
                            if ($deliveryLocations && $deliveryLocations->num_rows > 0) {
                                $deliveryLocations->data_seek(0);
                                while ($loc = $deliveryLocations->fetch_assoc()): 
                                ?>
                                    <option value="<?= $loc['id'] ?>" data-fee="<?= $loc['deliveryFee'] ?>">
                                        <?= htmlspecialchars($loc['locationName']) ?> (+<?= number_format($loc['deliveryFee']) ?> pts)
                                    </option>
                                <?php 
                                endwhile; 
                            }
                            ?>
                        </select>
                    </div>

                    <!-- Delivery Fee Display -->
                    <div class="mb-3" id="deliveryFeeBox" style="display: none;">
                        <div class="alert alert-warning py-2 small mb-0">
                            <div class="d-flex justify-content-between">
                                <span><i class="fa-solid fa-motorcycle me-1"></i>Delivery Fee:</span>
                                <span class="fw-bold" id="deliveryFeeDisplay">0 Points</span>
                            </div>
                        </div>
                    </div>

                    <!-- Pickup Time -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Pickup Time (လာယူမည့် အချိန်)</label>
                        <select name="pickupTime" id="pickupTimeSelect" class="form-select rounded-3 py-2 pickup-time-select" required>
                            <option value="08:00 AM">08:00 AM</option>
                            <option value="09:00 AM">09:00 AM</option>
                            <option value="10:00 AM">10:00 AM</option>
                            <option value="11:00 AM">11:00 AM</option>
                            <option value="12:00 PM">12:00 PM</option>
                            <option value="01:00 PM">01:00 PM</option>
                            <option value="02:00 PM">02:00 PM</option>
                            <option value="03:00 PM">03:00 PM</option>
                            <option value="04:00 PM">04:00 PM</option>
                            <option value="05:00 PM">05:00 PM</option>
                            <option value="06:00 PM">06:00 PM</option>
                            <option value="07:00 PM">07:00 PM</option>
                        </select>
                        <small class="text-muted" id="timeStatus">
                            <i class="fa-regular fa-clock me-1"></i>
                            <span id="timeStatusText">Loading...</span>
                        </small>
                    </div>

                    <!-- Special Request -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Special Request (မှတ်ချက်)</label>
                        <textarea name="specialRequest" id="specialRequestInput" class="form-control rounded-3" rows="2" placeholder="ဥပမာ - အစပ်လျှော့ပေးပါ..."></textarea>
                    </div>

                </div>
                <div class="modal-footer border-0 pt-0">
                    <input type="hidden" name="place_order" value="1">
                    <button type="submit" id="submitOrderBtn" class="btn btn-brand w-100 py-3 rounded-3 fw-bold">
                        <i class="fa-solid fa-coins me-2"></i><?= number_format($grandTotal) ?> Points ဖြင့် မှာယူမည်
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// =============================================
// CART QUANTITY
// =============================================
function adjustQty(cartId, change) {
    let input = document.getElementById('qtyInput' + cartId);
    let currentVal = parseInt(input.value) || 1;
    let newVal = currentVal + change;
    if (newVal >= 1) {
        input.value = newVal;
        document.getElementById('qtyForm' + cartId).submit();
    }
}

// =============================================
// ORDER TYPE SELECT
// =============================================
let currentDeliveryFee = 0;
let basePoints = <?= $grandTotal ?>;

function selectOrderType(type, element) {
    document.querySelectorAll('.order-type-option').forEach(el => {
        el.classList.remove('active');
        el.querySelector('input').checked = false;
    });
    
    element.classList.add('active');
    element.querySelector('input').checked = true;
    
    if (type === 'delivery') {
        document.getElementById('deliveryAddressBox').style.display = 'block';
        document.getElementById('deliveryFeeBox').style.display = 'block';
    } else {
        document.getElementById('deliveryAddressBox').style.display = 'none';
        document.getElementById('deliveryFeeBox').style.display = 'none';
        currentDeliveryFee = 0;
        updateTotalDisplay();
    }
}

function updateDeliveryFee() {
    const select = document.getElementById('deliveryLocation');
    const option = select.options[select.selectedIndex];
    const fee = parseInt(option.getAttribute('data-fee')) || 0;
    
    currentDeliveryFee = fee;
    document.getElementById('deliveryFeeDisplay').textContent = '+ ' + fee.toLocaleString() + ' Points';
    updateTotalDisplay();
}

function updateTotalDisplay() {
    const totalWithDelivery = basePoints + currentDeliveryFee;
    
    const submitBtn = document.getElementById('submitOrderBtn');
    if (submitBtn) {
        submitBtn.innerHTML = `<i class="fa-solid fa-coins me-2"></i>${totalWithDelivery.toLocaleString()} Points ဖြင့် မှာယူမည်`;
    }
    
    const grandTotalEl = document.getElementById('grandTotalDisplay');
    if (grandTotalEl) {
        grandTotalEl.textContent = totalWithDelivery.toLocaleString() + ' Points';
    }
    
    const infoAlert = document.getElementById('infoAlert');
    if (infoAlert) {
        infoAlert.innerHTML = `<small><i class="fa-solid fa-info-circle me-1"></i> ဤအော်ဒါအတွက် <strong>${totalWithDelivery.toLocaleString()} Points</strong> ကို သင့် Point မှ အလိုအလျောက် ဖြတ်တောက်မည် ဖြစ်ပါသည်။</small>`;
    }
    
    const feeRow = document.getElementById('deliveryFeeRow');
    const feeTotal = document.getElementById('deliveryFeeTotal');
    if (feeRow && feeTotal) {
        if (currentDeliveryFee > 0) {
            feeRow.style.display = 'flex';
            feeTotal.textContent = '+' + currentDeliveryFee.toLocaleString();
        } else {
            feeRow.style.display = 'none';
        }
    }
}

// =============================================
// PICKUP TIME FILTER
// =============================================
document.addEventListener('DOMContentLoaded', function() {
    const select = document.getElementById('pickupTimeSelect');
    const statusText = document.getElementById('timeStatusText');
    
    if (!select) return;
    
    const timeMap = {
        '08:00 AM': 8, '09:00 AM': 9, '10:00 AM': 10, '11:00 AM': 11,
        '12:00 PM': 12, '01:00 PM': 13, '02:00 PM': 14, '03:00 PM': 15,
        '04:00 PM': 16, '05:00 PM': 17, '06:00 PM': 18, '07:00 PM': 19
    };
    
    function updatePickupTimes() {
        const now = new Date();
        const currentHour = now.getHours();
        const currentMinute = now.getMinutes();
        let foundAvailable = false;
        
        for (let option of select.options) {
            option.disabled = true;
            option.style.color = '#999';
            option.style.backgroundColor = '#f5f5f5';
        }
        
        for (let option of select.options) {
            const timeValue = option.value;
            if (timeMap[timeValue] !== undefined) {
                const hour = timeMap[timeValue];
                if (hour > currentHour) {
                    option.disabled = false;
                    option.style.color = '#000';
                    option.style.backgroundColor = '#fff';
                    if (!foundAvailable) {
                        foundAvailable = true;
                        option.selected = true;
                    }
                } else if (hour === currentHour && currentMinute < 30) {
                    option.disabled = false;
                    option.style.color = '#000';
                    option.style.backgroundColor = '#fff';
                    if (!foundAvailable) {
                        foundAvailable = true;
                        option.selected = true;
                    }
                }
            }
        }
        
        if (foundAvailable) {
            statusText.textContent = '✅ ရွေးချယ်နိုင်သော အချိန်များ';
            statusText.style.color = '#284aa7';
        } else {
            statusText.textContent = '⛔ ဆိုင်ပိတ်ချိန်မှာ ၇ နာရီဖြစ်ပါသောကြောင့် မှာယူ၍မရတော့ပါ';
            statusText.style.color = '#4f0008';
            const submitBtn = document.getElementById('submitOrderBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.style.cursor = 'not-allowed';
            }
        }
    }
    
    updatePickupTimes();
    
    const checkoutModal = document.getElementById('checkoutModal');
    if (checkoutModal) {
        checkoutModal.addEventListener('shown.bs.modal', function() {
            updatePickupTimes();
        });
    }
});

// =============================================
// FORM VALIDATION & SUBMIT
// =============================================
const checkoutForm = document.getElementById('checkoutForm');
if (checkoutForm) {
    checkoutForm.addEventListener('submit', function(e) {
        const select = document.getElementById('pickupTimeSelect');
        const selectedValue = select.value;
        
        const timeMap = {
            '08:00 AM': 8, '09:00 AM': 9, '10:00 AM': 10, '11:00 AM': 11,
            '12:00 PM': 12, '01:00 PM': 13, '02:00 PM': 14, '03:00 PM': 15,
            '04:00 PM': 16, '05:00 PM': 17, '06:00 PM': 18, '07:00 PM': 19
        };
        
        const now = new Date();
        const currentHour = now.getHours();
        const currentMinute = now.getMinutes();
        const selectedHour = timeMap[selectedValue];
        
        if (selectedHour === undefined) return true;
        
        let isValid = false;
        if (selectedHour > currentHour) {
            isValid = true;
        } else if (selectedHour === currentHour && currentMinute < 30) {
            isValid = true;
        }
        
        if (!isValid) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'အချိန်မှားယွင်းနေပါသည်',
                text: 'လက်ရှိအချိန်ထက် နောက်ကျတဲ့ အချိန်ကိုသာ ရွေးချယ်ပါ။',
                confirmButtonColor: '#1EAFBD'
            });
            return false;
        }
        
        const submitBtn = document.getElementById('submitOrderBtn');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>မှာယူနေပါသည်...';
        }
        
        return true;
    });
}

console.log('✅ Cart Page Loaded');
console.log('📊 Base Points:', basePoints);
</script>
</body>
</html>

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
$orderId = intval($_GET['orderId'] ?? 0);

// =============================================
// HANDLE USER MARK AS RECEIVED
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_received') {
    $orderId = intval($_POST['orderId']);
    
    $stmt = $conn->prepare("UPDATE orders SET status = 'completed', deliveryStatus = 'delivered' WHERE orderId = ? AND userId = ?");
    $stmt->bind_param("ii", $orderId, $userId);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Order Received!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to update']);
    }
    $stmt->close();
    exit();
}

// =============================================
// FETCH ORDER WITH DELIVERY DATA
// =============================================
$stmt = $conn->prepare("
    SELECT o.*, u.username,
           dl.latitude as dest_lat, 
           dl.longitude as dest_lng
    FROM orders o 
    LEFT JOIN users u ON o.userId = u.userId 
    LEFT JOIN delivery_locations dl ON o.deliveryAddress = dl.locationName
    WHERE o.orderId = ? AND o.userId = ?
");
$stmt->bind_param("ii", $orderId, $userId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    header("Location: history.php");
    exit();
}

// =============================================
// GET USER POINTS
// =============================================
$userStmt = $conn->prepare("SELECT points FROM users WHERE userId = ?");
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$currentPoints = $userStmt->get_result()->fetch_assoc()['points'] ?? 0;
$userStmt->close();

// Cart Count
$cart_count = 0;
$cart_stmt = $conn->prepare("SELECT SUM(quantity) as total FROM cart WHERE userId = ?");
if ($cart_stmt) {
    $cart_stmt->bind_param("i", $userId);
    $cart_stmt->execute();
    $cart_count = $cart_stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $cart_stmt->close();
}

// Like Count
$like_count = 0;
$like_stmt = $conn->prepare("SELECT COUNT(*) as total FROM liked_items WHERE userId = ?");
if ($like_stmt) {
    $like_stmt->bind_param("i", $userId);
    $like_stmt->execute();
    $like_count = $like_stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $like_stmt->close();
}

// =============================================
// DELIVERY STATUS LOGIC
// =============================================
$status = strtolower($order['status']);
$deliveryStatus = 'pending';

if ($status === 'cooking') {
    $deliveryStatus = 'preparing';
} elseif ($status === 'pickup') {
    $deliveryStatus = 'on_the_way';
} elseif ($status === 'completed') {
    $deliveryStatus = 'delivered';
} elseif ($status === 'rejected') {
    $deliveryStatus = 'cancelled';
}

// Canteen Location (Starting Point)
$canteenLat = 16.8378531;
$canteenLng = 97.5987163;

// Destination
$destLat = $order['dest_lat'] ?? $canteenLat;
$destLng = $order['dest_lng'] ?? $canteenLng;

// =============================================
// ✅ 7 DELIVERY LOCATIONS WITH ROAD-BASED WAYPOINTS
// =============================================
$delivery_locations_map = [
    'ပင်မစာသင်ဆောင်' => [
        'lat' => 16.836874, 'lng' => 97.596156,
        'waypoints' => [
            [16.8378531, 97.5987163], // Canteen (Start)
            [16.8378000, 97.5985000], // Follow road
            [16.8376000, 97.5982000],
            [16.8374000, 97.5978000],
            [16.8372000, 97.5974000],
            [16.8370000, 97.5970000],
            [16.8369000, 97.5965000],
            [16.836874, 97.596156]  // Destination
        ]
    ],
    'ပင်မစာသင်ဆောင် (Lobby)' => [
        'lat' => 16.836874, 'lng' => 97.596156,
        'waypoints' => [
            [16.8378531, 97.5987163],
            [16.8378000, 97.5985000],
            [16.8376000, 97.5982000],
            [16.8374000, 97.5978000],
            [16.8372000, 97.5974000],
            [16.8370000, 97.5970000],
            [16.8369000, 97.5965000],
            [16.836874, 97.596156]
        ]
    ],
    'ကျောင်းသားဆောင် (H1) ဆောင်' => [
        'lat' => 16.837771, 'lng' => 97.599861,
        'waypoints' => [
            [16.8378531, 97.5987163],
            [16.8378500, 97.5990000],
            [16.8378300, 97.5993000],
            [16.8378100, 97.5995000],
            [16.8377900, 97.5997000],
            [16.837771, 97.599861]
        ]
    ],
    'ဆရာ၊ဆရာမ အိမ်ရာ (B1) ဆောင်' => [
        'lat' => 16.837603, 'lng' => 97.599519,
        'waypoints' => [
            [16.8378531, 97.5987163],
            [16.8378000, 97.5989000],
            [16.8377000, 97.5991000],
            [16.8376500, 97.5993000],
            [16.837603, 97.599519]
        ]
    ],
    'ဆရာ၊ဆရာမ အိမ်ရာ (B2) ဆောင်' => [
        'lat' => 16.838378, 'lng' => 97.598966,
        'waypoints' => [
            [16.8378531, 97.5987163],
            [16.8379000, 97.5987500],
            [16.8380000, 97.5988000],
            [16.8381000, 97.5988500],
            [16.8382000, 97.5989000],
            [16.838378, 97.598966]
        ]
    ],
    'ကျောင်းသူဆောင် (H2) ဆောင်' => [
        'lat' => 16.838183, 'lng' => 97.599650,
        'waypoints' => [
            [16.8378531, 97.5987163],
            [16.8379000, 97.5989000],
            [16.8379500, 97.5991000],
            [16.8380000, 97.5993000],
            [16.8381000, 97.5995000],
            [16.838183, 97.599650]
        ]
    ],
    'ကျောင်းသူဆောင် (160) ဆောင်' => [
        'lat' => 16.838698, 'lng' => 97.599426,
        'waypoints' => [
            [16.8378531, 97.5987163],
            [16.8379000, 97.5989000],
            [16.8380000, 97.5991000],
            [16.8382000, 97.5993000],
            [16.8384000, 97.5994000],
            [16.838698, 97.599426]
        ]
    ],
    'အမျိုးသား (H1) ဆောင်' => [
        'lat' => 16.837771, 'lng' => 97.599861,
        'waypoints' => [
            [16.8378531, 97.5987163],
            [16.8378500, 97.5990000],
            [16.8378300, 97.5993000],
            [16.8378100, 97.5995000],
            [16.8377900, 97.5997000],
            [16.837771, 97.599861]
        ]
    ],
    'အမျိုးသမီး (H2) ဆောင်' => [
        'lat' => 16.838183, 'lng' => 97.599650,
        'waypoints' => [
            [16.8378531, 97.5987163],
            [16.8379000, 97.5989000],
            [16.8379500, 97.5991000],
            [16.8380000, 97.5993000],
            [16.8381000, 97.5995000],
            [16.838183, 97.599650]
        ]
    ]
];

// Get waypoints for this order
$deliveryAddr = $order['deliveryAddress'] ?? 'ပင်မစာသင်ဆောင်';
$waypoints = [];

if (isset($delivery_locations_map[$deliveryAddr])) {
    $waypoints = $delivery_locations_map[$deliveryAddr]['waypoints'];
} else {
    // Default: 6 intermediate points
    $waypoints = [
        [$canteenLat, $canteenLng],
        [$canteenLat + ($destLat - $canteenLat) * 0.2, $canteenLng + ($destLng - $canteenLng) * 0.2],
        [$canteenLat + ($destLat - $canteenLat) * 0.4, $canteenLng + ($destLng - $canteenLng) * 0.4],
        [$canteenLat + ($destLat - $canteenLat) * 0.6, $canteenLng + ($destLng - $canteenLng) * 0.6],
        [$canteenLat + ($destLat - $canteenLat) * 0.8, $canteenLng + ($destLng - $canteenLng) * 0.8],
        [$destLat, $destLng]
    ];
}
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Track Delivery - UCSH Canteen</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Myanmar:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root { 
            --brand-color: #1EAFBD; 
            --brand-hover: #17939F; 
            --brand-light: #EBF8F9; 
        }
        body { 
            font-family: 'Plus Jakarta Sans', 'Noto Sans Myanmar', sans-serif; 
            background: #F8FAFC; 
            color: #1E293B; 
            overflow-x: hidden;
        }
        .text-brand { color: var(--brand-color) !important; }
        .bg-brand { background-color: var(--brand-color) !important; }
        .bg-brand-light { background-color: var(--brand-light) !important; }
        
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
        
        #trackingMap {
            height: clamp(300px, 50vh, 500px);
            width: 100%;
            border-radius: clamp(12px, 2vw, 20px);
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
            z-index: 1;
        }
        
        .delivery-status-card {
            background: white;
            border-radius: clamp(12px, 2vw, 20px);
            padding: clamp(14px, 2vw, 20px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 16px;
        }
        
        .status-timeline {
            display: flex;
            justify-content: space-between;
            margin: clamp(14px, 2vw, 20px) 0;
            position: relative;
            padding: 0 4px;
        }
        
        .status-timeline::before {
            content: '';
            position: absolute;
            top: 18px;
            left: 10%;
            right: 10%;
            height: 3px;
            background: #E2E8F0;
            z-index: 0;
        }
        
        .status-step {
            position: relative;
            z-index: 1;
            text-align: center;
            flex: 1;
            min-width: 0;
        }
        
        .status-icon {
            width: clamp(32px, 5vw, 40px);
            height: clamp(32px, 5vw, 40px);
            border-radius: 50%;
            background: #E2E8F0;
            color: #94A3B8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(12px, 1.8vw, 16px);
            transition: all 0.4s ease;
            margin: 0 auto;
        }
        
        .status-step.active .status-icon {
            background: var(--brand-color);
            color: white;
            box-shadow: 0 0 0 4px rgba(30,175,189,0.2);
            animation: pulse 1.5s infinite;
        }
        
        .status-step.completed .status-icon {
            background: #28a745;
            color: white;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(30,175,189,0.4); }
            70% { box-shadow: 0 0 0 12px rgba(30,175,189,0); }
            100% { box-shadow: 0 0 0 0 rgba(30,175,189,0); }
        }
        
        .status-label {
            font-size: clamp(0.6rem, 1.5vw, 0.7rem);
            color: #64748B;
            margin-top: 6px;
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .status-step.active .status-label { color: var(--brand-color); font-weight: 700; }
        .status-step.completed .status-label { color: #28a745; font-weight: 600; }
        
        .bike-icon-wrapper {
            position: relative;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .bike-icon {
            background: #FFC107;
            color: white;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            box-shadow: 0 6px 20px rgba(255, 193, 7, 0.8);
            border: 3px solid white;
            position: relative;
            transition: transform 0.6s ease;
        }
        
        .bike-icon::before {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            border: 3px solid rgba(255, 193, 7, 0.5);
            animation: bikePulse 2s ease-out infinite;
        }
        
        @keyframes bikePulse {
            0% { transform: scale(1); opacity: 1; }
            100% { transform: scale(1.5); opacity: 0; }
        }
        
        .eta-box {
            background: linear-gradient(135deg, #1EAFBD 0%, #0F5860 100%);
            color: white;
            border-radius: clamp(12px, 2vw, 16px);
            padding: clamp(12px, 2vw, 15px);
            text-align: center;
            box-shadow: 0 8px 24px rgba(30, 175, 189, 0.3);
        }
        
        .eta-number {
            font-size: clamp(1.5rem, 4vw, 2rem);
            font-weight: 800;
            line-height: 1;
        }
        
        .eta-box .small {
            font-size: clamp(0.65rem, 1.5vw, 0.75rem);
        }
        
        .delivery-info-card {
            background: white;
            border-radius: clamp(12px, 2vw, 16px);
            padding: clamp(14px, 2vw, 16px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        /* ✅ RECEIVED BUTTON - ONLY WHEN DELIVERED */
        .btn-received {
            background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
            color: white;
            border: none;
            padding: 14px 24px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1rem;
            width: 100%;
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
            transition: all 0.3s ease;
            animation: receivedPulse 2s infinite;
        }
        
        .btn-received:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(40, 167, 69, 0.6);
        }
        
        .btn-received:disabled {
            background: #6c757d;
            cursor: not-allowed;
            animation: none;
            box-shadow: none;
        }
        
        @keyframes receivedPulse {
            0%, 100% { box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4); }
            50% { box-shadow: 0 6px 30px rgba(40, 167, 69, 0.8); }
        }
        
        @media (max-width: 576px) {
            .navbar-custom { padding: 8px 0; }
            .nav-icon-btn { font-size: 1rem; padding: 6px 8px; }
            .points-nav { padding: 3px 10px; font-size: 0.75rem; }
            #trackingMap { height: 320px; }
            .container { padding-left: 10px; padding-right: 10px; }
            .status-label { font-size: 0.55rem; }
        }
        
        @media (min-width: 1920px) {
            body { font-size: 18px; }
            .container { max-width: 1400px; }
        }
    </style>
</head>
<body class="pb-5">

<?php include 'nav.php'; ?>

<div class="container my-3 my-md-4" style="max-width: 900px;">
    
    <!-- Back Button + Title -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <a href="history.php" class="btn btn-white border shadow-sm rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                <i class="fa-solid fa-arrow-left text-dark"></i>
            </a>
            <h5 class="fw-bold mb-0 text-dark">
                <i class="fa-solid fa-motorcycle text-brand me-1"></i>Delivery Tracking
            </h5>
        </div>
        <span class="badge bg-dark text-white px-3 py-2"><?= htmlspecialchars($order['queue_number']) ?></span>
    </div>
    
    <!-- Delivery Status Card -->
    <div class="delivery-status-card">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h6 class="fw-bold mb-0">
                <i class="fa-solid fa-motorcycle text-brand me-2"></i>
                Delivery Status
            </h6>
            <span class="badge bg-warning text-dark" id="statusBadge">
                <?= strtoupper(str_replace('_', ' ', $deliveryStatus)) ?>
            </span>
        </div>
        
        <!-- Timeline -->
        <div class="status-timeline">
            <div class="status-step completed">
                <div class="status-icon"><i class="fa-solid fa-check"></i></div>
                <div class="status-label">Confirmed</div>
            </div>
            <div class="status-step <?= in_array($deliveryStatus, ['preparing', 'on_the_way', 'delivered']) ? ($deliveryStatus === 'preparing' ? 'active' : 'completed') : '' ?>">
                <div class="status-icon"><i class="fa-solid fa-utensils"></i></div>
                <div class="status-label">Preparing</div>
            </div>
            <div class="status-step <?= in_array($deliveryStatus, ['on_the_way', 'delivered']) ? ($deliveryStatus === 'on_the_way' ? 'active' : 'completed') : '' ?>">
                <div class="status-icon"><i class="fa-solid fa-motorcycle"></i></div>
                <div class="status-label">On the way</div>
            </div>
            <div class="status-step <?= $deliveryStatus === 'delivered' ? 'completed' : '' ?>">
                <div class="status-icon"><i class="fa-solid fa-flag-checkered"></i></div>
                <div class="status-label">Delivered</div>
            </div>
        </div>
        
        <hr>
        
        <div class="row g-2 small">
            <div class="col-6">
                <div class="text-muted">Delivery To:</div>
                <div class="fw-bold">📍 <?= htmlspecialchars($order['deliveryAddress'] ?? 'N/A') ?></div>
            </div>
            <div class="col-6 text-end">
                <div class="text-muted">Delivery Fee:</div>
                <div class="fw-bold text-warning">+<?= number_format($order['deliveryFee'] ?? 0) ?> pts</div>
            </div>
        </div>
    </div>
    
    <!-- ETA Box -->
    <div class="eta-box mb-3" id="etaBox" style="display: none;">
        <div class="small opacity-75">Estimated Arrival Time</div>
        <div class="eta-number" id="etaNumber">--</div>
        <div class="small opacity-75">minutes</div>
    </div>
    
    <!-- ✅ RECEIVED BUTTON - ONLY WHEN ARRIVED (Hidden by default) -->
    <div class="mb-3" id="receivedButtonBox" style="display: none;">
        <button class="btn-received" id="btnReceived" onclick="markAsReceived()">
            <i class="fa-solid fa-check-circle me-2"></i>
            ပစ္စည်းရောက်ပါပြီ — Order Received
        </button>
        <p class="text-center text-muted small mt-2 mb-0">
            <i class="fa-solid fa-info-circle me-1"></i>
            သင့်အော်ဒါ ရောက်ရှိပါက ဤ Button ကို နှိပ်ပါ
        </p>
    </div>
    
    <!-- ✅ WAITING MESSAGE (When not yet arrived) -->
    <div class="mb-3" id="waitingBox">
        <div class="alert alert-info text-center rounded-3 border-0 mb-0">
            <i class="fa-solid fa-clock me-2"></i>
            <strong>သင့်အော်ဒါ လမ်းပေါ်ရောက်နေပါပြီ</strong>
            <br>
            <small>ပစ္စည်းရောက်သည့်အခါ "Order Received" Button ပေါ်လာပါမည်</small>
        </div>
    </div>
    
    <!-- Map -->
    <div id="trackingMap"></div>
    
    <!-- Order Info -->
    <div class="delivery-info-card mt-3">
        <div class="row g-3 small">
            <div class="col-6">
                <div class="text-muted">Order Number:</div>
                <div class="fw-bold"><?= htmlspecialchars($order['queue_number']) ?></div>
            </div>
            <div class="col-6 text-end">
                <div class="text-muted">Total Points:</div>
                <div class="fw-bold text-warning"><?= number_format($order['points_used']) ?> pts</div>
            </div>
            <div class="col-12">
                <div class="text-muted">Pickup Time:</div>
                <div class="fw-bold"><?= htmlspecialchars($order['pickupTime']) ?></div>
            </div>
        </div>
    </div>
    
</div>

<script>
// =============================================
// 🗺️ CONFIG
// =============================================
const canteenLat = <?= $canteenLat ?>;
const canteenLng = <?= $canteenLng ?>;
const destLat = <?= $destLat ?>;
const destLng = <?= $destLng ?>;
const deliveryStatus = '<?= $deliveryStatus ?>';
const orderId = <?= $orderId ?>;
const waypoints = <?= json_encode($waypoints) ?>;

const map = L.map('trackingMap', {
    center: [canteenLat, canteenLng],
    zoom: 18,
    zoomControl: true
});

var googleHybrid = L.tileLayer('https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
    attribution: '© Google Maps',
    maxZoom: 20
});

var googleStreets = L.tileLayer('https://mt1.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
    attribution: '© Google Maps',
    maxZoom: 20
});

googleHybrid.addTo(map);

L.control.layers({
    "🛰️ Hybrid": googleHybrid,
    "🗺️ Streets": googleStreets
}, null, { position: 'topright' }).addTo(map);

// Canteen Marker
const canteenIcon = L.divIcon({
    html: '<div style="background: #1EAFBD; color: white; width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; box-shadow: 0 6px 20px rgba(30,175,189,0.8); border: 3px solid white;">🍽️</div>',
    iconSize: [50, 50],
    iconAnchor: [25, 25],
    className: ''
});

L.marker([canteenLat, canteenLng], { icon: canteenIcon })
    .addTo(map)
    .bindPopup('<strong>🍽️ UCSH Canteen</strong><br>Starting Point');

// Destination Marker
const destIcon = L.divIcon({
    html: '<div style="background: #FF4757; color: white; width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; box-shadow: 0 6px 20px rgba(255,71,87,0.8); border: 3px solid white;">📍</div>',
    iconSize: [50, 50],
    iconAnchor: [25, 25],
    className: ''
});

L.marker([destLat, destLng], { icon: destIcon })
    .addTo(map)
    .bindPopup('<strong>📍 <?= htmlspecialchars($order['deliveryAddress'] ?? 'Destination') ?></strong>');

// ✅ ROUTE LINE (Follows Waypoints - Road-like)
L.polyline(waypoints, {
    color: '#1EAFBD',
    weight: 5,
    opacity: 0.8,
    dashArray: '10, 10',
    lineCap: 'round',
    lineJoin: 'round'
}).addTo(map);

// Fit Bounds
const bounds = L.latLngBounds(waypoints);
map.fitBounds(bounds, { padding: [60, 60] });

// =============================================
// 🛵 DELIVERY BIKE ANIMATION WITH DIRECTION
// =============================================

<?php if (in_array($deliveryStatus, ['on_the_way', 'delivered'])): ?>
    
    let currentAngle = 0;
    let eta = Math.ceil(waypoints.length * 2);

    function getBikeIcon(angle) {
        return L.divIcon({
            html: `<div class="bike-icon-wrapper">
                     <div class="bike-icon" style="transform: rotate(${angle}deg);">
                       <i class="fa-solid fa-motorcycle" style="transform: rotate(${-angle}deg);"></i>
                     </div>
                   </div>`,
            iconSize: [60, 60],
            iconAnchor: [30, 30],
            className: ''
        });
    }

    function calcAngle(fromLat, fromLng, toLat, toLng) {
        const dLng = toLng - fromLng;
        const dLat = toLat - fromLat;
        return Math.atan2(dLng, dLat) * (180 / Math.PI);
    }

    let bikeLat = waypoints[0][0];
    let bikeLng = waypoints[0][1];

    if (waypoints.length > 1) {
        currentAngle = calcAngle(
            waypoints[0][0], waypoints[0][1],
            waypoints[1][0], waypoints[1][1]
        );
    }

    const bikeMarker = L.marker([bikeLat, bikeLng], { icon: getBikeIcon(currentAngle) })
        .addTo(map)
        .bindPopup('<strong>🛵 Your Delivery</strong><br>On the way!');

    document.getElementById('etaBox').style.display = 'block';
    document.getElementById('etaNumber').textContent = eta;

    <?php if ($deliveryStatus === 'on_the_way'): ?>
    
    // ✅ Animate along waypoints
    let totalProgress = 0;
    const totalDistance = waypoints.length - 1;
    
    const interval = setInterval(() => {
        totalProgress += 0.02;
        
        if (totalProgress >= totalDistance) {
            clearInterval(interval);
            
            // ✅ Final position
            const lastIdx = waypoints.length - 1;
            bikeMarker.setLatLng([waypoints[lastIdx][0], waypoints[lastIdx][1]]);
            document.getElementById('etaNumber').textContent = '0';
            
            // ✅ SHOW RECEIVED BUTTON + HIDE WAITING
            document.getElementById('receivedButtonBox').style.display = 'block';
            document.getElementById('waitingBox').style.display = 'none';
            
            Swal.fire({
                icon: 'success',
                title: '🛵 Delivery Arrived!',
                text: 'သင့် Order ရောက်ပါပြီ! ပစ္စည်းလက်ခံရရှိပါက "Order Received" Button ကို နှိပ်ပါ။',
                confirmButtonColor: '#28a745',
                confirmButtonText: 'ပြီးပါပြီ'
            });
            return;
        }
        
        const segmentIndex = Math.floor(totalProgress);
        const segmentProgress = totalProgress - segmentIndex;
        
        if (segmentIndex >= waypoints.length - 1) {
            clearInterval(interval);
            return;
        }
        
        const fromPt = waypoints[segmentIndex];
        const toPt = waypoints[segmentIndex + 1];
        
        bikeLat = fromPt[0] + (toPt[0] - fromPt[0]) * segmentProgress;
        bikeLng = fromPt[1] + (toPt[1] - fromPt[1]) * segmentProgress;
        
        // ✅ Calculate angle for direction
        const newAngle = calcAngle(fromPt[0], fromPt[1], toPt[0], toPt[1]);
        
        // Smooth angle transition
        if (Math.abs(newAngle - currentAngle) > 3) {
            currentAngle = newAngle;
            bikeMarker.setIcon(getBikeIcon(currentAngle));
        }
        
        bikeMarker.setLatLng([bikeLat, bikeLng]);
        
        // ETA update
        eta = Math.ceil((totalDistance - totalProgress) * 2);
        if (eta < 0) eta = 0;
        document.getElementById('etaNumber').textContent = eta;
        
    }, 200);
    
    <?php else: ?>
    
    // ✅ Already delivered - show button immediately
    const lastIdx = waypoints.length - 1;
    bikeMarker.setLatLng([waypoints[lastIdx][0], waypoints[lastIdx][1]]);
    document.getElementById('etaNumber').textContent = '0';
    
    // Show button for already delivered orders
    document.getElementById('receivedButtonBox').style.display = 'block';
    document.getElementById('waitingBox').style.display = 'none';
    
    <?php endif; ?>
    
<?php else: ?>
    
    document.getElementById('etaBox').style.display = 'none';
    document.getElementById('receivedButtonBox').style.display = 'none';
    
    // Show waiting message only for "on_the_way" - not for preparing
    <?php if ($deliveryStatus === 'preparing'): ?>
        document.getElementById('waitingBox').style.display = 'none';
    <?php else: ?>
        document.getElementById('waitingBox').style.display = 'block';
    <?php endif; ?>
    
<?php endif; ?>

// =============================================
// ✅ MARK AS RECEIVED
// =============================================
function markAsReceived() {
    Swal.fire({
        title: 'ပစ္စည်း ရောက်ပါပြီလား?',
        text: 'သင့်အော်ဒါကို လက်ခံရရှိပါပြီဆိုရင် အတည်ပြုပါ။',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '✅ ရောက်ပါပြီ',
        cancelButtonText: 'မရောက်သေးပါ'
    }).then((result) => {
        if (result.isConfirmed) {
            const btn = document.getElementById('btnReceived');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Processing...';
            
            const formData = new FormData();
            formData.append('action', 'mark_received');
            formData.append('orderId', orderId);
            
            fetch('track_delivery.php?orderId=' + orderId, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: '🎉 Order Received!',
                        text: 'သင့်အော်ဒါ ပြီးစီးပါပြီ! ကျေးဇူးတင်ပါတယ်။',
                        confirmButtonColor: '#1EAFBD',
                        confirmButtonText: 'ပြီးပါပြီ'
                    }).then(() => {
                        window.location.href = 'history.php';
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-check-circle me-2"></i>ပစ္စည်းရောက်ပါပြီ — Order Received';
                }
            })
            .catch(err => {
                Swal.fire('Error', 'Cannot update. Please try again.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check-circle me-2"></i>ပစ္စည်းရောက်ပါပြီ — Order Received';
            });
        }
    });
}

// =============================================
// 🔄 AUTO REFRESH
// =============================================
setInterval(() => {
    fetch(window.location.href)
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const newStatus = doc.getElementById('statusBadge');
            const oldStatus = document.getElementById('statusBadge');
            
            if (newStatus && oldStatus && newStatus.textContent.trim() !== oldStatus.textContent.trim()) {
                location.reload();
            }
        })
        .catch(err => console.log('Refresh error:', err));
}, 5000);
</script>

</body>
</html>

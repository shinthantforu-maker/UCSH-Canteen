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

// Canteen Location (Starting Point) - UCSH Hpa-An
$canteenLat = 16.8378531;
$canteenLng = 97.5987163;

// Destination
$destLat = $order['dest_lat'] ?? $canteenLat;
$destLng = $order['dest_lng'] ?? $canteenLng;
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
        
        /* ============================================= */
        /* 🎨 NAVBAR (Same as other pages)                */
        /* ============================================= */
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
        
        /* ============================================= */
        /* 🗺️ MAP                                         */
        /* ============================================= */
        #trackingMap {
            height: clamp(300px, 50vh, 500px);
            width: 100%;
            border-radius: clamp(12px, 2vw, 20px);
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
            z-index: 1;
        }
        
        /* ============================================= */
        /* 📦 DELIVERY STATUS CARD                        */
        /* ============================================= */
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
        
        /* ============================================= */
        /* 🛵 BIKE ICON                                    */
        /* ============================================= */
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
            animation: bikeBounce 1s infinite;
            border: 3px solid white;
            position: relative;
        }
        
        .bike-icon::before {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            border: 3px solid rgba(255, 193, 7, 0.5);
            animation: bikePulse 2s ease-out infinite;
        }
        
        @keyframes bikeBounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }
        
        @keyframes bikePulse {
            0% { transform: scale(1); opacity: 1; }
            100% { transform: scale(1.5); opacity: 0; }
        }
        
        /* ============================================= */
        /* ⏱️ ETA BOX                                     */
        /* ============================================= */
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
        
        /* ============================================= */
        /* 📋 INFO CARD                                   */
        /* ============================================= */
        .delivery-info-card {
            background: white;
            border-radius: clamp(12px, 2vw, 16px);
            padding: clamp(14px, 2vw, 16px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        /* ============================================= */
        /* 📱 RESPONSIVE - MOBILE (≤ 576px)               */
        /* ============================================= */
        @media (max-width: 576px) {
            .navbar-custom { padding: 8px 0; }
            .nav-icon-btn { font-size: 1rem; padding: 6px 8px; }
            .points-nav { padding: 3px 10px; font-size: 0.75rem; }
            #trackingMap { height: 320px; }
            .container { padding-left: 10px; padding-right: 10px; }
            .status-label { font-size: 0.55rem; }
        }
        
        /* ============================================= */
        /* 📱 RESPONSIVE - TABLET (577px - 992px)         */
        /* ============================================= */
        @media (min-width: 577px) and (max-width: 992px) {
            #trackingMap { height: 400px; }
        }
        
        /* ============================================= */
        /* 💻 RESPONSIVE - DESKTOP (≥ 993px)              */
        /* ============================================= */
        @media (min-width: 993px) {
            #trackingMap { height: 500px; }
        }
        
        /* ============================================= */
        /* 📺 RESPONSIVE - 4K/TV (≥ 1920px)               */
        /* ============================================= */
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
// 🗺️ INITIALIZE MAP
// =============================================
const canteenLat = <?= $canteenLat ?>;
const canteenLng = <?= $canteenLng ?>;
const destLat = <?= $destLat ?>;
const destLng = <?= $destLng ?>;
const deliveryStatus = '<?= $deliveryStatus ?>';

const map = L.map('trackingMap', {
    center: [canteenLat, canteenLng],
    zoom: 18,
    zoomControl: true
});

// Google Maps Hybrid
var googleHybrid = L.tileLayer('https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
    attribution: '© Google Maps',
    maxZoom: 20
});

var googleSatellite = L.tileLayer('https://mt1.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', {
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
    "🌍 Satellite": googleSatellite,
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

// Route Line
L.polyline([
    [canteenLat, canteenLng],
    [destLat, destLng]
], {
    color: '#1EAFBD',
    weight: 5,
    opacity: 0.8,
    dashArray: '15, 15',
    lineCap: 'round'
}).addTo(map);

// Fit Bounds
const bounds = L.latLngBounds([
    [canteenLat, canteenLng],
    [destLat, destLng]
]);
map.fitBounds(bounds, { padding: [60, 60] });

// =============================================
// 🛵 DELIVERY BOY SIMULATION
// =============================================
const bikeIcon = L.divIcon({
    html: '<div class="bike-icon">🛵</div>',
    iconSize: [60, 60],
    iconAnchor: [30, 30],
    className: ''
});

<?php if (in_array($deliveryStatus, ['on_the_way', 'delivered'])): ?>
    
    let bikeLat = canteenLat;
    let bikeLng = canteenLng;
    let progress = 0;
    const totalSteps = 200;
    let eta = Math.ceil((totalSteps - progress) / 10);

    const bikeMarker = L.marker([bikeLat, bikeLng], { icon: bikeIcon })
        .addTo(map)
        .bindPopup('<strong>🛵 Your Delivery</strong><br>On the way!');

    document.getElementById('etaBox').style.display = 'block';
    document.getElementById('etaNumber').textContent = eta;

    <?php if ($deliveryStatus === 'on_the_way'): ?>
    const interval = setInterval(() => {
        progress++;
        
        if (progress > totalSteps) {
            clearInterval(interval);
            bikeMarker.setLatLng([destLat, destLng]);
            document.getElementById('etaNumber').textContent = '0';
            
            Swal.fire({
                icon: 'success',
                title: '🎉 Delivery Arrived!',
                text: 'သင့် Order ရောက်ပါပြီ!',
                confirmButtonColor: '#1EAFBD'
            });
            return;
        }
        
        bikeLat = canteenLat + (destLat - canteenLat) * (progress / totalSteps);
        bikeLng = canteenLng + (destLng - canteenLng) * (progress / totalSteps);
        
        bikeMarker.setLatLng([bikeLat, bikeLng]);
        
        eta = Math.ceil((totalSteps - progress) / 10);
        document.getElementById('etaNumber').textContent = eta;
        
    }, 100);
    <?php else: ?>
    bikeMarker.setLatLng([destLat, destLng]);
    document.getElementById('etaNumber').textContent = '0';
    <?php endif; ?>
    
<?php else: ?>
    
    document.getElementById('etaBox').style.display = 'none';
    
<?php endif; ?>

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
}, 3000);
</script>

</body>
</html>
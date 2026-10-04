<?php
require_once 'db.php';

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'Admin') {
        header("Location: admin/admin.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

$announcements = $conn->query("SELECT * FROM announcements ORDER BY announcementId DESC LIMIT 3");
$menu_items = $conn->query("SELECT * FROM menu_items WHERE isAvailable = 1 ORDER BY itemId DESC");
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UCSH Canteen - Guest Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Myanmar:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --brand-color: #1EAFBD; --brand-hover: #17939F; --brand-light: #EBF8F9; }
        body { font-family: 'Poppins', 'Noto Sans Myanmar', sans-serif; background-color: #F8FAFC; color: #2D3748; }
        .text-brand { color: var(--brand-color) !important; }
        .bg-brand { background-color: var(--brand-color) !important; }
        .navbar-custom { background-color: #FFFFFF; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05); }
        .btn-brand { background-color: var(--brand-color); color: #FFFFFF; border: none; transition: all 0.3s ease; }
        .btn-brand:hover { background-color: var(--brand-hover); color: #FFFFFF; }
        .announcement-card { background: linear-gradient(135deg, #FFFFFF 0%, var(--brand-light) 100%); border-left: 5px solid var(--brand-color); border-radius: 16px; }
        .menu-card { border: none; border-radius: 18px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03); background: #FFFFFF; }
        .menu-card-img { height: 160px; object-fit: cover; border-radius: 18px 18px 0 0; }
        .fs-7 { font-size: 0.75rem; }
        .swal-custom-popup { width: 320px !important; padding: 1.25rem !important; border-radius: 16px !important; }
        .tracker-input { max-width: 400px; margin: 0 auto; }
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
        .progress-bar-animated {
            transition: width 0.8s ease;
        }
    </style>
</head>
<body class="pb-5">

<!-- ============================================= -->
<!-- NAVBAR WITH SEARCH BOX                         -->
<!-- ============================================= -->
<nav class="navbar navbar-expand-lg sticky-top navbar-custom py-2">
    <div class="container">
        <a class="navbar-brand fw-bold fs-4 text-brand d-flex align-items-center me-3" href="guest.php">
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
            <div class="d-flex align-items-center gap-2 ms-auto mt-3 mt-lg-0">
                <a href="guest.php" class="btn btn-outline-secondary btn-sm px-3 rounded-pill fw-medium">
                    <i class="fa-solid fa-house me-1"></i> Home
                </a>
                <a href="login.php" class="btn btn-outline-secondary btn-sm px-3 rounded-pill fw-medium">
                    <i class="fa-solid fa-right-to-bracket me-1"></i> Login
                </a>
                <a href="register.php" class="btn btn-brand btn-sm px-3 rounded-pill fw-medium">
                    <i class="fa-solid fa-user-plus me-1"></i> Register
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="container my-4">

    <!-- Tracking Section -->
    <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
        <div class="text-center mb-3">
            <h5 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-magnifying-glass text-brand me-2"></i>Track Your Order
            </h5>
            <p class="text-secondary small mb-0">သင့် Queue နံပါတ်ကို ရိုက်ထည့်၍ အခြေအနေစစ်ဆေးပါ</p>
        </div>
        <div class="d-flex justify-content-center gap-2 tracker-input">
            <input type="text" id="trackQueueInput" class="form-control text-center rounded-3 text-uppercase fw-bold" placeholder="e.g. Q1234" required>
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
                <div id="statusProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-brand" role="progressbar" style="width: 0%;"></div>
            </div>
            <div id="trackDetails" class="small text-muted"></div>
            <div class="row text-center fs-7 text-muted fw-medium mt-2">
                <div class="col-3" id="step-ordered"><i class="fa-solid fa-circle-check mb-1 d-block"></i>Ordered</div>
                <div class="col-3" id="step-cooking"><i class="fa-solid fa-fire-burner mb-1 d-block"></i>Cooking</div>
                <div class="col-3" id="step-pickup"><i class="fa-solid fa-bag-shopping mb-1 d-block"></i>Pickup</div>
                <div class="col-3" id="step-completed"><i class="fa-solid fa-utensils mb-1 d-block"></i>Ready</div>
            </div>
        </div>
    </div>

    <?php if ($announcements && $announcements->num_rows > 0): ?>
        <div class="mb-4">
            <h6 class="fw-bold text-uppercase text-muted small mb-3">
                <!-- <i class="fa-solid fa-bullhorn text-brand me-2"></i>သတင်းထုတ်ပြန်ချက်များ -->
            </h6>
            <div class="row g-2">
                <?php while ($ann = $announcements->fetch_assoc()): ?>
                    <div class="col-12">
                        <div class="announcement-card p-3 shadow-sm d-flex align-items-start gap-3">
                            <div class="bg-brand text-white rounded-circle p-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;">
                                <i class="fa-solid fa-bullhorn text-white"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($ann['title']) ?></h6>
                                <p class="mb-0 text-secondary small"><?= htmlspecialchars($ann['content']) ?></p>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-dark m-0">ယနေ့ရရှိနိုင်သော အစားအသောက်များ</h5>
        <span class="badge bg-light text-secondary border fw-normal px-3 py-2 rounded-pill">View Only</span>
    </div>

    <!-- ============================================= -->
    <!-- MENU ITEMS - Search Filter ပါ                 -->
    <!-- ============================================= -->
    <div class="row g-3" id="menuContainer">
        <?php if ($menu_items && $menu_items->num_rows > 0): ?>
            <?php while ($item = $menu_items->fetch_assoc()): 
                $imageSrc = "";
                if (!empty($item['image'])) {
                    if (file_exists($item['image'])) $imageSrc = $item['image'];
                    elseif (file_exists('uploads/' . basename($item['image']))) $imageSrc = 'uploads/' . basename($item['image']);
                }
            ?>
                <div class="col-6 col-md-4 col-lg-3 menu-item-card" 
                     data-name="<?= htmlspecialchars(mb_strtolower($item['itemName'], 'UTF-8')) ?>"
                     data-category="<?= htmlspecialchars(mb_strtolower($item['category'] ?? '', 'UTF-8')) ?>">
                    <div class="card h-100 menu-card">
                        <?php if ($imageSrc): ?>
                            <img src="<?= htmlspecialchars($imageSrc) ?>" class="card-img-top menu-card-img" alt="<?= htmlspecialchars($item['itemName']) ?>">
                        <?php else: ?>
                            <div class="bg-light text-muted d-flex align-items-center justify-content-center menu-card-img">
                                <i class="fa-solid fa-utensils fa-2x opacity-25"></i>
                            </div>
                        <?php endif; ?>
                        <div class="card-body d-flex flex-column p-3">
                            <span class="badge bg-brand-light text-brand border-0 w-auto align-self-start mb-1 px-2 py-1 rounded-2 fs-7">
                                <?= htmlspecialchars($item['category'] ?? 'General') ?>
                            </span>
                            <h6 class="card-title fw-bold text-dark mb-1 text-truncate"><?= htmlspecialchars($item['itemName']) ?></h6>
                            
                            <?php if (!empty($item['special_note'])): ?>
                                <small class="text-danger fw-bold mb-1" style="font-size: 0.7rem;">
                                    <i class="fa-solid fa-circle-exclamation me-1"></i>
                                    <?= htmlspecialchars($item['special_note']) ?>
                                </small>
                            <?php endif; ?>
                            
                            <p class="card-text text-warning fw-bold fs-6 mb-3"><?= number_format($item['points']) ?> <small class="text-muted fw-normal fs-7">Points</small></p>
                            <div class="mt-auto">
                                <button onclick="promptLogin()" class="btn btn-outline-secondary btn-sm w-100 py-2 rounded-3 fw-medium">
                                    <i class="fa-solid fa-lock me-1"></i>မှာယူရန် Login ဝင်ပါ
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-12">
                <div class="text-center py-5 bg-white rounded-4 shadow-sm">
                    <i class="fa-solid fa-utensils fa-3x text-brand opacity-25 mb-3"></i>
                    <h6 class="text-dark fw-bold">လက်ရှိတွင် အစားအသောက်စာရင်းများ မရှိသေးပါ။</h6>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// SEARCH FUNCTION
document.getElementById('searchInput').addEventListener('input', function() {
    let filterValue = this.value.toLowerCase().trim();
    let items = document.querySelectorAll('.menu-item-card');

    items.forEach(function(item) {
        let name = item.getAttribute('data-name');
        let category = item.getAttribute('data-category');

        if (name.includes(filterValue) || category.includes(filterValue)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
});

function promptLogin() {
    Swal.fire({
        icon: 'info',
        title: 'Login ဝင်ရောက်ပါ',
        text: 'အစားအသောက်များ မှာယူရန် အကောင့်သို့ အရင် Login ဝင်ရောက်ပေးပါရန်။',
        showCancelButton: true,
        confirmButtonText: 'Login ဝင်မည်',
        cancelButtonText: 'မလုပ်သေးပါ',
        confirmButtonColor: '#1EAFBD',
        customClass: { popup: 'swal-custom-popup' }
    }).then((result) => {
        if (result.isConfirmed) window.location.href = 'login.php';
    });
}

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
            // ✅ CHANGED: ordered + cooking → bg-info
            if (order.status === 'ordered' || order.status === 'cooking') statusClass = 'bg-info text-dark';
            else if (order.status === 'pickup') statusClass = 'bg-primary';
            else if (order.status === 'completed') statusClass = 'bg-success';
            else if (order.status === 'rejected') statusClass = 'bg-danger';
            
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
            if (order.rejectionReason) {
                details += `<div class="mt-2 text-danger"><small><strong>Rejected:</strong> ${order.rejectionReason}</small></div>`;
            }
            document.getElementById('trackDetails').innerHTML = details;

            // Steps mapping
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
                    break;
                case 'rejected':
                    progress = 0;
                    statusBadge.innerText = 'REJECTED';
                    statusBadge.className = 'badge rounded-pill px-3 py-2 fs-7 bg-danger';
                    break;
                default:
                    progress = 10;
                    document.getElementById('step-ordered').classList.add('text-brand', 'fw-bold');
            }
            document.getElementById('statusProgressBar').style.width = progress + '%';

        } else {
            Swal.fire({ icon: 'error', title: 'မတွေ့ရှိပါ', text: data.message || 'ဒီ Queue နံပါတ်ဖြင့် Order မရှိပါ။', confirmButtonColor: '#1EAFBD' });
            resultBox.classList.add('d-none');
        }
    })
    .catch(err => {
        console.error(err);
        Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong.', confirmButtonColor: '#1EAFBD' });
    });
}

document.getElementById('trackQueueInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') trackOrder();
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
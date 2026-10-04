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

// Cart Count
$cart_count = 0;
$cart_stmt = $conn->prepare("SELECT SUM(quantity) as total FROM cart WHERE userId = ?");
if ($cart_stmt) {
    $cart_stmt->bind_param("i", $user_id);
    $cart_stmt->execute();
    $cart_res = $cart_stmt->get_result()->fetch_assoc();
    $cart_count = $cart_res['total'] ?? 0;
    $cart_stmt->close();
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

// Get User Points
$userStmt = $conn->prepare("SELECT points FROM users WHERE userId = ?");
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$userData = $userResult->fetch_assoc();
$currentPoints = $userData['points'] ?? 0;
$userStmt->close();

// Liked items
$stmt = $conn->prepare("SELECT m.* FROM liked_items l 
                        JOIN menu_items m ON l.itemId = m.itemId 
                        WHERE l.userId = ? ORDER BY l.likeId DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$likedItems = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liked Items - UCSH Canteen</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Myanmar:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root { 
            --brand-color: #1EAFBD; 
            --brand-color-hover: #17939F;
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
            transition: all 0.2s ease;
        }
        .btn-brand:hover { 
            background: var(--brand-color-hover); 
            color: white; 
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 175, 189, 0.25);
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

        .like-card {
            border: 1px solid #F1F5F9;
            border-radius: 18px;
            background: #FFFFFF;
            transition: all 0.3s ease;
        }
        .like-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05) !important;
        }

        .remove-like-btn {
            color: #FF4757;
            background: #FFF0F2;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        .remove-like-btn:hover {
            background: #FF4757;
            color: #FFFFFF;
        }

        .swal-custom-popup {
            width: 320px !important;
            padding: 1.25rem !important;
            border-radius: 16px !important;
            font-family: 'Plus Jakarta Sans', 'Noto Sans Myanmar', sans-serif !important;
        }

        .fs-7 { font-size: 0.75rem; }
        
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
    </style>
</head>
<body class="pb-5">

    <!-- ============================================= -->
    <!-- NAVBAR - Home, Queue, History, Points တန်းပြ -->
    <!-- ============================================= -->
    <nav class="navbar navbar-expand-lg sticky-top navbar-custom py-2">
        <div class="container">
            <a class="navbar-brand fw-bold fs-4 text-brand d-flex align-items-center me-3" href="index.php">
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
                    
                    <!-- ============================================= -->
                    <!-- HOME ICON -> Index                           -->
                    <!-- ============================================= -->
                    <a href="index.php" class="nav-icon-btn text-decoration-none" title="Home">
                        <i class="fa-solid fa-house"></i>
                        <span class="d-lg-none ms-2 small">Home</span>
                    </a>

                    <!-- ============================================= -->
                    <!-- QUEUE ICON -> Track                          -->
                    <!-- ============================================= -->
                    <a href="track.php" class="nav-icon-btn text-decoration-none" title="Track Order">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        <span class="d-lg-none ms-2 small">Queue</span>
                    </a>

                    <!-- ============================================= -->
                    <!-- HISTORY ICON -> History                       -->
                    <!-- ============================================= -->
                    <a href="history.php" class="nav-icon-btn text-decoration-none" title="Order History">
                        <i class="fa-solid fa-receipt"></i>
                        <span class="d-lg-none ms-2 small">History</span>
                    </a>

                    <!-- ============================================= -->
                    <!-- POINTS - Show directly in Nav                -->
                    <!-- ============================================= -->
                    <span class="points-nav">
                        <i class="fa-solid fa-coins text-warning"></i> 
                        <span id="userPointsText"><?= number_format($currentPoints) ?></span>
                    </span>

                    <!-- ============================================= -->
                    <!-- LIKES - Active (text-brand)                   -->
                    <!-- ============================================= -->
                    <a href="likes.php" class="nav-icon-btn text-decoration-none text-brand" title="Liked Items">
                        <i class="fa-solid fa-heart text-danger"></i>
                        <span class="badge rounded-pill nav-badge" id="likeBadge"><?= $like_count ?></span>
                        <span class="d-lg-none ms-2 small">Likes</span>
                    </a>

                    <!-- ============================================= -->
                    <!-- CART                                         -->
                    <!-- ============================================= -->
                    <a href="cart.php" class="nav-icon-btn text-decoration-none" title="Cart">
                        <i class="fa-solid fa-cart-shopping"></i>
                        <span class="badge rounded-pill nav-badge" id="cartBadge"><?= $cart_count ?></span>
                        <span class="d-lg-none ms-2 small">Cart</span>
                    </a>

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
                <h5 class="fw-bold mb-0 text-dark me-2">နှစ်သက်သော အစားအသောက်များ</h5>
            </div>
            <span class="badge bg-white text-danger border shadow-sm fw-normal px-3 py-2 rounded-pill fs-7">
                <i class="fa-solid fa-heart me-1"></i><span id="totalLikedCount"><?= $likedItems ? $likedItems->num_rows : 0 ?></span> Items
            </span>
        </div>

        <div class="row g-3" id="likedItemsContainer">
            <?php if ($likedItems && $likedItems->num_rows > 0): ?>
                <?php while ($item = $likedItems->fetch_assoc()): 
                    $imgPath = 'https://via.placeholder.com/100?text=No+Image';
                    if (!empty($item['image'])) {
                        if (file_exists($item['image'])) {
                            $imgPath = $item['image'];
                        } elseif (file_exists('uploads/' . basename($item['image']))) {
                            $imgPath = 'uploads/' . basename($item['image']);
                        }
                    }
                ?>
                    <div class="col-12 liked-item-row" id="itemCard<?= $item['itemId'] ?>" data-name="<?= htmlspecialchars(mb_strtolower($item['itemName'], 'UTF-8')) ?>">
                        <div class="card like-card border-0 shadow-sm p-3">
                            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                                
                                <div class="d-flex align-items-center gap-3">
                                    <img src="<?= $imgPath ?>" class="rounded-3 shadow-sm" style="width: 75px; height: 75px; object-fit: cover;">
                                    <div>
                                        <span class="badge bg-brand-light text-brand fs-7 mb-1"><?= htmlspecialchars($item['category'] ?? 'General') ?></span>
                                        <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($item['itemName']) ?></h6>
                                        <span class="text-warning fw-bold fs-6"><?= number_format($item['points']) ?> <small class="text-muted fw-normal fs-7">Points</small></span>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center gap-2 ms-auto mt-2 mt-sm-0">
                                    <button onclick="removeLike(<?= $item['itemId'] ?>)" class="remove-like-btn" title="Unlike">
                                        <i class="fa-solid fa-heart-broken"></i>
                                    </button>

                                    <button onclick="addToCart(<?= $item['itemId'] ?>)" class="btn btn-brand rounded-3 px-3 py-2 fw-medium fs-7">
                                        <i class="fa-solid fa-cart-plus me-1"></i>ဝယ်မည်
                                    </button>
                                </div>

                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12 text-center py-5 bg-white rounded-4 shadow-sm" id="emptyLikeState">
                    <i class="fa-regular fa-heart fa-3x text-muted opacity-50 mb-3"></i>
                    <h6 class="text-secondary fw-bold">Like လုပ်ထားသော အစားအသောက် မရှိသေးပါ</h6>
                    <p class="text-muted small mb-0">မူလစာမျက်နှာမှ နှစ်သက်ရာများကို ခိုလှုံသိမ်းဆည်းနိုင်ပါသည်။</p>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
        document.getElementById('searchInput').addEventListener('input', function() {
            let filterValue = this.value.toLowerCase().trim();
            let items = document.querySelectorAll('.liked-item-row');

            items.forEach(function(item) {
                let name = item.getAttribute('data-name');
                if (name.includes(filterValue)) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        });

        function addToCart(itemId) {
            let formData = new FormData();
            formData.append('itemId', itemId);
            formData.append('quantity', 1);

            fetch('api.php?action=add_to_cart', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'success') {
                    let cartBadge = document.getElementById('cartBadge');
                    let currentCount = parseInt(cartBadge.innerText) || 0;
                    cartBadge.innerText = data.cartCount ?? (currentCount + 1);

                    Swal.fire({
                        icon: 'success',
                        title: 'Cart ထဲသို့ ထည့်ပြီးပါပြီ',
                        timer: 1500,
                        showConfirmButton: false,
                        customClass: { popup: 'swal-custom-popup' }
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Point မလုံလောက်ပါ',
                        text: data.message || 'သင့်တွင် Point မလုံလောက်ပါ။',
                        confirmButtonColor: '#1EAFBD',
                        customClass: { popup: 'swal-custom-popup' }
                    });
                }
            })
            .catch(err => {
                Swal.fire({
                    icon: 'error',
                    title: 'အမှားတစ်ခု ဖြစ်ပေါ်နေပါသည်',
                    confirmButtonColor: '#1EAFBD',
                    customClass: { popup: 'swal-custom-popup' }
                });
            });
        }

        function removeLike(itemId) {
            let formData = new FormData();
            formData.append('itemId', itemId);
            formData.append('action', 'remove');

            fetch('toggle_like.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                let card = document.getElementById('itemCard' + itemId);
                if(card) {
                    card.style.transition = 'all 0.3s ease';
                    card.style.opacity = '0';
                    card.style.transform = 'scale(0.9)';
                    setTimeout(() => {
                        card.remove();
                        
                        let remainingItems = document.querySelectorAll('.liked-item-row');
                        let totalCountSpan = document.getElementById('totalLikedCount');
                        let count = remainingItems.length;
                        if(totalCountSpan) totalCountSpan.innerText = count;

                        if (count === 0) {
                            let container = document.getElementById('likedItemsContainer');
                            if(container && !document.getElementById('emptyLikeState')) {
                                container.innerHTML = `
                                    <div class="col-12 text-center py-5 bg-white rounded-4 shadow-sm" id="emptyLikeState">
                                        <i class="fa-regular fa-heart fa-3x text-muted opacity-50 mb-3"></i>
                                        <h6 class="text-secondary fw-bold">Like လုပ်ထားသော အစားအသောက် မရှိသေးပါ</h6>
                                        <p class="text-muted small mb-0">မူလစာမျက်နှာမှ နှစ်သက်ရာများကို ခိုလှုံသိမ်းဆည်းနိုင်ပါသည်။</p>
                                    </div>
                                `;
                            }
                        }
                    }, 300);
                }

                let likeBadge = document.getElementById('likeBadge');
                if(likeBadge) {
                    let currentCount = parseInt(likeBadge.innerText) || 0;
                    let newLikeCount = Math.max(0, currentCount - 1);
                    likeBadge.innerText = newLikeCount;
                }

                Swal.fire({
                    icon: 'info',
                    title: 'စာရင်းမှ ဖယ်ရှားပြီးပါပြီ',
                    timer: 1000,
                    showConfirmButton: false,
                    customClass: { popup: 'swal-custom-popup' }
                });
            })
            .catch(err => console.error(err));
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
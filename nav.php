<?php
// nav.php - Include this in all pages (index, likes, cart, history, track)
// Variables needed: $currentPoints, $cart_count, $like_count
?>

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
            <div class="d-flex align-items-center ms-auto gap-1 mt-3 mt-lg-0 justify-content-around flex-column flex-lg-row w-100 w-lg-auto">
                
                <!-- HOME ICON -->
                <a href="index.php" class="nav-icon-btn text-decoration-none" title="Home">
                    <i class="fa-solid fa-house"></i>
                    <span class="d-lg-none ms-2 small">Home</span>
                </a>

                <!-- QUEUE ICON -->
                <a href="track.php" class="nav-icon-btn text-decoration-none" title="Track Order" id="queueIcon">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span class="d-lg-none ms-2 small">Queue</span>
                </a>

                <!-- History -->
                <a href="history.php" class="nav-icon-btn text-decoration-none" title="Order History">
                    <i class="fa-solid fa-receipt"></i>
                    <span class="d-lg-none ms-2 small">History</span>
                </a>

                <!-- Points -->
                <span class="points-nav" id="pointsDisplay">
                    <i class="fa-solid fa-coins text-warning"></i> 
                    <span id="userPointsText"><?= number_format($currentPoints ?? 0) ?></span>
                </span>

                <!-- Likes -->
                <a href="likes.php" class="nav-icon-btn text-decoration-none" title="Liked Items">
                    <i class="fa-regular fa-heart"></i>
                    <span class="badge rounded-pill nav-badge" id="likeBadge"><?= $like_count ?? 0 ?></span>
                    <span class="d-lg-none ms-2 small">Likes</span>
                </a>

                <!-- Cart -->
                <a href="cart.php" class="nav-icon-btn text-decoration-none" title="Cart">
                    <i class="fa-solid fa-cart-shopping"></i>
                    <span class="badge rounded-pill nav-badge" id="cartBadge"><?= $cart_count ?? 0 ?></span>
                    <span class="d-lg-none ms-2 small">Cart</span>
                </a>

                <!-- User Dropdown -->
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

<style>
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
    background-color: #EBF8F9;
    color: #1EAFBD;
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
.search-box .form-control:focus {
    box-shadow: 0 0 0 3px rgba(30, 175, 189, 0.15);
    border-color: #1EAFBD;
    background-color: #FFFFFF;
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
    cursor: default;
}

/* ============================================= */
/* MOBILE RESPONSIVE - LINE BY LINE NAV         */
/* ============================================= */
@media (max-width: 991.98px) {
    .navbar-collapse {
        width: 100%;
    }
    
    #navbarIcons .d-flex.align-items-center.ms-auto {
        flex-direction: column;
        width: 100%;
        gap: 0 !important;
        margin-top: 10px;
        padding: 0;
    }
    
    #navbarIcons .nav-icon-btn,
    #navbarIcons .points-nav,
    #navbarIcons .dropdown {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        padding: 12px 16px;
        border-radius: 10px;
        border-bottom: 1px solid #F1F5F9;
        margin: 0;
    }
    
    #navbarIcons .dropdown {
        border-bottom: none;
    }
    
    #navbarIcons .nav-icon-btn i,
    #navbarIcons .points-nav i,
    #navbarIcons .dropdown i {
        width: 24px;
        text-align: center;
        font-size: 1.1rem;
        margin-right: 12px;
    }
    
    #navbarIcons .d-lg-none {
        display: inline !important;
        margin-left: 0 !important;
        font-size: 0.95rem;
        color: #334155;
    }
    
    #navbarIcons .points-nav {
        background: #FEF3C7;
        border: none;
        border-radius: 10px;
        margin-bottom: 4px;
    }
    
    #navbarIcons .nav-badge {
        position: static;
        margin-left: auto;
        font-size: 0.7rem;
        padding: 4px 10px;
    }
    
    #navbarIcons .dropdown-menu {
        width: 100%;
        margin-top: 5px !important;
    }
    
    #navbarIcons .dropdown > a {
        width: 100%;
        padding: 12px 16px !important;
    }
    
    #navbarIcons .dropdown .d-none.d-lg-inline {
        display: inline !important;
        margin-left: 0 !important;
        color: #334155;
    }
    
    .search-box {
        max-width: 100%;
        margin: 8px 0 !important;
        order: 3;
    }
    
    .navbar-custom .container {
        flex-wrap: wrap;
    }
    
    .navbar-brand {
        order: 1;
    }
    
    .navbar-toggler {
        order: 2;
    }
}
</style>

<script>
// Real-time Points Update
function updatePoints() {
    fetch('get_points.php')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('userPointsText').innerText = Number(data.points).toLocaleString();
            }
        })
        .catch(err => console.log('Points update error:', err));
}

setInterval(() => {
    updatePoints();
}, 5000);

document.addEventListener('DOMContentLoaded', function() {
    updatePoints();
    handleMobileNav();
});

// Search functionality
document.getElementById('searchInput')?.addEventListener('input', function() {
    let filterValue = this.value.toLowerCase().trim();
    let items = document.querySelectorAll('.menu-item-card');
    if (items.length === 0) return;

    items.forEach(function(item) {
        let name = item.getAttribute('data-name') || '';
        let category = item.getAttribute('data-category') || '';
        if (name.includes(filterValue) || category.includes(filterValue)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
});

// =============================================
// MOBILE NAV - LINE BY LINE TOGGLE
// =============================================
function handleMobileNav() {
    const navbarCollapse = document.getElementById('navbarIcons');
    const iconsContainer = navbarCollapse?.querySelector('.d-flex.align-items-center');
    
    if (!iconsContainer) return;
    
    if (window.innerWidth <= 991.98) {
        iconsContainer.classList.add('flex-column');
        iconsContainer.classList.remove('flex-lg-row');
        iconsContainer.style.width = '100%';
        
        const navItems = iconsContainer.children;
        for (let item of navItems) {
            item.style.width = '100%';
            item.style.display = 'flex';
            item.style.alignItems = 'center';
            item.style.justifyContent = 'flex-start';
        }
    } else {
        iconsContainer.classList.remove('flex-column');
        iconsContainer.classList.add('flex-lg-row');
        iconsContainer.style.width = '';
        
        const navItems = iconsContainer.children;
        for (let item of navItems) {
            item.style.width = '';
            item.style.display = '';
            item.style.alignItems = '';
            item.style.justifyContent = '';
        }
    }
}

window.addEventListener('resize', function() {
    handleMobileNav();
});
</script>
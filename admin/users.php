<?php
require_once '../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// =============================================
// HANDLE DELETE USER (AJAX)
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    header('Content-Type: application/json');
    
    $userId = intval($_POST['userId']);
    
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
        exit();
    }
    
    // Check if trying to delete self
    if (isset($_SESSION['user_id']) && $userId == $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'သင့်ကိုယ်သင် ဖျက်၍မရပါ။']);
        exit();
    }
    
    // Delete user
    $stmt = $conn->prepare("DELETE FROM users WHERE userId = ?");
    $stmt->bind_param("i", $userId);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'User ကို အောင်မြင်စွာ ဖျက်ပြီးပါပြီ။']);
        } else {
            echo json_encode(['success' => false, 'message' => 'User မတွေ့ပါ။']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'ဖျက်ရာတွင် အမှားရှိပါသည်။']);
    }
    $stmt->close();
    exit();
}

// Fetch all users
$users_query = "SELECT userId, username, phoneNumber, points, role, createdAt FROM users ORDER BY userId DESC";
$users_result = $conn->query($users_query);
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UCSH Admin - Users</title>
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
        .btn-brand { background-color: var(--brand-color); color: white; border: none; }
        .btn-brand:hover { background-color: var(--brand-hover); color: white; }
        
        /* Row delete animation */
        .row-deleting {
            opacity: 0 !important;
            transform: translateX(50px) !important;
            transition: all 0.3s ease !important;
        }
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
            <a href="users.php" class="nav-link-custom active"><i class="fa-solid fa-users"></i> Users</a>
            <a href="announcements.php" class="nav-link-custom"><i class="fa-solid fa-bullhorn"></i> Announcements</a>
        </div>
        <hr class="text-muted">
        <div><a href="../logout.php" class="nav-link-custom text-danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></div>
    </div>

    <div class="flex-grow-1 p-3 p-md-4 overflow-hidden">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold m-0 text-dark">User Management</h4>
            <span class="text-muted small" id="totalUsers">Total Users: <?= $users_result ? $users_result->num_rows : 0 ?></span>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold m-0 text-dark"><i class="fa-solid fa-users text-brand me-2"></i>All Users</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="border-0 text-secondary small fw-bold">#</th>
                            <th class="border-0 text-secondary small fw-bold">Username</th>
                            <th class="border-0 text-secondary small fw-bold">Phone</th>
                            <th class="border-0 text-secondary small fw-bold">Points</th>
                            <th class="border-0 text-secondary small fw-bold">Role</th>
                            <th class="border-0 text-secondary small fw-bold">Joined</th>
                            <th class="border-0 text-secondary small fw-bold text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <?php if ($users_result && $users_result->num_rows > 0): ?>
                            <?php $serial = 1; while ($user = $users_result->fetch_assoc()): ?>
                                <tr id="userRow<?= $user['userId'] ?>">
                                    <td class="fw-bold text-dark serial-number"><?= $serial++ ?></td>
                                    <td class="fw-medium text-dark"><?= htmlspecialchars($user['username']) ?></td>
                                    <td><?= htmlspecialchars($user['phoneNumber']) ?></td>
                                    <td><span class="badge bg-warning text-dark"><?= number_format($user['points']) ?></span></td>
                                    <td>
                                        <span class="badge <?= $user['role'] === 'Admin' ? 'bg-danger' : 'bg-info text-dark' ?>">
                                            <?= $user['role'] ?>
                                        </span>
                                    </td>
                                    <td><small class="text-muted"><?= date('d M Y', strtotime($user['createdAt'])) ?></small></td>
                                    <td class="text-center">
                                        <?php if ($user['userId'] != $_SESSION['user_id']): ?>
                                            <button class="btn btn-sm btn-light border text-danger rounded-3 delete-user-btn" 
                                                    data-userid="<?= $user['userId'] ?>"
                                                    data-username="<?= htmlspecialchars($user['username']) ?>"
                                                    title="Delete User">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted">You</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">Users များ မရှိသေးပါ။</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // =============================================
    // UPDATE SERIAL NUMBERS
    // =============================================
    function updateSerialNumbers() {
        const rows = document.querySelectorAll('#usersTableBody tr');
        rows.forEach((row, index) => {
            const serialTd = row.querySelector('.serial-number');
            if (serialTd) {
                serialTd.textContent = index + 1;
            }
        });
    }
    
    // =============================================
    // DELETE USER WITH SWEETALERT2 (AJAX)
    // =============================================
    document.querySelectorAll('.delete-user-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            
            const userId = this.getAttribute('data-userid');
            const username = this.getAttribute('data-username');
            const row = document.getElementById('userRow' + userId);
            
            Swal.fire({
                title: 'User ကိုဖျက်မည်',
                html: `<strong>${username}</strong> ကို ဖျက်ပစ်မှာသေချာပါသလား?<br><small class="text-danger">ဤအချက်ကို ပြန်ယူ၍မရပါ။</small>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fa-solid fa-trash me-1"></i>ဖျက်မည်',
                cancelButtonText: 'မလုပ်တော့ပါ',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'ဖျက်နေပါသည်...',
                        text: 'ကျေးဇူးပြု၍ စောင့်ပါ',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // AJAX request to delete (same page)
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=delete_user&userId=' + userId
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Animate and remove row
                            if (row) {
                                row.classList.add('row-deleting');
                                setTimeout(() => {
                                    row.remove();
                                    // Update total count
                                    const remainingRows = document.querySelectorAll('#usersTableBody tr').length;
                                    document.getElementById('totalUsers').textContent = 'Total Users: ' + remainingRows;
                                    // Update serial numbers
                                    updateSerialNumbers();
                                }, 350);
                            }
                            
                            Swal.fire({
                                icon: 'success',
                                title: 'ဖျက်ပြီးပါပြီ!',
                                text: data.message || 'User ကို အောင်မြင်စွာ ဖျက်ပြီးပါပြီ။',
                                timer: 2000,
                                showConfirmButton: false
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'အမှားရှိပါသည်!',
                                text: data.message || 'ဖျက်ရာတွင် အမှားရှိပါသည်။',
                                confirmButtonColor: '#1EAFBD'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.fire({
                            icon: 'error',
                            title: 'အမှားရှိပါသည်!',
                            text: 'Server နှင့် ဆက်သွယ်ရာတွင် အမှားရှိပါသည်။',
                            confirmButtonColor: '#1EAFBD'
                        });
                    });
                }
            });
        });
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
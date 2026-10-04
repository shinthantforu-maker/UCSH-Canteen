<?php
// ##########################################
// # 1. DATABASE CONNECTION & SESSION START  #
// ##########################################
require_once 'db.php';

// Session မစရသေးပါက စတင်ပေးခြင်း
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session တွင် Admin Role ရှိပြီးသားဖြစ်ပါက admin/admin.php သို့ Direct ပို့ပေးခြင်း
if (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin') {
    header("Location: admin/admin.php");
    exit();
}

$error_msg = "";
$redirect_url = "";

// ##########################################
// # 2. LOGIN PROCESS                        #
// ##########################################
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_id = trim($_POST['username'] ?? ''); // ဝင်ရောက်ရန် ရိုက်ထည့်လိုက်သော Phone သို့မဟုတ် Username
    $password = trim($_POST['password'] ?? '');

    if (!empty($input_id) && !empty($password)) {
        // Admin ဖြစ်ပါက username ဖြင့်၊ သာမန် User ဖြစ်ပါက phoneNumber ဖြင့် ရှာဖွေရန်
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? OR phoneNumber = ? LIMIT 1");
        $stmt->bind_param("ss", $input_id, $input_id);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows === 1) {
            $user = $res->fetch_assoc();

            $is_admin = (strtolower($user['role']) === 'admin');

            // လုံခြုံရေးအရ Admin ကို Username ဖြင့်သာ ဝင်ခွင့်ပြုမည် (Phone Number ဖြင့် Admin ဝင်ခွင့်မပေးပါ)
            if ($is_admin && $input_id !== $user['username']) {
                $error_msg = "Admin အကောင့်သည် Username ဖြင့်သာ ဝင်ရောက်ရပါမည်။";
            } else {
                // Password စစ်ဆေးခြင်း (Hash Verification သို့မဟုတ် Direct 'ucshadmin' Check)
                if (password_verify($password, $user['password']) || ($is_admin && $password === 'ucshadmin')) {
                    
                    // Session ထဲသို့ Set လုပ်ခြင်း
                    $_SESSION['user_id'] = $user['userId'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];

                    // Target URL သတ်မှတ်ခြင်း (SweetAlert ပြပြီးမှ ရွှေ့ရန်)
                    if ($is_admin) {
                        $redirect_url = "admin/admin.php";
                    } else {
                        $redirect_url = "index.php";
                    }

                } else {
                    $error_msg = "Password မှားယွင်းနေပါသည်။";
                }
            }
        } else {
            $error_msg = "ဤ Username (သို့) ဖုန်းနံပါတ်ဖြင့် အကောင့်မရှိပါ။";
        }
        $stmt->close();
    } else {
        $error_msg = "အချက်အလက်များကို ပြည့်စုံစွာ ဖြည့်သွင်းပေးပါ။";
    }
}
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - UCSH Smart Canteen</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Myanmar:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --brand-color: #1EAFBD;
            --brand-hover: #17939F;
            --brand-light: #EBF8F9;
        }

        body {
            font-family: 'Poppins', 'Noto Sans Myanmar', sans-serif;
            background-color: #F8FAFC;
            min-height: 100vh;
        }

        .login-card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            background: #FFFFFF;
            width: 100%;
            max-width: 400px;
            margin: 20px auto;
        }

        .text-brand { color: var(--brand-color) !important; }
        .bg-brand { background-color: var(--brand-color) !important; }

        .btn-brand {
            background-color: var(--brand-color);
            color: #FFFFFF;
            border: none;
            padding: 12px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-brand:hover {
            background-color: var(--brand-hover);
            color: #FFFFFF;
        }

        .form-control {
            border-radius: 12px;
            padding: 12px 15px;
            border: 1px solid #E2E8F0;
            background-color: #F8FAFC;
        }

        .form-control:focus {
            border-color: var(--brand-color);
            box-shadow: 0 0 0 3px rgba(30, 175, 189, 0.15);
            background-color: #FFFFFF;
        }

        /* Navbar Styles */
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
    </style>
</head>
<body>

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
                <div class="d-flex align-items-center ms-auto gap-1 mt-3 mt-lg-0">
                    <a href="guest.php" class="nav-icon-btn text-decoration-none" title="Home">
                        <i class="fa-solid fa-house"></i>
                        <span class="d-lg-none ms-2 small">Home</span>
                    </a>
                    <a href="login.php" class="btn btn-brand btn-sm px-3 rounded-pill fw-medium">
                        <i class="fa-solid fa-right-to-bracket me-1"></i> Login
                    </a>
                    <a href="register.php" class="btn btn-outline-secondary btn-sm px-3 rounded-pill fw-medium">
                        <i class="fa-solid fa-user-plus me-1"></i> Register
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container d-flex justify-content-center align-items-center" style="min-height: calc(100vh - 80px);">
        <div class="login-card p-4">
            <div class="text-center mb-4">
                <h5 class="fw-bold text-dark mt-2">အကောင့်သို့ ဝင်ရောက်ပါ</h5>
            </div>

            <form action="login.php" method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Phone Number</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 rounded-start-3 text-muted"><i class="fa-solid fa-phone"></i></span>
                        <input type="text" name="username" class="form-control border-start-0" placeholder="ဖုန်းနံပါတ်ထည့်ပါ" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-secondary">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 rounded-start-3 text-muted"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" name="password" class="form-control border-start-0" placeholder="••••••••" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-brand w-100 mb-3">
                    <i class="fa-solid fa-right-to-bracket me-2"></i>Login ဝင်မည်
                </button>

                <div class="text-center">
                    <p class="small text-muted mb-0">အကောင့်မရှိသေးပါက <a href="register.php" class="text-brand fw-bold text-decoration-none">အကောင့်သစ်ဖန်တီးပါ</a></p>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($redirect_url)): ?>
    <script>
        Swal.fire({
            icon: 'success',
            title: 'Login ဝင်ရောက်ခြင်း အောင်မြင်ပါသည်!',
            confirmButtonColor: '#1EAFBD',
            timer: 1500,
            showConfirmButton: false,
            width: '380px',
            padding: '1.25rem',
            customClass: {
                popup: 'rounded-4'
            }
        }).then(function() {
            window.location.href = '<?= $redirect_url ?>';
        });
    </script>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
    <script>
        Swal.fire({
            icon: 'error',
            title: 'Login မအောင်မြင်ပါ',
            text: '<?= htmlspecialchars($error_msg) ?>',
            confirmButtonColor: '#1EAFBD',
            customClass: {
                popup: 'rounded-4'
            }
        });
    </script>
    <?php endif; ?>

</body>
</html>
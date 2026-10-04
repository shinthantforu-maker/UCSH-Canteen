<?php
// ##########################################
// # 1. DATABASE CONNECTION & LOGIC         #
// ##########################################
require_once 'db.php';

$error_msg = "";
$success = false;

// Form Submit လုပ်လိုက်သောအခါ
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username     = trim($_POST['username']);
    $phoneNumber  = trim($_POST['phoneNumber']);
    $password     = $_POST['password'];
    $confirm_pass = $_POST['confirm_password'];

    // Back-end Validation စစ်ဆေးခြင်း
    if (!preg_match("/^[a-zA-Z\s\x{0980}-\x{09FF}\x{1000}-\x{109F}]+$/u", $username)) {
        $error_msg = "နာမည်တွင် စာလုံးနှင့် Space မှလွဲ၍ ဂဏန်း သို့မဟုတ် Special Character များ မပါရပါ!";
    } elseif (!preg_match("/^[0-9]{8,11}$/", $phoneNumber)) {
        $error_msg = "ဖုန်းနံပါတ်သည် ဂဏန်းသီးသန့် (၈ လုံးမှ ၁၁ လုံးအတွင်း) ဖြစ်ရပါမည်!";
    } elseif (strlen($password) < 4 || strlen($password) > 8) {
        $error_msg = "Password သည် ၄ လုံးမှ ၈ လုံးအတွင်း ဖြစ်ရပါမည်!";
    } elseif ($password !== $confirm_pass) {
        $error_msg = "Password နှစ်ခု ကိုက်ညီမှု မရှိပါ!";
    } else {
        // ဖုန်းနံပါတ် ထပ်မထပ် Database ထဲ စစ်ဆေးခြင်း
        $stmt = $conn->prepare("SELECT userId FROM users WHERE phoneNumber = ?");
        $stmt->bind_param("s", $phoneNumber);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error_msg = "ဒီဖုန်းနံပါတ်ဖြင့် အကောင့်ဖွင့်ပြီးသား ဖြစ်နေပါသည်။";
        } else {
            // Password ကို BCRYPT Hash လုပ်ခြင်း
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $role = 'Customer';
            $customerType = 'Logged';

            // Database ထဲ Insert ထည့်ခြင်း (points အတွက် default 1000 ထည့်ပေးထားသည်)
            $insert_stmt = $conn->prepare("INSERT INTO users (username, phoneNumber, password, role, customerType, points) VALUES (?, ?, ?, ?, ?, 1000)");
            $insert_stmt->bind_param("sssss", $username, $phoneNumber, $hashed_password, $role, $customerType);

            if ($insert_stmt->execute()) {
                $success = true;
            } else {
                $error_msg = "အကောင့်ဖွင့်ခြင်း မအောင်မြင်ပါ။ ပြန်လည်ကြိုးစားပါ။";
            }
            $insert_stmt->close();
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - UCSH Smart Canteen</title>

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

        .register-card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            background: #FFFFFF;
            width: 100%;
            max-width: 420px;
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

    <!-- Navbar -->
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

    <div class="container d-flex justify-content-center align-items-center" style="min-height: calc(100vh - 80px);">
        <div class="register-card p-4">
            <div class="text-center mb-4">
                <h5 class="fw-bold text-dark mt-2">အကောင့်သစ်ဖန်တီးပါ</h5>
                <p class="text-muted small">စမတ်ကန်တင်း စနစ်ကို အသုံးပြုရန် အချက်အလက်များ ဖြည့်ပါ။</p>
            </div>

            <form id="registerForm" action="register.php" method="POST" onsubmit="return validateForm()">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">အမည် (Name)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 rounded-start-3 text-muted"><i class="fa-regular fa-user"></i></span>
                        <input type="text" id="username" name="username" class="form-control border-start-0" placeholder="e.g. Aung Aung" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">ဖုန်းနံပါတ် (Phone Number)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 rounded-start-3 text-muted"><i class="fa-solid fa-phone"></i></span>
                        <input type="tel" id="phoneNumber" name="phoneNumber" class="form-control border-start-0" placeholder="09xxxxxxxxx" required value="<?= htmlspecialchars($_POST['phoneNumber'] ?? '') ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Password (၄ - ၈ လုံး)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 rounded-start-3 text-muted"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" id="password" name="password" class="form-control border-start-0" placeholder="••••••••" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-secondary">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 rounded-start-3 text-muted"><i class="fa-solid fa-shield-halved"></i></span>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control border-start-0" placeholder="••••••••" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-brand w-100 mb-3">
                    <i class="fa-solid fa-user-plus me-2"></i>Register ပြုလုပ်မည်
                </button>

                <div class="text-center">
                    <p class="small text-muted mb-0">အကောင့်ရှိပြီးသားဖြစ်ပါသလား? <a href="login.php" class="text-brand fw-bold text-decoration-none">Login ဝင်ပါ</a></p>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Front-end JavaScript Validation Function
        function validateForm() {
            let name = document.getElementById('username').value.trim();
            let phone = document.getElementById('phoneNumber').value.trim();
            let pass = document.getElementById('password').value;
            let confirmPass = document.getElementById('confirm_password').value;

            // Name validation (Letters and Space only)
            let nameRegex = /^[a-zA-Z\s\u1000-\u109F]+$/;
            if (!nameRegex.test(name)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'နာမည် အမှားပါဝင်နေပါသည်',
                    text: 'နာမည်တွင် စာလုံးနှင့် Space မှလွဲ၍ ဂဏန်း သို့မဟုတ် Special Character များ မပါရပါ!',
                    width: '420px',
                    confirmButtonColor: '#1EAFBD'
                });
                return false;
            }

            // Phone validation (8 to 11 digits)
            let phoneRegex = /^[0-9]{8,11}$/;
            if (!phoneRegex.test(phone)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'ဖုန်းနံပါတ် အမှားပါဝင်နေပါသည်',
                    text: 'ဖုန်းနံပါတ်သည် ဂဏန်းသီးသန့် ၈ လုံးမှ ၁၁ လုံးအတွင်း ဖြစ်ရပါမည်!',
                    width: '420px',
                    confirmButtonColor: '#1EAFBD'
                });
                return false;
            }

            // Password length validation (4 to 8 characters)
            if (pass.length < 4 || pass.length > 8) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Password အရှည် မမှန်ပါ',
                    text: 'Password သည် ၄ လုံးမှ ၈ လုံးအတွင်း ဖြစ်ရပါမည်!',
                    width: '420px',
                    confirmButtonColor: '#1EAFBD'
                });
                return false;
            }

            // Confirm Password validation
            if (pass !== confirmPass) {
                Swal.fire({
                    icon: 'error',
                    title: 'Password မတူညီပါ',
                    text: 'Password နှင့် Confirm Password တူညီအောင် ရိုက်ထည့်ပေးပါ!',
                    width: '420px',
                    confirmButtonColor: '#1EAFBD'
                });
                return false;
            }

            return true;
        }
    </script>

    <?php if ($success): ?>
        <script>
            Swal.fire({
                icon: 'success',
                title: 'အကောင့်ဖွင့်ခြင်း အောင်မြင်ပါသည်!',
                width: '420px',
                confirmButtonColor: '#1EAFBD',
                timer: 2000,
                showConfirmButton: false
            }).then(function() {
                window.location = 'login.php';
            });
        </script>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <script>
            Swal.fire({
                icon: 'error',
                title: 'မအောင်မြင်ပါ',
                width: '420px',
                text: '<?= $error_msg ?>',
                confirmButtonColor: '#1EAFBD'
            });
        </script>
    <?php endif; ?>

</body>
</html>
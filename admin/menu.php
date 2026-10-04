<?php
require_once '../db.php';

if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$message = "";
$message_type = "";

// Add special_note column if not exists
$conn->query("ALTER TABLE menu_items ADD COLUMN IF NOT EXISTS special_note VARCHAR(255) DEFAULT NULL");

// =============================================
// ALLOWED IMAGE TYPES: ONLY PNG AND JPEG
// =============================================
$allowed_types = ['image/png', 'image/jpeg', 'image/jpg'];
$allowed_extensions = ['png', 'jpg', 'jpeg'];

function isAllowedImage($file) {
    $allowed_types = ['image/png', 'image/jpeg', 'image/jpg'];
    $allowed_extensions = ['png', 'jpg', 'jpeg'];
    
    // Check mime type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        return false;
    }
    
    // Check extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions)) {
        return false;
    }
    
    return true;
}

// CRUD Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'add_item') {
        $itemName = trim($_POST['itemName']);
        $category = trim($_POST['category']);
        $points = intval($_POST['points']);
        $isAvailable = isset($_POST['isAvailable']) ? 1 : 0;
        $specialNote = trim($_POST['special_note'] ?? '');
        $imagePath = "";

        // =============================================
        // IMAGE UPLOAD - PNG/JPEG ONLY
        // =============================================
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            
            // Check if allowed
            if (!isAllowedImage($_FILES['image'])) {
                $message = "PNG နှင့် JPEG ပုံများကိုသာ တင်ခွင့်ပြုပါသည်။";
                $message_type = "danger";
            } else {
                $targetDir = "../uploads/";
                if (!file_exists($targetDir)) {
                    mkdir($targetDir, 0777, true);
                }
                $extension = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
                $fileName = time() . '_' . uniqid() . '.' . $extension;
                $targetFilePath = $targetDir . $fileName;
                
                if (move_uploaded_file($_FILES["image"]["tmp_name"], $targetFilePath)) {
                    $imagePath = "uploads/" . $fileName;
                }
            }
        }
        
        // Only proceed if no error
        if (empty($message) || $message_type !== 'danger') {
            $stmt = $conn->prepare("INSERT INTO menu_items (itemName, category, points, isAvailable, image, special_note) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdiss", $itemName, $category, $points, $isAvailable, $imagePath, $specialNote);
            if ($stmt->execute()) {
                $message = "Menu Item အသစ် '{$itemName}' ကို အောင်မြင်စွာ ထည့်သွင်းပြီးပါပြီ။";
                if (!empty($specialNote)) {
                    $message .= " Special Note: " . htmlspecialchars($specialNote);
                }
                $message_type = "success";
            } else {
                $message = "Menu Item ထည့်သွင်းရာတွင် အမှားအယွင်းရှိနေပါသည်။";
                $message_type = "danger";
            }
            $stmt->close();
        }
    }

    if ($_POST['action'] === 'edit_item') {
        $itemId = intval($_POST['itemId']);
        $itemName = trim($_POST['itemName']);
        $category = trim($_POST['category']);
        $points = intval($_POST['points']);
        $isAvailable = isset($_POST['isAvailable']) ? 1 : 0;
        $specialNote = trim($_POST['special_note'] ?? '');

        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            
            // Check if allowed
            if (!isAllowedImage($_FILES['image'])) {
                $message = "PNG နှင့် JPEG ပုံများကိုသာ တင်ခွင့်ပြုပါသည်။";
                $message_type = "danger";
            } else {
                $targetDir = "../uploads/";
                if (!file_exists($targetDir)) {
                    mkdir($targetDir, 0777, true);
                }
                $extension = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
                $fileName = time() . '_' . uniqid() . '.' . $extension;
                $targetFilePath = $targetDir . $fileName;
                
                if (move_uploaded_file($_FILES["image"]["tmp_name"], $targetFilePath)) {
                    $imagePath = "uploads/" . $fileName;
                    $stmt = $conn->prepare("UPDATE menu_items SET itemName = ?, category = ?, points = ?, isAvailable = ?, image = ?, special_note = ? WHERE itemId = ?");
                    $stmt->bind_param("ssdissi", $itemName, $category, $points, $isAvailable, $imagePath, $specialNote, $itemId);
                    if ($stmt->execute()) {
                        $message = "Menu Item ကို ပြင်ဆင်ပြီးပါပြီ။";
                        $message_type = "success";
                    }
                    $stmt->close();
                }
            }
        } else {
            $stmt = $conn->prepare("UPDATE menu_items SET itemName = ?, category = ?, points = ?, isAvailable = ?, special_note = ? WHERE itemId = ?");
            $stmt->bind_param("ssdssi", $itemName, $category, $points, $isAvailable, $specialNote, $itemId);
            if ($stmt->execute()) {
                $message = "Menu Item ကို ပြင်ဆင်ပြီးပါပြီ။";
                $message_type = "success";
            }
            $stmt->close();
        }
    }

    if ($_POST['action'] === 'toggle_stock') {
        $itemId = intval($_POST['itemId']);
        $status = intval($_POST['current_status']) === 1 ? 0 : 1;
        $stmt = $conn->prepare("UPDATE menu_items SET isAvailable = ? WHERE itemId = ?");
        $stmt->bind_param("ii", $status, $itemId);
        $stmt->execute();
        $stmt->close();
        $message = "Stock Status ကို ပြောင်းလဲပြီးပါပြီ။";
        $message_type = "info";
    }

    if ($_POST['action'] === 'delete_item') {
        $itemId = intval($_POST['itemId']);
        $stmt = $conn->prepare("DELETE FROM menu_items WHERE itemId = ?");
        $stmt->bind_param("i", $itemId);
        if ($stmt->execute()) {
            $message = "Menu Item ကို အပြီးဖျက်ဆီးပြီးပါပြီ။";
            $message_type = "warning";
        }
        $stmt->close();
    }
}

$menu_result = $conn->query("SELECT * FROM menu_items ORDER BY itemId DESC");
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UCSH Admin - Manage Menu</title>
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
        .menu-img-preview { width: 50px; height: 50px; object-fit: cover; border-radius: 8px; }
        @media (max-width: 991.98px) { .sidebar { position: fixed; top: 0; left: -260px; z-index: 1050; } .sidebar.show { left: 0; } }
        
        /* Image upload preview */
        .image-preview {
            max-width: 150px;
            max-height: 150px;
            border-radius: 8px;
            border: 1px solid #E2E8F0;
            padding: 4px;
            display: none;
        }
        .image-preview.show {
            display: block;
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
            <a href="menu.php" class="nav-link-custom active"><i class="fa-solid fa-bowl-food"></i> Manage Menu</a>
            <!-- <a href="orders.php" class="nav-link-custom"><i class="fa-solid fa-list-check"></i> Orders</a> -->
            <a href="users.php" class="nav-link-custom"><i class="fa-solid fa-users"></i> Users</a>
            <a href="announcements.php" class="nav-link-custom"><i class="fa-solid fa-bullhorn"></i> Announcements</a>
        </div>
        <hr class="text-muted">
        <div><a href="../logout.php" class="nav-link-custom text-danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></div>
    </div>

    <div class="flex-grow-1 p-3 p-md-4 overflow-hidden">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <button class="btn btn-light d-lg-none border-0" type="button" onclick="document.getElementById('sidebar').classList.toggle('show')">
                <i class="fa-solid fa-bars fs-5 text-dark"></i>
            </button>
            <h4 class="fw-bold m-0 text-dark">Menu Management</h4>
            <button class="btn bg-brand text-white rounded-3 fw-medium" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="fa-solid fa-plus me-1"></i> Menu အသစ်ထည့်မည်
            </button>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show rounded-3" role="alert">
                <i class="fa-solid fa-circle-info me-2"></i><?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold m-0 text-dark"><i class="fa-solid fa-bowl-food text-brand me-2"></i>All Menu Items</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="border-0 text-secondary small fw-bold">Image</th>
                            <th class="border-0 text-secondary small fw-bold">Item Name</th>
                            <th class="border-0 text-secondary small fw-bold">Category</th>
                            <th class="border-0 text-secondary small fw-bold">Points</th>
                            <th class="border-0 text-secondary small fw-bold">Special Note</th>
                            <th class="border-0 text-secondary small fw-bold">Availability</th>
                            <th class="border-0 text-secondary small fw-bold text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($menu_result && $menu_result->num_rows > 0): ?>
                            <?php while ($item = $menu_result->fetch_assoc()): ?>
                                <tr>
                                    <td><img src="<?= !empty($item['image']) ? '../' . htmlspecialchars($item['image']) : 'https://via.placeholder.com/50' ?>" class="menu-img-preview border" alt="Menu"></td>
                                    <td class="fw-medium text-dark"><?= htmlspecialchars($item['itemName']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($item['category']) ?></span></td>
                                    <td class="fw-bold text-warning"><?= number_format($item['points']) ?></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($item['special_note'] ?? '-') ?></small></td>
                                    <td>
                                        <form method="POST" action="menu.php" class="d-inline">
                                            <input type="hidden" name="action" value="toggle_stock">
                                            <input type="hidden" name="itemId" value="<?= $item['itemId'] ?>">
                                            <input type="hidden" name="current_status" value="<?= $item['isAvailable'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $item['isAvailable'] ? 'btn-success-subtle text-success border-success' : 'btn-danger-subtle text-danger border-danger' ?> rounded-pill px-3 fw-medium">
                                                <i class="fa-solid <?= $item['isAvailable'] ? 'fa-check' : 'fa-xmark' ?> me-1"></i>
                                                <?= $item['isAvailable'] ? 'In Stock' : 'Out of Stock' ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-light border text-brand me-1 rounded-3" data-bs-toggle="modal" data-bs-target="#editModal<?= $item['itemId'] ?>">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="POST" action="menu.php" class="d-inline" onsubmit="return confirm('ဒီ Menu Item ကို ဖျက်ရန် သေချာပါသလား?');">
                                            <input type="hidden" name="action" value="delete_item">
                                            <input type="hidden" name="itemId" value="<?= $item['itemId'] ?>">
                                            <button type="submit" class="btn btn-sm btn-light border text-danger rounded-3">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>

                                <!-- Edit Modal -->
                                <div class="modal fade" id="editModal<?= $item['itemId'] ?>" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content rounded-4 border-0">
                                            <div class="modal-header bg-light border-0">
                                                <h6 class="fw-bold m-0"><i class="fa-solid fa-pen-to-square text-brand me-2"></i>Menu Item ပြင်ဆင်ရန်</h6>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST" action="menu.php" enctype="multipart/form-data">
                                                <div class="modal-body p-4">
                                                    <input type="hidden" name="action" value="edit_item">
                                                    <input type="hidden" name="itemId" value="<?= $item['itemId'] ?>">
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-bold">Item နာမည်</label>
                                                        <input type="text" name="itemName" class="form-control" value="<?= htmlspecialchars($item['itemName']) ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-bold">Category</label>
                                                        <select name="category" class="form-select">
                                                            <option value="Food" <?= $item['category'] === 'Food' ? 'selected' : '' ?>>Food</option>
                                                            <option value="Drink" <?= $item['category'] === 'Drink' ? 'selected' : '' ?>>Drink</option>
                                                            <option value="Snack" <?= $item['category'] === 'Snack' ? 'selected' : '' ?>>Snack</option>
                                                        </select>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-bold">Points</label>
                                                        <input type="number" name="points" class="form-control" value="<?= $item['points'] ?>" required min="1">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-bold">Special Note (တစ်ခုခုမှာလို့ရအောင်)</label>
                                                        <input type="text" name="special_note" class="form-control" value="<?= htmlspecialchars($item['special_note'] ?? '') ?>" placeholder="ဥပမာ - ဆားနည်းနည်းလျှော့ပေးပါ...">
                                                        <small class="text-muted">Customer က ဒီမှာရေးထားတဲ့အတိုင်း မှာလို့ရပါတယ်</small>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-bold">ဓာတ်ပုံ (အသစ်လဲလိုပါက) <span class="text-danger">*PNG သို့မဟုတ် JPEG သာ</span></label>
                                                        <input type="file" name="image" class="form-control" accept=".png,.jpg,.jpeg,image/png,image/jpeg" onchange="previewImage(this, 'editPreview<?= $item['itemId'] ?>')">
                                                        <img id="editPreview<?= $item['itemId'] ?>" class="image-preview mt-2" src="#" alt="Preview">
                                                        <small class="text-muted d-block">PNG နှင့် JPEG ပုံများကိုသာ တင်ခွင့်ပြုပါသည်။</small>
                                                    </div>
                                                    <div class="form-check form-switch mb-2">
                                                        <input class="form-check-input" type="checkbox" name="isAvailable" id="avail<?= $item['itemId'] ?>" <?= $item['isAvailable'] ? 'checked' : '' ?>>
                                                        <label class="form-check-label small fw-bold" for="avail<?= $item['itemId'] ?>">In Stock</label>
                                                    </div>
                                                </div>
                                                <div class="modal-footer border-0 bg-light">
                                                    <button type="button" class="btn btn-secondary btn-sm rounded-3" data-bs-dismiss="modal">မလုပ်တော့ပါ</button>
                                                    <button type="submit" class="btn bg-brand text-white btn-sm rounded-3 fw-medium">သိမ်းဆည်းမည်</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">Menu Item များ မရှိသေးပါ။</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ============================================= -->
<!-- ADD ITEM MODAL - PNG/JPEG ONLY                -->
<!-- ============================================= -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header bg-light border-0">
                <h6 class="fw-bold m-0"><i class="fa-solid fa-plus text-brand me-2"></i>Menu Item အသစ်ထည့်မည်</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="menu.php" enctype="multipart/form-data" id="addItemForm">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="add_item">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Item နာမည်</label>
                        <input type="text" name="itemName" class="form-control" placeholder="ဥပမာ - ကြက်သားဆီချက်" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Category</label>
                        <select name="category" class="form-select">
                            <option value="Food">Food</option>
                            <option value="Drink">Drink</option>
                            <option value="Snack">Snack</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Points</label>
                        <input type="number" name="points" class="form-control" placeholder="Points ပမာဏ" required min="1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Special Note (တစ်ခုခုမှာလို့ရအောင်)</label>
                        <input type="text" name="special_note" class="form-control" placeholder="ဥပမာ - ဆားနည်းနည်းလျှော့ပေးပါ...">
                        <small class="text-muted">Customer က ဒီမှာရေးထားတဲ့အတိုင်း မှာလို့ရပါတယ်</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">ဓာတ်ပုံ <span class="text-danger">*PNG သို့မဟုတ် JPEG သာ</span></label>
                        <input type="file" name="image" id="imageInput" class="form-control" accept=".png,.jpg,.jpeg,image/png,image/jpeg" onchange="previewImage(this, 'addPreview')">
                        <img id="addPreview" class="image-preview mt-2" src="#" alt="Preview">
                        <small class="text-muted d-block">PNG နှင့် JPEG ပုံများကိုသာ တင်ခွင့်ပြုပါသည်။</small>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="isAvailable" id="addAvail" checked>
                        <label class="form-check-label small fw-bold" for="addAvail">In Stock</label>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-secondary btn-sm rounded-3" data-bs-dismiss="modal">မလုပ်တော့ပါ</button>
                    <button type="submit" class="btn bg-brand text-white btn-sm rounded-3 fw-medium" id="submitAddBtn">အသစ်ထည့်မည်</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// =============================================
// IMAGE PREVIEW FUNCTION
// =============================================
function previewImage(input, previewId) {
    var preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.classList.add('show');
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        preview.src = '#';
        preview.classList.remove('show');
    }
}

// =============================================
// FORM VALIDATION - Check file type before submit
// =============================================
document.getElementById('addItemForm')?.addEventListener('submit', function(e) {
    var fileInput = document.getElementById('imageInput');
    if (fileInput && fileInput.files && fileInput.files[0]) {
        var file = fileInput.files[0];
        var allowedTypes = ['image/png', 'image/jpeg', 'image/jpg'];
        var allowedExtensions = ['png', 'jpg', 'jpeg'];
        var extension = file.name.split('.').pop().toLowerCase();
        var mimeType = file.type;
        
        if (!allowedExtensions.includes(extension) || !allowedTypes.includes(mimeType)) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'ပုံအမျိုးအစား မမှန်ပါ',
                text: 'PNG နှင့် JPEG ပုံများကိုသာ တင်ခွင့်ပြုပါသည်။',
                confirmButtonColor: '#1EAFBD'
            });
            return false;
        }
    }
    return true;
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
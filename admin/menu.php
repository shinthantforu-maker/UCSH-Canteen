<?php
require_once '../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$message = "";
$message_type = "";

// Ensure special_note column exists
try {
    $conn->query("ALTER TABLE menu_items ADD special_note VARCHAR(255) DEFAULT NULL");
} catch (Exception $e) {}

// =============================================
// IMAGE VALIDATION
// =============================================
function isAllowedImage($file) {
    $allowed_types = ['image/png', 'image/jpeg', 'image/jpg'];
    $allowed_extensions = ['png', 'jpg', 'jpeg'];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) return false;
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions)) return false;
    
    return true;
}

// =============================================
// CRUD OPERATIONS
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // ---- ADD ITEM ----
    if ($_POST['action'] === 'add_item') {
        $itemName = trim($_POST['itemName']);
        $category = trim($_POST['category']);
        $points = intval($_POST['points']);
        $isAvailable = isset($_POST['isAvailable']) ? 1 : 0;
        $specialNote = trim($_POST['special_note'] ?? '');
        $imagePath = "";

        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            if (!isAllowedImage($_FILES['image'])) {
                $message = "PNG နှင့် JPEG ပုံများကိုသာ တင်ခွင့်ပြုပါသည်။";
                $message_type = "danger";
            } else {
                $targetDir = "../uploads/";
                if (!file_exists($targetDir)) mkdir($targetDir, 0777, true);
                $extension = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
                $fileName = time() . '_' . uniqid() . '.' . $extension;
                $targetFilePath = $targetDir . $fileName;
                
                if (move_uploaded_file($_FILES["image"]["tmp_name"], $targetFilePath)) {
                    $imagePath = "uploads/" . $fileName;
                }
            }
        }
        
        if (empty($message) || $message_type !== 'danger') {
            $stmt = $conn->prepare("INSERT INTO menu_items (itemName, category, points, isAvailable, image, special_note) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiiss", $itemName, $category, $points, $isAvailable, $imagePath, $specialNote);
            if ($stmt->execute()) {
                $message = "Menu Item အသစ် '{$itemName}' ကို အောင်မြင်စွာ ထည့်သွင်းပြီးပါပြီ။";
                $message_type = "success";
            } else {
                $message = "Menu Item ထည့်သွင်းရာတွင် အမှားအယွင်းရှိနေပါသည်။";
                $message_type = "danger";
            }
            $stmt->close();
        }
    }

    // ---- EDIT ITEM ----
    if ($_POST['action'] === 'edit_item') {
        $itemId = intval($_POST['itemId']);
        $itemName = trim($_POST['itemName']);
        $category = trim($_POST['category']);
        $points = intval($_POST['points']);
        $isAvailable = isset($_POST['isAvailable']) ? 1 : 0;
        $specialNote = trim($_POST['special_note'] ?? '');

        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            if (!isAllowedImage($_FILES['image'])) {
                $message = "PNG နှင့် JPEG ပုံများကိုသာ တင်ခွင့်ပြုပါသည်။";
                $message_type = "danger";
            } else {
                $targetDir = "../uploads/";
                if (!file_exists($targetDir)) mkdir($targetDir, 0777, true);
                $extension = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
                $fileName = time() . '_' . uniqid() . '.' . $extension;
                $targetFilePath = $targetDir . $fileName;
                
                if (move_uploaded_file($_FILES["image"]["tmp_name"], $targetFilePath)) {
                    $imagePath = "uploads/" . $fileName;
                    $stmt = $conn->prepare("UPDATE menu_items SET itemName = ?, category = ?, points = ?, isAvailable = ?, image = ?, special_note = ? WHERE itemId = ?");
                    $stmt->bind_param("ssiissi", $itemName, $category, $points, $isAvailable, $imagePath, $specialNote, $itemId);
                    if ($stmt->execute()) {
                        $message = "Menu Item ကို ပြင်ဆင်ပြီးပါပြီ။";
                        $message_type = "success";
                    }
                    $stmt->close();
                }
            }
        } else {
            $stmt = $conn->prepare("UPDATE menu_items SET itemName = ?, category = ?, points = ?, isAvailable = ?, special_note = ? WHERE itemId = ?");
            $stmt->bind_param("ssiisi", $itemName, $category, $points, $isAvailable, $specialNote, $itemId);
            if ($stmt->execute()) {
                $message = "Menu Item ကို ပြင်ဆင်ပြီးပါပြီ။";
                $message_type = "success";
            }
            $stmt->close();
        }
    }

    // ---- TOGGLE STOCK ----
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

    // ---- DELETE ITEM ----
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

    // =============================================
    // 🎯 OPTIONS MANAGEMENT
    // =============================================

    // ---- ADD OPTION GROUP ----
    if ($_POST['action'] === 'add_option_group') {
        $itemId = intval($_POST['itemId']);
        $groupName = trim($_POST['groupName'] ?? '');
        $isRequired = isset($_POST['isRequired']) ? 1 : 0;
        
        if (!empty($groupName) && $itemId > 0) {
            $stmt = $conn->prepare("INSERT INTO menu_option_groups (itemId, groupName, isRequired) VALUES (?, ?, ?)");
            $stmt->bind_param("isi", $itemId, $groupName, $isRequired);
            if ($stmt->execute()) {
                $message = "Option Group '{$groupName}' ထည့်ပြီးပါပြီ။";
                $message_type = "success";
            }
            $stmt->close();
        }
    }

    // ---- ADD OPTION ----
    if ($_POST['action'] === 'add_option') {
        $groupId = intval($_POST['groupId']);
        $optionName = trim($_POST['optionName'] ?? '');
        $extraPoints = intval($_POST['extraPoints'] ?? 0);
        
        if (!empty($optionName) && $groupId > 0) {
            $stmt = $conn->prepare("INSERT INTO menu_options (groupId, optionName, extraPoints) VALUES (?, ?, ?)");
            $stmt->bind_param("isi", $groupId, $optionName, $extraPoints);
            if ($stmt->execute()) {
                $message = "Option '{$optionName}' ထည့်ပြီးပါပြီ။";
                $message_type = "success";
            }
            $stmt->close();
        }
    }

    // ---- DELETE OPTION GROUP ----
    if ($_POST['action'] === 'delete_option_group') {
        $groupId = intval($_POST['groupId']);
        $stmt = $conn->prepare("DELETE FROM menu_option_groups WHERE groupId = ?");
        $stmt->bind_param("i", $groupId);
        $stmt->execute();
        $stmt->close();
        $message = "Option Group ဖျက်ပြီးပါပြီ။";
        $message_type = "warning";
    }

    // ---- DELETE OPTION ----
    if ($_POST['action'] === 'delete_option') {
        $optionId = intval($_POST['optionId']);
        $stmt = $conn->prepare("DELETE FROM menu_options WHERE optionId = ?");
        $stmt->bind_param("i", $optionId);
        $stmt->execute();
        $stmt->close();
        $message = "Option ဖျက်ပြီးပါပြီ။";
        $message_type = "warning";
    }

    // ---- TOGGLE OPTION AVAILABILITY ----
    if ($_POST['action'] === 'toggle_option') {
        $optionId = intval($_POST['optionId']);
        $stmt = $conn->prepare("UPDATE menu_options SET isAvailable = NOT isAvailable WHERE optionId = ?");
        $stmt->bind_param("i", $optionId);
        $stmt->execute();
        $stmt->close();
        $message = "Option status ပြောင်းပြီးပါပြီ။";
        $message_type = "info";
    }

    // Redirect to avoid form resubmission
    header("Location: menu.php?msg=" . urlencode($message) . "&type=" . urlencode($message_type));
    exit();
}

// Read message from redirect
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $message_type = $_GET['type'] ?? 'info';
}

// =============================================
// FETCH MENU ITEMS
// =============================================
$menu_result = $conn->query("SELECT * FROM menu_items ORDER BY itemId DESC");
$menu_items = [];
if ($menu_result && $menu_result->num_rows > 0) {
    while ($row = $menu_result->fetch_assoc()) {
        $menu_items[] = $row;
    }
}
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
        .btn-brand { background-color: var(--brand-color); color: white; border: none; }
        .btn-brand:hover { background-color: var(--brand-hover); color: white; }
        .menu-img-preview { width: 50px; height: 50px; object-fit: cover; border-radius: 8px; }
        .image-preview { max-width: 150px; max-height: 150px; border-radius: 8px; border: 1px solid #E2E8F0; padding: 4px; display: none; }
        .image-preview.show { display: block; }
        .option-group-box { background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 14px; margin-bottom: 14px; }
        .option-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; background: white; border-radius: 8px; margin-bottom: 5px; border: 1px solid #E2E8F0; }
        .options-badge-count { background: #1EAFBD; color: white; font-size: 0.65rem; padding: 2px 7px; border-radius: 20px; margin-left: 5px; }
        @media (max-width: 991.98px) { 
            .sidebar { position: fixed; top: 0; left: -260px; z-index: 1050; transition: left 0.3s; } 
            .sidebar.show { left: 0; } 
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
            <a href="users.php" class="nav-link-custom"><i class="fa-solid fa-users"></i> Users</a>
            <a href="announcements.php" class="nav-link-custom"><i class="fa-solid fa-bullhorn"></i> Announcements</a>
        </div>
        <hr class="text-muted">
        <div><a href="../logout.php" class="nav-link-custom text-danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></div>
    </div>

    <div class="flex-grow-1 p-3 p-md-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <button class="btn btn-light d-lg-none border-0" type="button" onclick="document.getElementById('sidebar').classList.toggle('show')">
                <i class="fa-solid fa-bars fs-5 text-dark"></i>
            </button>
            <h4 class="fw-bold m-0 text-dark">Menu Management</h4>
            <button type="button" class="btn bg-brand text-white rounded-3 fw-medium" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="fa-solid fa-plus me-1"></i> Menu အသစ်ထည့်မည်
            </button>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show rounded-3" role="alert">
                <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($message) ?>
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
                        <?php if (!empty($menu_items)): ?>
                            <?php foreach ($menu_items as $item): 
                                $optCountStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM menu_option_groups WHERE itemId = ?");
                                $optCountStmt->bind_param("i", $item['itemId']);
                                $optCountStmt->execute();
                                $optCount = (int)$optCountStmt->get_result()->fetch_assoc()['cnt'];
                                $optCountStmt->close();
                            ?>
                                <tr>
                                    <td><img src="<?= !empty($item['image']) ? '../' . htmlspecialchars($item['image']) : 'https://via.placeholder.com/50' ?>" class="menu-img-preview border" alt="Menu"></td>
                                    <td class="fw-medium text-dark">
                                        <?= htmlspecialchars($item['itemName']) ?>
                                        <?php if ($optCount > 0): ?>
                                            <span class="options-badge-count">
                                                <i class="fa-solid fa-list-check"></i> <?= $optCount ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
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
                                        <button type="button" class="btn btn-sm btn-light border text-warning me-1 rounded-3" 
                                                data-bs-toggle="modal" data-bs-target="#optionsModal<?= $item['itemId'] ?>"
                                                title="Manage Options">
                                            <i class="fa-solid fa-list-check"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-light border text-brand me-1 rounded-3" data-bs-toggle="modal" data-bs-target="#editModal<?= $item['itemId'] ?>">
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
                            <?php endforeach; ?>
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
<!-- ADD ITEM MODAL                                -->
<!-- ============================================= -->
<div class="modal fade" id="addItemModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
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
                        <label class="form-label small fw-bold">Special Note</label>
                        <input type="text" name="special_note" class="form-control" placeholder="ဥပမာ - ဆားနည်းနည်းလျှော့ပေးပါ...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">ဓာတ်ပုံ <span class="text-danger">*PNG/JPEG သာ</span></label>
                        <input type="file" name="image" id="imageInput" class="form-control" accept=".png,.jpg,.jpeg" onchange="previewImage(this, 'addPreview')">
                        <img id="addPreview" class="image-preview mt-2" src="#" alt="Preview">
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="isAvailable" id="addAvail" checked>
                        <label class="form-check-label small fw-bold" for="addAvail">In Stock</label>
                    </div>
                    <div class="alert alert-info small mb-0">
                        <i class="fa-solid fa-info-circle me-1"></i>
                        Item ထည့်ပြီးရင် Menu list မှာ <strong>Options</strong> button ကို နှိပ်ပြီး ရွေးချယ်စရာများ ထည့်နိုင်ပါတယ်။
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

<!-- ============================================= -->
<!-- EDIT ITEM MODALS                              -->
<!-- ============================================= -->
<?php if (!empty($menu_items)): ?>
    <?php foreach ($menu_items as $item): ?>
        <div class="modal fade" id="editModal<?= $item['itemId'] ?>" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
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
                                <label class="form-label small fw-bold">Special Note</label>
                                <input type="text" name="special_note" class="form-control" value="<?= htmlspecialchars($item['special_note'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">ဓာတ်ပုံ (အသစ်လဲလိုပါက)</label>
                                <input type="file" name="image" class="form-control" accept=".png,.jpg,.jpeg" onchange="previewImage(this, 'editPreview<?= $item['itemId'] ?>')">
                                <img id="editPreview<?= $item['itemId'] ?>" class="image-preview mt-2" src="#" alt="Preview">
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
    <?php endforeach; ?>
<?php endif; ?>

<!-- ============================================= -->
<!-- OPTIONS MANAGEMENT MODALS                     -->
<!-- ============================================= -->
<?php if (!empty($menu_items)): ?>
    <?php foreach ($menu_items as $item): 
        $optionGroups = getItemOptionGroups($conn, $item['itemId'], false);
    ?>
        <div class="modal fade" id="optionsModal<?= $item['itemId'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                <div class="modal-content rounded-4 border-0">
                    <div class="modal-header bg-light border-0">
                        <h6 class="fw-bold m-0">
                            <i class="fa-solid fa-list-check text-warning me-2"></i>
                            Options — <?= htmlspecialchars($item['itemName']) ?>
                        </h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        
                        <!-- Existing Groups -->
                        <?php if (!empty($optionGroups)): ?>
                            <?php foreach ($optionGroups as $group): ?>
                                <div class="option-group-box">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="fw-bold mb-1">
                                                <i class="fa-solid fa-folder-open text-brand me-1"></i>
                                                <?= htmlspecialchars($group['groupName']) ?>
                                                <?php if ($group['isRequired']): ?>
                                                    <span class="badge bg-danger-subtle text-danger" style="font-size:0.65rem;">Required</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary" style="font-size:0.65rem;">Optional</span>
                                                <?php endif; ?>
                                            </h6>
                                        </div>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Group ကို ဖျက်မှာလား? Options အားလုံးပါ ပျက်သွားပါမယ်။');">
                                            <input type="hidden" name="action" value="delete_option_group">
                                            <input type="hidden" name="groupId" value="<?= $group['groupId'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-3">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                    
                                    <!-- Options list -->
                                    <?php if (!empty($group['options'])): ?>
                                        <div class="ms-3 mb-2">
                                            <?php foreach ($group['options'] as $opt): ?>
                                                <div class="option-item">
                                                    <div>
                                                        <i class="fa-solid fa-circle-check <?= $opt['isAvailable'] ? 'text-success' : 'text-muted' ?> me-1"></i>
                                                        <span class="<?= $opt['isAvailable'] ? '' : 'text-muted text-decoration-line-through' ?>">
                                                            <?= htmlspecialchars($opt['optionName']) ?>
                                                        </span>
                                                        <?php if ($opt['extraPoints'] > 0): ?>
                                                            <span class="badge bg-warning-subtle text-warning ms-1">
                                                                +<?= number_format($opt['extraPoints']) ?> pts
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="d-flex gap-1">
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="action" value="toggle_option">
                                                            <input type="hidden" name="optionId" value="<?= $opt['optionId'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-secondary rounded-3 py-0 px-2" style="font-size:0.7rem;">
                                                                <?= $opt['isAvailable'] ? 'Disable' : 'Enable' ?>
                                                            </button>
                                                        </form>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Option ဖျက်မှာလား?');">
                                                            <input type="hidden" name="action" value="delete_option">
                                                            <input type="hidden" name="optionId" value="<?= $opt['optionId'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 py-0 px-2" style="font-size:0.7rem;">
                                                                <i class="fa-solid fa-xmark"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-muted small ms-3 mb-2">Option မရှိသေးပါ</div>
                                    <?php endif; ?>
                                    
                                    <!-- Add Option form -->
                                    <form method="POST" class="row g-2 mt-2">
                                        <input type="hidden" name="action" value="add_option">
                                        <input type="hidden" name="groupId" value="<?= $group['groupId'] ?>">
                                        <div class="col-5">
                                            <input type="text" name="optionName" class="form-control form-control-sm" 
                                                   placeholder="ဥပမာ - ကြက်" required>
                                        </div>
                                        <div class="col-4">
                                            <input type="number" name="extraPoints" class="form-control form-control-sm" 
                                                   placeholder="+Points" value="0" min="0">
                                        </div>
                                        <div class="col-3">
                                            <button type="submit" class="btn btn-sm btn-brand w-100">
                                                <i class="fa-solid fa-plus"></i> Option
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info rounded-3 small">
                                <i class="fa-solid fa-info-circle me-1"></i>
                                ဒီ Item မှာ options မရှိသေးပါ။ အောက်မှာ group အသစ်ထည့်ပါ။
                            </div>
                        <?php endif; ?>
                        
                        <!-- Add New Group form -->
                        <div class="border-top pt-3 mt-3">
                            <h6 class="fw-bold text-brand mb-2">
                                <i class="fa-solid fa-plus-circle me-1"></i>Option Group အသစ်ထည့်
                            </h6>
                            <form method="POST" class="row g-2">
                                <input type="hidden" name="action" value="add_option_group">
                                <input type="hidden" name="itemId" value="<?= $item['itemId'] ?>">
                                <div class="col-12 col-md-6">
                                    <input type="text" name="groupName" class="form-control form-control-sm" 
                                           placeholder="ဥပမာ - အသားအမျိုးအစား" required>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" name="isRequired" 
                                               id="req<?= $item['itemId'] ?>" checked>
                                        <label class="form-check-label small" for="req<?= $item['itemId'] ?>">
                                            Required
                                        </label>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <button type="submit" class="btn btn-sm btn-brand w-100">
                                        <i class="fa-solid fa-plus"></i> Group
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
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
</body>
</html>

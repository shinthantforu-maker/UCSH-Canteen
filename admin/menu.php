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

// Ensure menu_option_groups.maxSelect column exists (default 1)
try {
    $conn->query("ALTER TABLE menu_option_groups ADD COLUMN maxSelect TINYINT DEFAULT 1");
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
// SAVE ITEM OPTIONS (helper for add/edit)
// =============================================
function saveItemOptions($conn, $itemId, $groupsData) {
    // Delete existing groups (cascade will remove options)
    $delStmt = $conn->prepare("DELETE FROM menu_option_groups WHERE itemId = ?");
    $delStmt->bind_param("i", $itemId);
    $delStmt->execute();
    $delStmt->close();
    
    if (empty($groupsData) || !is_array($groupsData)) return;
    
    // Insert groups + options
    $groupStmt = $conn->prepare("INSERT INTO menu_option_groups (itemId, groupName, isRequired, maxSelect) VALUES (?, ?, ?, ?)");
    $optStmt = $conn->prepare("INSERT INTO menu_options (groupId, optionName, extraPoints) VALUES (?, ?, ?)");
    
    foreach ($groupsData as $group) {
        $groupName = trim($group['name'] ?? '');
        if (empty($groupName)) continue;
        
        $isRequired = !empty($group['required']) ? 1 : 0;
        $maxSelect = 1; // ✅ Always 1 (radio)
        
        $groupStmt->bind_param("isii", $itemId, $groupName, $isRequired, $maxSelect);
        $groupStmt->execute();
        $newGroupId = $groupStmt->insert_id;
        
        // Insert options
        if (!empty($group['options']) && is_array($group['options'])) {
            foreach ($group['options'] as $opt) {
                $optName = trim($opt['name'] ?? '');
                if (empty($optName)) continue;
                
                $extraPoints = intval($opt['extraPoints'] ?? 0);
                $optStmt->bind_param("isi", $newGroupId, $optName, $extraPoints);
                $optStmt->execute();
            }
        }
    }
    
    $groupStmt->close();
    $optStmt->close();
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
        $hasError = false;

        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            if (!isAllowedImage($_FILES['image'])) {
                $message = "PNG နှင့် JPEG ပုံများကိုသာ တင်ခွင့်ပြုပါသည်။";
                $message_type = "danger";
                $hasError = true;
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
        
        if (!$hasError) {
            $stmt = $conn->prepare("INSERT INTO menu_items (itemName, category, points, isAvailable, image, special_note) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiiss", $itemName, $category, $points, $isAvailable, $imagePath, $specialNote);
            if ($stmt->execute()) {
                $newItemId = $stmt->insert_id;
                $stmt->close();
                
                $optionsJson = $_POST['options_data'] ?? '[]';
                $groupsData = json_decode($optionsJson, true) ?: [];
                saveItemOptions($conn, $newItemId, $groupsData);
                
                $message = "Menu Item အသစ် '{$itemName}' ကို အောင်မြင်စွာ ထည့်သွင်းပြီးပါပြီ။";
                $message_type = "success";
            } else {
                $message = "Menu Item ထည့်သွင်းရာတွင် အမှားအယွင်းရှိနေပါသည်။";
                $message_type = "danger";
            }
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
                    $stmt->execute();
                    $stmt->close();
                }
            }
        } else {
            $stmt = $conn->prepare("UPDATE menu_items SET itemName = ?, category = ?, points = ?, isAvailable = ?, special_note = ? WHERE itemId = ?");
            $stmt->bind_param("ssiisi", $itemName, $category, $points, $isAvailable, $specialNote, $itemId);
            $stmt->execute();
            $stmt->close();
        }
        
        $optionsJson = $_POST['options_data'] ?? '[]';
        $groupsData = json_decode($optionsJson, true) ?: [];
        saveItemOptions($conn, $itemId, $groupsData);
        
        $message = "Menu Item ကို ပြင်ဆင်ပြီးပါပြီ။";
        $message_type = "success";
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

    header("Location: menu.php?msg=" . urlencode($message) . "&type=" . urlencode($message_type));
    exit();
}

if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $message_type = $_GET['type'] ?? 'info';
}

// =============================================
// FETCH MENU ITEMS + their options
// =============================================
$menu_result = $conn->query("SELECT * FROM menu_items ORDER BY itemId DESC");
$menu_items = [];
if ($menu_result && $menu_result->num_rows > 0) {
    while ($row = $menu_result->fetch_assoc()) {
        $row['option_groups'] = getItemOptionGroups($conn, $row['itemId'], false);
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
        
        .search-wrapper { position: relative; max-width: 400px; }
        .search-wrapper .search-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94A3B8; }
        .search-wrapper input { padding-left: 40px; border-radius: 20px; }

        /* ✅ FIX: Modal scroll */
        #itemModal .modal-dialog {
            max-height: 90vh;
            display: flex;
            align-items: center;
        }
        #itemModal .modal-content {
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        #itemModal .modal-body {
            overflow-y: auto;
            flex: 1 1 auto;
            max-height: calc(90vh - 140px);
        }
        #itemModal .modal-body::-webkit-scrollbar {
            width: 8px;
        }
        #itemModal .modal-body::-webkit-scrollbar-track {
            background: #F1F5F9;
            border-radius: 10px;
        }
        #itemModal .modal-body::-webkit-scrollbar-thumb {
            background: #CBD5E0;
            border-radius: 10px;
        }
        #itemModal .modal-body::-webkit-scrollbar-thumb:hover {
            background: #94A3B8;
        }
        
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
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <button class="btn btn-light d-lg-none border-0" type="button" onclick="document.getElementById('sidebar').classList.toggle('show')">
                <i class="fa-solid fa-bars fs-5 text-dark"></i>
            </button>
            <h4 class="fw-bold m-0 text-dark">Menu Management</h4>
            <div class="d-flex gap-2 flex-wrap">
                <div class="search-wrapper">
                    <i class="fa-solid fa-search search-icon"></i>
                    <input type="text" id="searchInput" class="form-control" placeholder="Menu item ရှာပါ...">
                </div>
                <button type="button" class="btn bg-brand text-white rounded-3 fw-medium" onclick="openAddModal()">
                    <i class="fa-solid fa-plus me-1"></i> Menu အသစ်ထည့်မည်
                </button>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show rounded-3" role="alert">
                <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold m-0 text-dark"><i class="fa-solid fa-bowl-food text-brand me-2"></i>All Menu Items <span id="itemCount" class="text-muted">(<?= count($menu_items) ?>)</span></h6>
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
                    <tbody id="menuTableBody">
                        <?php if (!empty($menu_items)): ?>
                            <?php foreach ($menu_items as $item): 
                                $optCount = count($item['option_groups']);
                            ?>
                                <tr data-name="<?= htmlspecialchars(mb_strtolower($item['itemName'], 'UTF-8')) ?>">
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
                                        <button type="button" class="btn btn-sm btn-light border text-brand me-1 rounded-3" 
                                                onclick='openEditModal(<?= json_encode($item, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                                title="Edit">
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
<!-- ADD / EDIT ITEM MODAL                         -->
<!-- ============================================= -->
<div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header bg-light border-0">
                <h6 class="fw-bold m-0" id="modalTitle">
                    <i class="fa-solid fa-plus text-brand me-2"></i>Menu Item အသစ်ထည့်မည်
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="menu.php" enctype="multipart/form-data" id="itemForm">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" id="formAction" value="add_item">
                    <input type="hidden" name="itemId" id="formItemId" value="">
                    <input type="hidden" name="options_data" id="optionsDataInput" value="[]">
                    
                    <!-- Basic Info -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Item နာမည် <span class="text-danger">*</span></label>
                            <input type="text" name="itemName" id="itemNameInput" class="form-control" placeholder="ဥပမာ - ကြက်သားဆီချက်" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Category</label>
                            <select name="category" id="itemCategoryInput" class="form-select">
                                <option value="Food">Food</option>
                                <option value="Drink">Drink</option>
                                <option value="Snack">Snack</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Points <span class="text-danger">*</span></label>
                            <input type="number" name="points" id="itemPointsInput" class="form-control" placeholder="Points" required min="1">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Special Note</label>
                        <input type="text" name="special_note" id="itemNoteInput" class="form-control" placeholder="ဥပမာ - ဆားနည်းနည်းလျှော့ပေးပါ...">
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold">ဓာတ်ပုံ <span class="text-danger">*PNG/JPEG သာ</span></label>
                            <input type="file" name="image" id="imageInput" class="form-control" accept=".png,.jpg,.jpeg" onchange="previewImage(this, 'imagePreview')">
                            <img id="imagePreview" class="image-preview mt-2" src="#" alt="Preview">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="isAvailable" id="isAvailableInput" checked>
                                <label class="form-check-label small fw-bold" for="isAvailableInput">In Stock</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ✅ OPTIONS SECTION -->
                    <div class="border-top pt-3 mt-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold text-brand m-0">
                                <i class="fa-solid fa-list-check me-1"></i>Options / ရွေးချယ်စရာများ
                            </h6>
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-3" onclick="addNewGroup()">
                                <i class="fa-solid fa-plus me-1"></i>Group ထည့်
                            </button>
                        </div>
                        <p class="text-muted small mb-2">
                            <i class="fa-solid fa-info-circle me-1"></i>
                            ဥပမာ - "အသားအမျိုးအစား" group အောက်မှာ "ကြက်", "ဝက်", "ပင်လယ်စာ" ထည့်ပါ။ (တစ်ခုသာ ရွေးလို့ရမယ်)
                        </p>
                        <div id="optionsContainer">
                            <!-- Dynamic groups -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-secondary btn-sm rounded-3" data-bs-dismiss="modal">မလုပ်တော့ပါ</button>
                    <button type="submit" class="btn bg-brand text-white btn-sm rounded-3 fw-medium px-4" id="confirmBtn">
                        <i class="fa-solid fa-check me-1"></i>သိမ်းဆည်းမည်
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// =============================================
// IMAGE PREVIEW
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
// OPTIONS BUILDER (Max Select မပါ — တစ်ခုသာ)
// =============================================
let groupCounter = 0;

function addNewGroup(groupData = null) {
    groupCounter++;
    const groupId = 'group_' + groupCounter + '_' + Date.now();
    
    const container = document.getElementById('optionsContainer');
    const div = document.createElement('div');
    div.className = 'option-group-box';
    div.dataset.groupId = groupId;
    
    const groupName = groupData?.groupName || '';
    const isRequired = groupData?.isRequired == 1 ? 'checked' : '';
    
    div.innerHTML = `
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="flex-grow-1 me-2">
                <input type="text" class="form-control form-control-sm group-name-input" 
                       placeholder="Group နာမည် (ဥပမာ - အသားအမျိုးအစား)" 
                       value="${escapeHtml(groupName)}" required>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger rounded-3" onclick="removeGroup(this)">
                <i class="fa-solid fa-trash"></i>
            </button>
        </div>
        <div class="mb-2">
            <div class="form-check">
                <input class="form-check-input group-required-input" type="checkbox" ${isRequired}>
                <label class="form-check-label small">Required (မဖြစ်မနေရွေးရမည်)</label>
            </div>
            <small class="text-muted" style="font-size: 0.7rem;">
                <i class="fa-solid fa-circle-info me-1"></i>တစ်ခုသာ ရွေးလို့ရမည်
            </small>
        </div>
        
        <div class="options-list ms-2 mb-2">
            <!-- options will be added here -->
        </div>
        
        <div class="row g-2 ms-2">
            <div class="col-6">
                <input type="text" class="form-control form-control-sm new-option-name" placeholder="Option နာမည် (ဥပမာ - ကြက်)">
            </div>
            <div class="col-3">
                <input type="number" class="form-control form-control-sm new-option-points" placeholder="+Points" value="0" min="0">
            </div>
            <div class="col-3">
                <button type="button" class="btn btn-sm btn-brand w-100" onclick="addOptionToGroup(this)">
                    <i class="fa-solid fa-plus"></i> ထည့်
                </button>
            </div>
        </div>
    `;
    
    container.appendChild(div);
    
    // Load existing options
    if (groupData && groupData.options && groupData.options.length > 0) {
        const optionsList = div.querySelector('.options-list');
        groupData.options.forEach(opt => {
            addOptionToGroupDOM(optionsList, opt.optionName, opt.extraPoints);
        });
    }
}

function addOptionToGroup(btn) {
    const groupBox = btn.closest('.option-group-box');
    const nameInput = groupBox.querySelector('.new-option-name');
    const pointsInput = groupBox.querySelector('.new-option-points');
    const optionsList = groupBox.querySelector('.options-list');
    
    const name = nameInput.value.trim();
    const extraPoints = parseInt(pointsInput.value) || 0;
    
    if (!name) {
        Swal.fire({
            icon: 'warning',
            title: 'Option နာမည် ထည့်ပါ',
            timer: 1500,
            showConfirmButton: false,
            toast: true,
            position: 'top'
        });
        return;
    }
    
    addOptionToGroupDOM(optionsList, name, extraPoints);
    
    nameInput.value = '';
    pointsInput.value = '0';
    nameInput.focus();
}

function addOptionToGroupDOM(optionsList, name, extraPoints) {
    const div = document.createElement('div');
    div.className = 'option-item';
    div.innerHTML = `
        <div>
            <i class="fa-solid fa-circle-check text-success me-1"></i>
            <span class="option-display-name">${escapeHtml(name)}</span>
            ${extraPoints > 0 ? `<span class="badge bg-warning-subtle text-warning ms-1">+${Number(extraPoints).toLocaleString()} pts</span>` : ''}
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger rounded-3 py-0 px-2" onclick="this.closest('.option-item').remove()">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <input type="hidden" class="option-name-data" value="${escapeHtml(name)}">
        <input type="hidden" class="option-points-data" value="${extraPoints}">
    `;
    optionsList.appendChild(div);
}

function removeGroup(btn) {
    if (confirm('ဒီ group ကို ဖျက်မှာလား?')) {
        btn.closest('.option-group-box').remove();
    }
}

// =============================================
// COLLECT OPTIONS DATA
// =============================================
function collectOptionsData() {
    const groups = [];
    document.querySelectorAll('#optionsContainer .option-group-box').forEach(groupBox => {
        const groupName = groupBox.querySelector('.group-name-input').value.trim();
        if (!groupName) return;
        
        const isRequired = groupBox.querySelector('.group-required-input').checked ? 1 : 0;
        
        const options = [];
        groupBox.querySelectorAll('.option-item').forEach(optItem => {
            const optName = optItem.querySelector('.option-name-data').value;
            const optPoints = parseInt(optItem.querySelector('.option-points-data').value) || 0;
            if (optName) {
                options.push({ name: optName, extraPoints: optPoints });
            }
        });
        
        groups.push({
            name: groupName,
            required: isRequired,
            maxSelect: 1, // ✅ Always 1
            options: options
        });
    });
    return groups;
}

// =============================================
// OPEN ADD MODAL
// =============================================
function openAddModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-plus text-brand me-2"></i>Menu Item အသစ်ထည့်မည်';
    document.getElementById('formAction').value = 'add_item';
    document.getElementById('formItemId').value = '';
    document.getElementById('itemNameInput').value = '';
    document.getElementById('itemCategoryInput').value = 'Food';
    document.getElementById('itemPointsInput').value = '';
    document.getElementById('itemNoteInput').value = '';
    document.getElementById('isAvailableInput').checked = true;
    document.getElementById('imageInput').value = '';
    document.getElementById('imagePreview').classList.remove('show');
    document.getElementById('optionsContainer').innerHTML = '';
    groupCounter = 0;
    
    // Auto add 1 empty group
    addNewGroup();
    
    // ✅ Reset modal scroll to top
    const modal = new bootstrap.Modal(document.getElementById('itemModal'));
    modal.show();
    
    setTimeout(() => {
        const modalBody = document.querySelector('#itemModal .modal-body');
        if (modalBody) modalBody.scrollTop = 0;
    }, 300);
}

// =============================================
// OPEN EDIT MODAL
// =============================================
function openEditModal(item) {
    document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square text-brand me-2"></i>Menu Item ပြင်ဆင်ရန်';
    document.getElementById('formAction').value = 'edit_item';
    document.getElementById('formItemId').value = item.itemId;
    document.getElementById('itemNameInput').value = item.itemName || '';
    document.getElementById('itemCategoryInput').value = item.category || 'Food';
    document.getElementById('itemPointsInput').value = item.points || '';
    document.getElementById('itemNoteInput').value = item.special_note || '';
    document.getElementById('isAvailableInput').checked = item.isAvailable == 1;
    document.getElementById('imageInput').value = '';
    document.getElementById('imagePreview').classList.remove('show');
    
    document.getElementById('optionsContainer').innerHTML = '';
    groupCounter = 0;
    
    const groups = item.option_groups || [];
    if (groups.length > 0) {
        groups.forEach(g => addNewGroup(g));
    } else {
        addNewGroup();
    }
    
    const modal = new bootstrap.Modal(document.getElementById('itemModal'));
    modal.show();
    
    setTimeout(() => {
        const modalBody = document.querySelector('#itemModal .modal-body');
        if (modalBody) modalBody.scrollTop = 0;
    }, 300);
}

// =============================================
// FORM SUBMIT
// =============================================
document.getElementById('itemForm').addEventListener('submit', function(e) {
    const optionsData = collectOptionsData();
    document.getElementById('optionsDataInput').value = JSON.stringify(optionsData);
    
    const fileInput = document.getElementById('imageInput');
    if (fileInput && fileInput.files && fileInput.files[0]) {
        const file = fileInput.files[0];
        const allowedTypes = ['image/png', 'image/jpeg', 'image/jpg'];
        const allowedExtensions = ['png', 'jpg', 'jpeg'];
        const extension = file.name.split('.').pop().toLowerCase();
        
        if (!allowedExtensions.includes(extension) || !allowedTypes.includes(file.type)) {
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
    
    const btn = document.getElementById('confirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>သိမ်းဆည်းနေပါသည်...';
    
    return true;
});

// =============================================
// SEARCH
// =============================================
document.getElementById('searchInput').addEventListener('input', function() {
    const filterValue = this.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#menuTableBody tr[data-name]');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const name = row.getAttribute('data-name') || '';
        if (name.includes(filterValue)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    document.getElementById('itemCount').textContent = '(' + visibleCount + ')';
});

// =============================================
// ESCAPE HTML
// =============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// =============================================
// SCROLL PRESERVATION
// =============================================
document.addEventListener('DOMContentLoaded', function() {
    const scrollPos = sessionStorage.getItem('menuScrollPos');
    if (scrollPos) {
        window.scrollTo(0, parseInt(scrollPos));
        sessionStorage.removeItem('menuScrollPos');
    }
});

document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function() {
        if (this.id !== 'itemForm') {
            sessionStorage.setItem('menuScrollPos', window.scrollY);
        }
    });
});
</script>
</body>
</html>

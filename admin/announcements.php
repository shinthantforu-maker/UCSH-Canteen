<?php
require_once '../db.php';

if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$message = "";
$message_type = "";

// Get all announcements
$announcements = $conn->query("SELECT * FROM announcements ORDER BY announcementId DESC");

// CRUD Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Add Announcement
    if ($_POST['action'] === 'add_announcement') {
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        
        if (!empty($title) && !empty($content)) {
            $stmt = $conn->prepare("INSERT INTO announcements (title, content) VALUES (?, ?)");
            $stmt->bind_param("ss", $title, $content);
            if ($stmt->execute()) {
                $message = "အသိပေးချက် အသစ်ကို အောင်မြင်စွာ တင်ပြီးပါပြီ။";
                $message_type = "success";
            } else {
                $message = "တင်ဆောင်ရာတွင် အမှားအယွင်းရှိနေပါသည်။";
                $message_type = "danger";
            }
            $stmt->close();
        } else {
            $message = "ခေါင်းစဉ်နှင့် အကြောင်းအရာ ဖြည့်သွင်းပါ။";
            $message_type = "warning";
        }
    }
    
    // Edit Announcement
    if ($_POST['action'] === 'edit_announcement') {
        $announcementId = intval($_POST['announcementId']);
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        
        if (!empty($title) && !empty($content)) {
            $stmt = $conn->prepare("UPDATE announcements SET title = ?, content = ? WHERE announcementId = ?");
            $stmt->bind_param("ssi", $title, $content, $announcementId);
            if ($stmt->execute()) {
                $message = "အသိပေးချက်ကို အောင်မြင်စွာ ပြင်ဆင်ပြီးပါပြီ။";
                $message_type = "success";
            } else {
                $message = "ပြင်ဆင်ရာတွင် အမှားအယွင်းရှိနေပါသည်။";
                $message_type = "danger";
            }
            $stmt->close();
        } else {
            $message = "ခေါင်းစဉ်နှင့် အကြောင်းအရာ ဖြည့်သွင်းပါ။";
            $message_type = "warning";
        }
    }
    
    // Delete Announcement
    if ($_POST['action'] === 'delete_announcement') {
        $announcementId = intval($_POST['announcementId']);
        $stmt = $conn->prepare("DELETE FROM announcements WHERE announcementId = ?");
        $stmt->bind_param("i", $announcementId);
        if ($stmt->execute()) {
            $message = "အသိပေးချက်ကို အောင်မြင်စွာ ဖျက်ပြီးပါပြီ။";
            $message_type = "success";
        } else {
            $message = "ဖျက်ရာတွင် အမှားအယွင်းရှိနေပါသည်။";
            $message_type = "danger";
        }
        $stmt->close();
    }
}

// Refresh announcements after operation
$announcements = $conn->query("SELECT * FROM announcements ORDER BY announcementId DESC");
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UCSH Admin - Announcements</title>
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
        .announcement-item { border-left: 4px solid var(--brand-color); }
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
            <!-- <a href="orders.php" class="nav-link-custom"><i class="fa-solid fa-list-check"></i> Orders</a> -->
            <a href="users.php" class="nav-link-custom"><i class="fa-solid fa-users"></i> Users</a>
            <a href="announcements.php" class="nav-link-custom active"><i class="fa-solid fa-bullhorn"></i> Announcements</a>
        </div>
        <hr class="text-muted">
        <div><a href="../logout.php" class="nav-link-custom text-danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></div>
    </div>

    <div class="flex-grow-1 p-3 p-md-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold m-0 text-dark">Announcements Management</h4>
            <span class="text-muted small">Total: <?= $announcements ? $announcements->num_rows : 0 ?></span>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show rounded-3" role="alert">
                <i class="fa-solid fa-circle-info me-2"></i><?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-5 mb-4">
                <div class="card border-0 shadow-sm rounded-4 p-4">
                    <h6 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-plus text-brand me-2"></i>အကြောင်းကြားချက် အသစ်တင်ရန်</h6>
                    <form method="POST" action="announcements.php">
                        <input type="hidden" name="action" value="add_announcement">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">ခေါင်းစဉ် (Title)</label>
                            <input type="text" name="title" class="form-control" placeholder="ဥပမာ - ယနေ့ ကန်တင်း ခေတ္တပိတ်မည်" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">အသေးစိတ် ဖော်ပြချက် (Content)</label>
                            <textarea name="content" class="form-control" rows="4" placeholder="အသေးစိတ်အချက်အလက်များ ရေးရန်..." required></textarea>
                        </div>
                        <button type="submit" class="btn bg-brand text-white w-100 rounded-3 fw-medium">တင်မည်</button>
                    </form>
                </div>
            </div>

            <div class="col-md-7">
                <div class="card border-0 shadow-sm rounded-4 p-4">
                    <h6 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-list text-brand me-2"></i>အသိပေးချက်များ စာရင်း</h6>
                    
                    <?php if ($announcements && $announcements->num_rows > 0): ?>
                        <?php while ($ann = $announcements->fetch_assoc()): ?>
                            <div class="announcement-item p-3 mb-2 bg-white rounded-3 shadow-sm d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1 me-3">
                                    <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($ann['title']) ?></h6>
                                    <p class="mb-1 text-muted small"><?= htmlspecialchars($ann['content']) ?></p>
                                    <small class="text-muted fs-7">
                                        <i class="fa-regular fa-clock me-1"></i>
                                        <?= date('d M Y, h:i A', strtotime($ann['createdAt'])) ?>
                                        <?php if (!empty($ann['updated_at'])): ?>
                                            <span class="text-brand">(Edited: <?= date('d M Y, h:i A', strtotime($ann['updated_at'])) ?>)</span>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="d-flex gap-1 flex-shrink-0">
                                    <button class="btn btn-sm btn-light border text-brand rounded-3" data-bs-toggle="modal" data-bs-target="#editModal<?= $ann['announcementId'] ?>" title="Edit">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <form method="POST" action="announcements.php" class="d-inline" onsubmit="return confirm('ဒီအကြောင်းကြားချက်ကို ဖျက်ရန် သေချာပါသလား?');">
                                        <input type="hidden" name="action" value="delete_announcement">
                                        <input type="hidden" name="announcementId" value="<?= $ann['announcementId'] ?>">
                                        <button type="submit" class="btn btn-sm btn-light border text-danger rounded-3" title="Delete">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- Edit Modal -->
                            <div class="modal fade" id="editModal<?= $ann['announcementId'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content rounded-4 border-0">
                                        <div class="modal-header bg-light border-0">
                                            <h6 class="fw-bold m-0"><i class="fa-solid fa-pen text-brand me-2"></i>အသိပေးချက် ပြင်ဆင်ရန်</h6>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST" action="announcements.php">
                                            <div class="modal-body p-4">
                                                <input type="hidden" name="action" value="edit_announcement">
                                                <input type="hidden" name="announcementId" value="<?= $ann['announcementId'] ?>">
                                                <div class="mb-3">
                                                    <label class="form-label small fw-bold">ခေါင်းစဉ်</label>
                                                    <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($ann['title']) ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label small fw-bold">အသေးစိတ်</label>
                                                    <textarea name="content" class="form-control" rows="4" required><?= htmlspecialchars($ann['content']) ?></textarea>
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
                        <div class="text-center py-4 text-muted">
                            <i class="fa-regular fa-bell-slash fs-3 opacity-25 mb-2 d-block"></i>
                            <small>အသိပေးချက်များ မရှိသေးပါ။</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
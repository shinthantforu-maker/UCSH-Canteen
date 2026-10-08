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

// User Liked Item IDs
$user_liked_item_ids = [];
$liked_ids_stmt = $conn->prepare("SELECT itemId FROM liked_items WHERE userId = ?");
if ($liked_ids_stmt) {
    $liked_ids_stmt->bind_param("i", $user_id);
    $liked_ids_stmt->execute();
    $liked_ids_res = $liked_ids_stmt->get_result();
    while ($row = $liked_ids_res->fetch_assoc()) {
        $user_liked_item_ids[] = $row['itemId'];
    }
    $liked_ids_stmt->close();
}

// Get User Points
$userStmt = $conn->prepare("SELECT points FROM users WHERE userId = ?");
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();
$userData = $userResult->fetch_assoc();
$currentPoints = $userData['points'] ?? 0;
$userStmt->close();

// Announcements
$announcements = $conn->query("SELECT * FROM announcements ORDER BY announcementId DESC LIMIT 3");

// Menu Items
$menu_query = "SELECT m.*, 
               (SELECT AVG(rating) FROM ratings WHERE itemId = m.itemId) as avg_rating,
               (SELECT COUNT(*) FROM ratings WHERE itemId = m.itemId) as rating_count,
               (SELECT COUNT(*) FROM menu_option_groups WHERE itemId = m.itemId) as option_group_count
               FROM menu_items m 
               ORDER BY m.isAvailable DESC, m.itemId DESC";

$menu_items = $conn->query($menu_query); 

$categories = [];
$catResult = $conn->query("SELECT DISTINCT category FROM menu_items WHERE isAvailable = 1");
while ($cat = $catResult->fetch_assoc()) {
    $categories[] = $cat['category'];
}

// Voucher
$showVoucher = isset($_GET['show_voucher']) && isset($_SESSION['voucher_data']);
$voucherData = $showVoucher ? $_SESSION['voucher_data'] : null;

if ($showVoucher) {
    unset($_SESSION['voucher_data']);
}
?>

<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>UCSH Smart Canteen</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Myanmar:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

    <style>
        :root {
            --brand-color: #1EAFBD;
            --brand-color-hover: #17939F;
            --brand-light: #EBF8F9;
            --brand-dark: #0F5860;
        }

        * { box-sizing: border-box; }
        html, body { overflow-x: hidden; max-width: 100vw; }

        body {
            font-family: 'Plus Jakarta Sans', 'Noto Sans Myanmar', sans-serif;
            background-color: #F8FAFC;
            color: #1E293B;
            font-size: 15px;
        }

        h1 { font-size: clamp(1.5rem, 4vw, 2.5rem); }
        h2 { font-size: clamp(1.4rem, 3.5vw, 2.2rem); }
        h3 { font-size: clamp(1.3rem, 3vw, 1.9rem); }
        h4 { font-size: clamp(1.2rem, 2.8vw, 1.6rem); }
        h5 { font-size: clamp(1.1rem, 2.5vw, 1.4rem); }
        h6 { font-size: clamp(1rem, 2vw, 1.15rem); }

        .bg-brand { background-color: var(--brand-color) !important; }
        .bg-brand-light { background-color: var(--brand-light) !important; }
        .text-brand { color: var(--brand-color) !important; }
        
        .btn-brand {
            background-color: var(--brand-color);
            color: #FFFFFF;
            border: none;
            transition: all 0.25s ease;
        }
        .btn-brand:hover {
            background-color: var(--brand-color-hover);
            color: #FFFFFF;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(30, 175, 189, 0.3);
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
        .search-box .form-control:focus {
            box-shadow: 0 0 0 3px rgba(30, 175, 189, 0.15);
            border-color: var(--brand-color);
            background-color: #FFFFFF;
        }
        .search-box .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #94A3B8;
        }

        .announcement-box {
            background: linear-gradient(135deg, #1EAFBD 0%, #0F5860 100%);
            border-radius: 20px;
            color: #FFFFFF;
            box-shadow: 0 8px 24px rgba(30, 175, 189, 0.22);
            position: relative;
            overflow: hidden;
        }
        .announcement-card-item {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 16px;
            transition: all 0.3s ease;
        }
        .announcement-card-item:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-3px);
        }

        /* MENU CARD */
        .menu-item-card {
            display: flex;
            flex-direction: column;
            width: 100%;
        }
        
        .menu-card {
            border: 1px solid #F1F5F9;
            border-radius: 20px;
            overflow: hidden;
            background: #FFFFFF;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .menu-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 16px 30px rgba(0, 0, 0, 0.07);
            border-color: var(--brand-light);
        }
        .menu-card-img-wrapper {
            position: relative;
            height: clamp(120px, 18vw, 180px);
            overflow: hidden;
            flex-shrink: 0;
        }
        .menu-card-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        .menu-card:hover .menu-card-img { transform: scale(1.08); }

        .menu-card .card-body {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
            padding: 10px 12px;
        }

        .menu-card .card-title {
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 24px;
        }

        .menu-card .special-note-box {
            min-height: 20px;
            margin-bottom: 4px;
        }
        .menu-card .special-note-text {
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .menu-card .points-text {
            min-height: 28px;
        }

        .menu-card .rating-box {
            min-height: 20px;
            margin-bottom: 8px;
        }

        .options-indicator {
            background: linear-gradient(135deg, #FEF3C7, #FDE68A);
            color: #92400E;
            border: 1px solid #FCD34D;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        /* OUT OF STOCK */
        .menu-card.out-of-stock { position: relative; }
        .menu-card.out-of-stock .menu-card-img { filter: grayscale(50%) brightness(0.85); }
        .menu-card.out-of-stock .stock-overlay { display: flex; }

        .stock-overlay {
            display: none;
            position: absolute;
            left: 0; right: 0; top: 30%; bottom: 0;
            background: linear-gradient(180deg, 
                rgba(220, 38, 38, 0) 0%, 
                rgba(220, 38, 38, 0.35) 25%,
                rgba(220, 38, 38, 0.75) 55%, 
                rgba(185, 28, 28, 0.98) 100%);
            z-index: 5;
            align-items: flex-end;
            justify-content: center;
            padding-bottom: 70px;
            pointer-events: none;
            border-radius: 0 0 20px 20px;
        }

        .stock-overlay-text {
            color: #FFFFFF;
            font-weight: 800;
            font-size: clamp(1rem, 2.5vw, 1.35rem);
            letter-spacing: 2px;
            text-transform: uppercase;
            text-shadow: 0 2px 12px rgba(0, 0, 0, 0.5);
            animation: stockPulse 2s ease-in-out infinite;
        }

        @keyframes stockPulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.85; transform: scale(1.03); }
        }

        .menu-card.out-of-stock .btn-out-of-stock {
            background: #6B7280 !important;
            color: #FFFFFF !important;
            cursor: not-allowed !important;
            opacity: 1 !important;
            border: none;
        }

        .menu-card.out-of-stock .btn-out-of-stock:hover {
            transform: none !important;
            box-shadow: none !important;
        }

        .like-btn {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(6px);
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #CBD5E0;
            transition: all 0.2s ease;
        }
        .like-btn:hover, .like-btn.active {
            color: #FF4757;
            background: #FFFFFF;
            transform: scale(1.1);
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
        .category-filter-btn {
            border-radius: 20px;
            padding: 6px 16px;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.2s ease;
            border: 1px solid #E2E8F0;
            background: white;
            color: #64748B;
            white-space: nowrap;
        }
        .category-filter-btn:hover {
            border-color: var(--brand-color);
            color: var(--brand-color);
        }
        .category-filter-btn.active {
            background: var(--brand-color);
            color: white;
            border-color: var(--brand-color);
        }

        /* Voucher */
        .voucher-content { background: white; border: 1px solid #e2e8f0; }
        .voucher-header { background: #0d1b2a; color: white; padding: 20px; text-align: center; }
        .voucher-divider {
            background: #0d1b2a; height: 10px;
            background-image: radial-gradient(circle, #fff 45%, transparent 50%);
            background-size: 16px 16px; background-repeat: repeat-x;
        }
        .voucher-items { background: #f8fafc; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; }

        /* AI RECOMMENDATION */
        .ai-section { animation: aiFadeIn 0.6s ease-out; }
        @keyframes aiFadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .ai-card {
            position: relative;
            background: linear-gradient(135deg, #1EAFBD 0%, #17939F 50%, #0F5860 100%);
            border-radius: clamp(16px, 3vw, 24px);
            padding: clamp(16px, 3vw, 24px);
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(30, 175, 189, 0.35), 0 0 40px rgba(30, 175, 189, 0.15);
            color: white;
        }

        .ai-bg-animation {
            position: absolute; top: -50%; right: -50%;
            width: 200%; height: 200%;
            background: radial-gradient(circle at 30% 50%, rgba(255,255,255,0.15) 0%, transparent 50%),
                        radial-gradient(circle at 70% 80%, rgba(235, 248, 249, 0.4) 0%, transparent 50%);
            animation: bgFloat 15s ease-in-out infinite;
            pointer-events: none;
        }

        @keyframes bgFloat {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            33% { transform: translate(30px, -30px) rotate(120deg); }
            66% { transform: translate(-20px, 20px) rotate(240deg); }
        }

        .ai-header {
            display: flex; align-items: center;
            gap: clamp(8px, 2vw, 14px);
            margin-bottom: 16px;
            position: relative; z-index: 2;
            flex-wrap: wrap;
        }

        .ai-avatar {
            position: relative;
            width: clamp(42px, 8vw, 52px);
            height: clamp(42px, 8vw, 52px);
            background: linear-gradient(135deg, #ffffff 0%, #EBF8F9 100%);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #1EAFBD;
            font-size: clamp(18px, 3vw, 22px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
            flex-shrink: 0;
        }

        .ai-avatar-pulse {
            position: absolute; inset: -4px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.6);
            animation: avatarPulse 2s ease-out infinite;
        }

        @keyframes avatarPulse {
            0% { transform: scale(1); opacity: 1; }
            100% { transform: scale(1.4); opacity: 0; }
        }

        .ai-header-text { flex: 1; min-width: 0; }

        .ai-title {
            margin: 0; font-weight: 700;
            font-size: clamp(0.85rem, 2vw, 1rem);
            display: flex; align-items: center; gap: 8px;
            color: white; flex-wrap: wrap;
        }

        .ai-badge {
            background: #FFFFFF; color: #1EAFBD;
            padding: 2px 10px; border-radius: 20px;
            font-size: clamp(0.55rem, 1.5vw, 0.65rem);
            font-weight: 800; letter-spacing: 1px;
            animation: badgeShine 2s infinite;
            box-shadow: 0 4px 12px rgba(255, 255, 255, 0.4);
        }

        @keyframes badgeShine {
            0%, 100% { box-shadow: 0 4px 12px rgba(255, 255, 255, 0.4); }
            50% { box-shadow: 0 4px 20px rgba(255, 255, 255, 0.9); }
        }

        .ai-subtitle {
            margin: 2px 0 0 0;
            font-size: clamp(0.65rem, 1.5vw, 0.75rem);
            opacity: 0.9;
        }

        .ai-refresh-btn {
            width: 36px; height: 36px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.15);
            color: white; cursor: pointer;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
            flex-shrink: 0;
        }

        .ai-refresh-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(180deg);
        }

        .ai-message-box {
            background: rgba(255,255,255,0.15);
            border-radius: 16px;
            padding: clamp(10px, 2vw, 14px) clamp(12px, 2vw, 16px);
            margin-bottom: 16px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            position: relative; z-index: 2;
            min-height: 56px;
        }

        .ai-message-text {
            margin: 0;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            line-height: 1.5; position: relative;
        }

        .ai-typing {
            display: inline-flex; gap: 4px;
            margin-right: 8px; vertical-align: middle;
        }

        .ai-typing span {
            width: 6px; height: 6px;
            background: white; border-radius: 50%;
            animation: typing 1.4s infinite;
        }

        .ai-typing span:nth-child(2) { animation-delay: 0.2s; }
        .ai-typing span:nth-child(3) { animation-delay: 0.4s; }

        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.5; }
            30% { transform: translateY(-6px); opacity: 1; }
        }

        .ai-loading {
            text-align: center; padding: 20px 0;
            position: relative; z-index: 2;
        }

        .ai-loading-spinner {
            position: relative;
            width: 60px; height: 60px;
            margin: 0 auto 16px;
        }

        .ai-spinner-ring {
            position: absolute; inset: 0;
            border-radius: 50%;
            border: 3px solid transparent;
            border-top-color: white;
            animation: spinRing 1.2s linear infinite;
        }

        .ai-spinner-ring:nth-child(2) {
            inset: 8px;
            border-top-color: rgba(255,255,255,0.7);
            animation-duration: 0.8s;
            animation-direction: reverse;
        }

        .ai-spinner-ring:nth-child(3) {
            inset: 16px;
            border-top-color: rgba(255,255,255,0.4);
            animation-duration: 1s;
        }

        @keyframes spinRing { to { transform: rotate(360deg); } }

        .ai-loading-text {
            font-size: clamp(0.75rem, 1.8vw, 0.85rem);
            opacity: 0.9; margin: 0;
        }

        .ai-favorite-box {
            margin-bottom: 16px;
            position: relative; z-index: 2;
            animation: slideInLeft 0.5s ease-out;
        }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .ai-section-label {
            font-size: clamp(0.7rem, 1.6vw, 0.75rem);
            font-weight: 700; margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.95;
        }

        .ai-favorite-content {
            background: rgba(255,255,255,0.2);
            border-radius: 14px; padding: 12px;
            display: flex; align-items: center;
            gap: 12px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
            flex-wrap: wrap;
        }

        .ai-suggestions-box {
            position: relative; z-index: 2;
            animation: slideInRight 0.5s ease-out;
        }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .ai-suggestions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(clamp(120px, 25vw, 160px), 1fr));
            gap: clamp(8px, 2vw, 12px);
        }

        .ai-suggestion-card {
            background: rgba(255,255,255,0.98);
            border-radius: 16px; overflow: hidden;
            color: #1E293B;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer; position: relative;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .ai-suggestion-card:hover {
            transform: translateY(-6px) scale(1.02);
            box-shadow: 0 12px 30px rgba(30, 175, 189, 0.3);
        }

        .ai-suggestion-card::before {
            content: '✨ AI Pick';
            position: absolute; top: 8px; right: 8px;
            background: #1EAFBD; color: white;
            font-size: 0.6rem; font-weight: 700;
            padding: 3px 8px; border-radius: 20px;
            z-index: 2;
            box-shadow: 0 4px 12px rgba(30, 175, 189, 0.5);
        }

        .ai-suggestion-img {
            width: 100%;
            height: clamp(70px, 12vw, 90px);
            object-fit: cover;
        }

        .ai-suggestion-body { padding: clamp(8px, 1.5vw, 10px); }

        .ai-suggestion-name {
            font-weight: 700;
            font-size: clamp(0.7rem, 1.6vw, 0.8rem);
            margin-bottom: 4px;
            overflow: hidden; text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ai-suggestion-points {
            color: #1EAFBD; font-weight: 700;
            font-size: clamp(0.75rem, 1.7vw, 0.85rem);
        }

        .ai-suggestion-reason {
            font-size: clamp(0.6rem, 1.4vw, 0.65rem);
            color: #64748B; margin-top: 4px;
            display: flex; align-items: center; gap: 4px;
        }

        .ai-suggestion-btn {
            width: 100%; margin-top: 8px; padding: 6px;
            border: none; border-radius: 8px;
            background: #1EAFBD; color: white;
            font-size: clamp(0.65rem, 1.5vw, 0.7rem);
            font-weight: 700; cursor: pointer;
            transition: all 0.2s;
        }

        .ai-suggestion-btn:hover {
            background: #17939F;
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(30, 175, 189, 0.4);
        }

        .ai-favorite-img {
            width: 50px; height: 50px;
            border-radius: 10px; object-fit: cover;
            flex-shrink: 0;
        }

        .ai-favorite-info { flex: 1; min-width: 0; }

        .ai-favorite-name {
            font-weight: 700;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            margin: 0;
            overflow: hidden; text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ai-favorite-meta {
            font-size: clamp(0.65rem, 1.5vw, 0.7rem);
            opacity: 0.9; margin: 2px 0 0 0;
        }

        /* 🎯 OPTIONS MODAL */
        .option-choice {
            cursor: pointer;
            transition: all 0.2s;
            background: white;
        }
        .option-choice:hover {
            background: #EBF8F9 !important;
            border-color: #1EAFBD !important;
        }
        .option-choice:has(input:checked) {
            background: #EBF8F9 !important;
            border-color: #1EAFBD !important;
        }
        .option-choice input:checked ~ span {
            color: #1EAFBD;
            font-weight: 700;
        }

        /* CHATBOT */
        .chatbot-widget {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
        }

        .chatbot-toggle-btn {
            width: 60px; height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1EAFBD 0%, #0F5860 100%);
            color: white; border: none;
            font-size: 24px; cursor: pointer;
            box-shadow: 0 8px 24px rgba(30, 175, 189, 0.4);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .chatbot-toggle-btn:hover {
            transform: scale(1.1) rotate(10deg);
            box-shadow: 0 12px 32px rgba(30, 175, 189, 0.6);
        }

        .chatbot-toggle-btn::before {
            content: '';
            position: absolute; inset: -4px;
            border-radius: 50%;
            border: 2px solid rgba(30, 175, 189, 0.4);
            animation: chatbotPulse 2s ease-out infinite;
        }

        @keyframes chatbotPulse {
            0% { transform: scale(1); opacity: 1; }
            100% { transform: scale(1.4); opacity: 0; }
        }

        .chatbot-badge {
            position: absolute; top: -4px; right: -4px;
            background: #FF4757; color: white;
            font-size: 0.65rem; font-weight: 700;
            width: 22px; height: 22px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid white;
            animation: badgeBounce 2s ease-in-out infinite;
        }

        @keyframes badgeBounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-4px); }
        }

        .chatbot-window {
            position: absolute;
            bottom: 80px; right: 0;
            width: 380px;
            max-width: calc(100vw - 40px);
            height: 560px;
            max-height: calc(100vh - 120px);
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            display: none;
            flex-direction: column;
            overflow: hidden;
            animation: chatbotOpen 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes chatbotOpen {
            from { opacity: 0; transform: translateY(20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .chatbot-window.active { display: flex; }

        .chatbot-header {
            background: linear-gradient(135deg, #1EAFBD 0%, #0F5860 100%);
            padding: 16px;
            display: flex; align-items: center; gap: 12px;
            color: white;
        }

        .chatbot-avatar {
            width: 44px; height: 44px;
            background: white; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #1EAFBD; font-size: 20px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .chatbot-header-info { flex: 1; min-width: 0; }

        .chatbot-title {
            margin: 0; font-weight: 700;
            font-size: 0.95rem; color: white;
        }

        .chatbot-status {
            margin: 2px 0 0 0;
            font-size: 0.7rem; opacity: 0.9;
            display: flex; align-items: center; gap: 6px;
        }

        .status-dot {
            width: 6px; height: 6px;
            background: #4ADE80; border-radius: 50%;
            animation: statusPulse 2s infinite;
        }

        @keyframes statusPulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .chatbot-close-btn {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            border: none; color: white; cursor: pointer;
            transition: all 0.2s;
        }

        .chatbot-close-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .chatbot-messages {
            flex: 1; overflow-y: auto; padding: 16px;
            background: #F8FAFC;
            display: flex; flex-direction: column; gap: 12px;
        }

        .chatbot-messages::-webkit-scrollbar { width: 6px; }
        .chatbot-messages::-webkit-scrollbar-thumb { background: #CBD5E0; border-radius: 3px; }

        .chat-message {
            display: flex; gap: 8px;
            animation: messageIn 0.3s ease-out;
        }

        @keyframes messageIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .chat-message.user-message { flex-direction: row-reverse; }

        .message-avatar {
            width: 32px; height: 32px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; font-size: 14px;
        }

        .bot-message .message-avatar {
            background: linear-gradient(135deg, #1EAFBD 0%, #0F5860 100%);
            color: white;
        }

        .user-message .message-avatar {
            background: #E2E8F0; color: #64748B;
        }

        .message-bubble {
            max-width: 75%;
            padding: 10px 14px;
            border-radius: 16px;
            font-size: 0.85rem;
            line-height: 1.5;
            word-wrap: break-word;
        }

        .bot-message .message-bubble {
            background: white; color: #1E293B;
            border-bottom-left-radius: 4px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .user-message .message-bubble {
            background: linear-gradient(135deg, #1EAFBD 0%, #0F5860 100%);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .typing-indicator {
            display: inline-flex; gap: 4px; padding: 4px 0;
        }

        .typing-indicator span {
            width: 8px; height: 8px;
            background: #1EAFBD; border-radius: 50%;
            animation: typingDot 1.4s infinite;
        }

        .typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
        .typing-indicator span:nth-child(3) { animation-delay: 0.4s; }

        @keyframes typingDot {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.5; }
            30% { transform: translateY(-6px); opacity: 1; }
        }

        .chatbot-quick-replies {
            padding: 8px 12px;
            background: white;
            border-top: 1px solid #F1F5F9;
            display: flex; gap: 6px;
            overflow-x: auto;
            scrollbar-width: none;
        }

        .chatbot-quick-replies::-webkit-scrollbar { display: none; }

        .quick-reply-btn {
            padding: 6px 12px;
            border-radius: 20px;
            border: 1px solid #1EAFBD;
            background: white;
            color: #1EAFBD;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            display: flex; align-items: center; gap: 4px;
            flex-shrink: 0;
        }

        .quick-reply-btn:hover {
            background: #1EAFBD;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 175, 189, 0.3);
        }

        .chatbot-input-box {
            padding: 12px;
            background: white;
            border-top: 1px solid #F1F5F9;
            display: flex; gap: 8px; align-items: center;
        }

        .chatbot-input {
            flex: 1;
            padding: 10px 16px;
            border: 1px solid #E2E8F0;
            border-radius: 24px;
            font-size: 0.85rem;
            outline: none;
            transition: all 0.2s;
            font-family: inherit;
        }

        .chatbot-input:focus {
            border-color: #1EAFBD;
            box-shadow: 0 0 0 3px rgba(30, 175, 189, 0.15);
        }

        .chatbot-send-btn {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1EAFBD 0%, #0F5860 100%);
            color: white; border: none; cursor: pointer;
            transition: all 0.2s;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        .chatbot-send-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(30, 175, 189, 0.4);
        }

        /* RESPONSIVE */
        @media (max-width: 576px) {
            body { font-size: 14px; }
            .container { padding-left: 12px; padding-right: 12px; }
            .menu-card-img-wrapper { height: 110px; }
            .menu-card .card-body { padding: 8px 10px !important; }
            .category-filter-btn { padding: 5px 12px; font-size: 0.75rem; }
            .ai-suggestions-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
            .announcement-box { padding: 14px !important; border-radius: 16px; }
            .chatbot-window {
                width: calc(100vw - 24px);
                height: calc(100vh - 100px);
                right: -8px;
                bottom: 80px;
                border-radius: 20px;
            }
            .chatbot-toggle-btn { width: 54px; height: 54px; font-size: 22px; }
            .message-bubble { max-width: 85%; }
            .stock-overlay { padding-bottom: 60px; }
        }

        @media (min-width: 577px) and (max-width: 992px) {
            .ai-suggestions-grid { grid-template-columns: repeat(3, 1fr); }
        }

        @media (min-width: 993px) and (max-width: 1399px) {
            .ai-suggestions-grid { grid-template-columns: repeat(4, 1fr); }
        }

        @media (min-width: 1400px) {
            #menuContainer {
                display: grid !important;
                grid-template-columns: repeat(4, 1fr) !important;
                gap: 16px !important;
            }
            #menuContainer .menu-item-card {
                width: 100% !important;
                max-width: 100% !important;
                flex: none !important;
                padding: 0 !important;
                margin: 0 !important;
            }
        }

        @media (min-width: 1920px) {
            body { font-size: 18px; }
            .container { max-width: 1800px; }
            .menu-card-img-wrapper { height: 240px; }
        }
    </style>
</head>
<body class="pb-5">

<?php include 'nav.php'; ?>

<!-- Voucher Modal -->
<div class="modal fade" id="voucherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-light border-0">
                <h6 class="fw-bold m-0"><i class="fa-solid fa-receipt text-brand me-2"></i>UCSH Canteen Voucher</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3 p-md-4">
                <div id="voucherContent" class="voucher-content rounded-4 shadow-sm overflow-hidden">
                    <div class="voucher-header">
                        <div class="d-flex align-items-center justify-content-center gap-2 mb-1">
                            <i class="fa-solid fa-utensils text-info fs-4"></i>
                            <h5 class="fw-bold m-0 text-white">UCSH Canteen Voucher</h5>
                        </div>
                        <small class="text-white-50" style="font-size: 12px;">Smart Canteen System</small>
                        <div class="mt-3">
                            <span class="badge text-white fs-2 px-4 py-2 rounded-3 fw-bold" id="vQueueNumber" style="background-color: #ff4757; letter-spacing: 1px;">Q-000</span>
                        </div>
                    </div>
                    <div class="voucher-divider"></div>
                    
                    <div class="p-3 p-md-4">
                        <div class="text-center mb-3">
                            <span class="badge bg-success-subtle text-success px-3 py-1 rounded-pill small fw-bold">
                                <i class="fa-solid fa-circle-check me-1"></i> Order Confirmed!
                            </span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 small">
                            <span class="text-muted">Customer:</span>
                            <span class="fw-bold text-dark" id="vCustomerName">-</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 small">
                            <span class="text-muted">Order Date:</span>
                            <span class="fw-bold text-dark" id="vOrderDate">-</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 small">
                            <span class="text-muted">Pickup Time:</span>
                            <span class="fw-bold text-danger" id="vPickupTime">-</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 small">
                            <span class="text-muted">Payment Method:</span>
                            <span class="fw-bold text-dark"><i class="fa-solid fa-coins text-warning me-1"></i> Points</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 small">
                            <span class="text-muted">Points Used:</span>
                            <span class="fw-bold text-dark" id="vPointsUsed">0</span>
                        </div>
                        <div class="voucher-items mb-3">
                            <div class="text-muted fw-bold mb-1" style="font-size: 10px;">ORDERED ITEMS:</div>
                            <div id="vItemsList" class="fw-medium text-dark">
                                <span class="text-muted">-</span>
                            </div>
                        </div>
                        <div class="bg-light p-3 rounded-3 mb-3 border small">
                            <div class="text-muted fw-bold mb-1" style="font-size: 10px;">ORDER DETAILS:</div>
                            <div class="d-flex justify-content-between fw-medium" id="vItemsSummary">
                                <span>-</span>
                                <span>-</span>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                            <span class="fw-bold text-dark small">TOTAL POINTS:</span>
                            <span class="fw-bold fs-5" id="vTotalAmount" style="color: #ff4757;">0</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-0">
                <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">ပိတ်မည်</button>
                <button type="button" onclick="downloadVoucher()" class="btn btn-dark btn-sm rounded-pill px-4 fw-bold shadow-sm">
                    <i class="fa-solid fa-download me-1"></i> Download (PNG)
                </button>
            </div>
        </div>
    </div>
</div>

<div class="container my-3 my-md-4">

    <?php if ($announcements && $announcements->num_rows > 0): ?>
        <div class="announcement-box p-3 p-md-4 mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="bg-white text-brand rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                        <i class="fa-solid fa-bullhorn"></i>
                    </div>
                    <h6 class="fw-bold mb-0 text-white text-uppercase">ကျောင်းကန်တင်း အသိပေးချက်များ</h6>
                </div>
                <span class="badge bg-white text-brand rounded-pill px-3 py-1 fs-7">Announcements</span>
            </div>
            <div class="row g-3">
                <?php while ($ann = $announcements->fetch_assoc()): ?>
                    <div class="col-12 col-md-4">
                        <div class="announcement-card-item p-3 h-100">
                            <h6 class="fw-bold mb-1 text-white text-truncate"><?= htmlspecialchars($ann['title']) ?></h6>
                            <p class="mb-0 text-white-50 small"><?= htmlspecialchars($ann['content']) ?></p>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- AI RECOMMENDATION -->
    <div id="aiRecommendationSection" class="ai-section mb-4" style="display: none;">
        <div class="ai-card">
            <div class="ai-bg-animation"></div>
            
            <div class="ai-header">
                <div class="ai-avatar">
                    <div class="ai-avatar-pulse"></div>
                    <i class="fa-solid fa-robot"></i>
                </div>
                <div class="ai-header-text">
                    <h6 class="ai-title">
                        <span class="ai-badge">AI</span> 
                        Smart Recommendation
                    </h6>
                    <p class="ai-subtitle">
                        <i class="fa-solid fa-sparkles me-1"></i>
                       Recommendation by Canteen
                    </p>
                </div>
                <button class="ai-refresh-btn" onclick="refreshAI()" title="Refresh">
                    <i class="fa-solid fa-rotate"></i>
                </button>
            </div>
            
            <div class="ai-message-box" style="display: none;">
                <div class="ai-typing" id="aiTypingIndicator" style="display: none;">
                    <span></span><span></span><span></span>
                </div>
                <p class="ai-message-text" id="aiMessageText"></p>
            </div>
            
            <div class="ai-loading" id="aiLoading" style="display: none;">
                <div class="ai-loading-spinner">
                    <div class="ai-spinner-ring"></div>
                    <div class="ai-spinner-ring"></div>
                    <div class="ai-spinner-ring"></div>
                </div>
                <p class="ai-loading-text">
                    <i class="fa-solid fa-brain me-1"></i>
                    AI က မိတ်ဆွေအတွက် စဉ်းစားနေတယ်...
                </p>
            </div>
            
            <div class="ai-favorite-box" id="aiFavorite" style="display: none;">
                <div class="ai-section-label">
                    <i class="fa-solid fa-heart text-danger me-1"></i>
                   မိတ်ဆွေ ကြိုက်တတ်တာ
                </div>
                <div class="ai-favorite-content" id="aiFavoriteContent"></div>
            </div>
            
            <div class="ai-suggestions-box" id="aiSuggestions" style="display: none;">
                <div class="ai-section-label">
                    <i class="fa-solid fa-wand-magic-sparkles text-warning me-1"></i>
                    <span id="aiTimeLabel">ဒီနေ့</span> AI အကြံပြုချက်
                </div>
                <div class="ai-suggestions-grid" id="aiSuggestionsGrid"></div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="fw-bold text-dark m-0">ယနေ့ရရှိနိုင်သော အစားအသောက်များ</h5>
        <span class="badge bg-white text-secondary border shadow-sm fw-normal px-3 py-2 rounded-pill">
            <?= $menu_items ? $menu_items->num_rows : 0 ?> Items
        </span>
    </div>

    <div class="mb-3 d-flex flex-wrap gap-2" style="overflow-x: auto; padding-bottom: 4px;">
        <button class="category-filter-btn active" data-category="all">All</button>
        <?php foreach ($categories as $cat): ?>
            <button class="category-filter-btn" data-category="<?= htmlspecialchars(strtolower($cat)) ?>"><?= htmlspecialchars($cat) ?></button>
        <?php endforeach; ?>
    </div>

    <!-- Menu Container -->
    <div class="row g-2 g-md-3" id="menuContainer">
        <?php if ($menu_items && $menu_items->num_rows > 0): ?>
            <?php while ($item = $menu_items->fetch_assoc()): ?>
                
                <?php 
                    $imgPath = 'https://via.placeholder.com/300x200?text=No+Image';
                    if (!empty($item['image'])) {
                        if (file_exists($item['image'])) {
                            $imgPath = $item['image'];
                        } elseif (file_exists('uploads/' . basename($item['image']))) {
                            $imgPath = 'uploads/' . basename($item['image']);
                        }
                    }

                    $is_liked = in_array($item['itemId'], $user_liked_item_ids);
                    $is_out = ($item['isAvailable'] == 0);
                    $has_options = ($item['option_group_count'] > 0);
                ?>

                <div class="col-6 col-sm-6 col-md-4 col-lg-3 menu-item-card" 
                     data-name="<?= htmlspecialchars(mb_strtolower($item['itemName'], 'UTF-8')) ?>"
                     data-category="<?= htmlspecialchars(mb_strtolower($item['category'] ?? '', 'UTF-8')) ?>">
    
                    <div class="card menu-card <?= $is_out ? 'out-of-stock' : '' ?>">
                        
                        <?php if ($is_out): ?>
                            <div class="stock-overlay">
                                <span class="stock-overlay-text">Out Of Stock</span>
                            </div>
                        <?php endif; ?>
                        
                        <div class="menu-card-img-wrapper">
                            <div class="position-absolute top-0 end-0 p-2 z-2">
                                <button class="like-btn shadow-sm <?= $is_liked ? 'active' : '' ?>" onclick="event.stopPropagation(); toggleLike(this, <?= $item['itemId'] ?>);">
                                    <i class="fa-solid fa-heart"></i>
                                </button>
                            </div>
                            <img src="<?= $imgPath ?>" class="menu-card-img" alt="<?= htmlspecialchars($item['itemName']) ?>" data-bs-toggle="modal" data-bs-target="#detailModal<?= $item['itemId'] ?>">
                        </div>

                        <div class="card-body d-flex flex-column">
                            
                            <div style="min-height: 26px;" class="d-flex justify-content-between align-items-start gap-1">
                                <span class="badge bg-brand-light text-brand border-0 align-self-start mb-2 px-2 py-1 rounded-2 fs-7 fw-medium">
                                    <?= htmlspecialchars($item['category'] ?? 'General') ?>
                                </span>
                                <?php if ($has_options): ?>
                                    <span class="options-indicator">
                                        <i class="fa-solid fa-list-check"></i>
                                        ရွေးချယ်ရန်
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <h6 class="card-title fw-bold text-dark mb-1" 
                                style="font-size: clamp(0.8rem, 1.8vw, 1rem);">
                                <?= htmlspecialchars($item['itemName']) ?>
                            </h6>
                            
                            <div class="special-note-box">
                                <?php if (!empty($item['special_note'])): ?>
                                    <small class="text-danger fw-bold special-note-text" style="font-size: 0.7rem;">
                                        <i class="fa-solid fa-circle-exclamation me-1"></i>
                                        <?= htmlspecialchars($item['special_note']) ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            
                            <p class="card-text text-brand fw-bold fs-6 mb-2 points-text">
                                <?= number_format($item['points']) ?> 
                                <small class="text-muted fw-normal fs-7">Points</small>
                            </p>
                            
                            <div class="small text-muted rating-box">
                                <?php if (!empty($item['avg_rating']) && $item['rating_count'] > 0): ?>
                                    <i class="fa-solid fa-star text-warning"></i>
                                    <span class="fw-bold"><?= number_format($item['avg_rating'], 1) ?></span>
                                    <span>(<?= $item['rating_count'] ?>)</span>
                                <?php endif; ?>
                            </div>

                            <div class="mt-auto">
                                <?php if ($is_out): ?>
                                    <button class="btn btn-out-of-stock btn-sm w-100 py-2 rounded-3 fw-bold" disabled>
                                        <i class="fa-solid fa-ban me-1"></i>Out Of Stock
                                    </button>
                                <?php else: ?>
                                    <button onclick="addToCart(<?= $item['itemId'] ?>)" class="btn btn-brand btn-sm w-100 py-2 rounded-3 fw-medium">
                                        <i class="fa-solid fa-cart-plus me-1"></i>မှာယူမည်
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal fade" id="detailModal<?= $item['itemId'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content rounded-4 border-0 overflow-hidden shadow">
                            <img src="<?= $imgPath ?>" class="w-100" style="height: 220px; object-fit: cover;">
                            <div class="modal-body p-4">
                                <span class="badge bg-brand-light text-brand mb-2"><?= htmlspecialchars($item['category'] ?? 'General') ?></span>
                                <h5 class="fw-bold text-dark"><?= htmlspecialchars($item['itemName']) ?></h5>
                                
                                <?php if (!empty($item['special_note'])): ?>
                                    <div class="alert alert-danger py-1 px-2 mb-2" style="font-size: 0.8rem;">
                                        <i class="fa-solid fa-circle-exclamation me-1"></i>
                                        <?= htmlspecialchars($item['special_note']) ?>
                                    </div>
                                <?php endif; ?>
                                
                                <h5 class="text-brand fw-bold mb-3"><?= number_format($item['points']) ?> Points</h5>
                                <p class="text-muted small mb-4">UCSH Canteen မှ လတ်ဆတ်စွာ ချက်ပြုတ်ပြင်ဆင်ပေးထားသော အစားအသောက်ဖြစ်ပါသည်။</p>
                                
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-light w-50 py-2 rounded-3" data-bs-dismiss="modal">ပိတ်မည်</button>
                                    <?php if ($is_out): ?>
                                        <button class="btn btn-secondary w-50 py-2 rounded-3 fw-medium" disabled>
                                            <i class="fa-solid fa-ban me-1"></i>Out of Stock
                                        </button>
                                    <?php else: ?>
                                        <button onclick="addToCart(<?= $item['itemId'] ?>); bootstrap.Modal.getInstance(document.getElementById('detailModal<?= $item['itemId'] ?>')).hide();" class="btn btn-brand w-50 py-2 rounded-3 fw-medium">
                                            <i class="fa-solid fa-cart-plus me-1"></i>မှာယူမည်
                                        </button>
                                    <?php endif; ?>
                                </div>
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

<!-- ============================================= -->
<!-- 🎯 OPTIONS MODAL (Add to Cart)                -->
<!-- ============================================= -->
<div class="modal fade" id="optionsModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-light border-0">
                <h6 class="fw-bold m-0">
                    <i class="fa-solid fa-list-check text-brand me-2"></i>
                    <span id="optionsModalTitle">ရွေးချယ်ပါ</span>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="optionsModalBody">
                <!-- Dynamic -->
            </div>
            <div class="modal-footer bg-light border-0">
                <button type="button" class="btn btn-secondary btn-sm rounded-3 px-4" data-bs-dismiss="modal">
                    မလုပ်တော့ပါ
                </button>
                <button type="button" class="btn btn-brand btn-sm rounded-3 px-4 fw-bold" 
                        onclick="confirmAddToCart()" id="optionsConfirmBtn">
                    <i class="fa-solid fa-cart-plus me-1"></i>Cart ထဲထည့်မည်
                </button>
            </div>
        </div>
    </div>
</div>

<!-- AI CHATBOT WIDGET -->
<div class="chatbot-widget" id="chatbotWidget">
    <button class="chatbot-toggle-btn" onclick="toggleChatbot()" id="chatbotToggle">
        <i class="fa-solid fa-comments" id="chatbotIcon"></i>
        <span class="chatbot-badge" id="chatbotBadge">1</span>
    </button>

    <div class="chatbot-window" id="chatbotWindow">
        <div class="chatbot-header">
            <div class="chatbot-avatar">
                <i class="fa-solid fa-robot"></i>
            </div>
            <div class="chatbot-header-info">
                <h6 class="chatbot-title">UCSH AI Assistant</h6>
                <p class="chatbot-status">
                    <span class="status-dot"></span>
                    Online • တစ်ချက်နှိပ်ပြီး မေးပါ
                </p>
            </div>
            <button class="chatbot-close-btn" onclick="toggleChatbot()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="chatbot-messages" id="chatbotMessages">
            <div class="chat-message bot-message">
                <div class="message-avatar"><i class="fa-solid fa-robot"></i></div>
                <div class="message-bubble">
                    👋 မင်္ဂလာပါ!<br>
                    ကျွန်တော် <strong>UCSH AI Assistant</strong> ပါ။<br>
                    ဘာကူညီပေးရမလဲ? 🤖
                </div>
            </div>
        </div>

        <div class="chatbot-quick-replies" id="chatbotQuickReplies">
            <button class="quick-reply-btn" onclick="sendQuickReply('Point ဘယ်လောက်ရှိလဲ?')">
                <i class="fa-solid fa-coins"></i> Point
            </button>
            <button class="quick-reply-btn" onclick="sendQuickReply('Menu ကြည့်မည်')">
                <i class="fa-solid fa-utensils"></i> Menu
            </button>
            <button class="quick-reply-btn" onclick="sendQuickReply('ဒီနေ့ ဘာစားရမလဲ?')">
                <i class="fa-solid fa-wand-magic-sparkles"></i> AI Pick
            </button>
            <button class="quick-reply-btn" onclick="sendQuickReply('Help')">
                <i class="fa-solid fa-circle-question"></i> Help
            </button>
        </div>

        <div class="chatbot-input-box">
            <input type="text" 
                   class="chatbot-input" 
                   id="chatbotInput" 
                   placeholder="သင့်မေးခွန်း ရိုက်ထည့်ပါ..." 
                   onkeypress="if(event.key==='Enter') sendMessage()">
            <button class="chatbot-send-btn" onclick="sendMessage()">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// =============================================
// GRID LAYOUT
// =============================================
function applyGridLayout() {
    const container = document.getElementById('menuContainer');
    if (!container) return;
    
    const width = window.innerWidth;
    
    if (width >= 1400) {
        container.style.display = 'grid';
        container.style.gridTemplateColumns = 'repeat(4, 1fr)';
        container.style.gap = '16px';
    } else if (width >= 993) {
        container.style.display = 'grid';
        container.style.gridTemplateColumns = 'repeat(4, 1fr)';
        container.style.gap = '16px';
    } else if (width >= 768) {
        container.style.display = 'grid';
        container.style.gridTemplateColumns = 'repeat(3, 1fr)';
        container.style.gap = '12px';
    } else {
        container.style.display = 'grid';
        container.style.gridTemplateColumns = 'repeat(2, 1fr)';
        container.style.gap = '8px';
    }
}

window.addEventListener('resize', applyGridLayout);
document.addEventListener('DOMContentLoaded', applyGridLayout);

// =============================================
// CATEGORY FILTER
// =============================================
document.querySelectorAll('.category-filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.category-filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        const category = this.dataset.category;
        const items = document.querySelectorAll('.menu-item-card');
        
        items.forEach(item => {
            if (category === 'all') {
                item.style.display = '';
            } else {
                const itemCategory = item.dataset.category;
                item.style.display = (itemCategory === category) ? '' : 'none';
            }
        });
    });
});

// =============================================
// SEARCH
// =============================================
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        let filterValue = this.value.toLowerCase().trim();
        let items = document.querySelectorAll('.menu-item-card');

        items.forEach(function(item) {
            let name = item.getAttribute('data-name');
            let category = item.getAttribute('data-category');

            if (name.includes(filterValue) || category.includes(filterValue)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    });
}

// =============================================
// 🎯 ADD TO CART WITH OPTIONS
// =============================================
let currentOptionsItemId = null;
let currentOptionsGroups = [];
let currentOptionsSelection = {};
let currentBasePoints = 0;

function addToCart(itemId) {
    fetch('api.php?action=get_item_options&itemId=' + itemId)
        .then(r => r.json())
        .then(data => {
            if (data.has_options) {
                showOptionsModal(itemId, data.groups);
            } else {
                proceedAddToCart(itemId, []);
            }
        })
        .catch(err => {
            console.error('Options fetch error:', err);
            proceedAddToCart(itemId, []);
        });
}

function showOptionsModal(itemId, groups) {
    currentOptionsItemId = itemId;
    currentOptionsGroups = groups;
    currentOptionsSelection = {};
    currentBasePoints = 0;
    
    groups.forEach(g => {
        currentOptionsSelection[g.groupId] = [];
    });
    
    // Base points — card မှ ရှာ
    const card = document.querySelector(`[onclick*="addToCart(${itemId})"]`);
    if (card) {
        const cardEl = card.closest('.menu-card');
        if (cardEl) {
            const ptsEl = cardEl.querySelector('.points-text');
            if (ptsEl) {
                currentBasePoints = parseInt(ptsEl.textContent.replace(/[^0-9]/g, '')) || 0;
            }
        }
    }
    
    const title = document.getElementById('optionsModalTitle');
    const body = document.getElementById('optionsModalBody');
    
    title.textContent = 'ရွေးချယ်ပါ';
    
    let html = '';
    groups.forEach((group) => {
        const isRadio = group.maxSelect == 1;
        html += `
            <div class="mb-4" data-group-id="${group.groupId}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold mb-0">
                        ${escapeHtml(group.groupName)}
                        ${group.isRequired == 1 
                            ? '<span class="badge bg-danger-subtle text-danger ms-1" style="font-size:0.65rem;">Required</span>' 
                            : '<span class="badge bg-secondary-subtle text-secondary ms-1" style="font-size:0.65rem;">Optional</span>'}
                    </h6>
                </div>
                <div class="d-flex flex-column gap-2">
        `;
        
        group.options.forEach(opt => {
            const optId = opt.optionId;
            const extraLabel = opt.extraPoints > 0 
                ? `<span class="badge bg-warning-subtle text-warning ms-2">+${Number(opt.extraPoints).toLocaleString()} pts</span>` 
                : '';
            
            html += `
                <label class="option-choice d-flex justify-content-between align-items-center p-2 border rounded-3" 
                       data-group-id="${group.groupId}"
                       data-option-id="${optId}">
                    <div class="d-flex align-items-center gap-2">
                        <input type="${isRadio ? 'radio' : 'checkbox'}" 
                               name="group_${group.groupId}" 
                               value="${optId}"
                               data-group-id="${group.groupId}"
                               data-option-id="${optId}"
                               data-extra="${opt.extraPoints}"
                               data-max="${group.maxSelect}"
                               class="form-check-input m-0 option-input">
                        <span class="fw-medium">${escapeHtml(opt.optionName)}</span>
                    </div>
                    ${extraLabel}
                </label>
            `;
        });
        
        html += `</div></div>`;
    });
    
    html += `
        <div class="alert alert-light border rounded-3 d-flex justify-content-between align-items-center mb-0">
            <span class="fw-bold text-dark">Total Points:</span>
            <span class="fw-bold text-brand fs-5" id="optionsTotalDisplay">${currentBasePoints.toLocaleString()}</span>
        </div>
    `;
    
    body.innerHTML = html;
    
    body.querySelectorAll('.option-input').forEach(inp => {
        inp.addEventListener('change', handleOptionChange);
    });
    
    updateConfirmButton();
    
    const modal = new bootstrap.Modal(document.getElementById('optionsModal'));
    modal.show();
}

function handleOptionChange(e) {
    const inp = e.target;
    const groupId = inp.dataset.groupId;
    const optionId = parseInt(inp.dataset.optionId);
    const maxSelect = parseInt(inp.dataset.max);
    const isRadio = inp.type === 'radio';
    
    if (isRadio) {
        currentOptionsSelection[groupId] = [optionId];
    } else {
        if (!currentOptionsSelection[groupId]) currentOptionsSelection[groupId] = [];
        if (inp.checked) {
            if (currentOptionsSelection[groupId].length >= maxSelect) {
                inp.checked = false;
                Swal.fire({
                    icon: 'warning',
                    title: 'ရွေးလို့မရပါ',
                    text: `ဒီ group မှာ ${maxSelect} ခုသာ ရွေးလို့ရပါတယ်`,
                    timer: 1500,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top'
                });
                return;
            }
            currentOptionsSelection[groupId].push(optionId);
        } else {
            currentOptionsSelection[groupId] = currentOptionsSelection[groupId].filter(id => id !== optionId);
        }
    }
    
    updateConfirmButton();
    updateOptionsTotal();
}

function updateOptionsTotal() {
    let extra = 0;
    Object.keys(currentOptionsSelection).forEach(gid => {
        currentOptionsSelection[gid].forEach(optId => {
            const inp = document.querySelector(`.option-input[data-option-id="${optId}"]`);
            if (inp) extra += parseInt(inp.dataset.extra) || 0;
        });
    });
    
    const total = currentBasePoints + extra;
    const disp = document.getElementById('optionsTotalDisplay');
    if (disp) disp.textContent = total.toLocaleString() + ' Points';
}

function updateConfirmButton() {
    const btn = document.getElementById('optionsConfirmBtn');
    if (!btn) return;
    
    let allRequiredSelected = true;
    currentOptionsGroups.forEach(g => {
        if (g.isRequired == 1 && (!currentOptionsSelection[g.groupId] || currentOptionsSelection[g.groupId].length === 0)) {
            allRequiredSelected = false;
        }
    });
    
    btn.disabled = !allRequiredSelected;
    btn.style.opacity = allRequiredSelected ? '1' : '0.5';
}

function confirmAddToCart() {
    if (!currentOptionsItemId) return;
    
    const optionsArr = [];
    currentOptionsGroups.forEach(g => {
        const picked = currentOptionsSelection[g.groupId] || [];
        picked.forEach(optId => {
            const inp = document.querySelector(`.option-input[data-option-id="${optId}"]`);
            if (inp) {
                optionsArr.push({
                    groupId: parseInt(g.groupId),
                    optionId: optId,
                    extraPoints: parseInt(inp.dataset.extra) || 0
                });
            }
        });
    });
    
    bootstrap.Modal.getInstance(document.getElementById('optionsModal')).hide();
    proceedAddToCart(currentOptionsItemId, optionsArr);
}

function proceedAddToCart(itemId, options) {
    let formData = new FormData();
    formData.append('itemId', itemId);
    formData.append('quantity', 1);
    formData.append('options', JSON.stringify(options));
    
    fetch('api.php?action=add_to_cart', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                let cartBadge = document.getElementById('cartBadge');
                if (cartBadge) {
                    let currentCount = parseInt(cartBadge.innerText) || 0;
                    cartBadge.innerText = currentCount + 1;
                }
                Swal.fire({
                    icon: 'success',
                    title: 'Cart ထဲသို့ ထည့်ပြီးပါပြီ',
                    timer: 1500,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top',
                    timerProgressBar: true,
                    background: '#07494f',
                    color: '#FFFFFF',
                    iconColor: '#FFFFFF',
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'မအောင်မြင်ပါ',
                    text: data.message || 'အမှားတစ်ခု ဖြစ်ပေါ်နေပါသည်',
                    confirmButtonColor: '#1EAFBD',
                });
            }
        })
        .catch(err => {
            Swal.fire({
                icon: 'error',
                title: 'အမှားတစ်ခု ဖြစ်ပေါ်နေပါသည်',
                confirmButtonColor: '#1EAFBD'
            });
        });
}

// =============================================
// TOGGLE LIKE
// =============================================
function toggleLike(btn, itemId) {
    let likeBadge = document.getElementById('likeBadge');
    let currentCount = parseInt(likeBadge.innerText) || 0;
    let isAdd = !btn.classList.contains('active');
    if (isAdd) {
        btn.classList.add('active');
        likeBadge.innerText = currentCount + 1;
    } else {
        btn.classList.remove('active');
        likeBadge.innerText = Math.max(0, currentCount - 1);
    }

    let formData = new FormData();
    formData.append('itemId', itemId);
    formData.append('action', isAdd ? 'add' : 'remove');

    fetch('toggle_like.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status !== 'success') {
            btn.classList.toggle('active');
            likeBadge.innerText = currentCount;
        }
    })
    .catch(err => {
        btn.classList.toggle('active');
        likeBadge.innerText = currentCount;
    });
}

// =============================================
// DOWNLOAD VOUCHER
// =============================================
function downloadVoucher() {
    const element = document.getElementById('voucherContent');
    html2canvas(element, {
        scale: 2,
        backgroundColor: '#ffffff',
        useCORS: true
    }).then(canvas => {
        const link = document.createElement('a');
        link.download = 'ucsh_voucher.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    });
}

// =============================================
// SHOW VOUCHER
// =============================================
<?php if ($showVoucher && $voucherData): ?>
document.addEventListener('DOMContentLoaded', function() {
    const orderData = <?= json_encode($voucherData) ?>;
    
    document.getElementById('vQueueNumber').innerText = orderData.queueNumber;
    document.getElementById('vCustomerName').innerText = '<?= htmlspecialchars($_SESSION['username'] ?? 'Customer') ?>';
    document.getElementById('vOrderDate').innerText = new Date().toLocaleString();
    document.getElementById('vPickupTime').innerText = orderData.pickupTime;
    document.getElementById('vTotalAmount').innerText = Number(orderData.totalAmount).toLocaleString();
    document.getElementById('vPointsUsed').innerText = Number(orderData.pointsUsed).toLocaleString();
    document.getElementById('vItemsList').innerHTML = orderData.items || 'No items';
    document.getElementById('vItemsSummary').innerHTML = `<span>Order #${orderData.orderId}</span><span>${Number(orderData.totalAmount).toLocaleString()} Points</span>`;
    
    const myModal = new bootstrap.Modal(document.getElementById('voucherModal'));
    myModal.show();
});
<?php endif; ?>

// =============================================
// AI FOOD RECOMMENDATION
// =============================================
function loadAIRecommendation() {
    const section = document.getElementById('aiRecommendationSection');
    const loading = document.getElementById('aiLoading');
    const favorite = document.getElementById('aiFavorite');
    const suggestions = document.getElementById('aiSuggestions');
    const messageBox = document.querySelector('.ai-message-box');
    
    if (!section) return;
    
    section.style.display = 'block';
    loading.style.display = 'block';
    favorite.style.display = 'none';
    suggestions.style.display = 'none';
    messageBox.style.display = 'none';
    
    fetch('ai_recommend.php')
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                section.style.display = 'none';
                return;
            }
            
            setTimeout(() => {
                loading.style.display = 'none';
                messageBox.style.display = 'block';
                
                typeAIMessage(data.aiMessage);
                
                if (data.favorite) {
                    renderFavorite(data.favorite);
                    favorite.style.display = 'block';
                }
                
                if (data.suggestions && data.suggestions.length > 0) {
                    renderSuggestions(data.suggestions, data.timeGreeting, data.timeEmoji);
                    suggestions.style.display = 'block';
                }
            }, 1200);
        })
        .catch(err => {
            console.log('AI Error:', err);
            section.style.display = 'none';
        });
}

function typeAIMessage(text) {
    const el = document.getElementById('aiMessageText');
    const typing = document.getElementById('aiTypingIndicator');
    if (!el || !typing) return;
    
    el.textContent = '';
    typing.style.display = 'inline-flex';
    
    let i = 0;
    const speed = 30;
    
    setTimeout(() => {
        const interval = setInterval(() => {
            if (i < text.length) {
                el.textContent += text.charAt(i);
                i++;
            } else {
                clearInterval(interval);
                typing.style.display = 'none';
            }
        }, speed);
    }, 500);
}

function renderFavorite(item) {
    const container = document.getElementById('aiFavoriteContent');
    if (!container) return;
    
    const imgPath = item.image ? (item.image.startsWith('uploads/') ? item.image : 'uploads/' + item.image) : 'https://via.placeholder.com/100?text=Food';
    
    container.innerHTML = `
        <img src="${imgPath}" class="ai-favorite-img" onerror="this.src='https://via.placeholder.com/100?text=Food'">
        <div class="ai-favorite-info">
            <p class="ai-favorite-name">${item.itemName}</p>
            <p class="ai-favorite-meta">
                <i class="fa-solid fa-fire text-warning"></i>
                ${item.order_count} ကြိမ် မှာဖူးတယ်
            </p>
        </div>
        <button class="btn btn-sm btn-light rounded-3 fw-bold" onclick="addToCart(${item.itemId})">
            <i class="fa-solid fa-cart-plus"></i>
        </button>
    `;
}

function renderSuggestions(items, greeting, emoji) {
    const grid = document.getElementById('aiSuggestionsGrid');
    const timeLabel = document.getElementById('aiTimeLabel');
    if (!grid) return;
    
    timeLabel.textContent = `${emoji} ${greeting}`;
    grid.innerHTML = '';
    
    items.forEach((item, index) => {
        const imgPath = item.image ? (item.image.startsWith('uploads/') ? item.image : 'uploads/' + item.image) : 'https://via.placeholder.com/200?text=Food';
        
        let reason = '';
        if (item.total_sold > 0) {
            reason = `🔥 ${item.total_sold} ခု ရောင်းရဆုံး`;
        } else if (item.avg_rating) {
            reason = `⭐ ${Number(item.avg_rating).toFixed(1)} Rating`;
        } else {
            reason = `✨ အသစ်ထည့်ထားတယ်`;
        }
        
        const card = document.createElement('div');
        card.className = 'ai-suggestion-card';
        card.style.animationDelay = `${index * 0.1}s`;
        card.innerHTML = `
            <img src="${imgPath}" class="ai-suggestion-img" onerror="this.src='https://via.placeholder.com/200?text=Food'">
            <div class="ai-suggestion-body">
                <div class="ai-suggestion-name">${item.itemName}</div>
                <div class="ai-suggestion-points">${Number(item.points).toLocaleString()} Points</div>
                <div class="ai-suggestion-reason">${reason}</div>
                <button class="ai-suggestion-btn" onclick="addToCart(${item.itemId})">
                    <i class="fa-solid fa-cart-plus me-1"></i>မှာယူမည်
                </button>
            </div>
        `;
        grid.appendChild(card);
    });
}

function refreshAI() {
    const section = document.getElementById('aiRecommendationSection');
    if (!section) return;
    section.style.animation = 'none';
    setTimeout(() => {
        section.style.animation = 'aiFadeIn 0.6s ease-out';
        loadAIRecommendation();
    }, 50);
}

// =============================================
// AI CHATBOT
// =============================================
let chatbotOpen = false;

function toggleChatbot() {
    const window = document.getElementById('chatbotWindow');
    const icon = document.getElementById('chatbotIcon');
    const badge = document.getElementById('chatbotBadge');
    
    chatbotOpen = !chatbotOpen;
    
    if (chatbotOpen) {
        window.classList.add('active');
        icon.className = 'fa-solid fa-xmark';
        if (badge) badge.style.display = 'none';
        setTimeout(() => document.getElementById('chatbotInput').focus(), 300);
    } else {
        window.classList.remove('active');
        icon.className = 'fa-solid fa-comments';
    }
}

function sendMessage() {
    const input = document.getElementById('chatbotInput');
    const message = input.value.trim();
    
    if (!message) return;
    
    addUserMessage(message);
    input.value = '';
    
    showTypingIndicator();
    
    fetch('chatbot.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'message=' + encodeURIComponent(message)
    })
    .then(r => r.json())
    .then(data => {
        removeTypingIndicator();
        if (data.success) {
            addBotMessage(data.response);
            updateQuickReplies(data.quickReplies || []);
        } else {
            addBotMessage('တောင်းပန်ပါတယ်၊ အမှားတစ်ခု ဖြစ်သွားပါတယ်။');
        }
    })
    .catch(err => {
        removeTypingIndicator();
        addBotMessage('Server နဲ့ ချိတ်ဆက်လို့မရပါ။');
    });
}

function sendQuickReply(text) {
    document.getElementById('chatbotInput').value = text;
    sendMessage();
}

function addUserMessage(text) {
    const container = document.getElementById('chatbotMessages');
    const div = document.createElement('div');
    div.className = 'chat-message user-message';
    div.innerHTML = `
        <div class="message-avatar"><i class="fa-solid fa-user"></i></div>
        <div class="message-bubble">${escapeHtml(text)}</div>
    `;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function addBotMessage(html) {
    const container = document.getElementById('chatbotMessages');
    const div = document.createElement('div');
    div.className = 'chat-message bot-message';
    div.innerHTML = `
        <div class="message-avatar"><i class="fa-solid fa-robot"></i></div>
        <div class="message-bubble">${html}</div>
    `;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function showTypingIndicator() {
    const container = document.getElementById('chatbotMessages');
    const div = document.createElement('div');
    div.className = 'chat-message bot-message';
    div.id = 'typingIndicator';
    div.innerHTML = `
        <div class="message-avatar"><i class="fa-solid fa-robot"></i></div>
        <div class="message-bubble">
            <div class="typing-indicator">
                <span></span><span></span><span></span>
            </div>
        </div>
    `;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function removeTypingIndicator() {
    const indicator = document.getElementById('typingIndicator');
    if (indicator) indicator.remove();
}

function updateQuickReplies(replies) {
    const container = document.getElementById('chatbotQuickReplies');
    if (!replies || replies.length === 0) return;
    
    container.innerHTML = '';
    replies.forEach(reply => {
        const btn = document.createElement('button');
        btn.className = 'quick-reply-btn';
        btn.textContent = reply;
        btn.onclick = () => sendQuickReply(reply);
        container.appendChild(btn);
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// =============================================
// PAGE LOAD
// =============================================
document.addEventListener('DOMContentLoaded', function() {
    applyGridLayout();
    setTimeout(loadAIRecommendation, 800);
});
</script>
</body>
</html>

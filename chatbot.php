<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$userId = $_SESSION['user_id'];
$message = trim($_POST['message'] ?? '');

if (empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Empty message']);
    exit();
}

$msg = mb_strtolower($message, 'UTF-8');
$response = "";
$quickReplies = [];

// =============================================
// GET USER DATA
// =============================================
$stmt = $conn->prepare("SELECT username, points FROM users WHERE userId = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$userPoints = $user['points'] ?? 0;
$username = $user['username'] ?? 'User';

// =============================================
// ✅ GET ALL AVAILABLE ITEMS
// =============================================
$allItems = [];
$itemsResult = $conn->query("SELECT itemId, itemName, category, points, special_note FROM menu_items WHERE isAvailable = 1 ORDER BY points ASC");
while ($row = $itemsResult->fetch_assoc()) {
    $allItems[] = $row;
}

// =============================================
// ✅ HELPER: SIMILARITY (Myanmar Support)
// =============================================
function similarity($a, $b) {
    $a = mb_strtolower($a, 'UTF-8');
    $b = mb_strtolower($b, 'UTF-8');
    
    if (mb_strpos($a, $b) !== false || mb_strpos($b, $a) !== false) {
        return 100;
    }
    
    similar_text($a, $b, $percent);
    return $percent;
}

// =============================================
// ✅ HELPER: FORMAT ITEM DETAILS
// =============================================
function formatItemDetail($item, $userPoints) {
    $itemName = htmlspecialchars($item['itemName']);
    $itemPoints = number_format($item['points']);
    $category = htmlspecialchars($item['category']);
    $specialNote = !empty($item['special_note']) ? htmlspecialchars($item['special_note']) : '';
    $canAfford = ($userPoints >= $item['points']);
    
    $output = "🍽️ <strong>{$itemName}</strong><br><br>";
    $output .= "📂 Category: <strong>{$category}</strong><br>";
    $output .= "💰 ဈေးနှုန်း: <strong>{$itemPoints} Points</strong><br>";
    
    if ($specialNote) {
        $output .= "📝 <strong>မှတ်ချက်:</strong> {$specialNote}<br>";
    }
    
    $output .= "<br>";
    if ($canAfford) {
        $output .= "✅ <strong>သင့်မှာ ဝယ်လို့ရပါတယ်!</strong><br>";
        $output .= "💰 သင့် Point: " . number_format($userPoints) . "<br>";
        $output .= "💵 ကျန်မယ့် Point: <strong>" . number_format($userPoints - $item['points']) . "</strong>";
    } else {
        $output .= "❌ <strong>Point မလုံလောက်ပါ။</strong><br>";
        $output .= "💰 သင့် Point: " . number_format($userPoints) . "<br>";
        $output .= "📉 လိုအပ်သေးတာ: <strong>" . number_format($item['points'] - $userPoints) . " Points</strong>";
    }
    
    return $output;
}

// =============================================
// ✅ 1. FIND ITEM IN MESSAGE (No Price Keyword Needed)
// =============================================
$matchedItem = null;
$partialMatches = [];

foreach ($allItems as $item) {
    $itemNameLower = mb_strtolower($item['itemName'], 'UTF-8');
    
    // ✅ Exact/Contains Match
    if (mb_strpos($msg, $itemNameLower) !== false) {
        $matchedItem = $item;
        break;
    }
    
    // ✅ Partial Match (50%+ similarity)
    $sim = similarity($msg, $itemNameLower);
    if ($sim >= 50) {
        $partialMatches[] = ['item' => $item, 'score' => $sim];
    }
}

// =============================================
// ✅ 2. IF EXACT ITEM FOUND - SHOW DIRECTLY
// =============================================
if ($matchedItem) {
    $response = formatItemDetail($matchedItem, $userPoints);
    $quickReplies = ["မှာယူမည်", "Menu ကြည့်မည်", "Help"];
}

// =============================================
// ✅ 3. IF PARTIAL MATCHES - SHOW SIMILAR
// =============================================
elseif (count($partialMatches) > 0) {
    usort($partialMatches, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    
    $topMatches = array_slice($partialMatches, 0, 5);
    
    $response = "🔍 <strong>သင် ရှာနေတာ:</strong><br>";
    $response .= "&nbsp;&nbsp;&nbsp;<em>'" . htmlspecialchars($message) . "'</em><br><br>";
    $response .= "💡 <strong>အနီးစပ်ဆုံး Item များ:</strong><br><br>";
    
    foreach ($topMatches as $match) {
        $item = $match['item'];
        $afford = ($userPoints >= $item['points']) ? '✅' : '❌';
        $response .= "{$afford} <strong>" . htmlspecialchars($item['itemName']) . "</strong><br>";
        $response .= "&nbsp;&nbsp;&nbsp;💰 " . number_format($item['points']) . " pts";
        $response .= " &nbsp; <span style='color: #64748B; font-size: 0.85em;'>[" . htmlspecialchars($item['category']) . "]</span><br><br>";
    }
    
    $quickReplies = ["Menu ကြည့်မည်", "Help"];
}

// =============================================
// 4. SHOW ALL MENU (FULL LIST)
// =============================================
elseif (preg_match('/menu|မီနူး|အစားအသောက်|ဘာတွေရှိလဲ|စာရင်း|list|အကုန်|show.*all|ပြပါ/i', $msg)) {
    $result = $conn->query("
        SELECT itemName, category, points 
        FROM menu_items 
        WHERE isAvailable = 1 
        ORDER BY category ASC, points DESC
    ");
    
    $grouped = [];
    while ($row = $result->fetch_assoc()) {
        $grouped[$row['category']][] = $row;
    }
    
    $response = "🍽️ <strong>ရရှိနိုင်တဲ့ Item အားလုံး:</strong><br><br>";
    
    $totalCount = 0;
    foreach ($grouped as $category => $items) {
        $response .= "📂 <strong>" . htmlspecialchars($category) . "</strong><br><br>";
        foreach ($items as $item) {
            $totalCount++;
            $afford = ($userPoints >= $item['points']) ? '✅' : '❌';
            $response .= "{$afford} <strong>" . htmlspecialchars($item['itemName']) . "</strong> — " . number_format($item['points']) . " pts<br>";
        }
        $response .= "<br>";
    }
    
    $response .= "💰 သင့် Point: <strong>" . number_format($userPoints) . "</strong><br>";
    $response .= "📊 စုစုပေါင်း: <strong>{$totalCount} မျိုး</strong><br><br>";
    $response .= "💡 ✅ = ဝယ်လို့ရ &nbsp; ❌ = Point မလုံလောက်";
    
    $quickReplies = ["Top Seller ဘာလဲ?", "ဈေးအချိုသာဆုံးက ဘာလဲ?", "ဒီနေ့ ဘာစားရမလဲ?"];
}

// =============================================
// 5. POINT မေးခွန်း
// =============================================
elseif (preg_match('/point|ပွိုင့်|ပိုင်|အမှတ်/i', $msg)) {
    $response = "💰 <strong>{$username}</strong> ရေ၊ သင့်မှာ <strong>" . number_format($userPoints) . " Points</strong> ရှိပါတယ်။";
    $quickReplies = ["Menu ကြည့်မည်", "Order မှာမည်"];
}

// =============================================
// 6. TOP SELLER
// =============================================
elseif (preg_match('/top seller|top item|အရောင်းရဆုံး|ရောင်းရဆုံး|best seller|အကောင်းဆုံး/i', $msg)) {
    $result = $conn->query("
        SELECT m.itemName, m.points, SUM(oi.quantity) as total_sold
        FROM menu_items m
        JOIN order_items oi ON m.itemId = oi.itemId
        WHERE m.isAvailable = 1
        GROUP BY m.itemId
        ORDER BY total_sold DESC
        LIMIT 3
    ");
    
    $items = [];
    $rank = 1;
    $medals = ['🥇', '🥈', '🥉'];
    
    while ($row = $result->fetch_assoc()) {
        $items[] = $medals[$rank-1] . " <strong>" . htmlspecialchars($row['itemName']) . "</strong><br>&nbsp;&nbsp;&nbsp;🔥 " . $row['total_sold'] . " ခု ရောင်းရတယ်<br>&nbsp;&nbsp;&nbsp;💰 " . number_format($row['points']) . " pts";
        $rank++;
    }
    
    if (count($items) > 0) {
        $response = "🏆 <strong>Top 3 Seller:</strong><br><br>" . implode("<br><br>", $items);
    } else {
        $response = "အရောင်းရဆုံး ပစ္စည်း မရှိသေးပါ။";
    }
    $quickReplies = ["ဈေးအချိုသာဆုံးက ဘာလဲ?", "Menu ကြည့်မည်", "ဒီနေ့ ဘာစားရမလဲ?"];
}

// =============================================
// 7. ဈေးအသက်သာဆုံး
// =============================================
elseif (preg_match('/ဈေးအချိုသာဆုံး|အသက်သာဆုံး|cheapest|ဈေးနည်း|အသက်သာ/i', $msg)) {
    $result = $conn->query("SELECT itemName, points FROM menu_items WHERE isAvailable = 1 ORDER BY points ASC LIMIT 1");
    $cheapest = $result->fetch_assoc();
    
    if ($cheapest) {
        $response = "💸 ဈေးအသက်သာဆုံးက <strong>'" . htmlspecialchars($cheapest['itemName']) . "'</strong><br>💰 " . number_format($cheapest['points']) . " Points ပါ။";
    } else {
        $response = "လက်ရှိ Menu မရှိသေးပါ။";
    }
    $quickReplies = ["ဈေးအကြီးဆုံး ဘာလဲ?", "Menu ကြည့်မည်"];
}

// =============================================
// 8. ဈေးအကြီးဆုံး
// =============================================
elseif (preg_match('/ဈေးအကြီးဆုံး|ဈေးကြီး|expensive|အများဆုံး/i', $msg)) {
    $result = $conn->query("SELECT itemName, points FROM menu_items WHERE isAvailable = 1 ORDER BY points DESC LIMIT 1");
    $expensive = $result->fetch_assoc();
    
    if ($expensive) {
        $response = "💎 ဈေးအကြီးဆုံးက <strong>'" . htmlspecialchars($expensive['itemName']) . "'</strong><br>💰 " . number_format($expensive['points']) . " Points ပါ။";
    }
    $quickReplies = ["Menu ကြည့်မည်", "ဈေးအချိုသာဆုံးက ဘာလဲ?"];
}

// =============================================
// 9. ORDER မှာနည်း
// =============================================
elseif (preg_match('/order.*မှာ|မှာယူ|မှာနည်း|ဘယ်လိုမှာ|how.*order/i', $msg)) {
    $response = "📝 Order မှာဖို့ <strong>၃ ဆင့်</strong> ရှိပါတယ်:<br><br>1️⃣ Menu ကနေ ပစ္စည်းရွေးပါ<br>2️⃣ 'မှာယူမည်' ကိုနှိပ်ပါ<br>3️⃣ Cart ထဲဝင်ပြီး Checkout လုပ်ပါ<br><br>ဒါဆို Queue Number ရပါပြီ! 🎫";
    $quickReplies = ["Cart ကြည့်မည်", "Order အခြေအနေ", "Help"];
}

// =============================================
// 10. CART
// =============================================
elseif (preg_match('/cart|ဈေးတောင်း|ခြင်းတောင်း/i', $msg)) {
    $stmt = $conn->prepare("
        SELECT m.itemName, c.quantity, m.points 
        FROM cart c 
        JOIN menu_items m ON c.itemId = m.itemId 
        WHERE c.userId = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $items[] = "• " . htmlspecialchars($row['itemName']) . " × " . $row['quantity'];
        $total += $row['points'] * $row['quantity'];
    }
    $stmt->close();
    
    if (count($items) > 0) {
        $response = "🛒 သင့် Cart ထဲမှာ:<br>" . implode("<br>", $items) . "<br><br>💰 စုစုပေါင်း: <strong>" . number_format($total) . " Points</strong>";
    } else {
        $response = "🛒 သင့် Cart ထဲမှာ ဘာမှ မရှိသေးပါ။<br>Menu ကနေ ပစ္စည်းရွေးပြီး ထည့်ပါ!";
    }
    $quickReplies = ["Menu ကြည့်မည်", "Order မှာမည်"];
}

// =============================================
// 11. ဒီနေ့ ဘာစားရမလဲ
// =============================================
elseif (preg_match('/ဒီနေ့.*ဘာစား|ဘာစားရမလဲ|suggest|အကြံပြု/i', $msg)) {
    $result = $conn->query("
        SELECT m.itemName, m.points, SUM(oi.quantity) as total_sold
        FROM menu_items m
        JOIN order_items oi ON m.itemId = oi.itemId
        WHERE m.isAvailable = 1 AND m.points <= $userPoints
        GROUP BY m.itemId
        ORDER BY total_sold DESC
        LIMIT 1
    ");
    $top = $result->fetch_assoc();
    
    if ($top) {
        $response = "🤖 AI အကြံပြုချက်:<br><br>ဒီနေ့ <strong>'" . htmlspecialchars($top['itemName']) . "'</strong> စမ်းကြည့်လား?<br>🔥 <strong>" . $top['total_sold'] . " ခု</strong> ရောင်းရဆုံးပါ။<br>💰 " . number_format($top['points']) . " Points<br><br>✅ သင့် Point နဲ့ ကိုက်ညီပါတယ်။";
    } else {
        $response = "😔 သင့် Point နဲ့ ကိုက်ညီတဲ့ Item မရှိသေးပါ။<br>💡 Point ဖြည့်ပြီး ပြန်စမ်းပါ။";
    }
    $quickReplies = ["Menu ကြည့်မည်", "ဈေးအချိုသာဆုံးက ဘာလဲ?"];
}

// =============================================
// 12. CATEGORY: Drink
// =============================================
elseif (preg_match('/drink|အချိုရည်|ဖျော်ရည်/i', $msg)) {
    $result = $conn->query("SELECT itemName, points FROM menu_items WHERE isAvailable = 1 AND category = 'Drink' ORDER BY points DESC");
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $afford = ($userPoints >= $row['points']) ? '✅' : '❌';
        $items[] = "{$afford} <strong>" . htmlspecialchars($row['itemName']) . "</strong> — " . number_format($row['points']) . " pts";
    }
    
    if (count($items) > 0) {
        $response = "🥤 <strong>Drinks စာရင်း:</strong><br><br>" . implode("<br>", $items);
    } else {
        $response = "Drinks မရှိသေးပါ။";
    }
    $quickReplies = ["Food ဘာတွေရှိလဲ?", "Menu ကြည့်မည်"];
}

// =============================================
// 13. CATEGORY: Food
// =============================================
elseif (preg_match('/food|အစားအသောက်|ဟင်း|ထမင်း/i', $msg)) {
    $result = $conn->query("SELECT itemName, points FROM menu_items WHERE isAvailable = 1 AND category = 'Food' ORDER BY points DESC");
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $afford = ($userPoints >= $row['points']) ? '✅' : '❌';
        $items[] = "{$afford} <strong>" . htmlspecialchars($row['itemName']) . "</strong> — " . number_format($row['points']) . " pts";
    }
    
    if (count($items) > 0) {
        $response = "🍚 <strong>Food စာရင်း:</strong><br><br>" . implode("<br>", $items);
    } else {
        $response = "Food မရှိသေးပါ။";
    }
    $quickReplies = ["Drinks ဘာတွေရှိလဲ?", "Menu ကြည့်မည်"];
}

// =============================================
// 14. CATEGORY: Snack
// =============================================
elseif (preg_match('/snack|မုန့်|အဆာပြေ/i', $msg)) {
    $result = $conn->query("SELECT itemName, points FROM menu_items WHERE isAvailable = 1 AND category = 'Snack' ORDER BY points DESC");
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $afford = ($userPoints >= $row['points']) ? '✅' : '❌';
        $items[] = "{$afford} <strong>" . htmlspecialchars($row['itemName']) . "</strong> — " . number_format($row['points']) . " pts";
    }
    
    if (count($items) > 0) {
        $response = "🍪 <strong>Snack စာရင်း:</strong><br><br>" . implode("<br>", $items);
    } else {
        $response = "Snack မရှိသေးပါ။";
    }
    $quickReplies = ["Food ဘာတွေရှိလဲ?", "Menu ကြည့်မည်"];
}

// =============================================
// 15. ORDER အရေအတွက်
// =============================================
elseif (preg_match('/order.*ဘယ်နှ|my order|order အရေအတွက်/i', $msg)) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM orders WHERE userId = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM orders WHERE userId = ? AND status = 'completed'");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $completed = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    
    $response = "📋 သင့်မှာ Order <strong>" . $total . " ခု</strong> ရှိပါတယ်။<br>✅ Complete ဖြစ်ပြီး: <strong>" . $completed . " ခု</strong>";
    $quickReplies = ["Order ကြည့်မည်", "Order အခြေအနေ"];
}

// =============================================
// 16. CANCEL
// =============================================
elseif (preg_match('/cancel|ဖျက်|ပယ်ဖျက်|မလုပ်တော့/i', $msg)) {
    $response = "❌ Order ကို Cancel လုပ်ဖို့:<br><br>1️⃣ History စာမျက်နှာ သွားပါ<br>2️⃣ Order ကို ရှာပါ<br>3️⃣ 'Cancel Order' ကို နှိပ်ပါ<br><br>⚠️ <strong>Admin က 'Cooking' မပြောင်းမချင်း</strong> ပဲ Cancel လုပ်လို့ရပါတယ်။ Points ပြန်ရပါမယ်။";
    $quickReplies = ["Order ကြည့်မည်", "Help"];
}

// =============================================
// 17. RATING
// =============================================
elseif (preg_match('/rating|rate|အဆင့်သတ်|ကြယ်|star/i', $msg)) {
    $response = "⭐ Rating ပေးဖို့:<br><br>1️⃣ History စာမျက်နှာ သွားပါ<br>2️⃣ Complete ဖြစ်တဲ့ Order ကို ရှာပါ<br>3️⃣ ⭐ ၁ ကနေ ၅ ထိ နှိပ်ပါ<br>4️⃣ Confirm လုပ်ပါ<br><br>⚠️ <strong>တစ်ခါပဲ ပေးလို့ရပါတယ်</strong>၊ ပြန်ပြင်လို့မရပါ။";
    $quickReplies = ["Order ကြည့်မည်", "Help"];
}

// =============================================
// 18. ACCOUNT
// =============================================
elseif (preg_match('/အကောင့်|account|profile|အချက်အလက်|ငါ့အကြောင်း/i', $msg)) {
    $stmt = $conn->prepare("SELECT username, phoneNumber, points, createdAt FROM users WHERE userId = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $userAcc = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $response = "👤 <strong>သင့်အကောင့် အချက်အလက်:</strong><br><br>📛 အမည်: <strong>" . htmlspecialchars($userAcc['username']) . "</strong><br>📱 ဖုန်း: " . htmlspecialchars($userAcc['phoneNumber']) . "<br>💰 Points: <strong>" . number_format($userAcc['points']) . "</strong><br>📅 မှတ်ပုံတင်ရက်: " . date('d M Y', strtotime($userAcc['createdAt']));
    $quickReplies = ["Point ဘယ်လောက်ရှိလဲ?", "Order အရေအတွက်"];
}

// =============================================
// 19. GREETING
// =============================================
elseif (preg_match('/^(hi|hello|hey|မင်္ဂလာပါ|ဟယ်လို|ဟိုင်း)/i', $msg)) {
    $response = "👋 မင်္ဂလာပါ <strong>" . htmlspecialchars($username) . "</strong>!<br><br>ကျွန်တော် <strong>UCSH Canteen AI</strong> ပါ။<br>ဘာကူညီပေးရမလဲ? 🤖";
    $quickReplies = ["Point ဘယ်လောက်ရှိလဲ?", "Menu ကြည့်မည်", "Order မှာမည်", "Help"];
}

// =============================================
// 20. THANK YOU
// =============================================
elseif (preg_match('/thank|ကျေးဇူး|thanks|thx/i', $msg)) {
    $response = "😊 ကျေးဇူးတင်ပါတယ်!<br>နောက်ထပ် ကူညီဖို့ လိုအပ်ရင် မေးလိုက်ပါ။ 🌟";
    $quickReplies = ["Menu ကြည့်မည်", "Bye"];
}

// =============================================
// 21. BYE
// =============================================
elseif (preg_match('/bye|goodbye|သွားတော့|သွားမည်/i', $msg)) {
    $response = "👋 သွားတော့မယ်ဆိုရင် ကျေးဇူးတင်ပါတယ်!<br>နောက်တစ်ခါ ပြန်လာပါ။ 🍽️<br><br>💚 <em>UCSH Canteen မှ ကြိုဆိုပါတယ်</em>";
    $quickReplies = ["Hello", "Menu ကြည့်မည်"];
}

// =============================================
// 22. HELP
// =============================================
elseif (preg_match('/help|အကူအညီ|ဘာလုပ်နိုင်/i', $msg)) {
    $response = "🤖 <strong>ကျွန်တော် ကူညီနိုင်တာများ:</strong><br><br>💰 Point စစ်ဆေးခြင်း<br>🍽️ Menu အားလုံး ကြည့်ခြင်း<br>💸 Item တစ်ခုချင်း ဈေးမေးခြင်း<br>📝 Order မှာခြင်း<br>🛒 Cart ကြည့်ခြင်း<br>📋 Order အခြေအနေ<br>🏆 Top Seller ကြည့်ခြင်း<br>❌ Order Cancel<br>⭐ Rating ပေးခြင်း<br><br>💡 <strong>ဥပမာ မေးခွန်းများ:</strong><br>• မာလာရှမ်းကော<br>• Thai Green Tea<br>• Menu ကြည့်မည်";
    $quickReplies = ["Point ဘယ်လောက်ရှိလဲ?", "Menu ကြည့်မည်", "မာလာရှမ်းကော"];
}

// =============================================
// DEFAULT
// =============================================
else {
    $response = "🤔 တောင်းပန်ပါတယ်၊ နားမလည်လိုက်ပါ။<br><br>ဒါတွေ မေးကြည့်ပါ:<br>• Menu ကြည့်မည်<br>• Point ဘယ်လောက်ရှိလဲ?<br>• မာလာရှမ်းကော<br>• Thai Green Tea<br>• Top Seller ဘာလဲ?<br>• Help";
    $quickReplies = ["Menu ကြည့်မည်", "Point ဘယ်လောက်ရှိလဲ?", "Help"];
}

echo json_encode([
    'success' => true,
    'response' => $response,
    'quickReplies' => $quickReplies
]);
?>

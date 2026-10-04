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
// 1. POINT မေးခွန်း
// =============================================
if (preg_match('/point|ပွိုင့်|ပိုင်|အမှတ်/i', $msg)) {
    $stmt = $conn->prepare("SELECT points FROM users WHERE userId = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $points = $user['points'] ?? 0;
    
    $response = "💰 သင့်မှာ <strong>" . number_format($points) . " Points</strong> ရှိပါတယ်။";
    $quickReplies = ["Menu ကြည့်မည်", "ဈေးဆုံး ဘာလဲ?", "Order မှာမည်"];
}

// =============================================
// 2. TOP SELLER မေးခွန်း
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
        $items[] = $medals[$rank-1] . " <strong>" . htmlspecialchars($row['itemName']) . "</strong><br>&nbsp;&nbsp;&nbsp;🔥 " . $row['total_sold'] . " ခု ရောင်းရတယ်";
        $rank++;
    }
    
    if (count($items) > 0) {
        $response = "🏆 <strong>Top 3 Seller:</strong><br><br>" . implode("<br><br>", $items);
    } else {
        $response = "အရောင်းရဆုံး ပစ္စည်း မရှိသေးပါ။";
    }
    $quickReplies = ["ဈေးအနည်းဆုံး ဘာလဲ?", "Menu ကြည့်မည်", "ဒီနေ့ ဘာစားရမလဲ?"];
}

// =============================================
// 3. MENU မေးခွန်း
// =============================================
elseif (preg_match('/menu|မီနူး|အစားအသောက်|ဘာတွေရှိလဲ|စာရင်း/i', $msg)) {
    $result = $conn->query("SELECT COUNT(*) as total FROM menu_items WHERE isAvailable = 1");
    $total = $result->fetch_assoc()['total'];
    
    $response = "🍽️ ဒီနေ့ ရရှိနိုင်တဲ့ <strong>" . $total . " မျိုး</strong> ရှိပါတယ်။<br>Menu စာမျက်နှာမှာ အားလုံး ကြည့်လို့ရပါတယ်။";
    $quickReplies = ["ဈေးအချိုသာဆုံး ဘာလဲ?", "Top Seller ဘာလဲ?", "Drinks ဘာတွေရှိလဲ?"];
}

// =============================================
// 4. ဈေးဆုံး မေးခွန်း
// =============================================
elseif (preg_match('/ဈေးအချိုသာဆုံး|အသက်သာဆုံး|cheapest|ဈေးနည်း/i', $msg)) {
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
// 5. ဈေးအကြီးဆုံး မေးခွန်း
// =============================================
elseif (preg_match('/ဈေးအကြီးဆုံး|ဈေးကြီး|expensive|အများဆုံး/i', $msg)) {
    $result = $conn->query("SELECT itemName, points FROM menu_items WHERE isAvailable = 1 ORDER BY points DESC LIMIT 1");
    $expensive = $result->fetch_assoc();
    
    if ($expensive) {
        $response = "💎 ဈေးအကြီးဆုံးက <strong>'" . htmlspecialchars($expensive['itemName']) . "'</strong><br>💰 " . number_format($expensive['points']) . " Points ပါ။";
    }
    $quickReplies = ["Menu ကြည့်မည်", "ဈေးဆုံး ဘာလဲ?"];
}

// =============================================
// 6. ORDER မှာနည်း
// =============================================
elseif (preg_match('/order|မှာယူ|မှာနည်း|ဘယ်လိုမှာ/i', $msg)) {
    $response = "📝 Order မှာဖို့ <strong>၃ ဆင့်</strong> ရှိပါတယ်:<br><br>1️⃣ Menu ကနေ ပစ္စည်းရွေးပါ<br>2️⃣ 'မှာယူမည်' ကိုနှိပ်ပါ<br>3️⃣ Cart ထဲဝင်ပြီး Checkout လုပ်ပါ<br><br>ဒါဆို Queue Number ရပါပြီ! 🎫";
    $quickReplies = ["Cart ကြည့်မည်", "Order အခြေအနေ", "Help"];
}

// =============================================
// 7. CART မေးခွန်း
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
// 8. ORDER အရေအတွက်
// =============================================
elseif (preg_match('/order.*ဘယ်နှ|ဘယ်နှ.*order|my order|order အရေအတွက်/i', $msg)) {
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
// 9. ဒီနေ့ ဘာစားရမလဲ (AI Suggestion)
// =============================================
elseif (preg_match('/ဒီနေ့.*ဘာစား|ဘာစားရမလဲ|suggest|အကြံပြု/i', $msg)) {
    $result = $conn->query("
        SELECT m.itemName, m.points, SUM(oi.quantity) as total_sold
        FROM menu_items m
        JOIN order_items oi ON m.itemId = oi.itemId
        WHERE m.isAvailable = 1
        GROUP BY m.itemId
        ORDER BY total_sold DESC
        LIMIT 1
    ");
    $top = $result->fetch_assoc();
    
    if ($top) {
        $response = "🤖 AI အကြံပြုချက်:<br><br>ဒီနေ့ <strong>'" . htmlspecialchars($top['itemName']) . "'</strong> စမ်းကြည့်လား?<br>🔥 <strong>" . $top['total_sold'] . " ခု</strong> ရောင်းရဆုံးပါ။<br>💰 " . number_format($top['points']) . " Points";
    } else {
        $response = "Menu ထဲက ပစ္စည်းတွေ ကြည့်ပြီး ရွေးလိုက်ပါ! 🍽️";
    }
    $quickReplies = ["ဈေးဆုံး ဘာလဲ?", "Menu ကြည့်မည်", "Drinks ဘာတွေရှိလဲ?"];
}

// =============================================
// 10. အကောင့် အချက်အလက်
// =============================================
elseif (preg_match('/အကောင့်|account|profile|အချက်အလက်|ငါ့အကြောင်း/i', $msg)) {
    $stmt = $conn->prepare("SELECT username, phoneNumber, points, createdAt FROM users WHERE userId = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $response = "👤 <strong>သင့်အကောင့် အချက်အလက်:</strong><br><br>📛 အမည်: <strong>" . htmlspecialchars($user['username']) . "</strong><br>📱 ဖုန်း: " . htmlspecialchars($user['phoneNumber']) . "<br>💰 Points: <strong>" . number_format($user['points']) . "</strong><br>📅 မှတ်ပုံတင်ရက်: " . date('d M Y', strtotime($user['createdAt']));
    $quickReplies = ["Point ဘယ်လောက်ရှိလဲ?", "Order အရေအတွက်"];
}

// =============================================
// 11. CANCEL လုပ်နည်း
// =============================================
elseif (preg_match('/cancel|ဖျက်|ပယ်ဖျက်|မလုပ်တော့/i', $msg)) {
    $response = "❌ Order ကို Cancel လုပ်ဖို့:<br><br>1️⃣ History စာမျက်နှာ သွားပါ<br>2️⃣ Order ကို ရှာပါ<br>3️⃣ 'Cancel Order' ကိုနှိပ်ပါ<br><br>⚠️ <strong>Admin က 'Cooking' မပြောင်းမချင်း</strong> ပဲ Cancel လုပ်လို့ရပါတယ်။ Points ပြန်ရပါမယ်။";
    $quickReplies = ["Order ကြည့်မည်", "Help"];
}

// =============================================
// 12. RATING ပေးနည်း
// =============================================
elseif (preg_match('/rating|rate|အဆင့်သတ်|ကြယ်|star/i', $msg)) {
    $response = "⭐ Rating ပေးဖို့:<br><br>1️⃣ History စာမျက်နှာ သွားပါ<br>2️⃣ Complete ဖြစ်တဲ့ Order ကို ရှာပါ<br>3️⃣ ⭐ ၁ ကနေ ၅ ထိ နှိပ်ပါ<br>4️⃣ Confirm လုပ်ပါ<br><br>⚠️ <strong>တစ်ခါပဲ ပေးလို့ရပါတယ်</strong>၊ ပြန်ပြင်လို့မရပါ။";
    $quickReplies = ["Order ကြည့်မည်", "Help"];
}

// =============================================
// 13. GREETING
// =============================================
elseif (preg_match('/^(hi|hello|hey|မင်္ဂလာပါ|ဟယ်လို|ဟိုင်း)/i', $msg)) {
    $stmt = $conn->prepare("SELECT username FROM users WHERE userId = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $username = $stmt->get_result()->fetch_assoc()['username'] ?? 'User';
    $stmt->close();
    
    $response = "👋 မင်္ဂလာပါ <strong>" . htmlspecialchars($username) . "</strong>!<br><br>ကျွန်တော် <strong>UCSH Canteen AI</strong> ပါ။<br>ဘာကူညီပေးရမလဲ? 🤖";
    $quickReplies = ["Point ဘယ်လောက်ရှိလဲ?", "Menu ကြည့်မည်", "Order မှာမည်", "Help"];
}

// =============================================
// 14. THANK YOU
// =============================================
elseif (preg_match('/thank|ကျေးဇူး|thanks|thx/i', $msg)) {
    $response = "😊 ကျေးဇူးတင်ပါတယ်!<br>နောက်ထပ် ကူညီဖို့ လိုအပ်ရင် မေးလိုက်ပါ။ 🌟";
    $quickReplies = ["Menu ကြည့်မည်", "Bye"];
}

// =============================================
// 15. BYE
// =============================================
elseif (preg_match('/bye|goodbye|သွားတော့|သွားမည်/i', $msg)) {
    $response = "👋 သွားတော့မယ်ဆိုရင် ကျေးဇူးတင်ပါတယ်!<br>နောက်တစ်ခါ ပြန်လာပါ။ 🍽️<br><br>💚 <em>UCSH Canteen မှ ကြိုဆိုပါတယ်</em>";
    $quickReplies = ["Hello", "Menu ကြည့်မည်"];
}

// =============================================
// 16. HELP
// =============================================
elseif (preg_match('/help|အကူအညီ|ဘာလုပ်နိုင်/i', $msg)) {
    $response = "🤖 <strong>ကျွန်တော် ကူညီနိုင်တာများ:</strong><br><br>💰 Point စစ်ဆေးခြင်း<br>🍽️ Menu ကြည့်ခြင်း<br>💸 ဈေးနှုန်း မေးခြင်း<br>📝 Order မှာခြင်း<br>🛒 Cart ကြည့်ခြင်း<br>📋 Order အခြေအနေ<br>🏆 Top Seller ကြည့်ခြင်း<br>🤖 AI အကြံပြုချက်<br>👤 အကောင့် အချက်အလက်<br>❌ Order Cancel<br>⭐ Rating ပေးခြင်း";
    $quickReplies = ["Point ဘယ်လောက်ရှိလဲ?", "Menu ကြည့်မည်", "Top Seller ဘာလဲ?"];
}

// =============================================
// 17. ABOUT
// =============================================
elseif (preg_match('/about|project|system|အကြောင်း|ဤစနစ်|ဒီစနစ်/i', $msg)) {
    $response = "🎓 <strong>UCSH Smart Canteen</strong><br><br>University of Computer Studies (Hpa-An) အတွက် ဖန်တီးထားတဲ့ <strong>Smart Canteen Ordering System</strong> ဖြစ်ပါတယ်။<br><br>🤖 <strong>AI Features:</strong><br>• Personalized Recommendations<br>• Smart Chatbot Assistant<br>• Sales Analytics<br><br>💻 <strong>Tech Stack:</strong><br>• PHP + MySQL<br>• Bootstrap 5<br>• Chart.js<br>• AI Logic";
    $quickReplies = ["Help", "Menu ကြည့်မည်"];
}

// =============================================
// DEFAULT
// =============================================
else {
    $response = "🤔 တောင်းပန်ပါတယ်၊ နားမလည်လိုက်ပါ။<br><br>ဒါတွေ မေးကြည့်ပါ:<br>• Point ဘယ်လောက်ရှိလဲ?<br>• Top Seller ဘာလဲ?<br>• Menu ကြည့်မည်<br>• Help";
    $quickReplies = ["Point ဘယ်လောက်ရှိလဲ?", "Top Seller ဘာလဲ?", "Help"];
}

echo json_encode([
    'success' => true,
    'response' => $response,
    'quickReplies' => $quickReplies
]);
?>
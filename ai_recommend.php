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

// =============================================
// 1. USER FAVORITE ITEM (Most Ordered)
// =============================================
$favStmt = $conn->prepare("
    SELECT m.itemId, m.itemName, m.points, m.image, m.category, m.special_note,
           COUNT(*) as order_count
    FROM orders o
    JOIN order_items oi ON o.orderId = oi.orderId
    JOIN menu_items m ON oi.itemId = m.itemId
    WHERE o.userId = ?
    GROUP BY m.itemId
    ORDER BY order_count DESC
    LIMIT 1
");
$favStmt->bind_param("i", $userId);
$favStmt->execute();
$favorite = $favStmt->get_result()->fetch_assoc();
$favStmt->close();

// =============================================
// 2. USER မမှာဖူးတဲ့ TOP SELLER 3 ခု
// =============================================
$suggestStmt = $conn->prepare("
    SELECT m.itemId, m.itemName, m.points, m.image, m.category, m.special_note,
           COALESCE(SUM(oi.quantity), 0) as total_sold,
           (SELECT AVG(rating) FROM ratings WHERE itemId = m.itemId) as avg_rating,
           (SELECT COUNT(*) FROM ratings WHERE itemId = m.itemId) as rating_count
    FROM menu_items m
    LEFT JOIN order_items oi ON m.itemId = oi.itemId
    WHERE m.isAvailable = 1 
    AND m.itemId NOT IN (
        SELECT DISTINCT oi2.itemId 
        FROM orders o2 
        JOIN order_items oi2 ON o2.orderId = oi2.orderId 
        WHERE o2.userId = ?
    )
    GROUP BY m.itemId
    ORDER BY total_sold DESC, avg_rating DESC
    LIMIT 3
");
$suggestStmt->bind_param("i", $userId);
$suggestStmt->execute();
$suggestions = [];
$result = $suggestStmt->get_result();
while ($row = $result->fetch_assoc()) {
    $suggestions[] = $row;
}
$suggestStmt->close();

// =============================================
// 3. TIME-BASED RECOMMENDATION
// =============================================
$hour = (int)date('H');
$timeGreeting = "";
$timeEmoji = "";

if ($hour >= 5 && $hour < 11) {
    $timeGreeting = "မနက်စာ အတွက်";
    $timeEmoji = "🌅";
} elseif ($hour >= 11 && $hour < 14) {
    $timeGreeting = "နေ့လယ်စာ အတွက်";
    $timeEmoji = "☀️";
} elseif ($hour >= 14 && $hour < 17) {
    $timeGreeting = "မွန်းလွဲပိုင်း အတွက်";
    $timeEmoji = "🌤️";
} elseif ($hour >= 17 && $hour < 21) {
    $timeGreeting = "ညစာ အတွက်";
    $timeEmoji = "🌙";
} else {
    $timeGreeting = "သွားရည်စာ အတွက်";
    $timeEmoji = "⭐";
}

// =============================================
// 4. BUILD AI MESSAGE
// =============================================
$aiMessage = "";
if ($favorite) {
    $aiMessage = "မိတ်ဆွေ '{$favorite['itemName']}' ကို မကြာခဏ မှာတယ်နော်။ ဒီနေ့တော့ အသစ်တစ်ခုခု စမ်းကြည့်လား?";
} else {
    $aiMessage = "မင်္ဂလာပါ! မိတ်ဆွေ အတွက် ဒီနေ့ အသစ်ဆုံး အစားအသောက်တွေ ရှာပေးထားတယ်။";
}

echo json_encode([
    'success' => true,
    'favorite' => $favorite,
    'suggestions' => $suggestions,
    'timeGreeting' => $timeGreeting,
    'timeEmoji' => $timeEmoji,
    'aiMessage' => $aiMessage
]);
?>
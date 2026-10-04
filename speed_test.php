<?php
// speed_test.php - Page Load Speed Diagnostic
$pageStart = microtime(true);

echo "<!DOCTYPE html><html><head><title>Speed Test</title>";
echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>";
echo "<style>body{padding:20px;font-family:monospace;} .good{color:green;font-weight:bold;} .warn{color:orange;font-weight:bold;} .bad{color:red;font-weight:bold;}</style>";
echo "</head><body><div class='container'>";
echo "<h2>🔍 Speed Diagnostic Report</h2><hr>";

// =============================================
// TEST 1: Database Connection
// =============================================
$t1 = microtime(true);
require_once 'db.php';
$dbTime = round((microtime(true) - $t1) * 1000, 2);

echo "<h4>1. Database Connection</h4>";
echo "<p>Time: <strong>{$dbTime} ms</strong> ";
if ($dbTime < 100) echo "<span class='good'>✅ Fast</span>";
elseif ($dbTime < 500) echo "<span class='warn'>🟡 Medium</span>";
else echo "<span class='bad'>❌ Slow</span>";
echo "</p>";

// =============================================
// TEST 2: Simple Query
// =============================================
$t2 = microtime(true);
$conn->query("SELECT 1");
$q1Time = round((microtime(true) - $t2) * 1000, 2);
echo "<p>Simple query: <strong>{$q1Time} ms</strong></p>";

// =============================================
// TEST 3: Orders Count
// =============================================
$t3 = microtime(true);
$result = $conn->query("SELECT COUNT(*) as cnt FROM orders");
$ordersCount = $result->fetch_assoc()['cnt'];
$q2Time = round((microtime(true) - $t3) * 1000, 2);
echo "<p>Orders count ({$ordersCount} rows): <strong>{$q2Time} ms</strong> ";
if ($q2Time < 100) echo "<span class='good'>✅</span>";
elseif ($q2Time < 500) echo "<span class='warn'>🟡</span>";
else echo "<span class='bad'>❌ Index လိုတယ်</span>";
echo "</p>";

// =============================================
// TEST 4: Complex Query (Recent Orders with JOINs)
// =============================================
$t4 = microtime(true);
$orders_query = "SELECT o.*, u.username, 
                (SELECT GROUP_CONCAT(CONCAT(m.itemId, ':', m.itemName, ' (', oi.quantity, ')') SEPARATOR '|') 
                 FROM order_items oi JOIN menu_items m ON oi.itemId = m.itemId WHERE oi.orderId = o.orderId) as items_with_id,
                (SELECT GROUP_CONCAT(CONCAT(m.itemName, ' (', oi.quantity, ')') SEPARATOR ', ') 
                 FROM order_items oi JOIN menu_items m ON oi.itemId = m.itemId WHERE oi.orderId = o.orderId) as items 
                FROM orders o 
                LEFT JOIN users u ON o.userId = u.userId 
                ORDER BY o.orderId DESC LIMIT 20";
$conn->query($orders_query);
$q3Time = round((microtime(true) - $t4) * 1000, 2);
echo "<p>Recent orders (complex): <strong>{$q3Time} ms</strong> ";
if ($q3Time < 200) echo "<span class='good'>✅</span>";
elseif ($q3Time < 1000) echo "<span class='warn'>🟡 Index လိုနိုင်</span>";
else echo "<span class='bad'>❌ အရမ်းနှေး — Index မဖြစ်မနေ လိုတယ်</span>";
echo "</p>";

// =============================================
// TEST 5: Menu Query
// =============================================
$t5 = microtime(true);
$menu_query = "SELECT m.*, 
               (SELECT AVG(rating) FROM ratings WHERE itemId = m.itemId) as avg_rating,
               (SELECT COUNT(*) FROM ratings WHERE itemId = m.itemId) as rating_count
               FROM menu_items m 
               WHERE m.isAvailable = 1 
               ORDER BY m.itemId DESC";
$conn->query($menu_query);
$q4Time = round((microtime(true) - $t5) * 1000, 2);
echo "<p>Menu items (with rating subqueries): <strong>{$q4Time} ms</strong> ";
if ($q4Time < 200) echo "<span class='good'>✅</span>";
elseif ($q4Time < 1000) echo "<span class='warn'>🟡</span>";
else echo "<span class='bad'>❌ Index လိုတယ်</span>";
echo "</p>";

// =============================================
// TEST 6: Table Sizes
// =============================================
echo "<h4 class='mt-4'>2. Table Sizes</h4>";
$tables = ['orders', 'order_items', 'users', 'menu_items', 'cart', 'ratings', 'liked_items'];
echo "<table class='table table-sm'><thead><tr><th>Table</th><th>Rows</th><th>Size</th></tr></thead><tbody>";
foreach ($tables as $table) {
    $r = $conn->query("SELECT COUNT(*) as cnt FROM $table");
    $cnt = $r ? $r->fetch_assoc()['cnt'] : 0;
    $s = $conn->query("SELECT ROUND(((data_length + index_length) / 1024), 2) AS size FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = '$table'");
    $size = $s ? ($s->fetch_assoc()['size'] ?? 0) : 0;
    echo "<tr><td>{$table}</td><td>{$cnt}</td><td>{$size} KB</td></tr>";
}
echo "</tbody></table>";

// =============================================
// TEST 7: Check Indexes
// =============================================
echo "<h4 class='mt-4'>3. Indexes Check</h4>";
$indexCheck = $conn->query("SHOW INDEX FROM orders");
$orderIndexes = [];
while ($row = $indexCheck->fetch_assoc()) {
    $orderIndexes[] = $row['Column_name'];
}
echo "<p><strong>orders</strong> table indexes: " . implode(', ', array_unique($orderIndexes)) . "</p>";

$indexCheck2 = $conn->query("SHOW INDEX FROM order_items");
$oiIndexes = [];
while ($row = $indexCheck2->fetch_assoc()) {
    $oiIndexes[] = $row['Column_name'];
}
echo "<p><strong>order_items</strong> table indexes: " . implode(', ', array_unique($oiIndexes)) . "</p>";

// =============================================
// TEST 8: PHP Info
// =============================================
echo "<h4 class='mt-4'>4. PHP Info</h4>";
echo "<p>PHP Version: <strong>" . phpversion() . "</strong></p>";
echo "<p>OPcache: ";
if (function_exists('opcache_get_status')) {
    $opcache = opcache_get_status();
    if ($opcache && $opcache['opcache_enabled']) {
        echo "<span class='good'>✅ Enabled</span>";
    } else {
        echo "<span class='warn'>🟡 Disabled — Enable လုပ်ပါ</span>";
    }
} else {
    echo "<span class='warn'>🟡 Not installed</span>";
}
echo "</p>";

echo "<p>Memory Limit: <strong>" . ini_get('memory_limit') . "</strong></p>";

// =============================================
// TOTAL
// =============================================
$totalTime = round((microtime(true) - $pageStart) * 1000, 2);
echo "<hr><h3>Total Server Time: <strong>{$totalTime} ms</strong></h3>";

echo "</div></body></html>";
?>
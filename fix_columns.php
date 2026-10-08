<?php
/**
 * UCSH Canteen - Fix missing columns
 * Run once: https://ucsh-canteen-production-ff5e.up.railway.app/fix_columns.php
 * ပြီးရင် DELETE လုပ်ပါ
 */

require_once 'db.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Fix Columns Migration</title>
    <style>
        body { font-family: 'Courier New', monospace; background: #0d1b2a; color: #4ADE80; padding: 30px; line-height: 1.6; }
        .success { color: #4ADE80; }
        .error { color: #F87171; }
        .skip { color: #FBBF24; }
        .info { color: #60A5FA; }
        h1 { color: #FFFFFF; border-bottom: 2px solid #4ADE80; padding-bottom: 10px; }
        .warning { background: #7F1D1D; color: #FCA5A5; padding: 15px; border-radius: 8px; margin-top: 20px; }
    </style>
</head>
<body>
<h1>🔧 UCSH Canteen — Fix Columns Migration</h1>
<pre>
<?php
echo "=== Fixing missing columns ===\n\n";

// =============================================
// 1. cart.selected_options
// =============================================
echo "📋 Checking cart table...\n";
$check1 = $conn->query("SHOW COLUMNS FROM cart LIKE 'selected_options'");
if ($check1 && $check1->num_rows === 0) {
    if ($conn->query("ALTER TABLE cart ADD COLUMN selected_options TEXT DEFAULT NULL")) {
        echo "<span class='success'>✅ cart.selected_options — ADDED</span>\n";
    } else {
        echo "<span class='error'>❌ cart — ERROR: " . htmlspecialchars($conn->error) . "</span>\n";
    }
} else {
    echo "<span class='skip'>⏭️  cart.selected_options — Already exists</span>\n";
}

// =============================================
// 2. order_items.selected_options
// =============================================
echo "\n📋 Checking order_items table...\n";
$check2 = $conn->query("SHOW COLUMNS FROM order_items LIKE 'selected_options'");
if ($check2 && $check2->num_rows === 0) {
    if ($conn->query("ALTER TABLE order_items ADD COLUMN selected_options TEXT DEFAULT NULL")) {
        echo "<span class='success'>✅ order_items.selected_options — ADDED</span>\n";
    } else {
        echo "<span class='error'>❌ order_items — ERROR: " . htmlspecialchars($conn->error) . "</span>\n";
    }
} else {
    echo "<span class='skip'>⏭️  order_items.selected_options — Already exists</span>\n";
}

// =============================================
// 3. Verify tables (option groups/options)
// =============================================
echo "\n📋 Verifying option tables...\n";
$tables = ['menu_option_groups', 'menu_options'];
foreach ($tables as $t) {
    $check = $conn->query("SHOW TABLES LIKE '$t'");
    if ($check && $check->num_rows > 0) {
        $cnt = $conn->query("SELECT COUNT(*) as c FROM $t")->fetch_assoc()['c'];
        echo "<span class='success'>✅ $t — exists ($cnt rows)</span>\n";
    } else {
        echo "<span class='error'>❌ $t — MISSING! Run migrate_options.php first</span>\n";
    }
}

// =============================================
// 4. Final verification
// =============================================
echo "\n=== Final Verification ===\n";
$final1 = $conn->query("SHOW COLUMNS FROM cart LIKE 'selected_options'");
echo "cart.selected_options: " . ($final1->num_rows > 0 ? "<span class='success'>✅ OK</span>" : "<span class='error'>❌ MISSING</span>") . "\n";

$final2 = $conn->query("SHOW COLUMNS FROM order_items LIKE 'selected_options'");
echo "order_items.selected_options: " . ($final2->num_rows > 0 ? "<span class='success'>✅ OK</span>" : "<span class='error'>❌ MISSING</span>") . "\n";

echo "\n<span class='info'>=== DONE ===</span>\n";
?>
</pre>

<div class="warning">
    ⚠️ <strong>DELETE this file after successful run!</strong><br>
    GitHub → fix_columns.php → Delete → Commit
</div>

</body>
</html>

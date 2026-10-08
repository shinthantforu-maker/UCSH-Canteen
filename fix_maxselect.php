<?php
require_once 'db.php';
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Fix maxSelect</title>
    <style>
        body { font-family: 'Courier New', monospace; background: #0d1b2a; color: #4ADE80; padding: 30px; line-height: 1.6; }
        .success { color: #4ADE80; }
        .error { color: #F87171; }
        .skip { color: #FBBF24; }
        .info { color: #60A5FA; }
        h1 { color: #FFFFFF; border-bottom: 2px solid #4ADE80; padding-bottom: 10px; }
        .warning { background: #7F1D1D; color: #FCA5A5; padding: 15px; border-radius: 8px; margin-top: 20px; }
        table { border-collapse: collapse; margin-top: 15px; width: 100%; }
        th, td { border: 1px solid #4ADE80; padding: 6px 10px; text-align: left; }
        th { background: #064e3b; color: white; }
    </style>
</head>
<body>
<h1>🔧 Fix maxSelect Column</h1>
<pre>
<?php
echo "=== Adding maxSelect to menu_option_groups ===\n\n";

// 1. Check if column exists
$check = $conn->query("SHOW COLUMNS FROM menu_option_groups LIKE 'maxSelect'");

if ($check && $check->num_rows === 0) {
    // Column မရှိ → ထည့်
    if ($conn->query("ALTER TABLE menu_option_groups ADD COLUMN maxSelect TINYINT DEFAULT 1")) {
        echo "<span class='success'>✅ maxSelect column ADDED</span>\n";
    } else {
        echo "<span class='error'>❌ Error: " . htmlspecialchars($conn->error) . "</span>\n";
    }
} else {
    echo "<span class='skip'>⏭️  maxSelect column already exists</span>\n";
}

// 2. Set default = 1 for all existing rows
echo "\n📋 Setting maxSelect = 1 for existing groups...\n";
if ($conn->query("UPDATE menu_option_groups SET maxSelect = 1 WHERE maxSelect IS NULL OR maxSelect = 0")) {
    $affected = $conn->affected_rows;
    echo "<span class='success'>✅ Updated $affected rows</span>\n";
} else {
    echo "<span class='error'>❌ Error: " . htmlspecialchars($conn->error) . "</span>\n";
}

// 3. Show current data
echo "\n📋 Current option groups:\n";
$result = $conn->query("SELECT * FROM menu_option_groups ORDER BY groupId");
if ($result && $result->num_rows > 0) {
    echo "<table>";
    echo "<tr><th>groupId</th><th>itemId</th><th>groupName</th><th>isRequired</th><th>maxSelect</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['groupId'] . "</td>";
        echo "<td>" . $row['itemId'] . "</td>";
        echo "<td>" . htmlspecialchars($row['groupName']) . "</td>";
        echo "<td>" . $row['isRequired'] . "</td>";
        echo "<td><strong>" . $row['maxSelect'] . "</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<span class='skip'>Group မရှိသေးပါ</span>";
}

echo "\n<span class='info'>=== DONE ===</span>\n";
?>
</pre>

<div class="warning">
    ⚠️ <strong>DELETE this file after successful run!</strong><br>
    GitHub → fix_maxselect.php → Delete → Commit
</div>

</body>
</html>

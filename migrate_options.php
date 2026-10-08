<?php
require_once 'db.php';
header('Content-Type: text/html; charset=utf-8');
echo "<pre style='font-family:monospace;background:#0d1b2a;color:#4ADE80;padding:20px;'>";
echo "=== UCSH Options Migration ===\n\n";

$queries = [
"CREATE TABLE IF NOT EXISTS `menu_option_groups` (
  `groupId` INT(11) NOT NULL AUTO_INCREMENT,
  `itemId` INT(11) NOT NULL,
  `groupName` VARCHAR(100) NOT NULL,
  `isRequired` TINYINT(1) DEFAULT 1,
  `sortOrder` INT DEFAULT 0,
  PRIMARY KEY (`groupId`),
  KEY `itemId` (`itemId`),
  CONSTRAINT `mog_fk` FOREIGN KEY (`itemId`) REFERENCES `menu_items`(`itemId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

"CREATE TABLE IF NOT EXISTS `menu_options` (
  `optionId` INT(11) NOT NULL AUTO_INCREMENT,
  `groupId` INT(11) NOT NULL,
  `optionName` VARCHAR(100) NOT NULL,
  `extraPoints` INT(11) DEFAULT 0,
  `isAvailable` TINYINT(1) DEFAULT 1,
  `sortOrder` INT DEFAULT 0,
  PRIMARY KEY (`optionId`),
  KEY `groupId` (`groupId`),
  CONSTRAINT `mo_fk` FOREIGN KEY (`groupId`) REFERENCES `menu_option_groups`(`groupId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
];

foreach ($queries as $i => $q) {
    try {
        $conn->query($q);
        echo "✅ Table " . ($i+1) . " created\n";
    } catch (Exception $e) {
        echo "⏭️  Table " . ($i+1) . ": " . $e->getMessage() . "\n";
    }
}

// Add columns safely
$cols = [
    'cart' => 'selected_options TEXT DEFAULT NULL',
    'order_items' => 'selected_options TEXT DEFAULT NULL',
];

foreach ($cols as $table => $colDef) {
    $colName = explode(' ', $colDef)[0];
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$colName'");
    if ($check->num_rows === 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN $colDef");
        echo "✅ $table.$colName added\n";
    } else {
        echo "⏭️  $table.$colName exists\n";
    }
}

echo "\n=== DONE ===\n⚠️ Delete this file after migration!\n</pre>";

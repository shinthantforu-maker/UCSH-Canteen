<?php
// Railway ပေါ်မှာရှိရင် Railway Variables တွေကိုယူမယ်၊ မဟုတ်ရင် Localhost တွေကို သုံးမယ်
$host = getenv('MYSQLHOST') ?: 'localhost';
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: '';
$db   = getenv('MYSQLDATABASE') ?: 'railway';
$port = getenv('MYSQLPORT') ?: '3306';

$conn = mysqli_connect($host, $user, $pass, $db, $port);

if (!$conn) {
    die("Database Connection Failed: " . mysqli_connect_error());
}

// UTF-8 charset (မြန်မာစာ မှန်ကန်စွာ ပြသရန်)
$conn->set_charset("utf8mb4");

// =============================================
// 🎯 OPTIONS SYSTEM HELPER FUNCTIONS
// =============================================

/**
 * Item တစ်ခုရဲ့ option groups + options အားလုံး ဆွဲထုတ်
 */
function getItemOptionGroups($conn, $itemId, $onlyAvailable = true) {
    $groups = [];
    
    $gStmt = $conn->prepare("SELECT * FROM menu_option_groups WHERE itemId = ? ORDER BY sortOrder, groupId");
    if (!$gStmt) return $groups;
    
    $gStmt->bind_param("i", $itemId);
    $gStmt->execute();
    $gRes = $gStmt->get_result();
    
    while ($g = $gRes->fetch_assoc()) {
        $availClause = $onlyAvailable ? " AND isAvailable = 1" : "";
        $oStmt = $conn->prepare("SELECT * FROM menu_options WHERE groupId = ?$availClause ORDER BY sortOrder, optionId");
        
        if ($oStmt) {
            $oStmt->bind_param("i", $g['groupId']);
            $oStmt->execute();
            $oRes = $oStmt->get_result();
            $g['options'] = [];
            while ($o = $oRes->fetch_assoc()) {
                $g['options'][] = $o;
            }
            $oStmt->close();
        } else {
            $g['options'] = [];
        }
        
        $groups[] = $g;
    }
    $gStmt->close();
    
    return $groups;
}

/**
 * Item မှာ options ရှိမရှိ စစ်
 */
function hasItemOptions($conn, $itemId) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM menu_option_groups WHERE itemId = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("i", $itemId);
    $stmt->execute();
    $cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    
    return $cnt > 0;
}

/**
 * selected_options JSON ကနေ item name + options ကို format လုပ်
 * ဥပမာ: "မာလာရှမ်းကော (ကြက်, ဝက်)"
 */
function formatItemWithOptions($itemName, $selectedOptionsJson) {
    if (empty($selectedOptionsJson)) return $itemName;
    
    $opts = json_decode($selectedOptionsJson, true);
    if (!is_array($opts) || empty($opts)) return $itemName;
    
    $names = [];
    foreach ($opts as $o) {
        if (is_array($o) && !empty($o['optionName'])) {
            $names[] = $o['optionName'];
        } elseif (is_string($o) && !empty($o)) {
            $names[] = $o;
        }
    }
    
    if (empty($names)) return $itemName;
    return $itemName . ' (' . implode(', ', $names) . ')';
}

/**
 * selected_options JSON ကနေ extra points စုစုပေါင်း တွက်
 */
function calcExtraPoints($selectedOptionsJson) {
    if (empty($selectedOptionsJson)) return 0;
    
    $opts = json_decode($selectedOptionsJson, true);
    if (!is_array($opts)) return 0;
    
    $sum = 0;
    foreach ($opts as $o) {
        if (is_array($o)) {
            $sum += (int)($o['extraPoints'] ?? 0);
        }
    }
    return $sum;
}

/**
 * selected_options JSON ကနေ option names array ပြန်
 */
function getOptionNamesFromJson($selectedOptionsJson) {
    if (empty($selectedOptionsJson)) return [];
    
    $opts = json_decode($selectedOptionsJson, true);
    if (!is_array($opts)) return [];
    
    $names = [];
    foreach ($opts as $o) {
        if (is_array($o) && !empty($o['optionName'])) {
            $names[] = $o['optionName'];
        } elseif (is_string($o)) {
            $names[] = $o;
        }
    }
    return $names;
}

/**
 * selected_options JSON ကနေ option IDs array ပြန်
 */
function getOptionIdsFromJson($selectedOptionsJson) {
    if (empty($selectedOptionsJson)) return [];
    
    $opts = json_decode($selectedOptionsJson, true);
    if (!is_array($opts)) return [];
    
    $ids = [];
    foreach ($opts as $o) {
        if (is_array($o) && !empty($o['optionId'])) {
            $ids[] = (int)$o['optionId'];
        }
    }
    return $ids;
}

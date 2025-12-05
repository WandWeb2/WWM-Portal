<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
echo "Checking Environment...\n";

// Robust Path Detection
// 1. Try standard relative path (if running from api/)
$utilsPath = __DIR__ . '/modules/utils.php';

// 2. Try parent directory (if file was copied to portal/)
if (!file_exists($utilsPath)) {
    $utilsPath = __DIR__ . '/../api/modules/utils.php';
}

if (file_exists($utilsPath)) {
    echo "modules/utils.php found at $utilsPath.\n";
    require_once $utilsPath;
} else {
    echo "CWD: " . getcwd() . "\n";
    die("ERROR: modules/utils.php NOT found. Searched:\n - " . __DIR__ . '/modules/utils.php' . "\n - " . __DIR__ . '/../api/modules/utils.php');
}

// Use a dummy secret to test DB connection logic
$secrets = ['DB_DSN' => 'sqlite:' . __DIR__ . '/../../data/test.sqlite'];

try {
    $pdo = getDBConnection($secrets);
    echo "Database Connected: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";

    if (function_exists('ensureSettingsSchema')) {
        ensureSettingsSchema($pdo);
        echo "Schema Test: PASSED (No Syntax Errors)\n";
    } else {
        echo "Schema Test: SKIPPED (Function missing)\n";
    }
} catch (Exception $e) {
    echo "CRITICAL ERROR: " . $e->getMessage();
}

?>

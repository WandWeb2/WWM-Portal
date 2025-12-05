<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
echo "Checking Environment...\n";

// Use absolute path based on the current file's directory
$utilsPath = __DIR__ . '/modules/utils.php';

if (file_exists($utilsPath)) {
    echo "modules/utils.php found at $utilsPath.\n";
    require_once $utilsPath;
} else {
    // Attempt to debug CWD if file not found
    echo "CWD: " . getcwd() . "\n";
    die("ERROR: modules/utils.php NOT found at $utilsPath");
}

// Use a dummy secret to test DB connection logic
// Assuming data folder is one level up from api/
$secrets = ['DB_DSN' => 'sqlite:' . __DIR__ . '/../data/test.sqlite'];

try {
    $pdo = getDBConnection($secrets);
    echo "Database Connected: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";

    // Test the schema fix
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

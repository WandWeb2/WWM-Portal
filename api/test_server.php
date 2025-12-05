<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
echo "Checking Environment...\n";

if (file_exists('modules/utils.php')) {
    echo "modules/utils.php found.\n";
    require_once 'modules/utils.php';
} else {
    die("ERROR: modules/utils.php NOT found in " . getcwd());
}

// Use a dummy secret to test DB connection logic
$secrets = ['DB_DSN' => 'sqlite:../data/test.sqlite'];

try {
    $pdo = getDBConnection($secrets);
    echo "Database Connected: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";

    // Test the schema fix
    ensureSettingsSchema($pdo);
    echo "Schema Test: PASSED (No Syntax Errors)\n";
} catch (Exception $e) {
    echo "CRITICAL ERROR: " . $e->getMessage();
}

?>

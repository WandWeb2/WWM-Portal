<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
echo "<h3>API Diagnostics</h3>";

if (file_exists('modules/utils.php')) {
    echo "<p>✓ Found modules/utils.php</p>";
    require_once 'modules/utils.php';
} else {
    die("<p>✗ ERROR: modules/utils.php NOT FOUND in " . __DIR__ . "</p>");
}

// Mock secrets
$secrets = ['DB_DSN' => 'sqlite:../data/test.sqlite'];

try {
    $pdo = getDBConnection($secrets);
    echo "<p>✓ Database Connected (" . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . ")</p>";

    // Test the schema fix
    echo "<p>Testing Settings Schema... ";
    ensureSettingsSchema($pdo);
    echo "<span style='color:green'>✓ OK (Schema Created/Exists)</span></p>";
} catch (Exception $e) {
    echo "<p>✗ DB ERROR: " . $e->getMessage() . "</p>";
}

?>

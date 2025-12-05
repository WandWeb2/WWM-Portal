<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');
echo "1. Server is Alive\n";

if (file_exists('../secrets.php')) {
    echo "2. secrets.php found\n";
    include '../secrets.php';
} else {
    echo "2. secrets.php NOT found (using defaults)\n";
    $secrets = ['DB_DSN' => 'sqlite:../data/portal.sqlite'];
}

require_once 'modules/utils.php';

echo "3. utils.php loaded\n";

try {
    $pdo = getDBConnection($secrets);
    echo "4. Database Connection: SUCCESS\n";
    echo " Driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";

    // Try a simple query
    $stmt = $pdo->query("SELECT count(*) FROM users");
    echo "5. User Count: " . $stmt->fetchColumn() . "\n";
} catch (Exception $e) {
    echo "4. Database Connection: FAILED\n";
    echo " Error: " . $e->getMessage() . "\n";
}

?>

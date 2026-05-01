<?php
// Simple testing script for logging functionality

require_once __DIR__ . '/modules/utils.php';

// Keep track of test results
$testsPassed = 0;
$testsFailed = 0;

function assertTest($condition, $message) {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "✅ PASS: $message\n";
        $testsPassed++;
    } else {
        echo "❌ FAIL: $message\n";
        $testsFailed++;
    }
}

echo "=== Running Logger Tests ===\n\n";

// Use memory sqlite for fast, clean testing
$secrets = ['DB_DSN' => 'sqlite::memory:'];
$pdo = getDBConnection($secrets);

// ---------------------------------------------------------
// Test 1: Successful DB Log + Successful File Log
// ---------------------------------------------------------
$testMessage1 = "Test success path " . uniqid();
logSystemEvent($pdo, $testMessage1, 'info');

// Check DB
$stmt = $pdo->query("SELECT * FROM system_logs WHERE message = '$testMessage1'");
$log1 = $stmt->fetch();
assertTest($log1 && $log1['level'] === 'info', "Log is written to the database successfully");

// Check File
$logFile = sys_get_temp_dir() . '/wandweb_system.log';
$fileContents = file_get_contents($logFile);
assertTest(strpos($fileContents, $testMessage1) !== false, "Log is written to the fallback file successfully");

// ---------------------------------------------------------
// Test 2: Failed DB Log + Successful File Log (Fallback)
// ---------------------------------------------------------
// We mock PDO by creating a class that throws an exception on prepare
class FailingPDO extends PDO {
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = []) {
        throw new Exception("Simulated DB Failure");
    }
}
$failingPdo = new FailingPDO('sqlite::memory:');

$testMessage2 = "Test fail path " . uniqid();
logSystemEvent($failingPdo, $testMessage2, 'error');

// Check File
$fileContents = file_get_contents($logFile);
assertTest(strpos($fileContents, $testMessage2) !== false, "Fallback writes to file when DB fails");

// Clean up
if (file_exists($logFile)) {
    unlink($logFile);
}

echo "\n=== Test Summary ===\n";
echo "Passed: $testsPassed\n";
echo "Failed: $testsFailed\n";

if ($testsFailed > 0) {
    exit(1);
}

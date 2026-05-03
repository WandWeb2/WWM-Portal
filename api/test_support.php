<?php
// Test script for checkTicketRateLimit in modules/support.php

if (isset($argv[1]) && $argv[1] === "run_test") {
    require_once __DIR__ . "/modules/utils.php";
    require_once __DIR__ . "/modules/support.php";

    $testName = $argv[2];

    class MockPDOStatement {
        private $result;
        public function __construct($result) {
            $this->result = $result;
        }
        public function execute($params = null) {}
        public function fetchColumn() {
            return $this->result;
        }
    }

    class MockPDO {
        private $result;
        public function __construct($result) {
            $this->result = $result;
        }
        public function prepare($sql) {
            return new MockPDOStatement($this->result);
        }
    }

    if ($testName === "no_tickets") {
        // Mock DB returns false (no previous tickets)
        $pdo = new MockPDO(false);
        checkTicketRateLimit($pdo, 1);
        echo "SUCCESS";
    } elseif ($testName === "old_ticket") {
        // Mock DB returns a ticket from 120 seconds ago
        $pdo = new MockPDO(date("Y-m-d H:i:s", time() - 120));
        checkTicketRateLimit($pdo, 1);
        echo "SUCCESS";
    } elseif ($testName === "recent_ticket") {
        // Mock DB returns a ticket from 30 seconds ago
        $pdo = new MockPDO(date("Y-m-d H:i:s", time() - 30));
        checkTicketRateLimit($pdo, 1);
        echo "SUCCESS"; // Should not be reached because sendJson calls exit()
    }
    die();
}

echo "Running tests for checkTicketRateLimit...\n";

function runTest($case, $expectedOutputContains) {
    // Run the test in a separate process to capture any exit() calls
    $cmd = escapeshellcmd(PHP_BINARY) . " " . escapeshellarg(__FILE__) . " run_test " . escapeshellarg($case);
    exec($cmd, $output, $returnCode);
    $outputStr = implode("\n", $output);

    $passed = (strpos($outputStr, $expectedOutputContains) !== false);
    if ($passed) {
        echo "✅ Test $case passed.\n";
    } else {
        echo "❌ Test $case failed.\n";
        echo "Expected output to contain: $expectedOutputContains\nGot: $outputStr\n";
        die(1);
    }
}

// Run test cases
runTest("no_tickets", "SUCCESS");
runTest("old_ticket", "SUCCESS");
runTest("recent_ticket", "Please wait 60 seconds.");

echo "All checkTicketRateLimit tests passed!\n";

<?php
// Simple testing script for getBestGeminiModel without relying on extensions like runkit/uopz
// We use namespace-based overriding to mock built-in functions.
// Because utils.php does not define a namespace, we must define our test in a namespace
// but since the functions to test are in the global namespace, we can't easily override curl_exec there.
// Instead we'll use a safer approach: modify utils.php to use an optional injected dependencies,
// or use our eval technique which is robust for this simple project without full test frameworks.

// Given the codebase style, we'll use the eval technique to securely isolate and test the function.
echo "Testing getBestGeminiModel...\n";

// 1. Read the utils.php file
$utilsPath = __DIR__ . '/../modules/utils.php';
if (!file_exists($utilsPath)) {
    die("utils.php not found at $utilsPath\n");
}
$code = file_get_contents($utilsPath);

// 2. Strip PHP tags
$code = str_replace(['<?php', '?>'], '', $code);

// 3. Rename global functions to avoid redeclaration if they were already included
// But actually we are running this script standalone, so nothing is included yet.
// We will replace curl calls with our mocked ones.
$code = str_replace('curl_init', 'mock_curl_init', $code);
$code = str_replace('curl_setopt', 'mock_curl_setopt', $code);
$code = str_replace('curl_exec', 'mock_curl_exec', $code);
$code = str_replace('curl_close', 'mock_curl_close', $code);

// 4. Set up the mocks
$GLOBALS['mock_curl_response'] = "";

if (!function_exists('mock_curl_init')) {
    function mock_curl_init($url) { return "mock_ch"; }
    function mock_curl_setopt($ch, $opt, $val) { return true; }
    function mock_curl_exec($ch) { return $GLOBALS['mock_curl_response']; }
    function mock_curl_close($ch) { return true; }
}

// 5. Evaluate the patched code
// We use a try-catch to handle any fatal errors that might occur during eval
try {
    eval($code);
} catch (Throwable $e) {
    die("Error evaluating patched code: " . $e->getMessage() . "\n");
}

$testSecrets = ['GEMINI_API_KEY' => 'test-key'];
$passed = 0;
$failed = 0;

function runTest($name, $mockResponse, $expectedFallback) {
    global $testSecrets, $passed, $failed;
    $GLOBALS['mock_curl_response'] = $mockResponse;
    $result = getBestGeminiModel($testSecrets);
    if ($result === $expectedFallback) {
        echo "[PASS] $name (Got '$result')\n";
        $passed++;
    } else {
        echo "[FAIL] $name (Expected '$expectedFallback', got '$result')\n";
        $failed++;
    }
}

// Scenario 1: Empty response (simulating API failure)
runTest("Empty Response", "", "gemini-pro");

// Scenario 2: Malformed JSON response
runTest("Malformed JSON", "this is not json", "gemini-pro");

// Scenario 3: Valid response but no 'models' key
runTest("Missing 'models' key", json_encode(['error' => 'invalid api key']), "gemini-pro");

// Scenario 4: Valid response, models array is empty
runTest("Empty 'models' array", json_encode(['models' => []]), "gemini-pro");

// Scenario 5: Valid response with preferred models
$validResponse = json_encode([
    'models' => [
        ['name' => 'models/gemini-1.5-flash', 'supportedGenerationMethods' => ['generateContent']],
        ['name' => 'models/gemini-pro', 'supportedGenerationMethods' => ['generateContent']]
    ]
]);
runTest("Valid Models List", $validResponse, "gemini-1.5-flash");

// Scenario 6: Valid response, but only standard pro available
$onlyProResponse = json_encode([
    'models' => [
        ['name' => 'models/gemini-pro', 'supportedGenerationMethods' => ['generateContent']],
        ['name' => 'models/gemini-unsupported', 'supportedGenerationMethods' => []]
    ]
]);
runTest("Only gemini-pro Available", $onlyProResponse, "gemini-pro");

echo "---------------------------------\n";
echo "Tests Completed: " . ($passed + $failed) . "\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

if ($failed > 0) {
    exit(1);
} else {
    exit(0);
}

<?php
require_once __DIR__ . '/../modules/utils.php';

echo "Testing getGoogleAccessToken...\n";
$testsPassed = 0;
$testsFailed = 0;

function assertEqual($expected, $actual, $testName) {
    global $testsPassed, $testsFailed;
    if ($expected === $actual) {
        echo "✅ PASS: $testName\n";
        $testsPassed++;
    } else {
        echo "❌ FAIL: $testName\n";
        echo "   Expected: " . print_r($expected, true) . "\n";
        echo "   Actual:   " . print_r($actual, true) . "\n";
        $testsFailed++;
    }
}

// Test 1: Empty refresh token
$secrets = [];
$res = getGoogleAccessToken($secrets);
assertEqual(null, $res, 'Returns null when GOOGLE_REFRESH_TOKEN is empty');

// Test 2: Valid response
$secrets = [
    'GOOGLE_REFRESH_TOKEN' => 'test_refresh',
    'GOOGLE_CLIENT_ID' => 'test_id',
    'GOOGLE_CLIENT_SECRET' => 'test_secret',
    '_mock_token_url' => 'mock_url',
    '_mock_curl_setopt' => function($ch, $opt, $val) { return true; },
    '_mock_curl_exec' => function($ch) { return json_encode(['access_token' => 'mock_token_123']); },
    '_mock_curl_close' => function($ch) { return true; }
];
$res = getGoogleAccessToken($secrets);
assertEqual('mock_token_123', $res, 'Returns access_token on success');

// Test 3: Invalid response / API Error
$secrets['_mock_curl_exec'] = function($ch) { return json_encode(['error' => 'invalid_grant']); };
$res = getGoogleAccessToken($secrets);
assertEqual(null, $res, 'Returns null when API returns an error or no access_token');

// Test 4: Empty string refresh token
$secrets['GOOGLE_REFRESH_TOKEN'] = '';
$res = getGoogleAccessToken($secrets);
assertEqual(null, $res, 'Returns null when GOOGLE_REFRESH_TOKEN is empty string');

// Test 5: Missing client_id and client_secret should not crash
$secrets = [
    'GOOGLE_REFRESH_TOKEN' => 'test_refresh',
    // Missing client_id and secret
    '_mock_token_url' => 'mock_url',
    '_mock_curl_setopt' => function($ch, $opt, $val) { return true; },
    '_mock_curl_exec' => function($ch) { return json_encode(['access_token' => 'mock_token_456']); },
    '_mock_curl_close' => function($ch) { return true; }
];
$res = getGoogleAccessToken($secrets);
assertEqual('mock_token_456', $res, 'Returns access_token even if client id/secret are missing');


echo "\nTests completed: $testsPassed passed, $testsFailed failed.\n";
if ($testsFailed > 0) exit(1);

<?php
// api/tests/test_sentiment.php

// Include the file containing the function to test
// We use require_once to avoid issues if it was already included
require_once __DIR__ . '/../modules/support.php';

/**
 * Simple assertion helper
 */
function assert_sentiment($text, $expected) {
    $actual = analyzeSentiment($text);
    if ($actual === $expected) {
        echo "✅ PASS: '$text' -> Expected $expected, Got $actual\n";
        return true;
    } else {
        echo "❌ FAIL: '$text' -> Expected $expected, Got $actual\n";
        return false;
    }
}

$all_passed = true;

echo "--- Running analyzeSentiment tests ---\n";

// 1. Empty/Neutral text (0 score)
$all_passed = assert_sentiment("", 0) && $all_passed;
$all_passed = assert_sentiment("Hello world", 0) && $all_passed;

// 2. Single trigger matching
$all_passed = assert_sentiment("This is urgent", 20) && $all_passed;
$all_passed = assert_sentiment("This is an emergency", 30) && $all_passed;

// 3. Case insensitivity
$all_passed = assert_sentiment("URGENT", 20) && $all_passed;
$all_passed = assert_sentiment("eMeRgEnCy", 30) && $all_passed;

// 4. Multiple different triggers
$all_passed = assert_sentiment("urgent emergency", 50) && $all_passed; // 20 + 30
$all_passed = assert_sentiment("broken and down", 50) && $all_passed; // 20 + 30
$all_passed = assert_sentiment("error and fail", 20) && $all_passed; // 10 + 10

// 5. Score capping at 100
$all_passed = assert_sentiment("urgent emergency broken down error fail refund cancel frustrated asap", 100) && $all_passed;

// 6. Repeated triggers (ensuring they only count once per keyword)
$all_passed = assert_sentiment("urgent urgent urgent", 20) && $all_passed;

// 7. Substring matching behavior
$all_passed = assert_sentiment("brokenness", 20) && $all_passed;

echo "--------------------------------------\n";

if ($all_passed) {
    echo "Summary: All tests PASSED!\n";
    exit(0);
} else {
    echo "Summary: Some tests FAILED!\n";
    exit(1);
}

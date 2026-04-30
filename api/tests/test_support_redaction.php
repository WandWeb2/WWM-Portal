<?php

require_once __DIR__ . '/../modules/support.php';

$exitCode = 0;

function runTest($name, $input, $expected) {
    global $exitCode;
    $result = redactSensitiveData($input);
    if ($result === $expected) {
        echo "PASS: $name\n";
    } else {
        echo "FAIL: $name\n";
        echo "  Expected: $expected\n";
        echo "  Got:      $result\n";
        $exitCode = 1;
    }
}

echo "Testing redactSensitiveData...\n\n";

// Credit Card tests
runTest('Credit Card - 16 digits contiguous', 'My card is 1234567812345678.', 'My card is [REDACTED_CARD].');
runTest('Credit Card - 16 digits dashed', 'My card is 1234-5678-1234-5678.', 'My card is [REDACTED_CARD].');
runTest('Credit Card - 16 digits spaced', 'My card is 1234 5678 1234 5678.', 'My card is [REDACTED_CARD].');
runTest('Credit Card - 13 digits', 'My old card is 1234567812345.', 'My old card is [REDACTED_CARD].');
runTest('Credit Card - ignore normal numbers', 'My phone is 123-456-7890.', 'My phone is 123-456-7890.');
runTest('Credit Card - ignore long non-card numbers', 'Order ID is 12345678901234567890.', 'Order ID is 12345678901234567890.');

// SSN tests
runTest('SSN - Standard format', 'My SSN is 123-45-6789.', 'My SSN is [REDACTED_SSN].');
runTest('SSN - Standard format spaced', 'My SSN is 123 - 45 - 6789.', 'My SSN is 123 - 45 - 6789.'); // Regex doesn't match spaces
runTest('SSN - Contiguous', 'My SSN is 123456789.', 'My SSN is 123456789.'); // Regex only matches dashes

// Combined tests
runTest('Combined CC and SSN', 'Card: 1234-5678-1234-5678, SSN: 123-45-6789.', 'Card: [REDACTED_CARD], SSN: [REDACTED_SSN].');

// Edge Cases
runTest('Empty string', '', '');
runTest('No sensitive data', 'Just a normal sentence with numbers like 42.', 'Just a normal sentence with numbers like 42.');
runTest('Multiple occurrences', 'Cards: 1111222233334444 and 5555666677778888', 'Cards: [REDACTED_CARD] and [REDACTED_CARD]');
runTest('Newline handling', "Card:\n1234567812345678\nSSN:\n123-45-6789", "Card:\n[REDACTED_CARD]\nSSN:\n[REDACTED_SSN]");

if ($exitCode === 0) {
    echo "\nAll tests passed successfully.\n";
} else {
    echo "\nSome tests failed.\n";
}

exit($exitCode);

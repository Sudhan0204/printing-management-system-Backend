<?php

/**
 * Smoke tests for shared Validation helper rules.
 * Run: php backend/scripts/smoke-validation.php
 */

require_once dirname(__DIR__) . "/app/Helpers/Validation.php";

$failures = 0;

function assertTrue($condition, $label)
{
    global $failures;

    if ($condition) {
        echo "[PASS] {$label}\n";
        return;
    }

    $failures++;
    echo "[FAIL] {$label}\n";
}

function assertEquals($expected, $actual, $label)
{
    assertTrue($expected === $actual, $label . " (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
}

assertTrue(Validation::length("A", 2, 100, "Customer name") !== null, "Customer name too short rejected");
assertTrue(Validation::length(str_repeat("A", 101), 2, 100, "Customer name") !== null, "Customer name too long rejected");
assertTrue(Validation::length("  ", 2, 100, "Customer name") !== null, "Whitespace-only name rejected");
assertTrue(Validation::length("Raj Prints", 2, 100, "Customer name") === null, "Valid customer name accepted");

assertTrue(Validation::indianMobile("9876543210") === null, "Valid Indian mobile accepted");
assertTrue(Validation::indianMobile("987654321") !== null, "9-digit mobile rejected");
assertTrue(Validation::indianMobile("98765432101") !== null, "11-digit mobile rejected");
assertTrue(Validation::indianMobile("1234567890") !== null, "Mobile starting with 1 rejected");
assertTrue(Validation::indianMobile("98765abcde") !== null, "Alpha mobile rejected");
assertEquals("9876543210", Validation::normalizePhone("+91 98765-43210"), "Phone normalized to 10 digits");

assertTrue(Validation::email("abc") !== null, "Incomplete email rejected");
assertTrue(Validation::email("abc@") !== null, "Trailing @ email rejected");
assertTrue(Validation::email("abc@gmail") !== null, "Missing TLD email rejected");
assertTrue(Validation::email("test.com") !== null, "Missing @ email rejected");
assertTrue(Validation::email("@domain.com") !== null, "Missing local part rejected");
assertTrue(Validation::email("owner@example.com") === null, "Valid email accepted");
assertTrue(Validation::email("") === null, "Optional empty email accepted");

assertTrue(Validation::strongPassword("short") !== null, "Short password rejected");
assertTrue(Validation::strongPassword("alllowercase1!") !== null, "Password missing uppercase rejected");
assertTrue(Validation::strongPassword("ALLUPPERCASE1!") !== null, "Password missing lowercase rejected");
assertTrue(Validation::strongPassword("NoNumber!") !== null, "Password missing number rejected");
assertTrue(Validation::strongPassword("NoSpecial1") !== null, "Password missing special rejected");
assertTrue(Validation::strongPassword("GoodPass1!", "mismatch") !== null, "Password mismatch rejected");
assertTrue(Validation::strongPassword("GoodPass1!", "GoodPass1!") === null, "Strong password accepted");

assertTrue(Validation::money("-1", "Rate", true) !== null, "Negative money rejected");
assertTrue(Validation::money("10.999", "Rate", true) !== null, "Too many decimals rejected");
assertTrue(Validation::money("abc", "Rate", true) !== null, "Alpha money rejected");
assertTrue(Validation::money("0", "Rate", true) === null, "Zero rate allowed when configured");
assertTrue(Validation::money("0", "Amount", false) !== null, "Zero payment rejected");
assertTrue(Validation::money("125.50", "Amount") === null, "Valid money accepted");

assertTrue(Validation::positiveNumber("0", "Quantity") !== null, "Zero quantity rejected");
assertTrue(Validation::positiveNumber("-2", "Width", false, 2) !== null, "Negative width rejected");
assertTrue(Validation::positiveNumber("12.345", "Width", false, 2) !== null, "Width >2 decimals rejected");
assertTrue(Validation::positiveNumber("12.34", "Width", false, 2) === null, "Valid width accepted");

assertTrue(Validation::indianPincode("110001") === null, "Valid pincode accepted");
assertTrue(Validation::indianPincode("012345") !== null, "Pincode starting with 0 rejected");
assertTrue(Validation::indianPincode("11001") !== null, "5-digit pincode rejected");

if ($failures > 0) {
    echo "\n{$failures} validation smoke test(s) failed.\n";
    exit(1);
}

echo "\nAll validation smoke tests passed.\n";
exit(0);

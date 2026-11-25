<?php

/**
 * Shared input validation for API requests.
 * Controllers still validate independently — this keeps rules consistent.
 */
class Validation
{
    public const LIMITS = [
        "customer_name" => ["min" => 2, "max" => 100],
        "business_name" => ["min" => 2, "max" => 150],
        "owner_name" => ["min" => 2, "max" => 100],
        "product_name" => ["min" => 2, "max" => 100],
        "category" => ["min" => 0, "max" => 100],
        "city" => ["min" => 2, "max" => 100],
        "state" => ["min" => 2, "max" => 100],
        "address" => ["min" => 0, "max" => 250],
        "email" => ["min" => 5, "max" => 254],
        "notes" => ["min" => 0, "max" => 500],
        "description" => ["min" => 0, "max" => 500],
        "reference_number" => ["min" => 0, "max" => 100],
        "password" => ["min" => 8, "max" => 64],
    ];

    public static function normalizePhone($phone)
    {
        $phone = preg_replace("/[\s\-()]/", "", trim((string) $phone));

        if (str_starts_with($phone, "+91")) {
            $phone = substr($phone, 3);
        } elseif (str_starts_with($phone, "91") && strlen($phone) === 12) {
            $phone = substr($phone, 2);
        }

        return preg_replace("/\D/", "", (string) $phone);
    }

    public static function hasControlChars($value)
    {
        return preg_match("/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/", (string) $value) === 1;
    }

    public static function length($value, $min, $max, $label = "This field", $required = true)
    {
        $trimmed = trim((string) $value);

        if ($trimmed === "") {
            return $required
                ? ($label === "This field" ? "This field is required." : $label . " is required.")
                : null;
        }

        if (self::hasControlChars($trimmed)) {
            return $label . " contains invalid characters.";
        }

        $length = mb_strlen($trimmed);

        if ($length < $min || $length > $max) {
            if ($min <= 0) {
                return $label . " cannot exceed " . $max . " characters.";
            }

            return $label . " must be between " . $min . " and " . $max . " characters.";
        }

        return null;
    }

    public static function indianMobile($phone, $required = true)
    {
        $phone = self::normalizePhone($phone);

        if ($phone === "") {
            return $required ? "This field is required." : null;
        }

        if (!preg_match("/^[6-9][0-9]{9}$/", $phone)) {
            return "Enter a valid 10-digit Indian mobile number.";
        }

        return null;
    }

    public static function email($email, $required = false)
    {
        $email = strtolower(trim((string) $email));

        if ($email === "") {
            return $required ? "This field is required." : null;
        }

        if (strlen($email) < 5 || strlen($email) > 254) {
            return "Enter a valid email address.";
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return "Enter a valid email address.";
        }

        $parts = explode("@", $email);
        $domain = $parts[1] ?? "";

        if ($domain === "" || !str_contains($domain, ".") || str_starts_with($domain, ".") || str_ends_with($domain, ".")) {
            return "Enter a valid email address.";
        }

        return null;
    }

    public static function required($value, $label = "This field")
    {
        if (trim((string) $value) === "") {
            return $label === "This field" ? "This field is required." : ($label . " is required.");
        }

        return null;
    }

    public static function indianPincode($pincode)
    {
        $pincode = trim((string) $pincode);

        if ($pincode === "") {
            return "This field is required.";
        }

        if (!preg_match("/^[1-9][0-9]{5}$/", $pincode)) {
            return "Enter a valid 6-digit Indian pincode.";
        }

        return null;
    }

    /**
     * Strong password rules for account creation / reset.
     * Do not use this for login credential checks.
     */
    public static function strongPassword($password, $confirmation = null)
    {
        $password = (string) $password;

        if ($password === "") {
            return "Password is required.";
        }

        if (strlen($password) < 8 || strlen($password) > 64) {
            return "Password must contain at least 8 characters, including uppercase, lowercase, number and special character.";
        }

        if (
            !preg_match("/[A-Z]/", $password)
            || !preg_match("/[a-z]/", $password)
            || !preg_match("/[0-9]/", $password)
            || !preg_match("/[^A-Za-z0-9]/", $password)
        ) {
            return "Password must contain at least 8 characters, including uppercase, lowercase, number and special character.";
        }

        if ($confirmation !== null && $password !== (string) $confirmation) {
            return "Password confirmation does not match.";
        }

        return null;
    }

    public static function money($value, $label = "Amount", $allowZero = false, $required = true)
    {
        if ($value === "" || $value === null) {
            return $required ? $label . " is required." : null;
        }

        $raw = trim((string) $value);

        if (!preg_match("/^-?\d+(\.\d{1,2})?$/", $raw)) {
            return $label . " must be a valid number with up to 2 decimal places.";
        }

        $number = (float) $raw;

        if ($allowZero) {
            if ($number < 0) {
                return $label . " cannot be negative.";
            }
        } elseif ($number <= 0) {
            return $label . " must be greater than zero.";
        }

        return null;
    }

    public static function positiveNumber($value, $label = "Value", $allowZero = false, $maxDecimals = 4, $required = true)
    {
        if ($value === "" || $value === null) {
            return $required ? $label . " is required." : null;
        }

        $raw = trim((string) $value);
        $pattern = "/^-?\d+(\.\d{1," . (int) $maxDecimals . "})?$/";

        if (!preg_match($pattern, $raw)) {
            return $label . " must be a valid number with up to " . $maxDecimals . " decimal places.";
        }

        if (!is_numeric($raw)) {
            return $label . " must be a valid number.";
        }

        $number = (float) $raw;

        if ($allowZero) {
            if ($number < 0) {
                return $label . " cannot be negative.";
            }
        } elseif ($number <= 0) {
            return $label . " must be greater than zero.";
        }

        return null;
    }

    public static function fail(array $errors, $message = "Validation failed")
    {
        Response::json([
            "success" => false,
            "message" => $message,
            "errors" => $errors,
        ], 422);
    }
}

<?php

/**
 * CLI smoke test for admin + tenant flows.
 * Usage: php scripts/smoke-test.php
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

$base = getenv("APP_URL") ?: "http://localhost/printing-management-system/backend/public";
$cookieFile = sys_get_temp_dir() . "/pms_smoke_cookies.txt";
@unlink($cookieFile);

function request($method, $url, $cookieFile, $body = null, $csrf = null, $multipart = false)
{
    $ch = curl_init($url);
    $headers = ["Accept: application/json"];

    if ($csrf) {
        $headers[] = "X-CSRF-Token: " . $csrf;
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HEADER => false,
    ]);

    if ($multipart && is_array($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif ($body !== null) {
        $headers[] = "Content-Type: application/json";
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("Request failed: " . $error);
    }

    $json = json_decode($raw, true);

    return [$status, $json, $raw];
}

function assertTrue($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    fwrite(STDOUT, "OK: {$message}\n");
}

[$status, $json] = request("GET", $base . "/api/auth/csrf", $cookieFile);
assertTrue($status === 200 && !empty($json["csrf_token"]), "Fetch CSRF");
$csrf = $json["csrf_token"];

[$status, $json] = request("POST", $base . "/api/admin/login", $cookieFile, [
    "email" => "admin@printing.local",
    "password" => "Admin123!",
], $csrf);
assertTrue($status === 200 && ($json["user"]["role"] ?? null) === "super_admin", "Super Admin login");
$csrf = $json["csrf_token"];

[$status, $json] = request("GET", $base . "/api/admin/dashboard", $cookieFile);
assertTrue($status === 200 && isset($json["stats"]["total_clients"]), "Admin dashboard stats");

$logoPath = sys_get_temp_dir() . "/pms_test_logo.png";
$png = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==");
file_put_contents($logoPath, $png);

$unique = bin2hex(random_bytes(4));
$loginEmail = "owner_{$unique}@example.com";

$multipart = [
    "business_name" => "Smoke Print Co {$unique}",
    "owner_name" => "Owner {$unique}",
    "email" => "biz_{$unique}@example.com",
    "phone" => "9876543210",
    "address" => "12 Press Lane",
    "city" => "Pune",
    "state" => "Maharashtra",
    "pincode" => "411001",
    "login_email" => $loginEmail,
    "password" => "Owner123!",
    "password_confirmation" => "Owner123!",
    "status" => "active",
    "logo" => new CURLFile($logoPath, "image/png", "logo.png"),
];

[$status, $json, $raw] = request("POST", $base . "/api/admin/clients", $cookieFile, $multipart, $csrf, true);
assertTrue($status === 201 && !empty($json["client"]["id"]), "Create client with logo: " . ($json["message"] ?? $raw));
$clientId = $json["client"]["id"];
$logoRel = $json["client"]["logo"] ?? null;
assertTrue(!empty($logoRel), "Logo path stored");
$absoluteLogo = __DIR__ . "/../public/" . $logoRel;
assertTrue(is_file($absoluteLogo), "Logo file exists on disk");
$csrf = $json["csrf_token"] ?? $csrf;

[$status, $json] = request("GET", $base . "/api/admin/clients/" . $clientId, $cookieFile);
assertTrue($status === 200 && ($json["client"]["id"] ?? null) === $clientId, "Fetch client profile");

[$status, $json] = request("POST", $base . "/api/admin/logout", $cookieFile, [], $csrf);
assertTrue($status === 200, "Admin logout");

@unlink($cookieFile);
[$status, $json] = request("GET", $base . "/api/auth/csrf", $cookieFile);
$csrf = $json["csrf_token"];

[$status, $json] = request("POST", $base . "/api/tenant/login", $cookieFile, [
    "email" => $loginEmail,
    "password" => "Owner123!",
], $csrf);
assertTrue($status === 200 && !empty($json["user"]["tenant_id"]), "Tenant login");
$csrf = $json["csrf_token"];

[$status, $json] = request("GET", $base . "/api/tenant/dashboard", $cookieFile);
assertTrue($status === 200 && !empty($json["dashboard"]["business_name"]), "Tenant dashboard");

[$status, $json] = request("GET", $base . "/api/admin/clients", $cookieFile);
assertTrue($status === 403, "Tenant blocked from admin clients");

[$status, $json] = request("POST", $base . "/api/tenant/logout", $cookieFile, [], $csrf);
assertTrue($status === 200, "Tenant logout");

@unlink($cookieFile);
[$status, $json] = request("GET", $base . "/api/auth/csrf", $cookieFile);
$csrf = $json["csrf_token"];
[$status, $json] = request("POST", $base . "/api/admin/login", $cookieFile, [
    "email" => "admin@printing.local",
    "password" => "Admin123!",
], $csrf);
$csrf = $json["csrf_token"];

[$status, $json] = request("POST", $base . "/api/admin/clients/{$clientId}/status", $cookieFile, [
    "status" => "suspended",
], $csrf);
assertTrue($status === 200 && ($json["client"]["status"] ?? null) === "suspended", "Suspend client");
$csrf = $json["csrf_token"] ?? $csrf;

[$status, $json] = request("POST", $base . "/api/admin/logout", $cookieFile, [], $csrf);
@unlink($cookieFile);
[$status, $json] = request("GET", $base . "/api/auth/csrf", $cookieFile);
$csrf = $json["csrf_token"];

[$status, $json] = request("POST", $base . "/api/tenant/login", $cookieFile, [
    "email" => $loginEmail,
    "password" => "Owner123!",
], $csrf);
assertTrue($status === 403, "Suspended tenant cannot login");

@unlink($cookieFile);
[$status, $json] = request("GET", $base . "/api/auth/csrf", $cookieFile);
$csrf = $json["csrf_token"];
[$status, $json] = request("POST", $base . "/api/admin/login", $cookieFile, [
    "email" => "admin@printing.local",
    "password" => "Admin123!",
], $csrf);
$csrf = $json["csrf_token"];
[$status, $json] = request("POST", $base . "/api/admin/clients/{$clientId}/status", $cookieFile, [
    "status" => "active",
], $csrf);
assertTrue($status === 200 && ($json["client"]["status"] ?? null) === "active", "Activate client");

fwrite(STDOUT, "\nAll smoke tests passed. Client ID {$clientId}, login {$loginEmail} / Owner123!\n");

<?php

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

$base = getenv("APP_URL") ?: "http://localhost/printing-management-system/backend/public";
$cookieFile = sys_get_temp_dir() . "/pms_billing_cookies.txt";
@unlink($cookieFile);

function request($method, $url, $cookieFile, $body = null, $csrf = null)
{
    $ch = curl_init($url);
    $headers = ["Accept: application/json", "Content-Type: application/json"];
    if ($csrf) {
        $headers[] = "X-CSRF-Token: " . $csrf;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($raw, true), $raw];
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
$csrf = $json["csrf_token"];
assertTrue($status === 200 && $csrf, "CSRF");

[$status, $json] = request("POST", $base . "/api/tenant/login", $cookieFile, [
    "email" => "owner_6829fe1c@example.com",
    "password" => "Owner123!",
], $csrf);
assertTrue($status === 200 && !empty($json["user"]["tenant_id"]), "Tenant login");
$csrf = $json["csrf_token"];
$tenantId = (int) $json["user"]["tenant_id"];

[$status, $json] = request("POST", $base . "/api/tenant/customers", $cookieFile, [
    "customer_name" => "Billing Test Customer",
    "phone" => "9000012345",
    "city" => "Chennai",
    "status" => "active",
], $csrf);
assertTrue($status === 201, "Create customer: " . ($json["message"] ?? ""));
$customerId = $json["customer"]["id"];

[$status, $json] = request("POST", $base . "/api/tenant/products", $cookieFile, [
    "product_name" => "Flex",
    "unit" => "sq_ft",
    "pricing_type" => "dimension_based",
    "initial_rate" => 5.5,
    "status" => "active",
], $csrf);
assertTrue($status === 201, "Create Flex product");
$flexId = $json["product"]["id"];

[$status, $json] = request("POST", $base . "/api/tenant/products", $cookieFile, [
    "product_name" => "Visiting Card",
    "unit" => "piece",
    "pricing_type" => "fixed_price",
    "initial_rate" => 2.0,
    "status" => "active",
], $csrf);
assertTrue($status === 201, "Create Visiting Card product");
$cardId = $json["product"]["id"];

[$status, $json] = request("POST", $base . "/api/tenant/products/{$flexId}/rates", $cookieFile, [
    "rate" => 6.0,
    "effective_from" => date("Y-m-d"),
], $csrf);
assertTrue($status === 201, "Update Flex rate to 6.00");

[$status, $json] = request("GET", $base . "/api/tenant/products/{$flexId}/rates", $cookieFile);
assertTrue($status === 200 && count($json["rates"]) >= 2, "Rate history retained");
$hasOld = false;
$hasNew = false;
foreach ($json["rates"] as $rate) {
    if ((float) $rate["rate"] === 5.5) $hasOld = true;
    if ((float) $rate["rate"] === 6.0 && $rate["status"] === "active") $hasNew = true;
}
assertTrue($hasOld && $hasNew, "Old 5.50 kept and new 6.00 active");

[$status, $json] = request("POST", $base . "/api/tenant/bills", $cookieFile, [
    "customer_id" => $customerId,
    "bill_date" => date("Y-m-d"),
    "items" => [
        [
            "product_id" => $flexId,
            "width" => 3,
            "height" => 1.5,
            "quantity" => 2,
            "rate" => 6.0,
        ],
        [
            "product_id" => $cardId,
            "quantity" => 100,
            "rate" => 2.0,
        ],
    ],
], $csrf);
assertTrue($status === 201, "Create multi-product bill: " . ($json["message"] ?? ""));
$bill = $json["bill"];
// Flex: 3*1.5*2=9 * 6 = 54; Cards: 100*2=200; Total 254
assertTrue(abs($bill["total_amount"] - 254.0) < 0.001, "Total amount is 254, got " . $bill["total_amount"]);
assertTrue(count($bill["items"]) === 2, "Two bill items saved");
assertTrue(abs($bill["items"][0]["rate"] - 6.0) < 0.001, "Bill stores rate snapshot 6.00");

// Isolation: another tenant cannot see this bill
[$status, $json] = request("POST", $base . "/api/tenant/logout", $cookieFile, [], $csrf);
@unlink($cookieFile);
[$status, $json] = request("GET", $base . "/api/auth/csrf", $cookieFile);
$csrf = $json["csrf_token"];
[$status, $json] = request("POST", $base . "/api/tenant/login", $cookieFile, [
    "email" => "owner_6204d9aa@example.com",
    "password" => "Owner123!",
], $csrf);
assertTrue($status === 200, "Other tenant login");
[$status, $json] = request("GET", $base . "/api/tenant/bills/" . $bill["id"], $cookieFile);
assertTrue($status === 404, "Tenant isolation: other tenant cannot view bill");

fwrite(STDOUT, "\nBilling smoke tests passed for tenant {$tenantId}. Bill {$bill["bill_number"]}\n");

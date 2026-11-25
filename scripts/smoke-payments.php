<?php

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

$base = getenv("APP_URL") ?: "http://localhost/printing-management-system/backend/public";
$cookieFile = sys_get_temp_dir() . "/pms_payment_cookies.txt";
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

[$status, $json] = request("POST", $base . "/api/tenant/login", $cookieFile, [
    "email" => "owner_6829fe1c@example.com",
    "password" => "Owner123!",
], $csrf);
assertTrue($status === 200, "Tenant login");
$csrf = $json["csrf_token"];

[$status, $json] = request("POST", $base . "/api/tenant/customers", $cookieFile, [
    "customer_name" => "Pay Test Customer",
    "phone" => "9876543210",
    "status" => "active",
], $csrf);
assertTrue($status === 201, "Create customer with valid phone");
$customerId = $json["customer"]["id"];

[$status, $json] = request("POST", $base . "/api/tenant/customers", $cookieFile, [
    "customer_name" => "Bad Phone",
    "phone" => "1234567890",
    "status" => "active",
], $csrf);
assertTrue($status === 422, "Reject phone starting with 1");

[$status, $json] = request("POST", $base . "/api/tenant/customers", $cookieFile, [
    "customer_name" => "Bad Phone 2",
    "phone" => "98765432101",
    "status" => "active",
], $csrf);
assertTrue($status === 422, "Reject 11-digit phone");

[$status, $json] = request("POST", $base . "/api/tenant/products", $cookieFile, [
    "product_name" => "Pay Flex",
    "unit" => "sq_ft",
    "pricing_type" => "dimension_based",
    "initial_rate" => 10,
    "status" => "active",
], $csrf);
assertTrue($status === 201, "Create product");
$productId = $json["product"]["id"];

[$status, $json] = request("POST", $base . "/api/tenant/bills", $cookieFile, [
    "customer_id" => $customerId,
    "bill_date" => date("Y-m-d"),
    "items" => [[
        "product_id" => $productId,
        "width" => 10,
        "height" => 5,
        "quantity" => 1,
        "rate" => 10,
    ]],
], $csrf);
assertTrue($status === 201, "Create bill of 500");
$billId = $json["bill"]["id"];
assertTrue(abs($json["bill"]["total_amount"] - 500) < 0.01, "Bill total 500");
assertTrue($json["bill"]["payment_status"] === "unpaid", "Bill starts unpaid");

[$status, $json] = request("POST", $base . "/api/tenant/payments", $cookieFile, [
    "customer_id" => $customerId,
    "bill_id" => $billId,
    "payment_date" => date("Y-m-d"),
    "payment_method" => "cash",
    "amount" => 200,
], $csrf);
assertTrue($status === 201, "Partial cash payment 200");

[$status, $json] = request("GET", $base . "/api/tenant/bills/" . $billId, $cookieFile);
assertTrue($json["bill"]["payment_status"] === "partially_paid", "Status partially paid");
assertTrue(abs($json["bill"]["paid_amount"] - 200) < 0.01, "Paid 200");
assertTrue(abs($json["bill"]["balance_amount"] - 300) < 0.01, "Balance 300");

[$status, $json] = request("POST", $base . "/api/tenant/payments", $cookieFile, [
    "customer_id" => $customerId,
    "bill_id" => $billId,
    "payment_date" => date("Y-m-d"),
    "payment_method" => "upi",
    "amount" => 100,
    "reference_number" => "UPI123",
], $csrf);
assertTrue($status === 201, "Second payment 100");

[$status, $json] = request("POST", $base . "/api/tenant/payments", $cookieFile, [
    "customer_id" => $customerId,
    "bill_id" => $billId,
    "payment_date" => date("Y-m-d"),
    "payment_method" => "bank_transfer",
    "amount" => 250,
    "reference_number" => "NEFT999",
], $csrf);
assertTrue($status === 422, "Reject overpayment");

[$status, $json] = request("POST", $base . "/api/tenant/payments", $cookieFile, [
    "customer_id" => $customerId,
    "bill_id" => $billId,
    "payment_date" => date("Y-m-d"),
    "payment_method" => "bank_transfer",
    "amount" => 200,
    "reference_number" => "NEFT100",
], $csrf);
assertTrue($status === 201, "Final payment 200");

[$status, $json] = request("GET", $base . "/api/tenant/bills/" . $billId, $cookieFile);
assertTrue($json["bill"]["payment_status"] === "paid", "Bill fully paid");
assertTrue(abs($json["bill"]["balance_amount"]) < 0.01, "Balance zero");
assertTrue(count($json["bill"]["payments"]) === 3, "Three payment records kept");

[$status, $json] = request("GET", $base . "/api/tenant/payments/summary", $cookieFile);
assertTrue($status === 200 && isset($json["summary"]["payments_today"]), "Payment summary");

[$status, $json] = request("GET", $base . "/api/tenant/customers/" . $customerId, $cookieFile);
assertTrue(
    abs($json["payment_summary"]["total_paid"] - 500) < 0.01,
    "Customer payment summary paid 500"
);

fwrite(STDOUT, "\nPayment smoke tests passed.\n");

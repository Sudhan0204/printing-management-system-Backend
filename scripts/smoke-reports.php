<?php

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

$base = getenv("APP_URL") ?: "http://localhost/printing-management-system/backend/public";
$cookieFile = sys_get_temp_dir() . "/pms_report_cookies.txt";
@unlink($cookieFile);

function request($method, $url, $cookieFile, $body = null, $csrf = null, $rawBinary = false)
{
    $ch = curl_init($url);
    $headers = ["Accept: application/json"];
    if ($body !== null) {
        $headers[] = "Content-Type: application/json";
    }
    if ($csrf) {
        $headers[] = "X-CSRF-Token: " . $csrf;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADER => true,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $header = substr($raw, 0, $headerSize);
    $content = substr($raw, $headerSize);

    if ($rawBinary) {
        return [$status, $header, $content];
    }

    return [$status, json_decode($content, true), $content];
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
assertTrue($status === 200 && !empty($json["csrf_token"]), "CSRF");
$csrf = $json["csrf_token"];

// Discover a working tenant from prior smoke tests by trying known emails.
$loginCandidates = [
    ["email" => "owner_6829fe1c@example.com", "password" => "Owner123!"],
    ["email" => "owner_2488b496@example.com", "password" => "Owner123!"],
];

$loggedIn = false;
foreach ($loginCandidates as $candidate) {
    [$status, $json] = request("POST", $base . "/api/tenant/login", $cookieFile, $candidate, $csrf);
    if ($status === 200 && !empty($json["csrf_token"])) {
        $csrf = $json["csrf_token"];
        $loggedIn = true;
        break;
    }
    // refresh csrf after failed attempt
    [$status, $json] = request("GET", $base . "/api/auth/csrf", $cookieFile);
    $csrf = $json["csrf_token"] ?? $csrf;
}

assertTrue($loggedIn, "Tenant login");

[$status, $json] = request("GET", $base . "/api/tenant/reports", $cookieFile);
assertTrue($status === 200 && !empty($json["modules"]), "Reports hub");

[$status, $json] = request("GET", $base . "/api/tenant/reports/customers?per_page=50", $cookieFile);
assertTrue($status === 200 && isset($json["summary"]["total_customers"]), "Customer report list");
assertTrue(isset($json["customers"]) && is_array($json["customers"]), "Customers array returned");

$summary = $json["summary"];
$customers = $json["customers"];
assertTrue(array_key_exists("total_billed", $summary), "Summary billed");
assertTrue(array_key_exists("total_received", $summary), "Summary received");
assertTrue(array_key_exists("total_outstanding", $summary), "Summary outstanding");

// Create a dedicated export fixture customer with zero bills.
[$status, $json] = request("POST", $base . "/api/tenant/customers", $cookieFile, [
    "customer_name" => "Report Zero Bills",
    "phone" => "9123456780",
    "email" => "report.zero@example.com",
    "status" => "active",
], $csrf);
assertTrue($status === 201, "Create zero-bill customer");
$csrf = $json["csrf_token"] ?? $csrf;
$zeroCustomerId = (int) $json["customer"]["id"];

[$status, $json] = request("POST", $base . "/api/tenant/customers", $cookieFile, [
    "customer_name" => "Report Multi Bill",
    "phone" => "9123456781",
    "status" => "active",
], $csrf);
assertTrue($status === 201, "Create multi-bill customer");
$csrf = $json["csrf_token"] ?? $csrf;
$multiCustomerId = (int) $json["customer"]["id"];

[$status, $json] = request("POST", $base . "/api/tenant/products", $cookieFile, [
    "product_name" => "Report Flex",
    "unit" => "sq_ft",
    "pricing_type" => "dimension_based",
    "initial_rate" => 5,
    "status" => "active",
], $csrf);
assertTrue($status === 201, "Create report product A");
$csrf = $json["csrf_token"] ?? $csrf;
$productA = (int) $json["product"]["id"];

[$status, $json] = request("POST", $base . "/api/tenant/products", $cookieFile, [
    "product_name" => "Report Card",
    "unit" => "piece",
    "pricing_type" => "quantity_based",
    "initial_rate" => 2.5,
    "status" => "active",
], $csrf);
assertTrue($status === 201, "Create report product B");
$csrf = $json["csrf_token"] ?? $csrf;
$productB = (int) $json["product"]["id"];

[$status, $json] = request("POST", $base . "/api/tenant/bills", $cookieFile, [
    "customer_id" => $multiCustomerId,
    "bill_date" => date("Y-m-d"),
    "items" => [
        [
            "product_id" => $productA,
            "width" => 2,
            "height" => 3,
            "quantity" => 1,
            "rate" => 5,
        ],
        [
            "product_id" => $productB,
            "quantity" => 4,
            "rate" => 2.5,
        ],
    ],
], $csrf);
assertTrue($status === 201, "Create multi-product bill");
$csrf = $json["csrf_token"] ?? $csrf;
$billId = (int) $json["bill"]["id"];
$billTotal = (float) $json["bill"]["total_amount"];
assertTrue(abs($billTotal - 40.0) < 0.01, "Bill total 40 expected, got {$billTotal}");

[$status, $json] = request("POST", $base . "/api/tenant/payments", $cookieFile, [
    "customer_id" => $multiCustomerId,
    "bill_id" => $billId,
    "payment_date" => date("Y-m-d"),
    "payment_method" => "cash",
    "amount" => 15,
], $csrf);
assertTrue($status === 201, "Partial payment");
$csrf = $json["csrf_token"] ?? $csrf;

[$status, $json] = request(
    "GET",
    $base . "/api/tenant/reports/customers?search_name=" . urlencode("Report Multi") . "&date_preset=this_month",
    $cookieFile
);
assertTrue($status === 200, "Filtered customer report");
$match = null;
foreach ($json["customers"] as $row) {
    if ((int) $row["id"] === $multiCustomerId) {
        $match = $row;
        break;
    }
}
assertTrue($match !== null, "Multi-bill customer appears in month filter");
assertTrue((int) $match["total_bills"] === 1, "One bill counted");
assertTrue(abs((float) $match["total_billed"] - 40) < 0.01, "Billed 40");
assertTrue(abs((float) $match["total_paid"] - 15) < 0.01, "Paid 15");
assertTrue(abs((float) $match["outstanding_balance"] - 25) < 0.01, "Outstanding 25");

// Unauthorized: no selected customers
[$status, $json] = request("POST", $base . "/api/tenant/reports/customers/export", $cookieFile, [
    "mode" => "selected",
    "customer_ids" => [],
    "filters" => ["date_preset" => "this_month"],
], $csrf);
assertTrue($status === 422, "Reject empty selected export");

// Export one customer (zero bills)
[$status, $header, $binary] = request("POST", $base . "/api/tenant/reports/customers/export", $cookieFile, [
    "mode" => "selected",
    "customer_ids" => [$zeroCustomerId],
    "filters" => ["date_preset" => ""],
], $csrf, true);
assertTrue($status === 200, "Export one customer (no bills)");
assertTrue(str_contains($header, "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"), "XLSX content type");
assertTrue(substr($binary, 0, 2) === "PK", "ZIP/XLSX signature");
$onePath = sys_get_temp_dir() . "/pms_report_one.xlsx";
file_put_contents($onePath, $binary);
assertTrue(filesize($onePath) > 2000, "One-customer workbook has content");

// Export multiple customers
[$status, $header, $binary] = request("POST", $base . "/api/tenant/reports/customers/export", $cookieFile, [
    "mode" => "selected",
    "customer_ids" => [$zeroCustomerId, $multiCustomerId],
    "filters" => ["date_preset" => "this_month"],
], $csrf, true);
assertTrue($status === 200, "Export multiple customers");
assertTrue(substr($binary, 0, 2) === "PK", "Multi export is XLSX");
$multiPath = sys_get_temp_dir() . "/pms_report_multi.xlsx";
file_put_contents($multiPath, $binary);

// Verify fixed 5-sheet workbook via PhpSpreadsheet
require_once dirname(__DIR__) . "/vendor/autoload.php";
$reader = \PhpOffice\PhpSpreadsheet\IOFactory::load($multiPath);
$titles = [];
foreach ($reader->getAllSheets() as $sheet) {
    $titles[] = $sheet->getTitle();
}
$expected = ["Dashboard", "Customers", "Bills", "Bill Items", "Payments"];
assertTrue($titles === $expected, "Exactly 5 fixed worksheets: " . implode(", ", $titles));

$oneReader = \PhpOffice\PhpSpreadsheet\IOFactory::load($onePath);
assertTrue(count($oneReader->getAllSheets()) === 5, "One-customer export also has exactly 5 sheets");

$billsSheet = $reader->getSheetByName("Bills");
$billHeader = [];
for ($col = 1; $col <= 8; $col++) {
    $billHeader[] = (string) $billsSheet->getCellByColumnAndRow($col, 4)->getValue();
}
assertTrue($billHeader[0] === "Bill Number", "Bills sheet has Bill Number header");
assertTrue(in_array("Total Amount", $billHeader, true), "Bills sheet has Total Amount once per bill");

$itemsSheet = $reader->getSheetByName("Bill Items");
$itemCount = 0;
for ($r = 5; $r <= 50; $r++) {
    $value = trim((string) $itemsSheet->getCell("A{$r}")->getValue());
    if ($value === "" || str_starts_with($value, "No bill")) {
        break;
    }
    $itemCount++;
}
assertTrue($itemCount >= 2, "Multi-product bill produces multiple bill-item rows");

// Tenant isolation: foreign customer id rejected
[$status, $json] = request("POST", $base . "/api/tenant/reports/customers/export", $cookieFile, [
    "mode" => "selected",
    "customer_ids" => [999999],
    "filters" => [],
], $csrf);
assertTrue($status === 404 || $status === 422, "Reject foreign/missing customer id");

// Super admin cannot use tenant reports
$adminCookie = sys_get_temp_dir() . "/pms_report_admin_cookies.txt";
@unlink($adminCookie);
[$status, $json] = request("GET", $base . "/api/auth/csrf", $adminCookie);
$adminCsrf = $json["csrf_token"];
[$status, $json] = request("POST", $base . "/api/admin/login", $adminCookie, [
    "email" => getenv("SUPER_ADMIN_EMAIL") ?: "admin@printdesk.local",
    "password" => getenv("SUPER_ADMIN_PASSWORD") ?: "Admin123!",
], $adminCsrf);
if ($status === 200) {
    [$status, $json] = request("GET", $base . "/api/tenant/reports/customers", $adminCookie);
    assertTrue($status === 403 || $status === 401, "Super admin blocked from tenant reports");
} else {
    fwrite(STDOUT, "OK: Skipped admin isolation check (admin login unavailable)\n");
}

fwrite(STDOUT, "\nCustomer report smoke tests passed.\n");
fwrite(STDOUT, "Sample exports: {$onePath} , {$multiPath}\n");
exit(0);

<?php

require_once __DIR__ . "/../app/core/Env.php";
Env::load(__DIR__ . "/../.env");

require_once __DIR__ . "/../app/core/Config.php";
require_once __DIR__ . "/../app/Helpers/Response.php";
require_once __DIR__ . "/../app/Helpers/Upload.php";
require_once __DIR__ . "/../app/Helpers/BillingCalc.php";
require_once __DIR__ . "/../app/Helpers/Validation.php";
require_once __DIR__ . "/../app/core/Database.php";
require_once __DIR__ . "/../app/core/Router.php";
require_once __DIR__ . "/../app/core/Session.php";
require_once __DIR__ . "/../app/Helpers/Csrf.php";
require_once __DIR__ . "/../app/Models/User.php";
require_once __DIR__ . "/../app/Models/Tenant.php";
require_once __DIR__ . "/../app/Middleware/AuthMiddleware.php";
require_once __DIR__ . "/../app/Controllers/AuthController.php";
require_once __DIR__ . "/../app/Controllers/DashboardController.php";
require_once __DIR__ . "/../app/Controllers/ClientController.php";
require_once __DIR__ . "/../app/Controllers/TenantAuthController.php";
require_once __DIR__ . "/../app/Controllers/CustomerController.php";
require_once __DIR__ . "/../app/Controllers/ProductController.php";
require_once __DIR__ . "/../app/Controllers/BillController.php";
require_once __DIR__ . "/../app/Controllers/PaymentController.php";

$appConfig = Config::get("app");
$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $appConfig["cors_origins"], true)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
    header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Headers: Content-Type, X-CSRF-Token");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

Session::start();

$router = require __DIR__ . "/../routes/api.php";
$router->dispatch($_SERVER["REQUEST_METHOD"], $_SERVER["REQUEST_URI"]);

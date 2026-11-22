<?php

require_once __DIR__ . "/../app/core/Config.php";
require_once __DIR__ . "/../app/Helpers/Response.php";
require_once __DIR__ . "/../app/core/Database.php";
require_once __DIR__ . "/../app/core/Router.php";
require_once __DIR__ . "/../app/core/Session.php";
require_once __DIR__ . "/../app/Helpers/Csrf.php";
require_once __DIR__ . "/../app/Models/User.php";
require_once __DIR__ . "/../app/Middleware/AuthMiddleware.php";
require_once __DIR__ . "/../app/Controllers/AuthController.php";
require_once __DIR__ . "/../app/Controllers/DashboardController.php";

$appConfig = Config::get("app");
$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $appConfig["cors_origins"], true)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
    header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Headers: Content-Type, X-CSRF-Token");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

Session::start();

$router = require __DIR__ . "/../routes/api.php";
$router->dispatch($_SERVER["REQUEST_METHOD"], $_SERVER["REQUEST_URI"]);

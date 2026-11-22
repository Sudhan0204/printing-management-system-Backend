<?php

$router = new Router();

$authController = new AuthController();
$dashboardController = new DashboardController();

$router->get("/api/health", function () {
    $database = new Database();
    $database->connect();

    Response::json([
        "success" => true,
        "message" => "API is running",
    ]);
});

$router->get("/api/auth/csrf", [$authController, "csrf"]);
$router->post("/api/auth/login", [$authController, "login"]);
$router->get("/api/auth/me", [$authController, "me"]);
$router->post("/api/auth/logout", [$authController, "logout"]);
$router->get("/api/dashboard/summary", [$dashboardController, "summary"]);

return $router;

<?php

$router = new Router();

$authController = new AuthController();
$dashboardController = new DashboardController();
$clientController = new ClientController();
$tenantAuthController = new TenantAuthController();

$router->get("/api/health", function () {
    $database = new Database();
    $database->connect();

    Response::json([
        "success" => true,
        "message" => "API is running",
    ]);
});

// Shared CSRF bootstrap
$router->get("/api/auth/csrf", [$authController, "csrf"]);
$router->get("/api/admin/csrf", [$authController, "csrf"]);
$router->get("/api/tenant/csrf", [$authController, "csrf"]);

// Super Admin authentication (legacy + preferred paths)
$router->post("/api/auth/login", [$authController, "login"]);
$router->get("/api/auth/me", [$authController, "me"]);
$router->post("/api/auth/logout", [$authController, "logout"]);

$router->post("/api/admin/login", [$authController, "login"]);
$router->get("/api/admin/me", [$authController, "me"]);
$router->post("/api/admin/logout", [$authController, "logout"]);
$router->get("/api/admin/dashboard", [$dashboardController, "summary"]);
$router->get("/api/dashboard/summary", [$dashboardController, "summary"]);

// Super Admin client (tenant) management
$router->get("/api/admin/clients", [$clientController, "index"]);
$router->post("/api/admin/clients", [$clientController, "store"]);
$router->get("/api/admin/clients/{id}", [$clientController, "show"]);
$router->put("/api/admin/clients/{id}", [$clientController, "update"]);
$router->post("/api/admin/clients/{id}", [$clientController, "update"]); // multipart-friendly
$router->patch("/api/admin/clients/{id}/status", [$clientController, "updateStatus"]);
$router->post("/api/admin/clients/{id}/status", [$clientController, "updateStatus"]);
$router->patch("/api/admin/clients/{id}/password", [$clientController, "updatePassword"]);
$router->post("/api/admin/clients/{id}/password", [$clientController, "updatePassword"]);

// Tenant authentication + dashboard
$router->post("/api/tenant/login", [$tenantAuthController, "login"]);
$router->get("/api/tenant/me", [$tenantAuthController, "me"]);
$router->post("/api/tenant/logout", [$tenantAuthController, "logout"]);
$router->get("/api/tenant/dashboard", [$tenantAuthController, "dashboard"]);

return $router;

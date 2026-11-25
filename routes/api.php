<?php

$router = new Router();

$authController = new AuthController();
$dashboardController = new DashboardController();
$clientController = new ClientController();
$tenantAuthController = new TenantAuthController();
$customerController = new CustomerController();
$productController = new ProductController();
$billController = new BillController();

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
$router->post("/api/admin/clients/{id}", [$clientController, "update"]);
$router->patch("/api/admin/clients/{id}/status", [$clientController, "updateStatus"]);
$router->post("/api/admin/clients/{id}/status", [$clientController, "updateStatus"]);
$router->patch("/api/admin/clients/{id}/password", [$clientController, "updatePassword"]);
$router->post("/api/admin/clients/{id}/password", [$clientController, "updatePassword"]);

// Tenant authentication + dashboard
$router->post("/api/tenant/login", [$tenantAuthController, "login"]);
$router->get("/api/tenant/me", [$tenantAuthController, "me"]);
$router->post("/api/tenant/logout", [$tenantAuthController, "logout"]);
$router->get("/api/tenant/dashboard", [$tenantAuthController, "dashboard"]);

// Tenant customers
$router->get("/api/tenant/customers", [$customerController, "index"]);
$router->post("/api/tenant/customers", [$customerController, "store"]);
$router->get("/api/tenant/customers/{id}", [$customerController, "show"]);
$router->put("/api/tenant/customers/{id}", [$customerController, "update"]);
$router->post("/api/tenant/customers/{id}", [$customerController, "update"]);
$router->patch("/api/tenant/customers/{id}/status", [$customerController, "updateStatus"]);
$router->post("/api/tenant/customers/{id}/status", [$customerController, "updateStatus"]);

// Tenant products + rates
$router->get("/api/tenant/products", [$productController, "index"]);
$router->post("/api/tenant/products", [$productController, "store"]);
$router->get("/api/tenant/products/{id}", [$productController, "show"]);
$router->put("/api/tenant/products/{id}", [$productController, "update"]);
$router->post("/api/tenant/products/{id}", [$productController, "update"]);
$router->patch("/api/tenant/products/{id}/status", [$productController, "updateStatus"]);
$router->post("/api/tenant/products/{id}/status", [$productController, "updateStatus"]);
$router->get("/api/tenant/products/{id}/rates", [$productController, "rates"]);
$router->post("/api/tenant/products/{id}/rates", [$productController, "storeRate"]);

// Tenant billing
$router->get("/api/tenant/bills", [$billController, "index"]);
$router->post("/api/tenant/bills", [$billController, "store"]);
$router->get("/api/tenant/bills/{id}", [$billController, "show"]);

return $router;

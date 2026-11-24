<?php

class TenantAuthController
{
    private $userModel;
    private $tenantModel;

    public function __construct()
    {
        $this->userModel = new User();
        $this->tenantModel = new Tenant();
    }

    public function login()
    {
        Csrf::validateRequest();

        $data = json_decode(file_get_contents("php://input"), true);

        if (!is_array($data)) {
            Response::json([
                "success" => false,
                "message" => "Invalid request body",
            ], 400);
        }

        $email = strtolower(trim($data["email"] ?? ""));
        $password = $data["password"] ?? "";

        if ($email === "" || $password === "") {
            Response::json([
                "success" => false,
                "message" => "Email and password are required",
            ], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json([
                "success" => false,
                "message" => "Enter a valid email address",
            ], 400);
        }

        if (!is_string($password) || strlen($password) > 128) {
            Response::json([
                "success" => false,
                "message" => "Invalid email or password",
            ], 401);
        }

        $user = $this->userModel->findByEmail($email);

        if (!$user || !password_verify($password, $user["password"])) {
            Response::json([
                "success" => false,
                "message" => "Invalid email or password",
            ], 401);
        }

        // Super Admin must use /admin/login — never tenant login.
        if ($user["role"] === "super_admin" || empty($user["tenant_id"])) {
            Response::json([
                "success" => false,
                "message" => "Invalid email or password",
            ], 401);
        }

        if ($user["status"] !== "active") {
            Response::json([
                "success" => false,
                "message" => "Account is inactive",
            ], 403);
        }

        $tenant = $this->tenantModel->findById($user["tenant_id"]);

        if (!$tenant) {
            Response::json([
                "success" => false,
                "message" => "Invalid email or password",
            ], 401);
        }

        if ($tenant["status"] !== "active") {
            Response::json([
                "success" => false,
                "message" => "This business account is suspended. Contact support.",
            ], 403);
        }

        Session::regenerate();

        $_SESSION["user"] = [
            "id" => (int) $user["id"],
            "name" => $user["name"],
            "email" => $user["email"],
            "role" => $user["role"],
            "tenant_id" => (int) $tenant["id"],
            "tenant" => AuthMiddleware::tenantPublic($tenant),
        ];
        $_SESSION["last_activity"] = time();

        Response::json([
            "success" => true,
            "message" => "Login successful",
            "user" => $_SESSION["user"],
            "csrf_token" => Csrf::rotate(),
        ]);
    }

    public function me()
    {
        $user = AuthMiddleware::requireTenantUser();

        Response::json([
            "success" => true,
            "user" => $user,
            "csrf_token" => Csrf::token(),
        ]);
    }

    public function logout()
    {
        Csrf::validateRequest();
        Session::destroy();

        Response::json([
            "success" => true,
            "message" => "Logged out successfully",
        ]);
    }

    public function dashboard()
    {
        $user = AuthMiddleware::requireTenantUser();

        Response::json([
            "success" => true,
            "dashboard" => [
                "business_name" => $user["tenant"]["business_name"],
                "logo" => $user["tenant"]["logo"],
                "logo_url" => $user["tenant"]["logo_url"],
                "status" => $user["tenant"]["status"],
                "owner_name" => $user["tenant"]["owner_name"],
            ],
            "user" => [
                "id" => $user["id"],
                "name" => $user["name"],
                "email" => $user["email"],
                "role" => $user["role"],
            ],
        ]);
    }
}

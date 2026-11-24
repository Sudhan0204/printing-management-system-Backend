<?php

class AuthController
{
    private $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    public function csrf()
    {
        Response::json([
            "success" => true,
            "csrf_token" => Csrf::token(),
        ]);
    }

    /**
     * Super Admin login. Kept under /api/auth/login and /api/admin/login.
     */
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

        if ($user["status"] !== "active") {
            Response::json([
                "success" => false,
                "message" => "Account is inactive",
            ], 403);
        }

        if ($user["role"] !== "super_admin" || $user["tenant_id"] !== null) {
            Response::json([
                "success" => false,
                "message" => "Super Admin access only",
            ], 403);
        }

        Session::regenerate();

        $_SESSION["user"] = [
            "id" => (int) $user["id"],
            "name" => $user["name"],
            "email" => $user["email"],
            "role" => $user["role"],
            "tenant_id" => null,
        ];
        $_SESSION["last_activity"] = time();

        Response::json([
            "success" => true,
            "message" => "Login successful",
            "user" => $_SESSION["user"],
            "csrf_token" => Csrf::rotate(),
        ]);
    }

    /**
     * Returns the current session user (admin or tenant) when authenticated.
     */
    public function me()
    {
        $user = AuthMiddleware::requireAuth();

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
}

<?php

class AuthMiddleware
{
    /**
     * Confirms the PHP session belongs to an active Super Admin.
     * The database is the source of truth. Frontend role checks are not.
     */
    public static function requireSuperAdmin()
    {
        $sessionUser = $_SESSION["user"] ?? null;

        if (!is_array($sessionUser) || empty($sessionUser["id"])) {
            Response::json([
                "success" => false,
                "message" => "Unauthenticated",
            ], 401);
        }

        $user = (new User())->findById($sessionUser["id"]);

        if (!$user) {
            Session::destroy();
            Response::json([
                "success" => false,
                "message" => "Unauthenticated",
            ], 401);
        }

        if ($user["status"] !== "active") {
            Session::destroy();
            Response::json([
                "success" => false,
                "message" => "Account is inactive",
            ], 403);
        }

        if ($user["role"] !== "super_admin") {
            Session::destroy();
            Response::json([
                "success" => false,
                "message" => "Super Admin access only",
            ], 403);
        }

        $_SESSION["user"] = [
            "id" => (int) $user["id"],
            "name" => $user["name"],
            "email" => $user["email"],
            "role" => $user["role"],
        ];

        return $_SESSION["user"];
    }
}

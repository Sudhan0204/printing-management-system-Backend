<?php

class AuthMiddleware
{
    /**
     * Any authenticated user (admin or tenant). Session is verified against the DB.
     */
    public static function requireAuth()
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

        $payload = [
            "id" => (int) $user["id"],
            "name" => $user["name"],
            "email" => $user["email"],
            "role" => $user["role"],
            "tenant_id" => $user["tenant_id"] !== null ? (int) $user["tenant_id"] : null,
        ];

        // Tenant users must belong to an active tenant.
        if ($user["role"] !== "super_admin") {
            if (empty($user["tenant_id"])) {
                Session::destroy();
                Response::json([
                    "success" => false,
                    "message" => "Unauthenticated",
                ], 401);
            }

            $tenant = (new Tenant())->findById($user["tenant_id"]);

            if (!$tenant) {
                Session::destroy();
                Response::json([
                    "success" => false,
                    "message" => "Unauthenticated",
                ], 401);
            }

            if ($tenant["status"] !== "active") {
                Session::destroy();
                Response::json([
                    "success" => false,
                    "message" => "This business account is suspended",
                ], 403);
            }

            $payload["tenant"] = self::tenantPublic($tenant);
        }

        $_SESSION["user"] = $payload;

        return $payload;
    }

    /**
     * Confirms the PHP session belongs to an active Super Admin.
     * The database is the source of truth. Frontend role checks are not.
     */
    public static function requireSuperAdmin()
    {
        $user = self::requireAuth();

        if ($user["role"] !== "super_admin" || $user["tenant_id"] !== null) {
            Response::json([
                "success" => false,
                "message" => "Super Admin access only",
            ], 403);
        }

        return $user;
    }

    /**
     * Confirms an authenticated tenant user. tenant_id always comes from the session/DB.
     */
    public static function requireTenantUser()
    {
        $user = self::requireAuth();

        if ($user["role"] === "super_admin" || empty($user["tenant_id"])) {
            Response::json([
                "success" => false,
                "message" => "Tenant access only",
            ], 403);
        }

        return $user;
    }

    public static function tenantPublic(array $tenant)
    {
        return [
            "id" => (int) $tenant["id"],
            "business_name" => $tenant["business_name"],
            "owner_name" => $tenant["owner_name"],
            "email" => $tenant["email"],
            "phone" => $tenant["phone"],
            "logo" => $tenant["logo"],
            "logo_url" => Upload::publicUrl($tenant["logo"]),
            "address" => $tenant["address"],
            "city" => $tenant["city"],
            "state" => $tenant["state"],
            "pincode" => $tenant["pincode"],
            "status" => $tenant["status"],
        ];
    }
}

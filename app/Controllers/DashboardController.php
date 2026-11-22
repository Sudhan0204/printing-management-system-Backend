<?php

class DashboardController
{
    public function summary()
    {
        AuthMiddleware::requireSuperAdmin();

        $totalUsers = (new User())->countAll();

        Response::json([
            "success" => true,
            "stats" => [
                "total_businesses" => 0,
                "active_businesses" => 0,
                "suspended_businesses" => 0,
                "total_users" => $totalUsers,
            ],
        ]);
    }
}

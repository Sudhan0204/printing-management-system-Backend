<?php

class DashboardController
{
    public function summary()
    {
        AuthMiddleware::requireSuperAdmin();

        $tenantModel = new Tenant();
        $userModel = new User();

        Response::json([
            "success" => true,
            "stats" => [
                "total_clients" => $tenantModel->countAll(),
                "active_clients" => $tenantModel->countByStatus("active"),
                "suspended_clients" => $tenantModel->countByStatus("suspended"),
                "total_tenant_users" => $userModel->countTenantUsers(),
                // Legacy keys kept for older frontend builds during transition.
                "total_businesses" => $tenantModel->countAll(),
                "active_businesses" => $tenantModel->countByStatus("active"),
                "suspended_businesses" => $tenantModel->countByStatus("suspended"),
                "total_users" => $userModel->countTenantUsers(),
            ],
        ]);
    }
}

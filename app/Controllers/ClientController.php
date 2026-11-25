<?php

class ClientController
{
    private $tenantModel;
    private $userModel;

    public function __construct()
    {
        $this->tenantModel = new Tenant();
        $this->userModel = new User();
    }

    public function index()
    {
        AuthMiddleware::requireSuperAdmin();

        $search = trim((string) ($_GET["search"] ?? ""));
        $status = strtolower(trim((string) ($_GET["status"] ?? "")));
        $page = max(1, (int) ($_GET["page"] ?? 1));
        $perPage = min(50, max(5, (int) ($_GET["per_page"] ?? 10)));

        if (strlen($search) > 100) {
            $search = substr($search, 0, 100);
        }

        $result = $this->tenantModel->listFiltered($search, $status, $page, $perPage);

        $clients = array_map(function ($tenant) {
            return $this->formatClient($tenant);
        }, $result["items"]);

        Response::json([
            "success" => true,
            "clients" => $clients,
            "pagination" => [
                "total" => $result["total"],
                "page" => $result["page"],
                "per_page" => $result["per_page"],
                "total_pages" => $result["total_pages"],
            ],
        ]);
    }

    public function show($params)
    {
        AuthMiddleware::requireSuperAdmin();

        $id = (int) ($params["id"] ?? 0);
        $tenant = $this->tenantModel->findById($id);

        if (!$tenant) {
            Response::json([
                "success" => false,
                "message" => "Client not found",
            ], 404);
        }

        $owner = $this->userModel->findOwnerByTenantId($id);

        Response::json([
            "success" => true,
            "client" => $this->formatClient($tenant, $owner),
        ]);
    }

    public function store()
    {
        AuthMiddleware::requireSuperAdmin();
        Csrf::validateRequest();

        $input = $this->normalizeInput($this->readInput());
        $errors = $this->validateClientInput($input, true);

        if ($errors) {
            Validation::fail($errors, reset($errors) ?: "Validation failed");
        }

        $loginEmail = $input["login_email"];

        if ($this->userModel->emailExists($loginEmail)) {
            Response::json([
                "success" => false,
                "message" => "Login email is already registered",
            ], 409);
        }

        $logoPath = Upload::storeLogo("logo");
        $pdo = $this->tenantModel->getPdo();

        try {
            $pdo->beginTransaction();

            $tenantId = $this->tenantModel->create([
                "business_name" => $input["business_name"],
                "owner_name" => $input["owner_name"],
                "email" => $input["email"],
                "phone" => $input["phone"],
                "logo" => $logoPath,
                "address" => $input["address"],
                "city" => $input["city"],
                "state" => $input["state"],
                "pincode" => $input["pincode"],
                "status" => $input["status"],
            ]);

            $created = $this->userModel->create(
                $input["owner_name"],
                $loginEmail,
                $input["password"],
                "business_owner",
                $tenantId,
                "active"
            );

            if (!$created) {
                throw new RuntimeException("Could not create owner account");
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($logoPath) {
                Upload::deleteLogo($logoPath);
            }

            Response::json([
                "success" => false,
                "message" => "Could not create client. Please try again.",
            ], 500);
        }

        $tenant = $this->tenantModel->findById($tenantId);
        $owner = $this->userModel->findOwnerByTenantId($tenantId);

        Response::json([
            "success" => true,
            "message" => "Client created successfully",
            "client" => $this->formatClient($tenant, $owner),
        ], 201);
    }

    public function update($params)
    {
        AuthMiddleware::requireSuperAdmin();
        Csrf::validateRequest();

        $id = (int) ($params["id"] ?? 0);
        $tenant = $this->tenantModel->findById($id);

        if (!$tenant) {
            Response::json([
                "success" => false,
                "message" => "Client not found",
            ], 404);
        }

        $input = $this->normalizeInput($this->readInput());
        $errors = $this->validateClientInput($input, false);

        if ($errors) {
            Validation::fail($errors, reset($errors) ?: "Validation failed");
        }

        $owner = $this->userModel->findOwnerByTenantId($id);
        $loginEmail = $input["login_email"] !== ""
            ? $input["login_email"]
            : strtolower(trim((string) ($owner["email"] ?? "")));

        if ($loginEmail === "" || !filter_var($loginEmail, FILTER_VALIDATE_EMAIL)) {
            Response::json([
                "success" => false,
                "message" => "A valid login email is required",
            ], 422);
        }

        if ($owner && $this->userModel->emailExists($loginEmail, $owner["id"])) {
            Response::json([
                "success" => false,
                "message" => "Login email is already registered",
            ], 409);
        }

        if (!$owner && $this->userModel->emailExists($loginEmail)) {
            Response::json([
                "success" => false,
                "message" => "Login email is already registered",
            ], 409);
        }

        $removeLogo = filter_var($input["remove_logo"] ?? false, FILTER_VALIDATE_BOOLEAN);
        $newLogo = Upload::storeLogo("logo");
        $oldLogo = $tenant["logo"];

        $pdo = $this->tenantModel->getPdo();

        try {
            $pdo->beginTransaction();

            $this->tenantModel->update($id, [
                "business_name" => $input["business_name"],
                "owner_name" => $input["owner_name"],
                "email" => $input["email"],
                "phone" => $input["phone"],
                "address" => $input["address"],
                "city" => $input["city"],
                "state" => $input["state"],
                "pincode" => $input["pincode"],
                "status" => $input["status"],
            ]);

            if ($newLogo) {
                $this->tenantModel->updateLogo($id, $newLogo);
            } elseif ($removeLogo) {
                $this->tenantModel->updateLogo($id, null);
            }

            if ($owner) {
                $this->userModel->updateProfile($owner["id"], $input["owner_name"], $loginEmail);
            } else {
                // Recover edge case: tenant without owner user.
                if (empty($input["password"]) || strlen($input["password"]) < 8) {
                    throw new RuntimeException("Owner account missing; provide a password to recreate it.");
                }

                $this->userModel->create(
                    $input["owner_name"],
                    $loginEmail,
                    $input["password"],
                    "business_owner",
                    $id,
                    "active"
                );
            }

            if (!empty($input["password"])) {
                if (!$owner) {
                    // created above
                } else {
                    $passwordErrors = $this->validatePasswordPair(
                        $input["password"],
                        $input["password_confirmation"] ?? $input["password"]
                    );

                    if ($passwordErrors) {
                        throw new InvalidArgumentException($passwordErrors[0]);
                    }

                    $this->userModel->updatePassword($owner["id"], $input["password"]);
                }
            }

            $pdo->commit();
        } catch (InvalidArgumentException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($newLogo) {
                Upload::deleteLogo($newLogo);
            }

            Response::json([
                "success" => false,
                "message" => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($newLogo) {
                Upload::deleteLogo($newLogo);
            }

            Response::json([
                "success" => false,
                "message" => "Could not update client. Please try again.",
            ], 500);
        }

        if ($newLogo && $oldLogo) {
            Upload::deleteLogo($oldLogo);
        } elseif ($removeLogo && $oldLogo && !$newLogo) {
            Upload::deleteLogo($oldLogo);
        }

        $tenant = $this->tenantModel->findById($id);
        $owner = $this->userModel->findOwnerByTenantId($id);

        Response::json([
            "success" => true,
            "message" => "Client updated successfully",
            "client" => $this->formatClient($tenant, $owner),
        ]);
    }

    public function updateStatus($params)
    {
        AuthMiddleware::requireSuperAdmin();
        Csrf::validateRequest();

        $id = (int) ($params["id"] ?? 0);
        $tenant = $this->tenantModel->findById($id);

        if (!$tenant) {
            Response::json([
                "success" => false,
                "message" => "Client not found",
            ], 404);
        }

        $data = json_decode(file_get_contents("php://input"), true);

        if (!is_array($data)) {
            Response::json([
                "success" => false,
                "message" => "Invalid request body",
            ], 400);
        }

        $status = strtolower(trim((string) ($data["status"] ?? "")));

        if (!in_array($status, ["active", "suspended"], true)) {
            Response::json([
                "success" => false,
                "message" => "Status must be active or suspended",
            ], 422);
        }

        $this->tenantModel->updateStatus($id, $status);
        $tenant = $this->tenantModel->findById($id);
        $owner = $this->userModel->findOwnerByTenantId($id);

        Response::json([
            "success" => true,
            "message" => $status === "suspended"
                ? "Client suspended successfully"
                : "Client activated successfully",
            "client" => $this->formatClient($tenant, $owner),
        ]);
    }

    public function updatePassword($params)
    {
        AuthMiddleware::requireSuperAdmin();
        Csrf::validateRequest();

        $id = (int) ($params["id"] ?? 0);
        $tenant = $this->tenantModel->findById($id);

        if (!$tenant) {
            Response::json([
                "success" => false,
                "message" => "Client not found",
            ], 404);
        }

        $owner = $this->userModel->findOwnerByTenantId($id);

        if (!$owner) {
            Response::json([
                "success" => false,
                "message" => "Owner account not found for this client",
            ], 404);
        }

        $data = json_decode(file_get_contents("php://input"), true);

        if (!is_array($data)) {
            Response::json([
                "success" => false,
                "message" => "Invalid request body",
            ], 400);
        }

        $password = (string) ($data["password"] ?? "");
        $confirmation = (string) ($data["password_confirmation"] ?? "");
        $errors = $this->validatePasswordPair($password, $confirmation);

        if ($errors) {
            Validation::fail($errors, reset($errors) ?: "Validation failed");
        }

        $this->userModel->updatePassword($owner["id"], $password);

        Response::json([
            "success" => true,
            "message" => "Password updated successfully",
        ]);
    }

    private function readInput()
    {
        $contentType = $_SERVER["CONTENT_TYPE"] ?? $_SERVER["HTTP_CONTENT_TYPE"] ?? "";

        if (stripos($contentType, "multipart/form-data") !== false) {
            return $_POST;
        }

        if (!empty($_POST)) {
            return $_POST;
        }

        $json = json_decode(file_get_contents("php://input"), true);

        return is_array($json) ? $json : [];
    }

    private function normalizeInput(array $input)
    {
        $fields = [
            "business_name",
            "owner_name",
            "email",
            "login_email",
            "phone",
            "address",
            "city",
            "state",
            "pincode",
            "status",
            "password",
            "password_confirmation",
            "remove_logo",
        ];

        $normalized = [];

        foreach ($fields as $field) {
            $normalized[$field] = isset($input[$field]) ? trim((string) $input[$field]) : "";
        }

        $normalized["email"] = strtolower($normalized["email"]);
        $normalized["login_email"] = strtolower($normalized["login_email"]);
        $normalized["phone"] = Validation::normalizePhone($normalized["phone"]);
        $normalized["status"] = $normalized["status"] !== ""
            ? strtolower($normalized["status"])
            : "active";

        return $normalized;
    }

    private function validateClientInput(array $input, $requirePassword)
    {
        $errors = [];

        $businessError = Validation::length(
            $input["business_name"],
            Validation::LIMITS["business_name"]["min"],
            Validation::LIMITS["business_name"]["max"],
            "Business name"
        );
        if ($businessError) {
            $errors["business_name"] = $businessError;
        }

        $ownerError = Validation::length(
            $input["owner_name"],
            Validation::LIMITS["owner_name"]["min"],
            Validation::LIMITS["owner_name"]["max"],
            "Owner name"
        );
        if ($ownerError) {
            $errors["owner_name"] = $ownerError;
        }

        $emailError = Validation::email($input["email"], true);
        if ($emailError) {
            $errors["email"] = $emailError;
        }

        if ($requirePassword || $input["login_email"] !== "") {
            $loginError = Validation::email($input["login_email"], true);
            if ($loginError) {
                $errors["login_email"] = $loginError === "This field is required."
                    ? "Login email is required."
                    : $loginError;
            }
        }

        $phoneError = Validation::indianMobile($input["phone"]);
        if ($phoneError) {
            $errors["phone"] = $phoneError;
        }

        $addressError = Validation::length(
            $input["address"],
            1,
            Validation::LIMITS["address"]["max"],
            "Address"
        );
        if ($addressError) {
            $errors["address"] = $addressError;
        }

        $cityError = Validation::length(
            $input["city"],
            Validation::LIMITS["city"]["min"],
            Validation::LIMITS["city"]["max"],
            "City"
        );
        if ($cityError) {
            $errors["city"] = $cityError;
        }

        $stateError = Validation::length(
            $input["state"],
            Validation::LIMITS["state"]["min"],
            Validation::LIMITS["state"]["max"],
            "State"
        );
        if ($stateError) {
            $errors["state"] = $stateError;
        }

        $pincodeError = Validation::indianPincode($input["pincode"]);
        if ($pincodeError) {
            $errors["pincode"] = $pincodeError;
        }

        if (!in_array($input["status"], ["active", "suspended"], true)) {
            $errors["status"] = "Status must be active or suspended.";
        }

        if ($requirePassword || $input["password"] !== "") {
            $passwordError = Validation::strongPassword(
                $input["password"],
                $input["password_confirmation"]
            );

            if ($passwordError) {
                if (str_contains($passwordError, "confirmation")) {
                    $errors["password_confirmation"] = $passwordError;
                } else {
                    $errors["password"] = $passwordError;
                }
            }
        }

        return $errors;
    }

    private function validatePasswordPair($password, $confirmation)
    {
        $error = Validation::strongPassword($password, $confirmation);

        return $error ? ["password" => $error] : [];
    }

    private function formatClient(array $tenant, $owner = null)
    {
        $formatted = [
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
            "created_at" => $tenant["created_at"],
            "updated_at" => $tenant["updated_at"],
        ];

        if ($owner) {
            $formatted["login_email"] = $owner["email"];
            $formatted["owner_user_id"] = (int) $owner["id"];
        }

        return $formatted;
    }
}

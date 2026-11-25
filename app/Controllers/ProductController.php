<?php

class ProductController
{
    private $db;

    public function __construct()
    {
        $this->db = (new Database())->connect();
    }

    public function index()
    {
        $user = AuthMiddleware::requireTenantUser();
        $tenantId = (int) $user["tenant_id"];

        $search = trim((string) ($_GET["search"] ?? ""));
        $status = strtolower(trim((string) ($_GET["status"] ?? "")));
        $page = max(1, (int) ($_GET["page"] ?? 1));
        $perPage = min(50, max(5, (int) ($_GET["per_page"] ?? 10)));

        $where = ["p.tenant_id = :tenant_id"];
        $params = [":tenant_id" => $tenantId];

        if ($search !== "") {
            $where[] = "(p.product_name LIKE :search OR p.category LIKE :search)";
            $params[":search"] = "%" . substr($search, 0, 100) . "%";
        }

        if (in_array($status, ["active", "inactive"], true)) {
            $where[] = "p.status = :status";
            $params[":status"] = $status;
        }

        $whereSql = implode(" AND ", $where);
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM products p WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT p.*,
                       (
                           SELECT r.rate FROM product_rates r
                           WHERE r.product_id = p.id AND r.tenant_id = p.tenant_id AND r.status = 'active'
                           ORDER BY r.effective_from DESC, r.id DESC
                           LIMIT 1
                       ) AS current_rate
                FROM products p
                WHERE {$whereSql}
                ORDER BY p.product_name ASC, p.id DESC
                LIMIT {$perPage} OFFSET {$offset}";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        Response::json([
            "success" => true,
            "products" => array_map([$this, "format"], $statement->fetchAll()),
            "pagination" => [
                "total" => $total,
                "page" => $page,
                "per_page" => $perPage,
                "total_pages" => max(1, (int) ceil($total / $perPage)),
            ],
            "meta" => [
                "units" => BillingCalc::UNITS,
                "pricing_types" => BillingCalc::PRICING_TYPES,
            ],
        ]);
    }

    public function show($params)
    {
        $user = AuthMiddleware::requireTenantUser();
        $product = $this->findForTenant((int) ($params["id"] ?? 0), (int) $user["tenant_id"]);

        if (!$product) {
            Response::json(["success" => false, "message" => "Product not found"], 404);
        }

        Response::json(["success" => true, "product" => $this->format($product)]);
    }

    public function store()
    {
        $user = AuthMiddleware::requireTenantUser();
        Csrf::validateRequest();

        $input = $this->normalize($this->readJson());
        $errors = $this->validate($input);

        if ($errors) {
            Validation::fail($errors);
        }

        $rateError = Validation::money($input["initial_rate_raw"], "Initial rate", true);
        if ($rateError) {
            Validation::fail(["initial_rate" => $rateError]);
        }
        $initialRate = round((float) $input["initial_rate_raw"], 2);

        try {
            $this->db->beginTransaction();

            $sql = "INSERT INTO products
                        (tenant_id, product_name, category, description, unit, pricing_type, status)
                    VALUES
                        (:tenant_id, :product_name, :category, :description, :unit, :pricing_type, :status)";
            $statement = $this->db->prepare($sql);
            $statement->execute([
                ":tenant_id" => (int) $user["tenant_id"],
                ":product_name" => $input["product_name"],
                ":category" => $input["category"] !== "" ? $input["category"] : null,
                ":description" => $input["description"] !== "" ? $input["description"] : null,
                ":unit" => $input["unit"],
                ":pricing_type" => $input["pricing_type"],
                ":status" => $input["status"],
            ]);

            $productId = (int) $this->db->lastInsertId();

            $rateSql = "INSERT INTO product_rates (tenant_id, product_id, rate, effective_from, status)
                        VALUES (:tenant_id, :product_id, :rate, :effective_from, 'active')";
            $rateStmt = $this->db->prepare($rateSql);
            $rateStmt->execute([
                ":tenant_id" => (int) $user["tenant_id"],
                ":product_id" => $productId,
                ":rate" => $initialRate,
                ":effective_from" => $input["effective_from"] ?: date("Y-m-d"),
            ]);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            Response::json(["success" => false, "message" => "Could not create product"], 500);
        }

        $product = $this->findForTenant($productId, (int) $user["tenant_id"]);

        Response::json([
            "success" => true,
            "message" => "Product created successfully",
            "product" => $this->format($product),
        ], 201);
    }

    public function update($params)
    {
        $user = AuthMiddleware::requireTenantUser();
        Csrf::validateRequest();

        $id = (int) ($params["id"] ?? 0);
        $product = $this->findForTenant($id, (int) $user["tenant_id"]);

        if (!$product) {
            Response::json(["success" => false, "message" => "Product not found"], 404);
        }

        $input = $this->normalize($this->readJson());
        $errors = $this->validate($input, false);

        if ($errors) {
            Validation::fail($errors);
        }

        $sql = "UPDATE products SET
                    product_name = :product_name,
                    category = :category,
                    description = :description,
                    unit = :unit,
                    pricing_type = :pricing_type,
                    status = :status
                WHERE id = :id AND tenant_id = :tenant_id";
        $statement = $this->db->prepare($sql);
        $statement->execute([
            ":product_name" => $input["product_name"],
            ":category" => $input["category"] !== "" ? $input["category"] : null,
            ":description" => $input["description"] !== "" ? $input["description"] : null,
            ":unit" => $input["unit"],
            ":pricing_type" => $input["pricing_type"],
            ":status" => $input["status"],
            ":id" => $id,
            ":tenant_id" => (int) $user["tenant_id"],
        ]);

        $product = $this->findForTenant($id, (int) $user["tenant_id"]);

        Response::json([
            "success" => true,
            "message" => "Product updated successfully",
            "product" => $this->format($product),
        ]);
    }

    public function updateStatus($params)
    {
        $user = AuthMiddleware::requireTenantUser();
        Csrf::validateRequest();

        $id = (int) ($params["id"] ?? 0);
        $product = $this->findForTenant($id, (int) $user["tenant_id"]);

        if (!$product) {
            Response::json(["success" => false, "message" => "Product not found"], 404);
        }

        $data = $this->readJson();
        $status = strtolower(trim((string) ($data["status"] ?? "")));

        if (!in_array($status, ["active", "inactive"], true)) {
            Response::json(["success" => false, "message" => "Status must be active or inactive"], 422);
        }

        $statement = $this->db->prepare(
            "UPDATE products SET status = :status WHERE id = :id AND tenant_id = :tenant_id"
        );
        $statement->execute([
            ":status" => $status,
            ":id" => $id,
            ":tenant_id" => (int) $user["tenant_id"],
        ]);

        $product = $this->findForTenant($id, (int) $user["tenant_id"]);

        Response::json([
            "success" => true,
            "message" => $status === "active" ? "Product activated" : "Product deactivated",
            "product" => $this->format($product),
        ]);
    }

    public function rates($params)
    {
        $user = AuthMiddleware::requireTenantUser();
        $productId = (int) ($params["id"] ?? 0);
        $product = $this->findForTenant($productId, (int) $user["tenant_id"]);

        if (!$product) {
            Response::json(["success" => false, "message" => "Product not found"], 404);
        }

        $statement = $this->db->prepare(
            "SELECT id, product_id, rate, effective_from, status, created_at
             FROM product_rates
             WHERE product_id = :product_id AND tenant_id = :tenant_id
             ORDER BY effective_from DESC, id DESC"
        );
        $statement->execute([
            ":product_id" => $productId,
            ":tenant_id" => (int) $user["tenant_id"],
        ]);

        $rates = array_map(function ($row) {
            return [
                "id" => (int) $row["id"],
                "product_id" => (int) $row["product_id"],
                "rate" => (float) $row["rate"],
                "effective_from" => $row["effective_from"],
                "status" => $row["status"],
                "created_at" => $row["created_at"],
            ];
        }, $statement->fetchAll());

        Response::json([
            "success" => true,
            "product" => $this->format($product),
            "rates" => $rates,
            "current_rate" => $this->currentRateRow($productId, (int) $user["tenant_id"]),
        ]);
    }

    public function storeRate($params)
    {
        $user = AuthMiddleware::requireTenantUser();
        Csrf::validateRequest();

        $productId = (int) ($params["id"] ?? 0);
        $tenantId = (int) $user["tenant_id"];
        $product = $this->findForTenant($productId, $tenantId);

        if (!$product) {
            Response::json(["success" => false, "message" => "Product not found"], 404);
        }

        $data = $this->readJson();
        $rateRaw = $data["rate"] ?? "";
        $effectiveFrom = trim((string) ($data["effective_from"] ?? date("Y-m-d")));

        $rateError = Validation::money($rateRaw, "Rate", true);
        if ($rateError) {
            Validation::fail(["rate" => $rateError]);
        }

        $rate = round((float) $rateRaw, 2);

        if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $effectiveFrom)) {
            Validation::fail(["effective_from" => "Enter a valid effective date."]);
        }

        try {
            $this->db->beginTransaction();

            // Keep history: deactivate previous active rates, insert the new current rate.
            $deactivate = $this->db->prepare(
                "UPDATE product_rates SET status = 'inactive'
                 WHERE product_id = :product_id AND tenant_id = :tenant_id AND status = 'active'"
            );
            $deactivate->execute([
                ":product_id" => $productId,
                ":tenant_id" => $tenantId,
            ]);

            $insert = $this->db->prepare(
                "INSERT INTO product_rates (tenant_id, product_id, rate, effective_from, status)
                 VALUES (:tenant_id, :product_id, :rate, :effective_from, 'active')"
            );
            $insert->execute([
                ":tenant_id" => $tenantId,
                ":product_id" => $productId,
                ":rate" => $rate,
                ":effective_from" => $effectiveFrom,
            ]);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            Response::json(["success" => false, "message" => "Could not save product rate"], 500);
        }

        Response::json([
            "success" => true,
            "message" => "New rate saved. Previous rates were kept in history.",
            "current_rate" => $this->currentRateRow($productId, $tenantId),
        ], 201);
    }

    private function findForTenant($id, $tenantId)
    {
        $sql = "SELECT p.*,
                       (
                           SELECT r.rate FROM product_rates r
                           WHERE r.product_id = p.id AND r.tenant_id = p.tenant_id AND r.status = 'active'
                           ORDER BY r.effective_from DESC, r.id DESC
                           LIMIT 1
                       ) AS current_rate
                FROM products p
                WHERE p.id = :id AND p.tenant_id = :tenant_id
                LIMIT 1";
        $statement = $this->db->prepare($sql);
        $statement->execute([":id" => $id, ":tenant_id" => $tenantId]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    private function currentRateRow($productId, $tenantId)
    {
        $statement = $this->db->prepare(
            "SELECT id, product_id, rate, effective_from, status, created_at
             FROM product_rates
             WHERE product_id = :product_id AND tenant_id = :tenant_id AND status = 'active'
             ORDER BY effective_from DESC, id DESC
             LIMIT 1"
        );
        $statement->execute([":product_id" => $productId, ":tenant_id" => $tenantId]);
        $row = $statement->fetch();

        if (!$row) {
            return null;
        }

        return [
            "id" => (int) $row["id"],
            "product_id" => (int) $row["product_id"],
            "rate" => (float) $row["rate"],
            "effective_from" => $row["effective_from"],
            "status" => $row["status"],
            "created_at" => $row["created_at"],
        ];
    }

    private function format(array $row)
    {
        return [
            "id" => (int) $row["id"],
            "product_name" => $row["product_name"],
            "category" => $row["category"],
            "description" => $row["description"],
            "unit" => $row["unit"],
            "unit_label" => BillingCalc::unitLabel($row["unit"]),
            "pricing_type" => $row["pricing_type"],
            "pricing_type_label" => BillingCalc::pricingLabel($row["pricing_type"]),
            "status" => $row["status"],
            "current_rate" => $row["current_rate"] !== null ? (float) $row["current_rate"] : null,
            "created_at" => $row["created_at"],
            "updated_at" => $row["updated_at"],
        ];
    }

    private function readJson()
    {
        $json = json_decode(file_get_contents("php://input"), true);
        return is_array($json) ? $json : [];
    }

    private function normalize(array $input)
    {
        $initialRate = $input["initial_rate"] ?? $input["rate"] ?? null;

        return [
            "product_name" => trim((string) ($input["product_name"] ?? "")),
            "category" => trim((string) ($input["category"] ?? "")),
            "description" => trim((string) ($input["description"] ?? "")),
            "unit" => strtolower(trim((string) ($input["unit"] ?? "piece"))),
            "pricing_type" => strtolower(trim((string) ($input["pricing_type"] ?? "fixed_price"))),
            "status" => strtolower(trim((string) ($input["status"] ?? "active"))) ?: "active",
            "initial_rate_raw" => $initialRate === null ? "" : (string) $initialRate,
            "effective_from" => trim((string) ($input["effective_from"] ?? "")),
        ];
    }

    private function validate(array $input, $forCreate = true)
    {
        $errors = [];

        $nameError = Validation::length(
            $input["product_name"],
            Validation::LIMITS["product_name"]["min"],
            Validation::LIMITS["product_name"]["max"],
            "Product name"
        );
        if ($nameError) {
            $errors["product_name"] = $nameError;
        }

        $categoryError = Validation::length(
            $input["category"],
            Validation::LIMITS["category"]["min"],
            Validation::LIMITS["category"]["max"],
            "Category",
            false
        );
        if ($categoryError) {
            $errors["category"] = $categoryError;
        }

        $descriptionError = Validation::length(
            $input["description"],
            Validation::LIMITS["description"]["min"],
            Validation::LIMITS["description"]["max"],
            "Description",
            false
        );
        if ($descriptionError) {
            $errors["description"] = $descriptionError;
        }

        if (!in_array($input["unit"], BillingCalc::UNITS, true)) {
            $errors["unit"] = "Select a valid unit.";
        }

        if (!in_array($input["pricing_type"], BillingCalc::PRICING_TYPES, true)) {
            $errors["pricing_type"] = "Select a valid pricing type.";
        }

        if (!in_array($input["status"], ["active", "inactive"], true)) {
            $errors["status"] = "Status must be active or inactive.";
        }

        if ($forCreate && $input["effective_from"] !== "" && !preg_match("/^\d{4}-\d{2}-\d{2}$/", $input["effective_from"])) {
            $errors["effective_from"] = "Enter a valid effective date.";
        }

        return $errors;
    }
}

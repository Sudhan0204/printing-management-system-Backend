<?php

class CustomerController
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

        $where = ["tenant_id = :tenant_id"];
        $params = [":tenant_id" => $tenantId];

        if ($search !== "") {
            $where[] = "(customer_name LIKE :search OR phone LIKE :search OR email LIKE :search OR city LIKE :search)";
            $params[":search"] = "%" . substr($search, 0, 100) . "%";
        }

        if (in_array($status, ["active", "inactive"], true)) {
            $where[] = "status = :status";
            $params[":status"] = $status;
        }

        $whereSql = implode(" AND ", $where);
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM customers WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT id, tenant_id, customer_name, phone, email, address, city, notes, status, created_at, updated_at
                FROM customers
                WHERE {$whereSql}
                ORDER BY customer_name ASC, id DESC
                LIMIT {$perPage} OFFSET {$offset}";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        Response::json([
            "success" => true,
            "customers" => array_map([$this, "format"], $statement->fetchAll()),
            "pagination" => [
                "total" => $total,
                "page" => $page,
                "per_page" => $perPage,
                "total_pages" => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function show($params)
    {
        $user = AuthMiddleware::requireTenantUser();
        $customer = $this->findForTenant((int) ($params["id"] ?? 0), (int) $user["tenant_id"]);

        if (!$customer) {
            Response::json(["success" => false, "message" => "Customer not found"], 404);
        }

        $tenantId = (int) $user["tenant_id"];
        $customerId = (int) $customer["id"];

        $totalsStmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(total_amount), 0) AS total_billed,
                COALESCE(SUM(paid_amount), 0) AS total_paid,
                COALESCE(SUM(balance_amount), 0) AS outstanding_balance
             FROM bills
             WHERE tenant_id = :tenant_id AND customer_id = :customer_id"
        );
        $totalsStmt->execute([":tenant_id" => $tenantId, ":customer_id" => $customerId]);
        $totals = $totalsStmt->fetch() ?: [];

        $billsStmt = $this->db->prepare(
            "SELECT id, bill_number, bill_date, total_amount, paid_amount, balance_amount, payment_status
             FROM bills
             WHERE tenant_id = :tenant_id AND customer_id = :customer_id
             ORDER BY bill_date DESC, id DESC
             LIMIT 50"
        );
        $billsStmt->execute([":tenant_id" => $tenantId, ":customer_id" => $customerId]);

        $paymentsStmt = $this->db->prepare(
            "SELECT p.id, p.payment_date, p.payment_method, p.amount, p.reference_number,
                    b.bill_number, b.id AS bill_id
             FROM payments p
             INNER JOIN bills b ON b.id = p.bill_id AND b.tenant_id = p.tenant_id
             WHERE p.tenant_id = :tenant_id AND p.customer_id = :customer_id
             ORDER BY p.payment_date DESC, p.id DESC
             LIMIT 50"
        );
        $paymentsStmt->execute([":tenant_id" => $tenantId, ":customer_id" => $customerId]);

        Response::json([
            "success" => true,
            "customer" => $this->format($customer),
            "payment_summary" => [
                "total_billed" => (float) ($totals["total_billed"] ?? 0),
                "total_paid" => (float) ($totals["total_paid"] ?? 0),
                "outstanding_balance" => (float) ($totals["outstanding_balance"] ?? 0),
            ],
            "bills" => array_map(function ($row) {
                return [
                    "id" => (int) $row["id"],
                    "bill_number" => $row["bill_number"],
                    "bill_date" => $row["bill_date"],
                    "total_amount" => (float) $row["total_amount"],
                    "paid_amount" => (float) $row["paid_amount"],
                    "balance_amount" => (float) $row["balance_amount"],
                    "payment_status" => $row["payment_status"],
                ];
            }, $billsStmt->fetchAll()),
            "payments" => array_map(function ($row) {
                return [
                    "id" => (int) $row["id"],
                    "payment_date" => $row["payment_date"],
                    "payment_method" => $row["payment_method"],
                    "payment_method_label" => PaymentController::methodLabel($row["payment_method"]),
                    "amount" => (float) $row["amount"],
                    "reference_number" => $row["reference_number"],
                    "bill_id" => (int) $row["bill_id"],
                    "bill_number" => $row["bill_number"],
                ];
            }, $paymentsStmt->fetchAll()),
        ]);
    }

    public function store()
    {
        $user = AuthMiddleware::requireTenantUser();
        Csrf::validateRequest();

        $input = $this->normalize($this->readJson());
        $errors = $this->validate($input, true);

        if ($errors) {
            Validation::fail($errors);
        }

        $sql = "INSERT INTO customers
                    (tenant_id, customer_name, phone, email, address, city, notes, status)
                VALUES
                    (:tenant_id, :customer_name, :phone, :email, :address, :city, :notes, :status)";
        $statement = $this->db->prepare($sql);
        $statement->execute([
            ":tenant_id" => (int) $user["tenant_id"],
            ":customer_name" => $input["customer_name"],
            ":phone" => $input["phone"],
            ":email" => $input["email"] !== "" ? $input["email"] : null,
            ":address" => $input["address"] !== "" ? $input["address"] : null,
            ":city" => $input["city"] !== "" ? $input["city"] : null,
            ":notes" => $input["notes"] !== "" ? $input["notes"] : null,
            ":status" => $input["status"],
        ]);

        $customer = $this->findForTenant((int) $this->db->lastInsertId(), (int) $user["tenant_id"]);

        Response::json([
            "success" => true,
            "message" => "Customer created successfully",
            "customer" => $this->format($customer),
        ], 201);
    }

    public function update($params)
    {
        $user = AuthMiddleware::requireTenantUser();
        Csrf::validateRequest();

        $id = (int) ($params["id"] ?? 0);
        $customer = $this->findForTenant($id, (int) $user["tenant_id"]);

        if (!$customer) {
            Response::json(["success" => false, "message" => "Customer not found"], 404);
        }

        $input = $this->normalize($this->readJson());
        $errors = $this->validate($input, false);

        if ($errors) {
            Validation::fail($errors);
        }

        $sql = "UPDATE customers SET
                    customer_name = :customer_name,
                    phone = :phone,
                    email = :email,
                    address = :address,
                    city = :city,
                    notes = :notes,
                    status = :status
                WHERE id = :id AND tenant_id = :tenant_id";
        $statement = $this->db->prepare($sql);
        $statement->execute([
            ":customer_name" => $input["customer_name"],
            ":phone" => $input["phone"],
            ":email" => $input["email"] !== "" ? $input["email"] : null,
            ":address" => $input["address"] !== "" ? $input["address"] : null,
            ":city" => $input["city"] !== "" ? $input["city"] : null,
            ":notes" => $input["notes"] !== "" ? $input["notes"] : null,
            ":status" => $input["status"],
            ":id" => $id,
            ":tenant_id" => (int) $user["tenant_id"],
        ]);

        $customer = $this->findForTenant($id, (int) $user["tenant_id"]);

        Response::json([
            "success" => true,
            "message" => "Customer updated successfully",
            "customer" => $this->format($customer),
        ]);
    }

    public function updateStatus($params)
    {
        $user = AuthMiddleware::requireTenantUser();
        Csrf::validateRequest();

        $id = (int) ($params["id"] ?? 0);
        $customer = $this->findForTenant($id, (int) $user["tenant_id"]);

        if (!$customer) {
            Response::json(["success" => false, "message" => "Customer not found"], 404);
        }

        $data = $this->readJson();
        $status = strtolower(trim((string) ($data["status"] ?? "")));

        if (!in_array($status, ["active", "inactive"], true)) {
            Response::json(["success" => false, "message" => "Status must be active or inactive"], 422);
        }

        $statement = $this->db->prepare(
            "UPDATE customers SET status = :status WHERE id = :id AND tenant_id = :tenant_id"
        );
        $statement->execute([
            ":status" => $status,
            ":id" => $id,
            ":tenant_id" => (int) $user["tenant_id"],
        ]);

        $customer = $this->findForTenant($id, (int) $user["tenant_id"]);

        Response::json([
            "success" => true,
            "message" => $status === "active" ? "Customer activated" : "Customer deactivated",
            "customer" => $this->format($customer),
        ]);
    }

    private function findForTenant($id, $tenantId)
    {
        $statement = $this->db->prepare(
            "SELECT id, tenant_id, customer_name, phone, email, address, city, notes, status, created_at, updated_at
             FROM customers WHERE id = :id AND tenant_id = :tenant_id LIMIT 1"
        );
        $statement->execute([":id" => $id, ":tenant_id" => $tenantId]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    private function format(array $row)
    {
        return [
            "id" => (int) $row["id"],
            "customer_name" => $row["customer_name"],
            "phone" => $row["phone"],
            "email" => $row["email"],
            "address" => $row["address"],
            "city" => $row["city"],
            "notes" => $row["notes"],
            "status" => $row["status"],
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
        return [
            "customer_name" => trim((string) ($input["customer_name"] ?? "")),
            "phone" => Validation::normalizePhone($input["phone"] ?? ""),
            "email" => strtolower(trim((string) ($input["email"] ?? ""))),
            "address" => trim((string) ($input["address"] ?? "")),
            "city" => trim((string) ($input["city"] ?? "")),
            "notes" => trim((string) ($input["notes"] ?? "")),
            "status" => strtolower(trim((string) ($input["status"] ?? "active"))) ?: "active",
        ];
    }

    private function validate(array $input, $requireStatus)
    {
        $errors = [];

        $nameError = Validation::length(
            $input["customer_name"],
            Validation::LIMITS["customer_name"]["min"],
            Validation::LIMITS["customer_name"]["max"],
            "Customer name"
        );
        if ($nameError) {
            $errors["customer_name"] = $nameError;
        }

        $phoneError = Validation::indianMobile($input["phone"]);
        if ($phoneError) {
            $errors["phone"] = $phoneError;
        }

        $emailError = Validation::email($input["email"], false);
        if ($emailError) {
            $errors["email"] = $emailError;
        }

        $addressError = Validation::length(
            $input["address"],
            Validation::LIMITS["address"]["min"],
            Validation::LIMITS["address"]["max"],
            "Address",
            false
        );
        if ($addressError) {
            $errors["address"] = $addressError;
        }

        $cityError = Validation::length(
            $input["city"],
            Validation::LIMITS["city"]["min"],
            Validation::LIMITS["city"]["max"],
            "City",
            false
        );
        if ($cityError) {
            $errors["city"] = $cityError;
        }

        $notesError = Validation::length(
            $input["notes"],
            Validation::LIMITS["notes"]["min"],
            Validation::LIMITS["notes"]["max"],
            "Notes",
            false
        );
        if ($notesError) {
            $errors["notes"] = $notesError;
        }

        if (!in_array($input["status"], ["active", "inactive"], true)) {
            $errors["status"] = "Status must be active or inactive.";
        }

        return $errors;
    }
}

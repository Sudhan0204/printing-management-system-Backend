<?php

class BillController
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
        $page = max(1, (int) ($_GET["page"] ?? 1));
        $perPage = min(50, max(5, (int) ($_GET["per_page"] ?? 10)));

        $where = ["b.tenant_id = :tenant_id"];
        $params = [":tenant_id" => $tenantId];

        if ($search !== "") {
            $where[] = "(b.bill_number LIKE :search OR c.customer_name LIKE :search OR c.phone LIKE :search)";
            $params[":search"] = "%" . substr($search, 0, 100) . "%";
        }

        $paymentStatus = strtolower(trim((string) ($_GET["payment_status"] ?? "")));
        if (in_array($paymentStatus, ["unpaid", "partially_paid", "paid"], true)) {
            $where[] = "b.payment_status = :payment_status";
            $params[":payment_status"] = $paymentStatus;
        }

        $whereSql = implode(" AND ", $where);
        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM bills b
             INNER JOIN customers c ON c.id = b.customer_id AND c.tenant_id = b.tenant_id
             WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;

        $sql = "SELECT b.id, b.bill_number, b.bill_date, b.total_amount, b.paid_amount,
                       b.balance_amount, b.payment_status, b.status, b.created_at,
                       c.id AS customer_id, c.customer_name, c.phone
                FROM bills b
                INNER JOIN customers c ON c.id = b.customer_id AND c.tenant_id = b.tenant_id
                WHERE {$whereSql}
                ORDER BY b.bill_date DESC, b.id DESC
                LIMIT {$perPage} OFFSET {$offset}";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        $bills = array_map(function ($row) {
            return [
                "id" => (int) $row["id"],
                "bill_number" => $row["bill_number"],
                "bill_date" => $row["bill_date"],
                "total_amount" => (float) $row["total_amount"],
                "paid_amount" => (float) $row["paid_amount"],
                "balance_amount" => (float) $row["balance_amount"],
                "payment_status" => $row["payment_status"],
                "status" => $row["status"],
                "created_at" => $row["created_at"],
                "customer" => [
                    "id" => (int) $row["customer_id"],
                    "customer_name" => $row["customer_name"],
                    "phone" => $row["phone"],
                ],
            ];
        }, $statement->fetchAll());

        Response::json([
            "success" => true,
            "bills" => $bills,
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
        $bill = $this->findBill((int) ($params["id"] ?? 0), (int) $user["tenant_id"]);

        if (!$bill) {
            Response::json(["success" => false, "message" => "Bill not found"], 404);
        }

        Response::json(["success" => true, "bill" => $bill]);
    }

    public function store()
    {
        $user = AuthMiddleware::requireTenantUser();
        Csrf::validateRequest();

        $tenantId = (int) $user["tenant_id"];
        $data = $this->readJson();

        $customerId = (int) ($data["customer_id"] ?? 0);
        $billDate = trim((string) ($data["bill_date"] ?? date("Y-m-d")));
        $items = $data["items"] ?? null;

        if ($customerId <= 0) {
            Response::json(["success" => false, "message" => "Select a customer"], 422);
        }

        if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $billDate)) {
            Response::json(["success" => false, "message" => "Enter a valid bill date"], 422);
        }

        if (!is_array($items) || count($items) === 0) {
            Response::json(["success" => false, "message" => "Add at least one product"], 422);
        }

        $customerStmt = $this->db->prepare(
            "SELECT id, customer_name, phone, status FROM customers
             WHERE id = :id AND tenant_id = :tenant_id LIMIT 1"
        );
        $customerStmt->execute([":id" => $customerId, ":tenant_id" => $tenantId]);
        $customer = $customerStmt->fetch();

        if (!$customer) {
            Response::json(["success" => false, "message" => "Customer not found"], 404);
        }

        if ($customer["status"] !== "active") {
            Response::json(["success" => false, "message" => "Selected customer is inactive"], 422);
        }

        $preparedItems = [];
        $totalAmount = 0.0;
        $totalQuantity = 0.0;

        foreach ($items as $index => $item) {
            $productId = (int) ($item["product_id"] ?? 0);

            if ($productId <= 0) {
                Response::json([
                    "success" => false,
                    "message" => "Select a product for line " . ($index + 1),
                ], 422);
            }

            $productStmt = $this->db->prepare(
                "SELECT id, product_name, unit, pricing_type, status FROM products
                 WHERE id = :id AND tenant_id = :tenant_id LIMIT 1"
            );
            $productStmt->execute([":id" => $productId, ":tenant_id" => $tenantId]);
            $product = $productStmt->fetch();

            if (!$product) {
                Response::json([
                    "success" => false,
                    "message" => "Product not found for line " . ($index + 1),
                ], 404);
            }

            if ($product["status"] !== "active") {
                Response::json([
                    "success" => false,
                    "message" => $product["product_name"] . " is inactive",
                ], 422);
            }

            // Prefer rate from current active rate history; allow override only if valid number provided.
            $currentRate = $this->currentRate($productId, $tenantId);
            $rateInput = $item["rate"] ?? null;
            $rateRaw = $rateInput !== null && $rateInput !== ""
                ? (string) $rateInput
                : ($currentRate !== null ? (string) $currentRate : "");

            if ($rateRaw === "") {
                Response::json([
                    "success" => false,
                    "message" => "No rate set for " . $product["product_name"],
                    "errors" => ["items." . $index . ".rate" => "No rate set for " . $product["product_name"]],
                ], 422);
            }

            $rateError = Validation::money($rateRaw, "Rate", true);
            if ($rateError) {
                Response::json([
                    "success" => false,
                    "message" => $product["product_name"] . ": " . $rateError,
                    "errors" => ["items." . $index . ".rate" => $rateError],
                ], 422);
            }

            $qtyRaw = $item["quantity"] ?? "";
            $qtyError = Validation::positiveNumber($qtyRaw, "Quantity", false, 4);
            if ($qtyError) {
                Response::json([
                    "success" => false,
                    "message" => $product["product_name"] . ": " . $qtyError,
                    "errors" => ["items." . $index . ".quantity" => $qtyError],
                ], 422);
            }

            if ($product["pricing_type"] === "dimension_based") {
                $widthError = Validation::positiveNumber($item["width"] ?? "", "Width", false, 2);
                $heightError = Validation::positiveNumber($item["height"] ?? "", "Height", false, 2);
                if ($widthError || $heightError) {
                    $dimError = $widthError ?: $heightError;
                    Response::json([
                        "success" => false,
                        "message" => $product["product_name"] . ": " . $dimError,
                        "errors" => ["items." . $index . ".dimensions" => $dimError],
                    ], 422);
                }
            }

            $rate = round((float) $rateRaw, 2);

            try {
                $calc = BillingCalc::calculateLine(
                    $product["pricing_type"],
                    $item["width"] ?? null,
                    $item["height"] ?? null,
                    $item["quantity"] ?? 0,
                    $rate
                );
            } catch (InvalidArgumentException $exception) {
                Response::json([
                    "success" => false,
                    "message" => $product["product_name"] . ": " . $exception->getMessage(),
                ], 422);
            }

            $preparedItems[] = [
                "product_id" => $productId,
                "product_name_snapshot" => $product["product_name"],
                "unit_snapshot" => $product["unit"],
                "pricing_type_snapshot" => $product["pricing_type"],
                "width" => $calc["width"],
                "height" => $calc["height"],
                "quantity" => $calc["quantity"],
                "rate_snapshot" => $calc["rate"],
                "area" => $calc["area"],
                "amount" => $calc["amount"],
            ];

            $totalAmount = round($totalAmount + $calc["amount"], 2);
            $totalQuantity = round($totalQuantity + $calc["quantity"], 4);
        }

        try {
            $this->db->beginTransaction();

            $billNumber = $this->nextBillNumber($tenantId);

            $billStmt = $this->db->prepare(
                "INSERT INTO bills
                    (tenant_id, customer_id, bill_number, bill_date, total_amount,
                     paid_amount, balance_amount, payment_status, status)
                 VALUES
                    (:tenant_id, :customer_id, :bill_number, :bill_date, :total_amount,
                     0, :balance_amount, 'unpaid', 'saved')"
            );
            $billStmt->execute([
                ":tenant_id" => $tenantId,
                ":customer_id" => $customerId,
                ":bill_number" => $billNumber,
                ":bill_date" => $billDate,
                ":total_amount" => $totalAmount,
                ":balance_amount" => $totalAmount,
            ]);

            $billId = (int) $this->db->lastInsertId();

            $itemStmt = $this->db->prepare(
                "INSERT INTO bill_items
                    (bill_id, tenant_id, product_id, product_name_snapshot, unit_snapshot,
                     pricing_type_snapshot, width, height, quantity, rate_snapshot, area, amount)
                 VALUES
                    (:bill_id, :tenant_id, :product_id, :product_name_snapshot, :unit_snapshot,
                     :pricing_type_snapshot, :width, :height, :quantity, :rate_snapshot, :area, :amount)"
            );

            foreach ($preparedItems as $line) {
                $itemStmt->execute([
                    ":bill_id" => $billId,
                    ":tenant_id" => $tenantId,
                    ":product_id" => $line["product_id"],
                    ":product_name_snapshot" => $line["product_name_snapshot"],
                    ":unit_snapshot" => $line["unit_snapshot"],
                    ":pricing_type_snapshot" => $line["pricing_type_snapshot"],
                    ":width" => $line["width"],
                    ":height" => $line["height"],
                    ":quantity" => $line["quantity"],
                    ":rate_snapshot" => $line["rate_snapshot"],
                    ":area" => $line["area"],
                    ":amount" => $line["amount"],
                ]);
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            Response::json(["success" => false, "message" => "Could not save bill. Please try again."], 500);
        }

        $bill = $this->findBill($billId, $tenantId);

        Response::json([
            "success" => true,
            "message" => "Bill saved successfully",
            "bill" => $bill,
            "summary" => [
                "total_quantity" => $totalQuantity,
                "total_amount" => $totalAmount,
            ],
        ], 201);
    }

    private function findBill($id, $tenantId)
    {
        $statement = $this->db->prepare(
            "SELECT b.id, b.bill_number, b.bill_date, b.total_amount, b.paid_amount, b.balance_amount,
                    b.payment_status, b.status, b.created_at, b.updated_at,
                    c.id AS customer_id, c.customer_name, c.phone, c.email, c.address, c.city
             FROM bills b
             INNER JOIN customers c ON c.id = b.customer_id AND c.tenant_id = b.tenant_id
             WHERE b.id = :id AND b.tenant_id = :tenant_id
             LIMIT 1"
        );
        $statement->execute([":id" => $id, ":tenant_id" => $tenantId]);
        $row = $statement->fetch();

        if (!$row) {
            return null;
        }

        $itemsStmt = $this->db->prepare(
            "SELECT id, product_id, product_name_snapshot, unit_snapshot, pricing_type_snapshot,
                    width, height, quantity, rate_snapshot, area, amount
             FROM bill_items
             WHERE bill_id = :bill_id AND tenant_id = :tenant_id
             ORDER BY id ASC"
        );
        $itemsStmt->execute([":bill_id" => $id, ":tenant_id" => $tenantId]);

        $items = array_map(function ($item) {
            return [
                "id" => (int) $item["id"],
                "product_id" => (int) $item["product_id"],
                "product_name" => $item["product_name_snapshot"],
                "unit" => $item["unit_snapshot"],
                "unit_label" => BillingCalc::unitLabel($item["unit_snapshot"]),
                "pricing_type" => $item["pricing_type_snapshot"],
                "pricing_type_label" => BillingCalc::pricingLabel($item["pricing_type_snapshot"]),
                "width" => $item["width"] !== null ? (float) $item["width"] : null,
                "height" => $item["height"] !== null ? (float) $item["height"] : null,
                "quantity" => (float) $item["quantity"],
                "rate" => (float) $item["rate_snapshot"],
                "area" => $item["area"] !== null ? (float) $item["area"] : null,
                "amount" => (float) $item["amount"],
            ];
        }, $itemsStmt->fetchAll());

        $paymentsStmt = $this->db->prepare(
            "SELECT id, payment_date, payment_method, amount, reference_number, notes, created_at
             FROM payments
             WHERE bill_id = :bill_id AND tenant_id = :tenant_id
             ORDER BY payment_date ASC, id ASC"
        );
        $paymentsStmt->execute([":bill_id" => $id, ":tenant_id" => $tenantId]);
        $payments = array_map(function ($payment) {
            return [
                "id" => (int) $payment["id"],
                "payment_date" => $payment["payment_date"],
                "payment_method" => $payment["payment_method"],
                "payment_method_label" => PaymentController::methodLabel($payment["payment_method"]),
                "amount" => (float) $payment["amount"],
                "reference_number" => $payment["reference_number"],
                "notes" => $payment["notes"],
                "created_at" => $payment["created_at"],
            ];
        }, $paymentsStmt->fetchAll());

        $totalQuantity = 0.0;
        foreach ($items as $item) {
            $totalQuantity += $item["quantity"];
        }

        return [
            "id" => (int) $row["id"],
            "bill_number" => $row["bill_number"],
            "bill_date" => $row["bill_date"],
            "total_amount" => (float) $row["total_amount"],
            "paid_amount" => (float) $row["paid_amount"],
            "balance_amount" => (float) $row["balance_amount"],
            "payment_status" => $row["payment_status"],
            "total_quantity" => round($totalQuantity, 4),
            "status" => $row["status"],
            "created_at" => $row["created_at"],
            "updated_at" => $row["updated_at"],
            "customer" => [
                "id" => (int) $row["customer_id"],
                "customer_name" => $row["customer_name"],
                "phone" => $row["phone"],
                "email" => $row["email"],
                "address" => $row["address"],
                "city" => $row["city"],
            ],
            "items" => $items,
            "payments" => $payments,
        ];
    }

    private function currentRate($productId, $tenantId)
    {
        $statement = $this->db->prepare(
            "SELECT rate FROM product_rates
             WHERE product_id = :product_id AND tenant_id = :tenant_id AND status = 'active'
             ORDER BY effective_from DESC, id DESC
             LIMIT 1"
        );
        $statement->execute([":product_id" => $productId, ":tenant_id" => $tenantId]);
        $rate = $statement->fetchColumn();

        return $rate === false ? null : (float) $rate;
    }

    private function nextBillNumber($tenantId)
    {
        $year = date("Y");
        $prefix = "BILL-" . $year . "-";

        $statement = $this->db->prepare(
            "SELECT bill_number FROM bills
             WHERE tenant_id = :tenant_id AND bill_number LIKE :prefix
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $statement->execute([
            ":tenant_id" => $tenantId,
            ":prefix" => $prefix . "%",
        ]);
        $last = $statement->fetchColumn();

        $seq = 1;
        if (is_string($last) && preg_match("/BILL-\\d{4}-(\\d+)$/", $last, $matches)) {
            $seq = ((int) $matches[1]) + 1;
        }

        return $prefix . str_pad((string) $seq, 4, "0", STR_PAD_LEFT);
    }

    private function readJson()
    {
        $json = json_decode(file_get_contents("php://input"), true);
        return is_array($json) ? $json : [];
    }
}

<?php

class PaymentController
{
    private $db;

    public const METHODS = ["cash", "bank_transfer", "upi", "card", "other"];

    public function __construct()
    {
        $this->db = (new Database())->connect();
    }

    public function summary()
    {
        $user = AuthMiddleware::requireTenantUser();
        $tenantId = (int) $user["tenant_id"];
        $today = date("Y-m-d");
        $monthStart = date("Y-m-01");

        $todayStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE tenant_id = :tenant_id AND payment_date = :today"
        );
        $todayStmt->execute([":tenant_id" => $tenantId, ":today" => $today]);
        $todayTotal = (float) $todayStmt->fetchColumn();

        $monthStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE tenant_id = :tenant_id AND payment_date BETWEEN :from AND :to"
        );
        $monthStmt->execute([
            ":tenant_id" => $tenantId,
            ":from" => $monthStart,
            ":to" => $today,
        ]);
        $monthTotal = (float) $monthStmt->fetchColumn();

        $outstandingStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(balance_amount), 0) FROM bills
             WHERE tenant_id = :tenant_id AND payment_status IN ('unpaid', 'partially_paid')"
        );
        $outstandingStmt->execute([":tenant_id" => $tenantId]);
        $outstanding = (float) $outstandingStmt->fetchColumn();

        $countByStatus = function ($status) use ($tenantId) {
            $statement = $this->db->prepare(
                "SELECT COUNT(*) FROM bills WHERE tenant_id = :tenant_id AND payment_status = :status"
            );
            $statement->execute([":tenant_id" => $tenantId, ":status" => $status]);
            return (int) $statement->fetchColumn();
        };

        Response::json([
            "success" => true,
            "summary" => [
                "payments_today" => round($todayTotal, 2),
                "payments_this_month" => round($monthTotal, 2),
                "outstanding_balance" => round($outstanding, 2),
                "unpaid_bills" => $countByStatus("unpaid"),
                "partially_paid_bills" => $countByStatus("partially_paid"),
                "paid_bills" => $countByStatus("paid"),
            ],
        ]);
    }

    public function index()
    {
        $user = AuthMiddleware::requireTenantUser();
        $tenantId = (int) $user["tenant_id"];

        $search = trim((string) ($_GET["search"] ?? ""));
        $method = strtolower(trim((string) ($_GET["payment_method"] ?? "")));
        $dateFrom = trim((string) ($_GET["date_from"] ?? ""));
        $dateTo = trim((string) ($_GET["date_to"] ?? ""));
        $page = max(1, (int) ($_GET["page"] ?? 1));
        $perPage = min(50, max(5, (int) ($_GET["per_page"] ?? 10)));

        $where = ["p.tenant_id = :tenant_id"];
        $params = [":tenant_id" => $tenantId];

        if ($search !== "") {
            $where[] = "(c.customer_name LIKE :search OR b.bill_number LIKE :search OR p.reference_number LIKE :search)";
            $params[":search"] = "%" . substr($search, 0, 100) . "%";
        }

        if (in_array($method, self::METHODS, true)) {
            $where[] = "p.payment_method = :method";
            $params[":method"] = $method;
        }

        if (preg_match("/^\d{4}-\d{2}-\d{2}$/", $dateFrom)) {
            $where[] = "p.payment_date >= :date_from";
            $params[":date_from"] = $dateFrom;
        }

        if (preg_match("/^\d{4}-\d{2}-\d{2}$/", $dateTo)) {
            $where[] = "p.payment_date <= :date_to";
            $params[":date_to"] = $dateTo;
        }

        $whereSql = implode(" AND ", $where);
        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM payments p
             INNER JOIN customers c ON c.id = p.customer_id AND c.tenant_id = p.tenant_id
             INNER JOIN bills b ON b.id = p.bill_id AND b.tenant_id = p.tenant_id
             WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT p.id, p.payment_date, p.payment_method, p.amount, p.reference_number, p.notes, p.created_at,
                       c.id AS customer_id, c.customer_name, c.phone,
                       b.id AS bill_id, b.bill_number, b.total_amount, b.payment_status
                FROM payments p
                INNER JOIN customers c ON c.id = p.customer_id AND c.tenant_id = p.tenant_id
                INNER JOIN bills b ON b.id = p.bill_id AND b.tenant_id = p.tenant_id
                WHERE {$whereSql}
                ORDER BY p.payment_date DESC, p.id DESC
                LIMIT {$perPage} OFFSET {$offset}";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        $payments = array_map([$this, "formatListRow"], $statement->fetchAll());

        Response::json([
            "success" => true,
            "payments" => $payments,
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
        $payment = $this->findPayment((int) ($params["id"] ?? 0), (int) $user["tenant_id"]);

        if (!$payment) {
            Response::json(["success" => false, "message" => "Payment not found"], 404);
        }

        Response::json(["success" => true, "payment" => $payment]);
    }

    public function outstandingBills()
    {
        $user = AuthMiddleware::requireTenantUser();
        $tenantId = (int) $user["tenant_id"];
        $customerId = (int) ($_GET["customer_id"] ?? 0);

        if ($customerId <= 0) {
            Validation::fail(["customer_id" => "Select a customer."]);
        }

        $customerStmt = $this->db->prepare(
            "SELECT id, customer_name, phone FROM customers
             WHERE id = :id AND tenant_id = :tenant_id LIMIT 1"
        );
        $customerStmt->execute([":id" => $customerId, ":tenant_id" => $tenantId]);
        $customer = $customerStmt->fetch();

        if (!$customer) {
            Response::json(["success" => false, "message" => "Customer not found"], 404);
        }

        $statement = $this->db->prepare(
            "SELECT id, bill_number, bill_date, total_amount, paid_amount, balance_amount, payment_status
             FROM bills
             WHERE tenant_id = :tenant_id
               AND customer_id = :customer_id
               AND payment_status IN ('unpaid', 'partially_paid')
             ORDER BY bill_date ASC, id ASC"
        );
        $statement->execute([
            ":tenant_id" => $tenantId,
            ":customer_id" => $customerId,
        ]);

        $bills = array_map(function ($row) {
            return [
                "id" => (int) $row["id"],
                "bill_number" => $row["bill_number"],
                "bill_date" => $row["bill_date"],
                "total_amount" => (float) $row["total_amount"],
                "paid_amount" => (float) $row["paid_amount"],
                "balance_amount" => (float) $row["balance_amount"],
                "payment_status" => $row["payment_status"],
            ];
        }, $statement->fetchAll());

        Response::json([
            "success" => true,
            "customer" => [
                "id" => (int) $customer["id"],
                "customer_name" => $customer["customer_name"],
                "phone" => $customer["phone"],
            ],
            "bills" => $bills,
        ]);
    }

    public function store()
    {
        $user = AuthMiddleware::requireTenantUser();
        Csrf::validateRequest();

        $tenantId = (int) $user["tenant_id"];
        $data = $this->readJson();

        $customerId = (int) ($data["customer_id"] ?? 0);
        $billId = (int) ($data["bill_id"] ?? 0);
        $paymentDate = trim((string) ($data["payment_date"] ?? date("Y-m-d")));
        $method = strtolower(trim((string) ($data["payment_method"] ?? "")));
        $amount = $data["amount"] ?? null;
        $reference = trim((string) ($data["reference_number"] ?? ""));
        $notes = trim((string) ($data["notes"] ?? ""));

        $errors = [];

        if ($customerId <= 0) {
            $errors["customer_id"] = "Select a customer.";
        }

        if ($billId <= 0) {
            $errors["bill_id"] = "Select a bill.";
        }

        if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $paymentDate)) {
            $errors["payment_date"] = "Enter a valid payment date.";
        }

        if (!in_array($method, self::METHODS, true)) {
            $errors["payment_method"] = "Select a valid payment method.";
        }

        $amountError = Validation::money($amount, "Payment amount");
        if ($amountError) {
            $errors["amount"] = $amountError;
        }

        if (in_array($method, ["bank_transfer", "upi", "card"], true) && $reference === "") {
            $errors["reference_number"] = "Reference number is required for this payment method.";
        } else {
            $referenceError = Validation::length(
                $reference,
                Validation::LIMITS["reference_number"]["min"],
                Validation::LIMITS["reference_number"]["max"],
                "Reference number",
                false
            );
            if ($referenceError) {
                $errors["reference_number"] = $referenceError;
            }
        }

        $notesError = Validation::length(
            $notes,
            Validation::LIMITS["notes"]["min"],
            Validation::LIMITS["notes"]["max"],
            "Notes",
            false
        );
        if ($notesError) {
            $errors["notes"] = $notesError;
        }

        if ($errors) {
            Validation::fail($errors);
        }

        $amount = round((float) $amount, 2);

        try {
            $this->db->beginTransaction();

            // Lock bill row to prevent concurrent overpayments.
            $billStmt = $this->db->prepare(
                "SELECT id, customer_id, total_amount, paid_amount, balance_amount, payment_status
                 FROM bills
                 WHERE id = :id AND tenant_id = :tenant_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $billStmt->execute([":id" => $billId, ":tenant_id" => $tenantId]);
            $bill = $billStmt->fetch();

            if (!$bill) {
                $this->db->rollBack();
                Response::json(["success" => false, "message" => "Bill not found"], 404);
            }

            if ((int) $bill["customer_id"] !== $customerId) {
                $this->db->rollBack();
                Validation::fail([
                    "bill_id" => "Selected bill does not belong to this customer.",
                ]);
            }

            $customerStmt = $this->db->prepare(
                "SELECT id FROM customers WHERE id = :id AND tenant_id = :tenant_id LIMIT 1"
            );
            $customerStmt->execute([":id" => $customerId, ":tenant_id" => $tenantId]);

            if (!$customerStmt->fetch()) {
                $this->db->rollBack();
                Response::json(["success" => false, "message" => "Customer not found"], 404);
            }

            // Recalculate paid from payments table (source of truth) while locked.
            $sumStmt = $this->db->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM payments
                 WHERE bill_id = :bill_id AND tenant_id = :tenant_id"
            );
            $sumStmt->execute([":bill_id" => $billId, ":tenant_id" => $tenantId]);
            $alreadyPaid = round((float) $sumStmt->fetchColumn(), 2);
            $billTotal = round((float) $bill["total_amount"], 2);
            $balance = round(max($billTotal - $alreadyPaid, 0), 2);

            if ($balance <= 0) {
                $this->db->rollBack();
                Validation::fail(["amount" => "This bill is already fully paid."]);
            }

            if ($amount - $balance > 0.009) {
                $this->db->rollBack();
                Validation::fail([
                    "amount" => "Payment amount cannot exceed the outstanding balance.",
                ], "Payment amount cannot exceed the outstanding balance.");
            }

            $insert = $this->db->prepare(
                "INSERT INTO payments
                    (tenant_id, bill_id, customer_id, payment_date, payment_method, amount, reference_number, notes)
                 VALUES
                    (:tenant_id, :bill_id, :customer_id, :payment_date, :payment_method, :amount, :reference_number, :notes)"
            );
            $insert->execute([
                ":tenant_id" => $tenantId,
                ":bill_id" => $billId,
                ":customer_id" => $customerId,
                ":payment_date" => $paymentDate,
                ":payment_method" => $method,
                ":amount" => $amount,
                ":reference_number" => $reference !== "" ? $reference : null,
                ":notes" => $notes !== "" ? $notes : null,
            ]);

            $paymentId = (int) $this->db->lastInsertId();
            $this->recalculateBillPayment($billId, $tenantId, $billTotal);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            Response::json(["success" => false, "message" => "Could not save payment. Please try again."], 500);
        }

        $payment = $this->findPayment($paymentId, $tenantId);

        Response::json([
            "success" => true,
            "message" => "Payment recorded successfully",
            "payment" => $payment,
        ], 201);
    }

    /**
     * Recalculate paid/balance/status for a bill from the payments table.
     */
    public function recalculateBillPayment($billId, $tenantId, $billTotal = null)
    {
        if ($billTotal === null) {
            $billStmt = $this->db->prepare(
                "SELECT total_amount FROM bills WHERE id = :id AND tenant_id = :tenant_id LIMIT 1"
            );
            $billStmt->execute([":id" => $billId, ":tenant_id" => $tenantId]);
            $billTotal = (float) $billStmt->fetchColumn();
        }

        $sumStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE bill_id = :bill_id AND tenant_id = :tenant_id"
        );
        $sumStmt->execute([":bill_id" => $billId, ":tenant_id" => $tenantId]);
        $paid = round((float) $sumStmt->fetchColumn(), 2);
        $billTotal = round((float) $billTotal, 2);
        $balance = round(max($billTotal - $paid, 0), 2);

        if ($paid <= 0) {
            $status = "unpaid";
        } elseif ($balance <= 0.009) {
            $status = "paid";
            $balance = 0;
            $paid = $billTotal;
        } else {
            $status = "partially_paid";
        }

        $update = $this->db->prepare(
            "UPDATE bills
             SET paid_amount = :paid, balance_amount = :balance, payment_status = :payment_status
             WHERE id = :id AND tenant_id = :tenant_id"
        );
        $update->execute([
            ":paid" => $paid,
            ":balance" => $balance,
            ":payment_status" => $status,
            ":id" => $billId,
            ":tenant_id" => $tenantId,
        ]);
    }

    private function findPayment($id, $tenantId)
    {
        $statement = $this->db->prepare(
            "SELECT p.id, p.payment_date, p.payment_method, p.amount, p.reference_number, p.notes,
                    p.created_at, p.updated_at,
                    c.id AS customer_id, c.customer_name, c.phone,
                    b.id AS bill_id, b.bill_number, b.total_amount, b.paid_amount,
                    b.balance_amount, b.payment_status
             FROM payments p
             INNER JOIN customers c ON c.id = p.customer_id AND c.tenant_id = p.tenant_id
             INNER JOIN bills b ON b.id = p.bill_id AND b.tenant_id = p.tenant_id
             WHERE p.id = :id AND p.tenant_id = :tenant_id
             LIMIT 1"
        );
        $statement->execute([":id" => $id, ":tenant_id" => $tenantId]);
        $row = $statement->fetch();

        return $row ? $this->formatDetail($row) : null;
    }

    private function formatListRow(array $row)
    {
        return [
            "id" => (int) $row["id"],
            "payment_date" => $row["payment_date"],
            "payment_method" => $row["payment_method"],
            "payment_method_label" => self::methodLabel($row["payment_method"]),
            "amount" => (float) $row["amount"],
            "reference_number" => $row["reference_number"],
            "notes" => $row["notes"] ?? null,
            "created_at" => $row["created_at"],
            "customer" => [
                "id" => (int) $row["customer_id"],
                "customer_name" => $row["customer_name"],
                "phone" => $row["phone"],
            ],
            "bill" => [
                "id" => (int) $row["bill_id"],
                "bill_number" => $row["bill_number"],
                "total_amount" => isset($row["total_amount"]) ? (float) $row["total_amount"] : null,
                "payment_status" => $row["payment_status"] ?? null,
            ],
        ];
    }

    private function formatDetail(array $row)
    {
        $base = $this->formatListRow($row);
        $base["bill"]["paid_amount"] = (float) $row["paid_amount"];
        $base["bill"]["balance_amount"] = (float) $row["balance_amount"];
        $base["updated_at"] = $row["updated_at"];
        return $base;
    }

    public static function methodLabel($method)
    {
        $map = [
            "cash" => "Cash",
            "bank_transfer" => "Bank Transfer",
            "upi" => "UPI",
            "card" => "Card",
            "other" => "Other",
        ];

        return $map[$method] ?? $method;
    }

    private function readJson()
    {
        $json = json_decode(file_get_contents("php://input"), true);
        return is_array($json) ? $json : [];
    }
}

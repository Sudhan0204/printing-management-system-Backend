<?php

class ReportController
{
    private $db;

    public const DATE_PRESETS = ["today", "this_week", "this_month", "last_month", "custom", ""];
    public const EXPORT_MODES = ["selected", "filtered", "all"];
    public const MAX_EXPORT = 75;

    public function __construct()
    {
        $this->db = (new Database())->connect();
    }

    public function hub()
    {
        AuthMiddleware::requireTenantUser();

        Response::json([
            "success" => true,
            "modules" => [
                [
                    "key" => "customers",
                    "title" => "Customer Reports",
                    "description" => "Generate, filter and export detailed customer billing and payment records.",
                    "path" => "/reports",
                    "available" => true,
                ],
            ],
        ]);
    }

    public function customers()
    {
        $user = AuthMiddleware::requireTenantUser();
        $tenantId = (int) $user["tenant_id"];
        $filters = $this->parseFilters($_GET);
        $page = max(1, (int) ($_GET["page"] ?? 1));
        $perPage = min(100, max(5, (int) ($_GET["per_page"] ?? 25)));

        $result = $this->queryCustomerReport($tenantId, $filters, $page, $perPage);
        $summary = $this->querySummary($tenantId, $filters);

        Response::json([
            "success" => true,
            "summary" => $summary,
            "customers" => $result["items"],
            "pagination" => [
                "total" => $result["total"],
                "page" => $page,
                "per_page" => $perPage,
                "total_pages" => max(1, (int) ceil($result["total"] / $perPage)),
            ],
            "filters" => [
                "date_preset" => $filters["date_preset"],
                "date_from" => $filters["date_from"],
                "date_to" => $filters["date_to"],
                "filter_label" => $filters["filter_label"],
                "date_scope" => [
                    "bills" => "bill_date",
                    "payments" => "payment_date",
                    "outstanding" => "balance on bills matching bill_date filter",
                ],
            ],
        ]);
    }

    public function customerShow($params)
    {
        $user = AuthMiddleware::requireTenantUser();
        $tenantId = (int) $user["tenant_id"];
        $customerId = (int) ($params["id"] ?? 0);
        $filters = $this->parseFilters($_GET);

        $customer = $this->findCustomerSummary($tenantId, $customerId, $filters);

        if (!$customer) {
            Response::json(["success" => false, "message" => "Customer not found"], 404);
        }

        $exportRow = $this->buildExportCustomer($tenantId, $customer, $filters);

        Response::json([
            "success" => true,
            "customer" => $exportRow,
            "filters" => [
                "date_from" => $filters["date_from"],
                "date_to" => $filters["date_to"],
                "filter_label" => $filters["filter_label"],
            ],
        ]);
    }

    public function exportCustomers()
    {
        $user = AuthMiddleware::requireTenantUser();
        Csrf::validateRequest();

        $tenantId = (int) $user["tenant_id"];
        $data = $this->readJson();
        $mode = strtolower(trim((string) ($data["mode"] ?? "filtered")));

        if (!in_array($mode, self::EXPORT_MODES, true)) {
            Validation::fail(["mode" => "Invalid export mode."]);
        }

        $filters = $this->parseFilters(is_array($data["filters"] ?? null) ? $data["filters"] : []);
        $requestedIds = $data["customer_ids"] ?? [];

        if (!is_array($requestedIds)) {
            Validation::fail(["customer_ids" => "Customer IDs must be an array."]);
        }

        $requestedIds = array_values(array_unique(array_map("intval", $requestedIds)));
        $requestedIds = array_values(array_filter($requestedIds, function ($id) {
            return $id > 0;
        }));

        if ($mode === "selected") {
            if (!$requestedIds) {
                Validation::fail(["customer_ids" => "Select at least one customer to export."]);
            }

            if (count($requestedIds) > self::MAX_EXPORT) {
                Validation::fail([
                    "customer_ids" => "Export is limited to " . self::MAX_EXPORT . " customers at a time.",
                ]);
            }

            $customers = $this->fetchCustomersByIds($tenantId, $requestedIds, $filters);
            if (count($customers) !== count($requestedIds)) {
                Response::json([
                    "success" => false,
                    "message" => "One or more selected customers were not found for this business.",
                ], 404);
            }
        } elseif ($mode === "all") {
            // All customers for tenant (status filter only; ignore date/name/phone for membership).
            $allFilters = $filters;
            $allFilters["search_name"] = "";
            $allFilters["search_phone"] = "";
            $allFilters["search_email"] = "";
            $allFilters["date_from"] = null;
            $allFilters["date_to"] = null;
            $allFilters["date_preset"] = "";
            $allFilters["filter_label"] = $filters["filter_label"];
            $allFilters["require_activity"] = false;

            $customers = $this->fetchCustomersForExport($tenantId, $allFilters, self::MAX_EXPORT + 1);
            // Still apply original date range to bill/payment sheet contents.
            $filtersForSheets = $filters;
        } else {
            $customers = $this->fetchCustomersForExport($tenantId, $filters, self::MAX_EXPORT + 1);
            $filtersForSheets = $filters;
        }

        if ($mode !== "all") {
            $filtersForSheets = $filters;
        } else {
            // Keep bill/payment date filters from request for sheet contents.
            $filtersForSheets = $filters;
        }

        if (!$customers) {
            Validation::fail(["customers" => "No customers matched the export criteria."]);
        }

        if (count($customers) > self::MAX_EXPORT) {
            Validation::fail([
                "customers" => "Export is limited to " . self::MAX_EXPORT . " customers. Narrow filters or select fewer customers.",
            ]);
        }

        $exportRows = [];
        foreach ($customers as $customer) {
            $exportRows[] = $this->buildExportCustomer($tenantId, $customer, $filtersForSheets);
        }

        $business = $user["tenant"] ?? (new Tenant())->findById($tenantId);
        if (!$business) {
            Response::json(["success" => false, "message" => "Business profile not found"], 404);
        }

        $path = null;

        try {
            $excel = new CustomerReportExcel();
            $path = $excel->build($business, $exportRows, [
                "generated_at" => date("d-m-Y H:i"),
                "filter_label" => $filters["filter_label"],
                "date_from" => $filters["date_from"],
                "date_to" => $filters["date_to"],
            ]);

            $downloadName = "Customer_Report_" . date("Ymd_His") . ".xlsx";
            $this->streamDownload($path, $downloadName);
        } catch (InvalidArgumentException $exception) {
            Response::json([
                "success" => false,
                "message" => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            Response::json([
                "success" => false,
                "message" => "Could not generate Excel export. Please try again.",
            ], 500);
        } finally {
            if ($path && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function parseFilters(array $input)
    {
        $searchName = trim((string) ($input["search_name"] ?? $input["name"] ?? ""));
        $searchPhone = preg_replace("/\D/", "", (string) ($input["search_phone"] ?? $input["phone"] ?? ""));
        $searchEmail = strtolower(trim((string) ($input["search_email"] ?? $input["email"] ?? "")));
        $status = strtolower(trim((string) ($input["status"] ?? "")));
        $preset = strtolower(trim((string) ($input["date_preset"] ?? "")));
        $dateFrom = trim((string) ($input["date_from"] ?? ""));
        $dateTo = trim((string) ($input["date_to"] ?? ""));

        if (strlen($searchName) > 100) {
            $searchName = substr($searchName, 0, 100);
        }

        if (strlen($searchPhone) > 15) {
            $searchPhone = substr($searchPhone, 0, 15);
        }

        if (strlen($searchEmail) > 254) {
            $searchEmail = substr($searchEmail, 0, 254);
        }

        if ($status !== "" && !in_array($status, ["active", "inactive"], true)) {
            Validation::fail(["status" => "Status must be active or inactive."]);
        }

        if ($preset !== "" && !in_array($preset, self::DATE_PRESETS, true)) {
            Validation::fail(["date_preset" => "Invalid date preset."]);
        }

        $resolved = $this->resolveDateRange($preset, $dateFrom, $dateTo);

        return [
            "search_name" => $searchName,
            "search_phone" => $searchPhone,
            "search_email" => $searchEmail,
            "status" => $status,
            "date_preset" => $preset,
            "date_from" => $resolved["from"],
            "date_to" => $resolved["to"],
            "filter_label" => $resolved["label"],
            "require_activity" => $resolved["from"] !== null && $resolved["to"] !== null,
        ];
    }

    private function resolveDateRange($preset, $dateFrom, $dateTo)
    {
        $today = new DateTimeImmutable("today");

        if ($preset === "today") {
            $day = $today->format("Y-m-d");
            return ["from" => $day, "to" => $day, "label" => "Today (" . $today->format("d-m-Y") . ")"];
        }

        if ($preset === "this_week") {
            $from = $today->modify("monday this week");
            $to = $from->modify("+6 days");
            return [
                "from" => $from->format("Y-m-d"),
                "to" => $to->format("Y-m-d"),
                "label" => "This week (" . $from->format("d-m-Y") . " to " . $to->format("d-m-Y") . ")",
            ];
        }

        if ($preset === "this_month") {
            $from = $today->modify("first day of this month");
            $to = $today->modify("last day of this month");
            return [
                "from" => $from->format("Y-m-d"),
                "to" => $to->format("Y-m-d"),
                "label" => "This month (" . $from->format("d-m-Y") . " to " . $to->format("d-m-Y") . ")",
            ];
        }

        if ($preset === "last_month") {
            $from = $today->modify("first day of last month");
            $to = $today->modify("last day of last month");
            return [
                "from" => $from->format("Y-m-d"),
                "to" => $to->format("Y-m-d"),
                "label" => "Last month (" . $from->format("d-m-Y") . " to " . $to->format("d-m-Y") . ")",
            ];
        }

        if ($preset === "custom" || $dateFrom !== "" || $dateTo !== "") {
            if ($dateFrom === "" || $dateTo === "") {
                Validation::fail([
                    "date_from" => "Provide both start and end dates for a custom range.",
                ]);
            }

            if (!preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $dateFrom) || !preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $dateTo)) {
                Validation::fail(["date_from" => "Enter valid dates (YYYY-MM-DD)."]);
            }

            if ($dateFrom > $dateTo) {
                Validation::fail(["date_to" => "End date must be on or after the start date."]);
            }

            $fromLabel = date("d-m-Y", strtotime($dateFrom));
            $toLabel = date("d-m-Y", strtotime($dateTo));

            return [
                "from" => $dateFrom,
                "to" => $dateTo,
                "label" => "Custom (" . $fromLabel . " to " . $toLabel . ")",
            ];
        }

        return ["from" => null, "to" => null, "label" => "All dates"];
    }

    private function queryCustomerReport($tenantId, array $filters, $page, $perPage)
    {
        [$whereSql, $params] = $this->customerWhere($tenantId, $filters);

        $countSql = "SELECT COUNT(*) FROM customers c WHERE {$whereSql}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $select = $this->customerSelectSql($filters);
        $sql = "SELECT {$select}
                FROM customers c
                WHERE {$whereSql}
                ORDER BY c.customer_name ASC, c.id ASC
                LIMIT {$perPage} OFFSET {$offset}";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        $items = array_map([$this, "formatListRow"], $statement->fetchAll());

        return ["total" => $total, "items" => $items];
    }

    private function querySummary($tenantId, array $filters)
    {
        [$whereSql, $params] = $this->customerWhere($tenantId, $filters);

        $customerCountStmt = $this->db->prepare("SELECT COUNT(*) FROM customers c WHERE {$whereSql}");
        $customerCountStmt->execute($params);
        $customerCount = (int) $customerCountStmt->fetchColumn();

        $billDateSql = "";
        $payDateSql = "";
        if ($filters["date_from"] && $filters["date_to"]) {
            $billDateSql = " AND b.bill_date BETWEEN :bill_from AND :bill_to";
            $payDateSql = " AND p.payment_date BETWEEN :pay_from AND :pay_to";
        }

        $billedParams = $params;
        $billedSql = "SELECT COALESCE(SUM(b.total_amount), 0)
                      FROM bills b
                      INNER JOIN customers c ON c.id = b.customer_id AND c.tenant_id = b.tenant_id
                      WHERE {$whereSql} AND b.tenant_id = :tenant_id_bills {$billDateSql}";
        $billedParams[":tenant_id_bills"] = $tenantId;
        if ($billDateSql !== "") {
            $billedParams[":bill_from"] = $filters["date_from"];
            $billedParams[":bill_to"] = $filters["date_to"];
        }
        $billedStmt = $this->db->prepare($billedSql);
        $billedStmt->execute($billedParams);
        $totalBilled = (float) $billedStmt->fetchColumn();

        $paidParams = $params;
        $paidSql = "SELECT COALESCE(SUM(p.amount), 0)
                    FROM payments p
                    INNER JOIN customers c ON c.id = p.customer_id AND c.tenant_id = p.tenant_id
                    WHERE {$whereSql} AND p.tenant_id = :tenant_id_pay {$payDateSql}";
        $paidParams[":tenant_id_pay"] = $tenantId;
        if ($payDateSql !== "") {
            $paidParams[":pay_from"] = $filters["date_from"];
            $paidParams[":pay_to"] = $filters["date_to"];
        }
        $paidStmt = $this->db->prepare($paidSql);
        $paidStmt->execute($paidParams);
        $totalPaid = (float) $paidStmt->fetchColumn();

        $outstandingParams = $params;
        $outstandingSql = "SELECT COALESCE(SUM(b.balance_amount), 0)
                           FROM bills b
                           INNER JOIN customers c ON c.id = b.customer_id AND c.tenant_id = b.tenant_id
                           WHERE {$whereSql} AND b.tenant_id = :tenant_id_out {$billDateSql}";
        $outstandingParams[":tenant_id_out"] = $tenantId;
        if ($billDateSql !== "") {
            $outstandingParams[":bill_from"] = $filters["date_from"];
            $outstandingParams[":bill_to"] = $filters["date_to"];
        }
        $outstandingStmt = $this->db->prepare($outstandingSql);
        $outstandingStmt->execute($outstandingParams);
        $outstanding = (float) $outstandingStmt->fetchColumn();

        $billCountParams = $params;
        $billCountSql = "SELECT COUNT(*)
                         FROM bills b
                         INNER JOIN customers c ON c.id = b.customer_id AND c.tenant_id = b.tenant_id
                         WHERE {$whereSql} AND b.tenant_id = :tenant_id_bc {$billDateSql}";
        $billCountParams[":tenant_id_bc"] = $tenantId;
        if ($billDateSql !== "") {
            $billCountParams[":bill_from"] = $filters["date_from"];
            $billCountParams[":bill_to"] = $filters["date_to"];
        }
        $billCountStmt = $this->db->prepare($billCountSql);
        $billCountStmt->execute($billCountParams);
        $totalBills = (int) $billCountStmt->fetchColumn();

        return [
            "total_customers" => $customerCount,
            "total_bills" => $totalBills,
            "total_billed" => round($totalBilled, 2),
            "total_received" => round($totalPaid, 2),
            "total_outstanding" => round($outstanding, 2),
        ];
    }

    private function customerWhere($tenantId, array $filters)
    {
        $where = ["c.tenant_id = :tenant_id"];
        $params = [":tenant_id" => $tenantId];

        if ($filters["search_name"] !== "") {
            $where[] = "c.customer_name LIKE :search_name";
            $params[":search_name"] = "%" . $filters["search_name"] . "%";
        }

        if ($filters["search_phone"] !== "") {
            $where[] = "c.phone LIKE :search_phone";
            $params[":search_phone"] = "%" . $filters["search_phone"] . "%";
        }

        if (!empty($filters["search_email"])) {
            $where[] = "LOWER(COALESCE(c.email, '')) LIKE :search_email";
            $params[":search_email"] = "%" . $filters["search_email"] . "%";
        }

        if ($filters["status"] !== "") {
            $where[] = "c.status = :status";
            $params[":status"] = $filters["status"];
        }

        if (!empty($filters["require_activity"]) && $filters["date_from"] && $filters["date_to"]) {
            $where[] = "(
                EXISTS (
                    SELECT 1 FROM bills b_act
                    WHERE b_act.customer_id = c.id
                      AND b_act.tenant_id = c.tenant_id
                      AND b_act.bill_date BETWEEN :act_bill_from AND :act_bill_to
                )
                OR EXISTS (
                    SELECT 1 FROM payments p_act
                    WHERE p_act.customer_id = c.id
                      AND p_act.tenant_id = c.tenant_id
                      AND p_act.payment_date BETWEEN :act_pay_from AND :act_pay_to
                )
            )";
            $params[":act_bill_from"] = $filters["date_from"];
            $params[":act_bill_to"] = $filters["date_to"];
            $params[":act_pay_from"] = $filters["date_from"];
            $params[":act_pay_to"] = $filters["date_to"];
        }

        return [implode(" AND ", $where), $params];
    }

    private function customerSelectSql(array $filters)
    {
        $billDate = "";
        $payDate = "";

        if ($filters["date_from"] && $filters["date_to"]) {
            $billDate = " AND b.bill_date BETWEEN " . $this->db->quote($filters["date_from"])
                . " AND " . $this->db->quote($filters["date_to"]);
            $payDate = " AND p.payment_date BETWEEN " . $this->db->quote($filters["date_from"])
                . " AND " . $this->db->quote($filters["date_to"]);
        }

        // Quoted date literals are safe (validated YYYY-MM-DD). Keeps bind params simpler in subqueries.
        return "c.id, c.customer_name, c.phone, c.email, c.address, c.city, c.notes, c.status, c.created_at,
            (
                SELECT COUNT(*) FROM bills b
                WHERE b.customer_id = c.id AND b.tenant_id = c.tenant_id {$billDate}
            ) AS total_bills,
            (
                SELECT COALESCE(SUM(b.total_amount), 0) FROM bills b
                WHERE b.customer_id = c.id AND b.tenant_id = c.tenant_id {$billDate}
            ) AS total_billed,
            (
                SELECT COALESCE(SUM(p.amount), 0) FROM payments p
                WHERE p.customer_id = c.id AND p.tenant_id = c.tenant_id {$payDate}
            ) AS total_paid,
            (
                SELECT COALESCE(SUM(b.balance_amount), 0) FROM bills b
                WHERE b.customer_id = c.id AND b.tenant_id = c.tenant_id {$billDate}
            ) AS outstanding_balance";
    }

    private function formatListRow(array $row)
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
            "total_bills" => (int) $row["total_bills"],
            "total_billed" => round((float) $row["total_billed"], 2),
            "total_paid" => round((float) $row["total_paid"], 2),
            "outstanding_balance" => round((float) $row["outstanding_balance"], 2),
        ];
    }

    private function findCustomerSummary($tenantId, $customerId, array $filters)
    {
        $select = $this->customerSelectSql($filters);
        $statement = $this->db->prepare(
            "SELECT {$select} FROM customers c
             WHERE c.id = :id AND c.tenant_id = :tenant_id
             LIMIT 1"
        );
        $statement->execute([":id" => $customerId, ":tenant_id" => $tenantId]);
        $row = $statement->fetch();

        return $row ? $this->formatListRow($row) : null;
    }

    private function fetchCustomersByIds($tenantId, array $ids, array $filters)
    {
        if (!$ids) {
            return [];
        }

        $placeholders = [];
        $params = [":tenant_id" => $tenantId];
        foreach ($ids as $index => $id) {
            $key = ":id" . $index;
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        $select = $this->customerSelectSql($filters);
        $sql = "SELECT {$select}
                FROM customers c
                WHERE c.tenant_id = :tenant_id AND c.id IN (" . implode(",", $placeholders) . ")
                ORDER BY FIELD(c.id, " . implode(",", array_map("intval", $ids)) . ")";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return array_map([$this, "formatListRow"], $statement->fetchAll());
    }

    private function fetchCustomersForExport($tenantId, array $filters, $limit)
    {
        [$whereSql, $params] = $this->customerWhere($tenantId, $filters);
        $select = $this->customerSelectSql($filters);
        $limit = (int) $limit;
        $sql = "SELECT {$select}
                FROM customers c
                WHERE {$whereSql}
                ORDER BY c.customer_name ASC, c.id ASC
                LIMIT {$limit}";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return array_map([$this, "formatListRow"], $statement->fetchAll());
    }

    private function buildExportCustomer($tenantId, array $customer, array $filters)
    {
        $customerId = (int) $customer["id"];

        // Separate queries avoid join-duplicated bill/payment totals.
        $customer["bills"] = $this->fetchBills($tenantId, $customerId, $filters);
        $customer["bill_items"] = $this->fetchBillItems($tenantId, $customerId, $filters);
        $customer["payments"] = $this->fetchPayments($tenantId, $customerId, $filters);

        return $customer;
    }

    private function fetchBills($tenantId, $customerId, array $filters)
    {
        $sql = "SELECT b.bill_number, b.bill_date, b.total_amount, b.paid_amount, b.balance_amount, b.payment_status
                FROM bills b
                WHERE b.tenant_id = :tenant_id AND b.customer_id = :customer_id";
        $params = [
            ":tenant_id" => $tenantId,
            ":customer_id" => $customerId,
        ];

        if ($filters["date_from"] && $filters["date_to"]) {
            $sql .= " AND b.bill_date BETWEEN :from AND :to";
            $params[":from"] = $filters["date_from"];
            $params[":to"] = $filters["date_to"];
        }

        $sql .= " ORDER BY b.bill_date ASC, b.id ASC";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return array_map(function ($row) {
            return [
                "bill_number" => $row["bill_number"],
                "bill_date" => $row["bill_date"],
                "total_amount" => (float) $row["total_amount"],
                "paid_amount" => (float) $row["paid_amount"],
                "balance_amount" => (float) $row["balance_amount"],
                "payment_status" => $row["payment_status"],
            ];
        }, $statement->fetchAll());
    }

    private function fetchBillItems($tenantId, $customerId, array $filters)
    {
        $sql = "SELECT b.bill_number,
                       bi.product_name_snapshot, bi.unit_snapshot, bi.width, bi.height, bi.quantity,
                       bi.rate_snapshot, bi.area, bi.amount AS item_amount
                FROM bills b
                INNER JOIN bill_items bi ON bi.bill_id = b.id AND bi.tenant_id = b.tenant_id
                WHERE b.tenant_id = :tenant_id AND b.customer_id = :customer_id";
        $params = [
            ":tenant_id" => $tenantId,
            ":customer_id" => $customerId,
        ];

        if ($filters["date_from"] && $filters["date_to"]) {
            $sql .= " AND b.bill_date BETWEEN :from AND :to";
            $params[":from"] = $filters["date_from"];
            $params[":to"] = $filters["date_to"];
        }

        $sql .= " ORDER BY b.bill_date ASC, b.id ASC, bi.id ASC";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return array_map(function ($row) {
            return [
                "bill_number" => $row["bill_number"],
                "product_name" => $row["product_name_snapshot"],
                "unit" => $row["unit_snapshot"],
                "unit_label" => BillingCalc::unitLabel($row["unit_snapshot"]),
                "width" => $row["width"] !== null ? (float) $row["width"] : null,
                "height" => $row["height"] !== null ? (float) $row["height"] : null,
                "quantity" => (float) $row["quantity"],
                "rate" => (float) $row["rate_snapshot"],
                "area" => $row["area"] !== null ? (float) $row["area"] : null,
                "item_amount" => (float) $row["item_amount"],
            ];
        }, $statement->fetchAll());
    }

    private function fetchPayments($tenantId, $customerId, array $filters)
    {
        $sql = "SELECT p.id, p.payment_date, p.payment_method, p.amount, p.reference_number, p.notes,
                       b.bill_number
                FROM payments p
                INNER JOIN bills b ON b.id = p.bill_id AND b.tenant_id = p.tenant_id
                WHERE p.tenant_id = :tenant_id AND p.customer_id = :customer_id";
        $params = [
            ":tenant_id" => $tenantId,
            ":customer_id" => $customerId,
        ];

        if ($filters["date_from"] && $filters["date_to"]) {
            $sql .= " AND p.payment_date BETWEEN :from AND :to";
            $params[":from"] = $filters["date_from"];
            $params[":to"] = $filters["date_to"];
        }

        $sql .= " ORDER BY p.payment_date ASC, p.id ASC";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return array_map(function ($row) {
            return [
                "id" => (int) $row["id"],
                "bill_number" => $row["bill_number"],
                "payment_date" => $row["payment_date"],
                "payment_method" => $row["payment_method"],
                "payment_method_label" => PaymentController::methodLabel($row["payment_method"]),
                "amount" => (float) $row["amount"],
                "reference_number" => $row["reference_number"],
                "notes" => $row["notes"],
            ];
        }, $statement->fetchAll());
    }

    private function streamDownload($path, $downloadName)
    {
        if (!is_file($path)) {
            Response::json(["success" => false, "message" => "Export file missing"], 500);
        }

        // Clear any prior output buffering so the binary stream is clean.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
        header("Content-Disposition: attachment; filename=\"" . $downloadName . "\"");
        header("Content-Length: " . filesize($path));
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("Pragma: no-cache");

        readfile($path);
        exit;
    }

    private function readJson()
    {
        $json = json_decode(file_get_contents("php://input"), true);
        return is_array($json) ? $json : [];
    }
}

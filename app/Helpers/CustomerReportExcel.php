<?php

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Fixed 6-sheet customer report workbook:
 * Dashboard | Customers | Bills | Bill Items | Payments | Customer Products
 */
class CustomerReportExcel
{
    private const NAVY = "0B1F3A";
    private const HEADER_BLUE = "1F4E79";
    private const ALT_ROW = "EAF3FB";
    private const WHITE = "FFFFFF";
    private const GREEN_FILL = "C6EFCE";
    private const GREEN_FONT = "006100";
    private const ORANGE_FILL = "FCE4D6";
    private const ORANGE_FONT = "C65911";
    private const RED_FILL = "F8CBAD";
    private const RED_FONT = "9C0006";
    private const MUTED = "667085";
    private const MAX_CUSTOMERS = 75;

    public static function maxCustomers()
    {
        return self::MAX_CUSTOMERS;
    }

    /**
     * @param array $business Tenant public fields
     * @param array $customers Export customer rows with bills / bill_items / payments
     * @param array $meta generated_at, filter_label, date_from, date_to
     * @return string Absolute path to temporary .xlsx file
     */
    public function build(array $business, array $customers, array $meta)
    {
        if (count($customers) > self::MAX_CUSTOMERS) {
            throw new InvalidArgumentException(
                "Export is limited to " . self::MAX_CUSTOMERS . " customers at a time. Narrow your selection or filters."
            );
        }

        $dataset = $this->flattenDataset($customers);
        $totals = $this->computeTotals($customers, $dataset);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator($business["business_name"] ?? "PrintDesk")
            ->setTitle("Customer Report")
            ->setSubject("Customer financial report")
            ->setDescription("Dashboard, customers, bills, bill items, payments and customer products");

        $dashboard = $spreadsheet->getActiveSheet();
        $dashboard->setTitle("Dashboard");
        $this->writeDashboard($dashboard, $business, $customers, $meta, $totals);

        $customersSheet = $spreadsheet->createSheet();
        $customersSheet->setTitle("Customers");
        $this->writeCustomersSheet($customersSheet, $business, $customers, $meta);

        $billsSheet = $spreadsheet->createSheet();
        $billsSheet->setTitle("Bills");
        $this->writeBillsSheet($billsSheet, $business, $dataset["bills"], $meta);

        $itemsSheet = $spreadsheet->createSheet();
        $itemsSheet->setTitle("Bill Items");
        $this->writeBillItemsSheet($itemsSheet, $business, $dataset["bill_items"], $meta);

        $paymentsSheet = $spreadsheet->createSheet();
        $paymentsSheet->setTitle("Payments");
        $this->writePaymentsSheet($paymentsSheet, $business, $dataset["payments"], $meta);

        $productsSheet = $spreadsheet->createSheet();
        $productsSheet->setTitle("Customer Products");
        $this->writeCustomerProductsSheet($productsSheet, $business, $dataset["bill_items"], $meta);

        $spreadsheet->setActiveSheetIndex(0);

        $tmpDir = dirname(__DIR__, 2) . "/storage/exports";
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new RuntimeException("Could not create export directory.");
        }

        $filename = "Customer_Report_" . date("Ymd_His") . "_" . bin2hex(random_bytes(4)) . ".xlsx";
        $path = $tmpDir . "/" . $filename;

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $path;
    }

    private function flattenDataset(array $customers)
    {
        $bills = [];
        $billItems = [];
        $payments = [];

        foreach ($customers as $customer) {
            $customerId = (int) $customer["id"];
            $customerName = $customer["customer_name"];
            $customerPhone = $customer["phone"] ?? "";

            foreach ($customer["bills"] ?? [] as $bill) {
                $bills[] = array_merge($bill, [
                    "customer_id" => $customerId,
                    "customer_name" => $customerName,
                ]);
            }

            foreach ($customer["bill_items"] ?? [] as $item) {
                $billItems[] = array_merge($item, [
                    "customer_id" => $customerId,
                    "customer_name" => $customerName,
                    "customer_phone" => $customerPhone,
                ]);
            }

            foreach ($customer["payments"] ?? [] as $payment) {
                $payments[] = array_merge($payment, [
                    "customer_id" => $customerId,
                    "customer_name" => $customerName,
                ]);
            }
        }

        return [
            "bills" => $bills,
            "bill_items" => $billItems,
            "payments" => $payments,
        ];
    }

    private function computeTotals(array $customers, array $dataset)
    {
        $totalBilled = 0.0;
        $totalPaid = 0.0;
        $totalOutstanding = 0.0;

        foreach ($customers as $customer) {
            $totalBilled += (float) ($customer["total_billed"] ?? 0);
            $totalPaid += (float) ($customer["total_paid"] ?? 0);
            $totalOutstanding += (float) ($customer["outstanding_balance"] ?? 0);
        }

        return [
            "total_customers" => count($customers),
            "total_bills" => count($dataset["bills"]),
            "total_sales" => round($totalBilled, 2),
            "total_payments" => round($totalPaid, 2),
            "total_outstanding" => round($totalOutstanding, 2),
        ];
    }

    private function writeDashboard(Worksheet $sheet, array $business, array $customers, array $meta, array $totals)
    {
        $sheet->getSheetView()->setZoomScale(100);
        $sheet->getDefaultRowDimension()->setRowHeight(18);

        $this->applyLogo($sheet, $business, "A1");
        $this->styleTitleBlock($sheet, "B1:H1", $business["business_name"] ?? "Business Report", 20);
        $this->styleTitleBlock($sheet, "B2:H2", "Customer Financial Report", 14);
        $sheet->setCellValue("B3", "Generated: " . ($meta["generated_at"] ?? date("d-m-Y H:i")));
        $sheet->setCellValue("B4", "Date range: " . ($meta["filter_label"] ?? "All dates"));
        $sheet->getStyle("B3:B4")->getFont()->getColor()->setRGB(self::MUTED);
        $sheet->mergeCells("B3:H3");
        $sheet->mergeCells("B4:H4");

        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getRowDimension(2)->setRowHeight(24);

        // KPI cards
        $kpis = [
            ["A6", "B6", "Total Customers", $totals["total_customers"], false],
            ["C6", "D6", "Total Bills", $totals["total_bills"], false],
            ["E6", "F6", "Total Sales", $totals["total_sales"], true],
            ["G6", "H6", "Payments Received", $totals["total_payments"], true],
            ["A8", "B8", "Outstanding Balance", $totals["total_outstanding"], true],
        ];

        foreach ($kpis as $kpi) {
            $this->writeKpiCard($sheet, $kpi[0], $kpi[1], $kpi[2], $kpi[3], $kpi[4]);
        }

        $sheet->setCellValue("C8", "Navigation");
        $sheet->setCellValue("D8", "Customers");
        $sheet->setCellValue("E8", "Bills");
        $sheet->setCellValue("F8", "Bill Items");
        $sheet->setCellValue("G8", "Payments");
        $sheet->getCell("D8")->getHyperlink()->setUrl("sheet://'Customers'!A1");
        $sheet->getCell("E8")->getHyperlink()->setUrl("sheet://'Bills'!A1");
        $sheet->getCell("F8")->getHyperlink()->setUrl("sheet://'Bill Items'!A1");
        $sheet->getCell("G8")->getHyperlink()->setUrl("sheet://'Payments'!A1");
        $sheet->getStyle("D8:G8")->getFont()->setBold(true)->setUnderline(Font::UNDERLINE_SINGLE)
            ->getColor()->setRGB("1F4E79");
        $sheet->getStyle("C8")->getFont()->setBold(true);

        $headers = [
            "Customer Name",
            "Phone",
            "Total Bills",
            "Total Billed",
            "Total Paid",
            "Outstanding Balance",
            "Go to Bills",
        ];

        $headerRow = 11;
        $sheet->setCellValue("A10", "Customer-wise Summary");
        $sheet->mergeCells("A10:G10");
        $sheet->getStyle("A10")->getFont()->setBold(true)->setSize(13)->getColor()->setRGB(self::NAVY);
        $sheet->getRowDimension(10)->setRowHeight(22);

        $this->writeHeaderRow($sheet, $headers, $headerRow);
        $row = $headerRow + 1;
        $startData = $row;

        foreach ($customers as $index => $customer) {
            $sheet->fromArray([
                $customer["customer_name"],
                $customer["phone"],
                (int) $customer["total_bills"],
                (float) $customer["total_billed"],
                (float) $customer["total_paid"],
                (float) $customer["outstanding_balance"],
                "Open Bills",
            ], null, "A" . $row);

            $customerRowOnCustomersSheet = 5 + $index; // Customers sheet data starts at row 5
            $sheet->getCell("A{$row}")->getHyperlink()
                ->setUrl("sheet://'Customers'!A{$customerRowOnCustomersSheet}");
            $sheet->getCell("G{$row}")->getHyperlink()->setUrl("sheet://'Bills'!A1");
            $sheet->getStyle("A{$row}")->getFont()->setUnderline(Font::UNDERLINE_SINGLE)
                ->getColor()->setRGB("1F4E79");
            $sheet->getStyle("G{$row}")->getFont()->setUnderline(Font::UNDERLINE_SINGLE)
                ->getColor()->setRGB("1F4E79");

            $this->styleCurrency($sheet, "D{$row}:F{$row}");
            $this->applyOutstandingHighlight($sheet, "F{$row}", (float) $customer["outstanding_balance"]);
            $this->applyPaidHighlight($sheet, "E{$row}", (float) $customer["total_paid"]);
            $this->applyAltRow($sheet, "A{$row}:G{$row}", $index);
            $row++;
        }

        if ($row === $startData) {
            $sheet->setCellValue("A{$row}", "No customers in this export.");
            $sheet->mergeCells("A{$row}:G{$row}");
            $row++;
        }

        $lastDataRow = max($startData, $row - 1);
        $this->finalizeTable($sheet, $headerRow, $lastDataRow, count($headers));
        $this->autosize($sheet, count($headers));
        $sheet->getColumnDimension("G")->setWidth(14);
        $sheet->freezePane("A" . ($headerRow + 1));
    }

    private function writeCustomersSheet(Worksheet $sheet, array $business, array $customers, array $meta)
    {
        $headers = [
            "Customer ID",
            "Customer Name",
            "Phone",
            "Email",
            "Address",
            "City",
            "Notes",
            "Status",
            "Created Date",
        ];

        $this->writeSheetBanner($sheet, $business, $meta, "Customers", count($headers));
        $headerRow = 4;
        $this->writeHeaderRow($sheet, $headers, $headerRow);

        $row = $headerRow + 1;
        $start = $row;
        foreach ($customers as $index => $customer) {
            $sheet->fromArray([
                (int) $customer["id"],
                $customer["customer_name"],
                $customer["phone"],
                $customer["email"] ?? "",
                $customer["address"] ?? "",
                $customer["city"] ?? "",
                $customer["notes"] ?? "",
                $customer["status"],
                $this->formatDate($customer["created_at"] ?? null),
            ], null, "A" . $row);
            $this->applyAltRow($sheet, "A{$row}:I{$row}", $index);
            $row++;
        }

        if ($row === $start) {
            $sheet->setCellValue("A{$row}", "No customers.");
            $sheet->mergeCells("A{$row}:I{$row}");
            $row++;
        }

        $this->finalizeTable($sheet, $headerRow, max($start, $row - 1), count($headers));
        $this->autosize($sheet, count($headers));
        $sheet->freezePane("A" . ($headerRow + 1));
    }

    private function writeBillsSheet(Worksheet $sheet, array $business, array $bills, array $meta)
    {
        $headers = [
            "Bill Number",
            "Customer ID",
            "Customer Name",
            "Bill Date",
            "Total Amount",
            "Total Paid",
            "Outstanding Balance",
            "Payment Status",
        ];

        $this->writeSheetBanner($sheet, $business, $meta, "Bills (one row per bill)", count($headers));
        $headerRow = 4;
        $this->writeHeaderRow($sheet, $headers, $headerRow);

        $row = $headerRow + 1;
        $start = $row;
        foreach ($bills as $index => $bill) {
            $sheet->fromArray([
                $bill["bill_number"],
                (int) $bill["customer_id"],
                $bill["customer_name"],
                $this->formatDate($bill["bill_date"]),
                (float) $bill["total_amount"],
                (float) $bill["paid_amount"],
                (float) $bill["balance_amount"],
                $bill["payment_status"],
            ], null, "A" . $row);

            $this->styleCurrency($sheet, "E{$row}:G{$row}");
            $this->applyPaymentStatusStyle($sheet, "H{$row}", (string) $bill["payment_status"]);
            $this->applyOutstandingHighlight($sheet, "G{$row}", (float) $bill["balance_amount"]);
            $this->applyPaidHighlight($sheet, "F{$row}", (float) $bill["paid_amount"]);
            $this->applyAltRow($sheet, "A{$row}:H{$row}", $index);
            $row++;
        }

        if ($row === $start) {
            $sheet->setCellValue("A{$row}", "No bills in the selected bill-date range.");
            $sheet->mergeCells("A{$row}:H{$row}");
            $row++;
        }

        $last = max($start, $row - 1);
        $this->finalizeTable($sheet, $headerRow, $last, count($headers));
        $this->autosize($sheet, count($headers));
        $sheet->freezePane("A" . ($headerRow + 1));
    }

    private function writeBillItemsSheet(Worksheet $sheet, array $business, array $items, array $meta)
    {
        $headers = [
            "Bill Number",
            "Customer ID",
            "Customer Name",
            "Product Name",
            "Unit",
            "Width",
            "Height",
            "Quantity",
            "Rate Snapshot",
            "Area",
            "Item Amount",
        ];

        $this->writeSheetBanner($sheet, $business, $meta, "Bill Items (historical snapshots)", count($headers));
        $headerRow = 4;
        $this->writeHeaderRow($sheet, $headers, $headerRow);

        $row = $headerRow + 1;
        $start = $row;
        foreach ($items as $index => $item) {
            $sheet->fromArray([
                $item["bill_number"],
                (int) $item["customer_id"],
                $item["customer_name"],
                $item["product_name"],
                $item["unit_label"] ?? $item["unit"],
                $item["width"],
                $item["height"],
                (float) $item["quantity"],
                (float) $item["rate"],
                $item["area"],
                (float) $item["item_amount"],
            ], null, "A" . $row);

            $this->styleCurrency($sheet, "I{$row}");
            $this->styleCurrency($sheet, "K{$row}");
            $this->applyAltRow($sheet, "A{$row}:K{$row}", $index);
            $row++;
        }

        if ($row === $start) {
            $sheet->setCellValue("A{$row}", "No bill items in the selected bill-date range.");
            $sheet->mergeCells("A{$row}:K{$row}");
            $row++;
        }

        $this->finalizeTable($sheet, $headerRow, max($start, $row - 1), count($headers));
        $this->autosize($sheet, count($headers));
        $sheet->freezePane("A" . ($headerRow + 1));
    }

    private function writeCustomerProductsSheet(Worksheet $sheet, array $business, array $items, array $meta)
    {
        $headers = [
            "Customer ID",
            "Customer Name",
            "Customer Phone",
            "Bill Number",
            "Bill Date",
            "Product Name",
            "Unit",
            "Pricing Type",
            "Width",
            "Height",
            "Quantity",
            "Rate",
            "Area",
            "Product Amount",
        ];

        $this->writeSheetBanner($sheet, $business, $meta, "Customer Products (historical snapshots)", count($headers));
        $headerRow = 4;
        $this->writeHeaderRow($sheet, $headers, $headerRow);

        $row = $headerRow + 1;
        $start = $row;
        foreach ($items as $index => $item) {
            $sheet->fromArray([
                (int) $item["customer_id"],
                $item["customer_name"],
                $item["customer_phone"] ?? "",
                $item["bill_number"],
                $this->formatDate($item["bill_date"] ?? null),
                $item["product_name"],
                $item["unit_label"] ?? $item["unit"],
                $item["pricing_type_label"] ?? ($item["pricing_type"] ?? ""),
                $item["width"],
                $item["height"],
                (float) $item["quantity"],
                (float) $item["rate"],
                $item["area"],
                (float) $item["item_amount"],
            ], null, "A" . $row);

            $this->styleCurrency($sheet, "L{$row}");
            $this->styleCurrency($sheet, "N{$row}");
            $this->applyAltRow($sheet, "A{$row}:N{$row}", $index);
            $row++;
        }

        if ($row === $start) {
            $sheet->setCellValue("A{$row}", "No products in the selected bill-date range.");
            $sheet->mergeCells("A{$row}:N{$row}");
            $row++;
        }

        $this->finalizeTable($sheet, $headerRow, max($start, $row - 1), count($headers));
        $this->autosize($sheet, count($headers));
        $sheet->freezePane("A" . ($headerRow + 1));
    }

    private function writePaymentsSheet(Worksheet $sheet, array $business, array $payments, array $meta)
    {
        $headers = [
            "Payment ID",
            "Customer ID",
            "Customer Name",
            "Bill Number",
            "Payment Date",
            "Payment Method",
            "Amount",
            "Reference Number",
            "Notes",
        ];

        $this->writeSheetBanner($sheet, $business, $meta, "Payments (one row per transaction)", count($headers));
        $headerRow = 4;
        $this->writeHeaderRow($sheet, $headers, $headerRow);

        $row = $headerRow + 1;
        $start = $row;
        foreach ($payments as $index => $payment) {
            $sheet->fromArray([
                (int) $payment["id"],
                (int) $payment["customer_id"],
                $payment["customer_name"],
                $payment["bill_number"],
                $this->formatDate($payment["payment_date"]),
                $payment["payment_method_label"] ?? $payment["payment_method"],
                (float) $payment["amount"],
                $payment["reference_number"] ?? "",
                $payment["notes"] ?? "",
            ], null, "A" . $row);

            $this->styleCurrency($sheet, "G{$row}");
            $this->applyPaidHighlight($sheet, "G{$row}", (float) $payment["amount"]);
            $this->applyAltRow($sheet, "A{$row}:I{$row}", $index);
            $row++;
        }

        if ($row === $start) {
            $sheet->setCellValue("A{$row}", "No payments in the selected payment-date range.");
            $sheet->mergeCells("A{$row}:I{$row}");
            $row++;
        }

        $this->finalizeTable($sheet, $headerRow, max($start, $row - 1), count($headers));
        $this->autosize($sheet, count($headers));
        $sheet->freezePane("A" . ($headerRow + 1));
    }

    private function writeSheetBanner(Worksheet $sheet, array $business, array $meta, $title, $columnCount)
    {
        $lastCol = Coordinate::stringFromColumnIndex($columnCount);
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->mergeCells("A3:{$lastCol}3");

        $sheet->setCellValue("A1", $business["business_name"] ?? "Business Report");
        $sheet->setCellValue("A2", $title);
        $sheet->setCellValue(
            "A3",
            "Generated: " . ($meta["generated_at"] ?? date("d-m-Y H:i"))
            . "  |  Date range: " . ($meta["filter_label"] ?? "All dates")
            . "  |  Bills by bill date · Payments by payment date"
        );

        $sheet->getStyle("A1")->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::WHITE);
        $sheet->getStyle("A1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::NAVY);
        $sheet->getStyle("A2")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB(self::WHITE);
        $sheet->getStyle("A2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_BLUE);
        $sheet->getStyle("A3")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB(self::MUTED);
        $sheet->getRowDimension(1)->setRowHeight(26);
        $sheet->getRowDimension(2)->setRowHeight(22);
    }

    private function writeKpiCard(Worksheet $sheet, $labelCell, $valueCell, $label, $value, $isMoney)
    {
        $sheet->setCellValue($labelCell, $label);
        $sheet->setCellValue($valueCell, $value);
        $sheet->getStyle($labelCell)->getFont()->setBold(true)->setSize(9)->getColor()->setRGB(self::WHITE);
        $sheet->getStyle($labelCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::NAVY);
        $sheet->getStyle($labelCell)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle($valueCell)->getFont()->setBold(true)->setSize(14)->getColor()->setRGB(self::NAVY);
        $sheet->getStyle($valueCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::ALT_ROW);
        $sheet->getStyle($valueCell)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("{$labelCell}:{$valueCell}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        if ($isMoney) {
            $this->styleCurrency($sheet, $valueCell);
        }
        $sheet->getRowDimension((int) preg_replace("/\D/", "", $labelCell))->setRowHeight(24);
    }

    private function styleTitleBlock(Worksheet $sheet, $range, $text, $size)
    {
        $start = explode(":", $range)[0];
        $sheet->mergeCells($range);
        $sheet->setCellValue($start, $text);
        $sheet->getStyle($range)->getFont()->setBold(true)->setSize($size)->getColor()->setRGB(self::WHITE);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::NAVY);
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function applyLogo(Worksheet $sheet, array $business, $coordinate)
    {
        $relative = (string) ($business["logo"] ?? "");
        if ($relative === "") {
            $sheet->setCellValue($coordinate, "");
            return;
        }

        $absolute = dirname(__DIR__, 2) . "/public/" . ltrim(str_replace("\\", "/", $relative), "/");
        if (!is_file($absolute)) {
            return;
        }

        try {
            $drawing = new Drawing();
            $drawing->setName("Business Logo");
            $drawing->setPath($absolute);
            $drawing->setHeight(58);
            $drawing->setCoordinates($coordinate);
            $drawing->setOffsetX(4);
            $drawing->setOffsetY(4);
            $drawing->setWorksheet($sheet);
            $sheet->getColumnDimension("A")->setWidth(12);
            $sheet->getRowDimension(1)->setRowHeight(48);
            $sheet->mergeCells("A1:A4");
        } catch (Throwable $exception) {
            // Logo is optional — never fail the export.
        }
    }

    private function writeHeaderRow(Worksheet $sheet, array $headers, $row)
    {
        $sheet->fromArray($headers, null, "A{$row}");
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true)->getColor()->setRGB(self::WHITE);
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB(self::HEADER_BLUE);
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    private function finalizeTable(Worksheet $sheet, $headerRow, $lastRow, $columnCount)
    {
        if ($lastRow < $headerRow) {
            return;
        }

        $lastCol = Coordinate::stringFromColumnIndex($columnCount);
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$lastRow}")
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$lastRow}");
    }

    private function applyAltRow(Worksheet $sheet, $range, $index)
    {
        if ($index % 2 === 1) {
            $sheet->getStyle($range)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(self::ALT_ROW);
        }
    }

    private function applyOutstandingHighlight(Worksheet $sheet, $cell, $amount)
    {
        if ($amount > 0.009) {
            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(self::RED_FILL);
            $sheet->getStyle($cell)->getFont()->getColor()->setRGB(self::RED_FONT);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }
    }

    private function applyPaidHighlight(Worksheet $sheet, $cell, $amount)
    {
        if ($amount > 0.009) {
            $sheet->getStyle($cell)->getFont()->getColor()->setRGB(self::GREEN_FONT);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }
    }

    private function applyPaymentStatusStyle(Worksheet $sheet, $cell, $status)
    {
        $status = strtolower(trim($status));
        if ($status === "paid") {
            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(self::GREEN_FILL);
            $sheet->getStyle($cell)->getFont()->getColor()->setRGB(self::GREEN_FONT);
        } elseif ($status === "partially_paid") {
            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(self::ORANGE_FILL);
            $sheet->getStyle($cell)->getFont()->getColor()->setRGB(self::ORANGE_FONT);
        } elseif ($status === "unpaid") {
            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(self::RED_FILL);
            $sheet->getStyle($cell)->getFont()->getColor()->setRGB(self::RED_FONT);
        }
        $sheet->getStyle($cell)->getFont()->setBold(true);
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function styleCurrency(Worksheet $sheet, $range)
    {
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode('"₹"#,##0.00');
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    private function autosize(Worksheet $sheet, $columnCount)
    {
        for ($i = 1; $i <= $columnCount; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }

    private function formatDate($value)
    {
        if (!$value) {
            return "";
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return (string) $value;
        }

        return date("d-m-Y", $timestamp);
    }
}

<?php

declare(strict_types=1);

namespace App\Ai\Services;

/**
 * Deterministic builder that formats authoritative ERP facts into human-readable summaries.
 * Uses ONLY verified fields from tool payloads without hallucinating or inferring unverified data.
 */
class VerifiedSummaryBuilder
{
    /**
     * Build a deterministic markdown summary from verified facts and optional warnings.
     *
     * @param array<int, array{tool: string, as_of: ?string, data: array, reconciled: bool}> $facts
     * @param string[] $warnings
     */
    public function build(array $facts, array $warnings = []): string
    {
        if (empty($facts)) {
            return 'No verified ERP records were found for this query.';
        }

        $sections = [];
        foreach ($facts as $fact) {
            $tool = $fact['tool'] ?? '';
            $data = $fact['data'] ?? [];
            $asOf = $fact['as_of'] ?? null;
            $reconciled = (bool) ($fact['reconciled'] ?? true);

            $section = match ($tool) {
                'receivables.outstanding.v1' => $this->summarizeReceivablesOutstanding($data, $asOf, $reconciled),
                'receivables.invoices.v1' => $this->summarizeCustomerInvoices($data),
                'receivables.invoice_allocations.v1' => $this->summarizeInvoiceAllocations($data),
                'inventory.stock_summary.v1' => $this->summarizeInventoryStock($data, $reconciled),
                'sales.summary.v1' => $this->summarizeSales($data),
                'payables.summary.v1' => $this->summarizePayables($data, $asOf, $reconciled),
                'accounting.statements.v1' => $this->summarizeFinancialStatements($data),
                'tax.gst_summary.v1' => $this->summarizeGst($data),
                'orders.open.v1' => $this->summarizeOpenOrders($data),
                'manufacturing.boms.v1' => $this->summarizeManufacturingBoms($data),
                'jobwork.status.v1' => $this->summarizeJobWork($data),
                'documents.preview.v1' => $this->summarizeDocumentPreview($data),
                'knowledge.query.v1' => $this->summarizeKnowledge($data),
                'copilot.help.v1' => $this->summarizeHelp($data),
                default => $this->summarizeGeneric($tool, $data),
            };

            if ($section !== '') {
                $sections[] = $section;
            }
        }

        $result = implode("\n\n---\n\n", $sections);

        if (! empty($warnings)) {
            $uniqueWarnings = array_values(array_unique($warnings));
            $result .= "\n\n> **Notice:** " . implode(' ', $uniqueWarnings);
        }

        return $result;
    }

    protected function formatCurrency(string $code): string
    {
        return $code === 'INR' ? '₹' : ($code . ' ');
    }

    protected function formatDate(?string $asOf): string
    {
        if (empty($asOf)) {
            return date('j F Y');
        }
        $timestamp = strtotime($asOf);
        return $timestamp ? date('j F Y', $timestamp) : $asOf;
    }

    protected function summarizeReceivablesOutstanding(array $data, ?string $asOf, bool $reconciled): string
    {
        $currency = $this->formatCurrency($data['currency'] ?? 'INR');
        $dateFormatted = $this->formatDate($asOf);
        $totalNum = (float) ($data['total_outstanding'] ?? 0);
        $total = number_format($totalNum, 2);
        $top = $data['top_customers'] ?? [];
        $count = (int) ($data['total_customers_with_dues'] ?? $data['customers_with_dues'] ?? count($top));

        $lines = [];
        $lines[] = "### Customer Outstanding Summary";
        $lines[] = "**As of {$dateFormatted}**";
        $lines[] = "- **Total Outstanding:** {$currency}{$total}";
        $lines[] = "- **Customers with Dues:** {$count}";

        if ($count === 1 && ! empty($top)) {
            $cust = $top[0];
            $name = $cust['customer_name'] ?? $cust['name'] ?? 'Customer';
            $invCount = (int) ($cust['invoice_count'] ?? 1);
            $invWord = $invCount === 1 ? 'one invoice' : ($invCount === 2 ? 'two invoices' : "{$invCount} invoices");
            $maxOverdue = (int) ($cust['max_days_overdue'] ?? 0);
            $overdueSentence = $maxOverdue > 0
                ? "The oldest invoice is {$maxOverdue} days overdue."
                : "No invoices are currently overdue.";
            $lines[] = "The entire outstanding balance belongs to {$name}, across {$invWord}. {$overdueSentence}";
        } elseif ($count > 1 && ! empty($top)) {
            $cust = $top[0];
            $name = $cust['customer_name'] ?? $cust['name'] ?? 'Top Customer';
            $bal = number_format((float) ($cust['outstanding_amount'] ?? $cust['open_balance'] ?? 0), 2);
            $invCount = (int) ($cust['invoice_count'] ?? 1);
            $maxOverdue = (int) ($cust['max_days_overdue'] ?? 0);
            $overdueSentence = $maxOverdue > 0 ? " (oldest {$maxOverdue} days overdue)" : "";
            $lines[] = "Receivables are distributed across {$count} customers. The largest balance is {$name} with {$currency}{$bal} across {$invCount} invoice(s){$overdueSentence}.";
        } elseif ($count === 0) {
            $lines[] = "There are currently no customer dues outstanding.";
        }

        if ($reconciled) {
            $lines[] = "Reconciled: No control-account variance.";
        } else {
            $lines[] = "Variance Reported: Control-account reconciliation difference detected.";
        }

        if ($count === 1 && $totalNum > 0) {
            $lines[] = "Key takeaway: The receivables are concentrated in a single customer. Prioritising follow-up on the overdue invoices could improve collections.";
        } elseif ($count > 1 && $totalNum > 0) {
            $lines[] = "Key takeaway: Prioritising collection on top overdue accounts will reduce aging risk and accelerate cash flow.";
        }

        return implode("\n\n", $lines);
    }

    protected function summarizeCustomerInvoices(array $data): string
    {
        $currency = $this->formatCurrency($data['currency'] ?? 'INR');
        $customer = $data['customer']['name'] ?? 'Customer';
        $count = (int) ($data['invoice_count'] ?? 0);
        $totals = $data['totals'] ?? [];
        $totalOutstanding = number_format((float) ($totals['outstanding_amount'] ?? 0), 2);
        $overdue = number_format((float) ($totals['overdue_amount'] ?? 0), 2);
        $notYetDue = number_format((float) ($totals['not_yet_due_amount'] ?? 0), 2);

        $lines = [];
        $lines[] = "### Customer Invoice Breakdown";
        $lines[] = "For **{$customer}**, found **{$count} open invoice(s)** totaling **{$currency}{$totalOutstanding}**.";
        $lines[] = "- **Overdue Amount:** {$currency}{$overdue}\n- **Not Yet Due:** {$currency}{$notYetDue}";

        $invoices = $data['invoices'] ?? [];
        if (! empty($invoices)) {
            $invLines = [];
            foreach (array_slice($invoices, 0, 5) as $inv) {
                $num = $inv['invoice_number'] ?? '-';
                $amt = number_format((float) ($inv['open_amount'] ?? 0), 2);
                $due = $inv['due_date'] ?? '-';
                $status = ($inv['is_overdue'] ?? false) ? 'Overdue' : 'Current';
                $invLines[] = "- **{$num}**: {$currency}{$amt} (Due: {$due}, Status: {$status})";
            }
            $lines[] = "**Invoices:**\n" . implode("\n", $invLines);
        }

        return implode("\n\n", $lines);
    }

    protected function summarizeInvoiceAllocations(array $data): string
    {
        $currency = $this->formatCurrency($data['currency'] ?? 'INR');
        $inv = $data['invoice'] ?? [];
        $invNum = $inv['invoice_number'] ?? '-';
        $orig = number_format((float) ($inv['original_amount'] ?? 0), 2);
        $alloc = number_format((float) ($inv['total_allocated'] ?? 0), 2);
        $rem = number_format((float) ($inv['remaining_balance'] ?? 0), 2);
        $status = ucfirst(str_replace('_', ' ', (string) ($inv['status'] ?? 'open')));
        $allocCount = count($data['allocations'] ?? []);

        $lines = [];
        $lines[] = "### Invoice Payment Allocations";
        $lines[] = "Invoice **{$invNum}**: Original amount **{$currency}{$orig}**, total allocated **{$currency}{$alloc}**, remaining open balance **{$currency}{$rem}** ({$status}).";

        if ($allocCount > 0) {
            $allocLines = [];
            foreach (array_slice($data['allocations'], 0, 5) as $a) {
                $doc = $a['contra_document_no'] ?? 'Receipt';
                $amt = number_format((float) ($a['allocated_amount'] ?? 0), 2);
                $dt = $a['allocation_date'] ?? '';
                $allocLines[] = "- Applied {$currency}{$amt} via {$doc}" . ($dt ? " on {$dt}" : '');
            }
            $lines[] = "**Allocation History ({$allocCount} applied):**\n" . implode("\n", $allocLines);
        } else {
            $lines[] = "No payment or credit allocations have been applied to this invoice yet.";
        }

        return implode("\n\n", $lines);
    }

    protected function summarizeInventoryStock(array $data, bool $reconciled): string
    {
        $currency = $this->formatCurrency($data['currency'] ?? 'INR');
        $wh = $data['warehouse'] ?? 'Active Warehouses';
        $tracked = (int) ($data['total_products_tracked'] ?? 0);
        $inStock = (int) ($data['in_stock_count'] ?? 0);
        $outOfStock = (int) ($data['out_of_stock_count'] ?? 0);
        $stockVal = $data['total_stock_value'] !== null ? number_format((float) $data['total_stock_value'], 2) : null;

        $lines = [];
        $lines[] = "### Warehouse Stock Summary";
        $lines[] = "**Scope:** {$wh}";
        $lines[] = "- **Tracked Products:** {$tracked}\n- **In Stock:** {$inStock} | **Out of Stock:** {$outOfStock}";

        if ($stockVal !== null) {
            $lines[] = "- **Total Stock Valuation:** {$currency}{$stockVal}";
        }

        $units = $data['units_summary'] ?? [];
        if (! empty($units)) {
            $uLines = [];
            foreach (array_slice($units, 0, 4) as $u) {
                $code = $u['unit'] ?? 'Units';
                $onHand = number_format((float) ($u['on_hand'] ?? 0), 2);
                $avail = number_format((float) ($u['available'] ?? 0), 2);
                $uLines[] = "- **{$code}**: {$onHand} on hand ({$avail} available)";
            }
            $lines[] = "**Unit Stock:**\n" . implode("\n", $uLines);
        }

        $lines[] = $reconciled
            ? "Reconciled: Physical projections match movement ledger."
            : "Variance Reported: Stock projections differ from the movement ledger.";

        return implode("\n\n", $lines);
    }

    protected function summarizeSales(array $data): string
    {
        $currency = $this->formatCurrency($data['currency'] ?? 'INR');
        $totals = $data['totals'] ?? [];
        $salesCount = (int) ($totals['sales_count'] ?? 0);
        $gross = number_format((float) ($totals['gross_sales'] ?? 0), 2);
        $net = number_format((float) ($totals['net_sales'] ?? 0), 2);
        $tax = number_format((float) ($totals['total_tax'] ?? 0), 2);
        $dues = number_format((float) ($totals['due_amount'] ?? 0), 2);

        $lines = [];
        $lines[] = "### Sales Analytics Summary";
        $lines[] = "- **Completed Sales Count:** {$salesCount}\n- **Gross Sales:** {$currency}{$gross}\n- **Net Sales:** {$currency}{$net}\n- **Total Tax:** {$currency}{$tax}\n- **Outstanding Balance:** {$currency}{$dues}";

        $top = $data['top_products'] ?? [];
        if (! empty($top)) {
            $p = $top[0];
            $pName = $p['name'] ?? $p['code'] ?? 'Top Product';
            $pQty = number_format((float) ($p['total_qty'] ?? 0), 2);
            $pRev = number_format((float) ($p['total_revenue'] ?? 0), 2);
            $lines[] = "Top selling product: **{$pName}** with {$pQty} units sold ({$currency}{$pRev}).";
        }

        return implode("\n\n", $lines);
    }

    protected function summarizePayables(array $data, ?string $asOf, bool $reconciled): string
    {
        $currency = $this->formatCurrency($data['currency'] ?? 'INR');
        $dateFormatted = $this->formatDate($asOf);
        $total = number_format((float) ($data['total_outstanding'] ?? 0), 2);
        $count = (int) ($data['supplier_count_with_dues'] ?? 0);
        $top = $data['top_suppliers'] ?? [];

        $lines = [];
        $lines[] = "### Accounts Payable Summary";
        $lines[] = "**As of {$dateFormatted}**";
        $lines[] = "- **Total Accounts Payable:** {$currency}{$total}\n- **Suppliers with Dues:** {$count}";

        if ($count > 0 && ! empty($top)) {
            $sup = $top[0];
            $name = $sup['name'] ?? 'Supplier';
            $bal = number_format((float) ($sup['open_balance'] ?? 0), 2);
            $invCount = (int) ($sup['invoice_count'] ?? 0);
            $overdue = (int) ($sup['max_days_overdue'] ?? 0);
            $lines[] = "Largest balance owed to **{$name}**: {$currency}{$bal} across {$invCount} bill(s) (oldest {$overdue} days overdue).";
        }

        $lines[] = $reconciled ? "Reconciled: Control account verified." : "Variance reported in payables ledger.";

        return implode("\n\n", $lines);
    }

    protected function summarizeFinancialStatements(array $data): string
    {
        $currency = $this->formatCurrency($data['currency'] ?? 'INR');
        $type = $data['statement_type'] ?? 'profit_and_loss';
        $summary = $data['summary'] ?? [];

        $lines = [];
        if ($type === 'profit_and_loss') {
            $lines[] = "### Profit & Loss Statement";
            $rev = number_format((float) ($summary['total_revenue'] ?? 0), 2);
            $gp = number_format((float) ($summary['gross_profit'] ?? 0), 2);
            $net = number_format((float) ($summary['net_income'] ?? 0), 2);
            $lines[] = "- **Total Revenue:** {$currency}{$rev}\n- **Gross Profit:** {$currency}{$gp}\n- **Net Income:** {$currency}{$net}";
        } elseif ($type === 'balance_sheet') {
            $lines[] = "### Balance Sheet";
            $assets = number_format((float) ($summary['total_assets'] ?? 0), 2);
            $liab = number_format((float) ($summary['total_liabilities'] ?? 0), 2);
            $eq = number_format((float) ($summary['total_equity'] ?? 0), 2);
            $lines[] = "- **Total Assets:** {$currency}{$assets}\n- **Total Liabilities:** {$currency}{$liab}\n- **Total Equity:** {$currency}{$eq}";
        } else {
            $lines[] = "### Trial Balance";
            $dr = number_format((float) ($summary['total_debit'] ?? 0), 2);
            $cr = number_format((float) ($summary['total_credit'] ?? 0), 2);
            $lines[] = "- **Total Debit:** {$currency}{$dr}\n- **Total Credit:** {$currency}{$cr}";
        }

        return implode("\n\n", $lines);
    }

    protected function summarizeGst(array $data): string
    {
        $currency = $this->formatCurrency($data['currency'] ?? 'INR');
        $taxable = number_format((float) ($data['total_taxable_value'] ?? 0), 2);
        $cgst = number_format((float) ($data['total_cgst'] ?? 0), 2);
        $sgst = number_format((float) ($data['total_sgst'] ?? 0), 2);
        $liability = number_format((float) ($data['total_tax_liability'] ?? 0), 2);

        $lines = [];
        $lines[] = "### Statutory GST Breakdown";
        $lines[] = "- **Total Taxable Value:** {$currency}{$taxable}\n- **CGST:** {$currency}{$cgst}\n- **SGST:** {$currency}{$sgst}\n- **Total Tax Liability:** {$currency}{$liability}";

        return implode("\n\n", $lines);
    }

    protected function summarizeOpenOrders(array $data): string
    {
        $currency = $this->formatCurrency($data['currency'] ?? 'INR');
        $summary = $data['summary'] ?? [];
        $openCount = (int) ($summary['total_open_orders'] ?? 0);
        $salesVal = $summary['total_sales_value'] !== null ? number_format((float) $summary['total_sales_value'], 2) : null;
        $purchVal = $summary['total_purchase_value'] !== null ? number_format((float) $summary['total_purchase_value'], 2) : null;

        $lines = [];
        $lines[] = "### Open Orders & Fulfilment";
        $lines[] = "- **Total Open Orders:** {$openCount}";
        if ($salesVal !== null) {
            $lines[] = "- **Open Sales Order Value:** {$currency}{$salesVal}";
        }
        if ($purchVal !== null) {
            $lines[] = "- **Open Purchase Order Value:** {$currency}{$purchVal}";
        }

        return implode("\n\n", $lines);
    }

    protected function summarizeManufacturingBoms(array $data): string
    {
        $count = (int) ($data['total_boms_found'] ?? 0);
        $boms = $data['boms'] ?? [];

        $lines = [];
        $lines[] = "### Manufacturing Bill of Materials (BOM)";
        $lines[] = "Found **{$count} active BOM recipe(s)**.";

        if (! empty($boms)) {
            $b = $boms[0];
            $fg = $b['output_product']['name'] ?? 'Finished Good';
            $qty = $b['output_product']['output_qty'] ?? 1;
            $uom = $b['output_product']['uom'] ?? 'Unit';
            $compCount = $b['component_count'] ?? count($b['components'] ?? []);
            $lines[] = "Primary output: **{$fg}** (Yield: {$qty} {$uom}) with {$compCount} component item(s).";
        }

        return implode("\n\n", $lines);
    }

    protected function summarizeJobWork(array $data): string
    {
        $currency = $this->formatCurrency($data['currency'] ?? 'INR');
        $summary = $data['summary'] ?? [];
        $pending = (int) ($summary['total_orders_pending'] ?? 0);
        $overdue = (int) ($summary['overdue_count'] ?? 0);
        $val = number_format((float) ($summary['total_pending_value'] ?? 0), 2);

        $lines = [];
        $lines[] = "### Job Work Processing Status";
        $lines[] = "- **Orders Pending:** {$pending}\n- **Overdue Orders:** {$overdue}\n- **Estimated Pending Value:** {$currency}{$val}";

        return implode("\n\n", $lines);
    }

    protected function summarizeDocumentPreview(array $data): string
    {
        $currency = $this->formatCurrency($data['currency'] ?? 'INR');
        $ref = $data['reference_no'] ?? '-';
        $party = $data['party']['name'] ?? 'Party';
        $date = $data['date'] ?? '-';
        $total = number_format((float) ($data['grand_total'] ?? 0), 2);
        $status = $data['status'] ?? 'Active';

        $lines = [];
        $lines[] = "### Document Preview";
        $lines[] = "**Reference:** {$ref} ({$status}) | **Party:** {$party} | **Date:** {$date}\n- **Grand Total:** {$currency}{$total}";

        return implode("\n\n", $lines);
    }

    protected function summarizeKnowledge(array $data): string
    {
        $kw = $data['knowledge'] ?? [];
        $companyName = $kw['company']['name'] ?? 'Company';
        $branchCount = count($kw['company']['branches'] ?? []);

        $lines = [];
        $lines[] = "### Company Knowledge Profile";
        $lines[] = "**Company:** {$companyName} ({$branchCount} active branch(es)).";
        if (! empty($kw['return_policy']['approval_window_days'])) {
            $lines[] = "Sales return window: {$kw['return_policy']['approval_window_days']} days.";
        }

        return implode("\n\n", $lines);
    }

    protected function summarizeHelp(array $data): string
    {
        $count = (int) ($data['total_authorized_tools'] ?? 0);
        return "### Copilot Capabilities\nYou have **{$count} authorized ERP tools** available in this company context. Ask about customer receivables, stock balances, sales performance, or financial statements.";
    }

    protected function summarizeGeneric(string $tool, array $data): string
    {
        return "### Authoritative ERP Report ({$tool})\nVerified figures returned successfully.";
    }
}

<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Company;

/**
 * Authoritative, capability-aware help and tool catalog.
 * Informs users and LLMs exactly which tools and questions are authorized for their role.
 */
class ReadCopilotHelp
{
    public const TOOL_DEFINITIONS = [
        'receivables.outstanding.v1' => [
            'domain' => 'Customer Receivables',
            'title' => 'Customer Outstanding Summary',
            'description' => 'Calculates company/branch total customer outstanding, customers with dues, and top debtors.',
            'examples' => [
                'Show customer outstanding breakdown',
                'Who owes us the most money?',
                'What is our total AR balance?',
            ],
        ],
        'receivables.invoices.v1' => [
            'domain' => 'Customer Receivables',
            'title' => 'Customer Invoices Drill-Down',
            'description' => 'Lists exact invoice numbers, document dates, due dates, original/open balances, and overdue days.',
            'examples' => [
                'What are the two invoices for John Doe?',
                'Show all open invoices for customer #1',
                'Which invoices are overdue?',
            ],
        ],
        'receivables.invoice_allocations.v1' => [
            'domain' => 'Customer Receivables',
            'title' => 'Invoice Payment Allocations',
            'description' => 'Breaks down receipts, credit notes, and reversals applied against an open invoice.',
            'examples' => [
                'What did they pay for invoice INV-2026-001?',
                'Show payment history for open item #12',
            ],
        ],
        'inventory.stock_summary.v1' => [
            'domain' => 'Inventory & Warehouses',
            'title' => 'Inventory Stock Summary',
            'description' => 'Reports tracked products, total stock valuation, quantities grouped strictly by UOM, and top stock items.',
            'examples' => [
                'How much stock do we have?',
                'Show stock in Warehouse 1',
                'What are our top items in inventory?',
            ],
        ],
        'sales.summary.v1' => [
            'domain' => 'Sales & POS',
            'title' => 'Sales & Turnover Analytics',
            'description' => 'Summarizes gross sales, net sales, taxes, discounts, and top selling products for any date range.',
            'examples' => [
                'What are our sales this month?',
                'Show sales summary and top products',
                'How much did we sell in Warehouse 1?',
            ],
        ],
        'payables.summary.v1' => [
            'domain' => 'Supplier Payables',
            'title' => 'Accounts Payable (AP) Summary',
            'description' => 'Summarizes total supplier dues, top creditors owed, and control account reconciliation.',
            'examples' => [
                'How much do we owe suppliers?',
                'What are our top supplier dues?',
                'Show AP aging summary',
            ],
        ],
        'accounting.statements.v1' => [
            'domain' => 'Finance & Accounting',
            'title' => 'Financial Statements',
            'description' => 'Generates authoritative Profit and Loss (P&L), Balance Sheet, or Trial Balance statements.',
            'examples' => [
                'Show Profit and Loss statement',
                'What is our net income this year?',
                'Generate Balance Sheet summary',
                'Check if Trial Balance is balanced',
            ],
        ],
        'tax.gst_summary.v1' => [
            'domain' => 'Tax & Compliance',
            'title' => 'GST & Tax Breakdown',
            'description' => 'Summarizes outward GST liability (CGST/SGST/IGST) and inward eligible input tax credit (ITC).',
            'examples' => [
                'Show GST breakdown for this month',
                'What is our tax liability and input credit?',
            ],
        ],
        'copilot.help.v1' => [
            'domain' => 'Copilot System',
            'title' => 'Capability-Aware Help',
            'description' => 'Lists all permitted tools and question templates available for your role.',
            'examples' => [
                'What can you do?',
                'What can I ask?',
            ],
        ],
    ];

    public function execute(AiPrincipal $principal): ErpToolResult
    {
        $permittedNames = $principal->permittedToolNames;
        $company = Company::find($principal->companyId);
        $companyName = $company?->name ?? 'zoloERP Pro';

        $availableCatalog = [];
        $samplePrompts = [];

        foreach (self::TOOL_DEFINITIONS as $key => $meta) {
            if (in_array($key, $permittedNames, true) || $key === 'copilot.help.v1') {
                $availableCatalog[] = [
                    'tool_key' => $key,
                    'domain' => $meta['domain'],
                    'title' => $meta['title'],
                    'description' => $meta['description'],
                    'examples' => $meta['examples'],
                ];
                foreach ($meta['examples'] as $ex) {
                    $samplePrompts[] = $ex;
                }
            }
        }

        return ErpToolResult::ok(
            toolKey: 'copilot.help.v1',
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
            ],
            data: [
                'company_name' => $companyName,
                'total_authorized_tools' => count($availableCatalog),
                'catalog' => $availableCatalog,
                'suggested_questions' => array_slice($samplePrompts, 0, 8),
            ],
            sourceRefs: ['/ai'],
            warnings: [],
            asOf: now()->toDateString(),
            reconciled: true
        );
    }
}


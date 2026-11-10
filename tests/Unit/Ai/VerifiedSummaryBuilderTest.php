<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Services\VerifiedSummaryBuilder;
use PHPUnit\Framework\TestCase;

class VerifiedSummaryBuilderTest extends TestCase
{
    protected VerifiedSummaryBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new VerifiedSummaryBuilder();
    }

    public function test_receivables_outstanding_formats_single_customer_concentration_and_reconciliation(): void
    {
        $facts = [
            [
                'tool' => 'receivables.outstanding.v1',
                'as_of' => '2026-11-10',
                'reconciled' => true,
                'data' => [
                    'currency' => 'INR',
                    'total_outstanding' => '22656.00',
                    'customers_with_dues' => 1,
                    'top_customers' => [
                        [
                            'name' => 'John Doe',
                            'open_balance' => '22656.00',
                            'invoice_count' => 2,
                            'max_days_overdue' => 29,
                        ],
                    ],
                ],
            ],
        ];

        $summary = $this->builder->build($facts);

        $this->assertStringContainsString('Customer Outstanding Summary', $summary);
        $this->assertStringContainsString('10 November 2026', $summary);
        $this->assertStringContainsString('₹22,656.00', $summary);
        $this->assertStringContainsString('Customers with Dues:** 1', $summary);
        $this->assertStringContainsString('The entire outstanding balance belongs to John Doe, across two invoices. The oldest invoice is 29 days overdue.', $summary);
        $this->assertStringContainsString('Reconciled: No control-account variance.', $summary);
        $this->assertStringContainsString('Key takeaway: The receivables are concentrated in a single customer.', $summary);
    }

    public function test_receivables_outstanding_formats_unreconciled_difference(): void
    {
        $facts = [
            [
                'tool' => 'receivables.outstanding.v1',
                'as_of' => '2026-11-10',
                'reconciled' => false,
                'data' => [
                    'currency' => 'INR',
                    'total_outstanding' => '50000.00',
                    'customers_with_dues' => 3,
                    'top_customers' => [
                        ['name' => 'Acme Corp', 'open_balance' => '30000.00', 'invoice_count' => 5, 'max_days_overdue' => 45],
                    ],
                ],
            ],
        ];

        $summary = $this->builder->build($facts, ['Control account variance detected between subledger and GL.']);

        $this->assertStringContainsString('Variance Reported: Control-account reconciliation difference detected.', $summary);
        $this->assertStringContainsString('Receivables are distributed across 3 customers.', $summary);
        $this->assertStringContainsString('> **Notice:** Control account variance detected', $summary);
    }

    public function test_customer_invoices_formats_invoice_list(): void
    {
        $facts = [
            [
                'tool' => 'receivables.invoices.v1',
                'as_of' => '2026-11-10',
                'reconciled' => true,
                'data' => [
                    'currency' => 'INR',
                    'customer' => ['id' => 10, 'name' => 'John Doe'],
                    'invoice_count' => 2,
                    'totals' => [
                        'outstanding_amount' => '22656.00',
                        'overdue_amount' => '15000.00',
                        'not_yet_due_amount' => '7656.00',
                    ],
                    'invoices' => [
                        ['invoice_number' => 'INV-001', 'open_amount' => '15000.00', 'due_date' => '2026-10-12', 'is_overdue' => true],
                        ['invoice_number' => 'INV-002', 'open_amount' => '7656.00', 'due_date' => '2026-11-30', 'is_overdue' => false],
                    ],
                ],
            ],
        ];

        $summary = $this->builder->build($facts);

        $this->assertStringContainsString('Customer Invoice Breakdown', $summary);
        $this->assertStringContainsString('John Doe', $summary);
        $this->assertStringContainsString('₹22,656.00', $summary);
        $this->assertStringContainsString('Overdue Amount:** ₹15,000.00', $summary);
        $this->assertStringContainsString('INV-001', $summary);
        $this->assertStringContainsString('INV-002', $summary);
    }

    public function test_multi_tool_facts_are_separated_by_dividers(): void
    {
        $facts = [
            [
                'tool' => 'receivables.outstanding.v1',
                'as_of' => '2026-11-10',
                'reconciled' => true,
                'data' => ['currency' => 'INR', 'total_outstanding' => '1000.00', 'customers_with_dues' => 0],
            ],
            [
                'tool' => 'inventory.stock_summary.v1',
                'as_of' => '2026-11-10',
                'reconciled' => true,
                'data' => [
                    'currency' => 'INR',
                    'warehouse' => 'Main Warehouse',
                    'total_products_tracked' => 50,
                    'in_stock_count' => 45,
                    'out_of_stock_count' => 5,
                    'total_stock_value' => '125000.00',
                ],
            ],
        ];

        $summary = $this->builder->build($facts);

        $this->assertStringContainsString('Customer Outstanding Summary', $summary);
        $this->assertStringContainsString('---', $summary);
        $this->assertStringContainsString('Warehouse Stock Summary', $summary);
        $this->assertStringContainsString('Main Warehouse', $summary);
        $this->assertStringContainsString('₹125,000.00', $summary);
    }
}


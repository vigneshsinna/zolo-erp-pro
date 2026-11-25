<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Shared ERP Capability Discovery & Dispatch
    |--------------------------------------------------------------------------
    |
    | Default-off switch governing adoption of the derived shared capability
    | catalog across internal SDK tools, REST discovery, and Automation Builder.
    | When false, legacy tool registrations and prompts continue unchanged.
    |
    */
    'shared_discovery_enabled' => (bool) env('AI_SHARED_CAPABILITY_DISCOVERY_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Bounded Discovery Limits
    |--------------------------------------------------------------------------
    |
    | Prevents unbounded tool lists from bloating LLM tool schemas or API
    | responses when 100+ capabilities are registered.
    |
    */
    'max_sdk_tools' => (int) env('AI_CAPABILITY_MAX_SDK_TOOLS', 25),
    'max_rest_page_size' => 50,

    /*
    |--------------------------------------------------------------------------
    | Code-Owned Channel Exposure Policy
    |--------------------------------------------------------------------------
    |
    | Stores only interface-specific availability, stable aliases, ownership,
    | and review references. Never duplicate tax rules, requirements, or
    | permission lists here; those remain in AiToolManifest and
    | ActionCapabilityRegistry / ActionRequirementsRegistry.
    |
    */
    'exposure' => [
        'read_defaults' => [
            'sdk' => true,
            'rest' => true,
            'mcp' => false,
            'automation' => false,
            'owner' => 'platform-ai',
            'review_reference' => 'REV-U1-READ-V1',
        ],
        'prepare_defaults' => [
            'sdk' => true,
            'rest' => true,
            'mcp' => false,
            'automation' => false,
            'owner' => 'erp-commercial',
            'review_reference' => 'REV-U1-PREP-V1',
        ],
        'capabilities' => [
            'receivables.outstanding.v1' => [
                'read' => [
                    'channels' => ['sdk' => true, 'rest' => true, 'mcp' => true, 'automation' => false],
                    'aliases' => ['sdk' => 'CustomerOutstanding', 'mcp' => 'CustomerOutstanding'],
                    'owner' => 'finance-ar',
                    'review_reference' => 'REV-READ-AR-V1',
                    'lifecycle' => 'active',
                ],
            ],
            'inventory.stock_summary.v1' => [
                'read' => [
                    'channels' => ['sdk' => true, 'rest' => true, 'mcp' => true, 'automation' => true],
                    'aliases' => ['sdk' => 'InventoryStockSummary', 'mcp' => 'InventoryStockSummary'],
                    'owner' => 'inventory',
                    'review_reference' => 'REV-READ-INV-V1',
                    'lifecycle' => 'active',
                ],
            ],
            'purchase_order.create.v1' => [
                'prepare' => [
                    'channels' => ['sdk' => true, 'rest' => true, 'mcp' => true, 'automation' => true],
                    'aliases' => [
                        'sdk' => 'prepare_purchase_order',
                        'mcp' => 'erp_purchase_order_prepare',
                        'rest' => 'purchase_order.create.v1',
                        'automation' => 'purchase_order.create.v1',
                    ],
                    'owner' => 'purchasing',
                    'review_reference' => 'REV-U2-PO-PREP-V1',
                    'lifecycle' => 'active',
                    'knowledge_id' => 'action.purchase_order.create.v1',
                    'compatibility_legacy_tool' => \App\Ai\Tools\PreparePurchaseOrder::class,
                ],
            ],
            'sales_order.create.v1' => [
                'prepare' => [
                    'channels' => ['sdk' => true, 'rest' => true, 'mcp' => false, 'automation' => false],
                    'aliases' => ['sdk' => 'prepare_sales_order'],
                    'owner' => 'sales',
                    'review_reference' => 'REV-U1-SO-PREP-V1',
                    'lifecycle' => 'active',
                    'compatibility_legacy_tool' => \App\Ai\Tools\PrepareSalesOrder::class,
                ],
            ],
            'grn.create.v1' => [
                'prepare' => [
                    'channels' => ['sdk' => true, 'rest' => true, 'mcp' => false, 'automation' => false],
                    'aliases' => ['sdk' => 'prepare_goods_receipt'],
                    'owner' => 'purchasing',
                    'review_reference' => 'REV-U1-GRN-PREP-V1',
                    'lifecycle' => 'active',
                    'knowledge_id' => 'action.grn.create.v1',
                    'compatibility_legacy_tool' => \App\Ai\Tools\PrepareGoodsReceipt::class,
                ],
            ],
            'purchase_bill.create.v1' => [
                'prepare' => [
                    'channels' => ['sdk' => true, 'rest' => true, 'mcp' => false, 'automation' => false],
                    'aliases' => ['sdk' => 'prepare_purchase_bill'],
                    'owner' => 'accounting',
                    'review_reference' => 'REV-U1-PBILL-PREP-V1',
                    'lifecycle' => 'active',
                    'knowledge_id' => 'action.purchase_bill.create.v1',
                    'compatibility_legacy_tool' => \App\Ai\Tools\PreparePurchaseBill::class,
                ],
            ],
            'quotation.create.v1' => [
                'prepare' => [
                    'channels' => ['sdk' => true, 'rest' => true, 'mcp' => false, 'automation' => false],
                    'aliases' => ['sdk' => 'prepare_quotation'],
                    'owner' => 'sales',
                    'review_reference' => 'REV-U1-QUOTE-PREP-V1',
                    'lifecycle' => 'active',
                    'compatibility_legacy_tool' => \App\Ai\Tools\PrepareQuotation::class,
                ],
            ],
            'sales_invoice.create.v1' => [
                'prepare' => [
                    'channels' => ['sdk' => true, 'rest' => true, 'mcp' => false, 'automation' => false],
                    'aliases' => ['sdk' => 'prepare_sales_invoice'],
                    'owner' => 'sales',
                    'review_reference' => 'REV-U1-SINV-PREP-V1',
                    'lifecycle' => 'active',
                    'compatibility_legacy_tool' => \App\Ai\Tools\PrepareSalesInvoice::class,
                ],
            ],
            'payment_receipt.create.v1' => [
                'prepare' => [
                    'channels' => ['sdk' => true, 'rest' => true, 'mcp' => false, 'automation' => false],
                    'aliases' => ['sdk' => 'prepare_payment_receipt'],
                    'owner' => 'accounting',
                    'review_reference' => 'REV-U1-RCPT-PREP-V1',
                    'lifecycle' => 'active',
                    'compatibility_legacy_tool' => \App\Ai\Tools\PreparePaymentReceipt::class,
                ],
            ],
            'payment_reminder.prepare.v1' => [
                'prepare' => [
                    'channels' => ['sdk' => true, 'rest' => true, 'mcp' => false, 'automation' => false],
                    'aliases' => ['sdk' => 'prepare_payment_reminder'],
                    'owner' => 'collections',
                    'review_reference' => 'REV-U1-REMIND-PREP-V1',
                    'lifecycle' => 'active',
                    'compatibility_legacy_tool' => \App\Ai\Tools\PreparePaymentReminder::class,
                ],
            ],
            'stock_transfer.create.v1' => [
                'prepare' => [
                    'channels' => ['sdk' => true, 'rest' => true, 'mcp' => false, 'automation' => false],
                    'aliases' => ['sdk' => 'prepare_stock_transfer'],
                    'owner' => 'inventory',
                    'review_reference' => 'REV-U1-STRANS-PREP-V1',
                    'lifecycle' => 'active',
                    'compatibility_legacy_tool' => \App\Ai\Tools\PrepareStockTransfer::class,
                ],
            ],
            'stock_adjustment.create.v1' => [
                'prepare' => [
                    'channels' => ['sdk' => true, 'rest' => true, 'mcp' => false, 'automation' => false],
                    'aliases' => ['sdk' => 'prepare_stock_adjustment'],
                    'owner' => 'inventory',
                    'review_reference' => 'REV-U1-SADJ-PREP-V1',
                    'lifecycle' => 'active',
                    'compatibility_legacy_tool' => \App\Ai\Tools\PrepareStockAdjustment::class,
                ],
            ],
            'journal_entry.create.v1' => [
                'prepare' => [
                    'channels' => ['sdk' => true, 'rest' => true, 'mcp' => false, 'automation' => false],
                    'aliases' => ['sdk' => 'prepare_journal_entry'],
                    'owner' => 'accounting',
                    'review_reference' => 'REV-U1-JRNL-PREP-V1',
                    'lifecycle' => 'active',
                    'compatibility_legacy_tool' => \App\Ai\Tools\PrepareJournalEntry::class,
                ],
            ],
            'production_order.create.v1' => [
                'prepare' => [
                    'channels' => ['sdk' => true, 'rest' => true, 'mcp' => false, 'automation' => false],
                    'aliases' => ['sdk' => 'prepare_production_order'],
                    'owner' => 'manufacturing',
                    'review_reference' => 'REV-U1-PROD-PREP-V1',
                    'lifecycle' => 'active',
                    'compatibility_legacy_tool' => \App\Ai\Tools\PrepareProductionOrder::class,
                ],
            ],
            'proposal.status.v1' => [
                'status' => [
                    'channels' => ['sdk' => false, 'rest' => true, 'mcp' => true, 'automation' => false],
                    'aliases' => ['mcp' => 'erp_proposal_status', 'rest' => 'proposal.status.v1'],
                    'owner' => 'platform-security',
                    'review_reference' => 'REV-U2-STATUS-V1',
                    'lifecycle' => 'active',
                    'title' => 'Proposal & Task Status',
                    'description' => 'Read scoped status, approval version, review link, and verified execution receipt for an owned AI workflow task or proposal.',
                    'module' => 'governance',
                    'tags' => ['status', 'proposal', 'audit'],
                ],
            ],
        ],
    ],
];

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\Contracts\AiPrincipalAuthorizerInterface;
use App\Ai\DTOs\AiToolRegistration;
use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Handlers\CompanyKnowledgeHandler;
use App\Ai\Handlers\CopilotHelpHandler;
use App\Ai\Handlers\CustomerInvoicesHandler;
use App\Ai\Handlers\CustomerOutstandingHandler;
use App\Ai\Handlers\DocumentPreviewHandler;
use App\Ai\Handlers\FinancialStatementsHandler;
use App\Ai\Handlers\GstSummaryHandler;
use App\Ai\Handlers\InventoryStockSummaryHandler;
use App\Ai\Handlers\InvoiceAllocationsHandler;
use App\Ai\Handlers\JobWorkStatusHandler;
use App\Ai\Handlers\ManufacturingBomsHandler;
use App\Ai\Handlers\OpenOrdersHandler;
use App\Ai\Handlers\PayablesSummaryHandler;
use App\Ai\Handlers\SalesSummaryHandler;
use App\Ai\Services\AiAuditRecorder;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalAuthorizer;
use App\Ai\Services\AiPrincipalFactory;
use App\Ai\Services\AiToolManifest;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiToolManifest::class, function ($app) {
            $manifest = new AiToolManifest();
            $receivables = ['accounting.reports.view', 'customer-report', 'due-report', 'sales-index'];
            $orderPermissions = ['sales-index', 'purchases-index'];
            foreach ([
                new AiToolRegistration($app->make(CustomerOutstandingHandler::class), 'CustomerOutstanding', $receivables, legacyReconciliation: true),
                new AiToolRegistration($app->make(CustomerInvoicesHandler::class), 'CustomerInvoices', $receivables),
                new AiToolRegistration($app->make(InvoiceAllocationsHandler::class), 'InvoiceAllocations', $receivables),
                new AiToolRegistration($app->make(InventoryStockSummaryHandler::class), 'InventoryStockSummary', ['warehouse-stock-report', 'product-report', 'products-index'], legacyReconciliation: true),
                new AiToolRegistration($app->make(SalesSummaryHandler::class), 'sales_summary', ['sales-index', 'sale-report']),
                new AiToolRegistration($app->make(PayablesSummaryHandler::class), 'payables_summary', ['accounting.reports.view', 'supplier-report', 'supplier-due-report', 'purchases-index']),
                new AiToolRegistration($app->make(FinancialStatementsHandler::class), 'accounting_statements', ['accounting.reports.view', 'financial-report', 'profit-loss-report', 'trial-balance-report']),
                new AiToolRegistration($app->make(GstSummaryHandler::class), 'tax_gst_summary', ['accounting.reports.view', 'tax-report', 'gst-report', 'sales-index']),
                new AiToolRegistration($app->make(ManufacturingBomsHandler::class), 'manufacturing_boms', ['manufacturing.read'], operationsCapability: 'manufacturing.bom'),
                new AiToolRegistration($app->make(JobWorkStatusHandler::class), 'jobwork_status', ['job_work.read'], operationsCapability: 'operations.job_work'),
                new AiToolRegistration($app->make(OpenOrdersHandler::class), 'orders_open', $orderPermissions),
                new AiToolRegistration($app->make(DocumentPreviewHandler::class), 'document_preview', $orderPermissions),
                new AiToolRegistration($app->make(CompanyKnowledgeHandler::class), 'company_knowledge', []),
                new AiToolRegistration($app->make(CopilotHelpHandler::class), 'copilot_help', []),
            ] as $registration) {
                $manifest->register($registration);
            }

            return $manifest;
        });

        $this->app->singleton(AiPrincipalAuthorizer::class, function ($app) {
            return new AiPrincipalAuthorizer(
                contextResolver: $app->make(\App\Services\Platform\CompanyContextResolver::class),
                principalFactory: $app->make(AiPrincipalFactory::class)
            );
        });
        $this->app->bind(AiPrincipalAuthorizerInterface::class, AiPrincipalAuthorizer::class);

        $this->app->singleton(AiFeatureGate::class, function () {
            return new AiFeatureGate(
                enabledGlobally: (bool) config('erp_ai.enabled', false)
            );
        });

        $this->app->singleton(AiAuditRecorder::class);
        $this->app->scoped(ErpToolExecutor::class, fn ($app) => new ErpToolExecutor(
            $app->make(AiToolManifest::class),
            $app->make(AiPrincipalAuthorizerInterface::class),
            $app->make(AiAuditRecorder::class),
            $app->make(AiFeatureGate::class),
            max(0, (int) config('erp_ai.max_tools_per_run', 3)),
        ));
        $this->app->scoped(ApprovedModelGateway::class);
        $this->app->singleton(AiPrincipalFactory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(RequestSending::class, function (RequestSending $event): void {
            if ($event->request->method() === 'POST' && $this->app->resolved(ApprovedModelGateway::class)) {
                $this->app->make(ApprovedModelGateway::class)->admitProviderDispatch($event->request->url());
            }
        });
    }
}


<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\Contracts\AiPrincipalAuthorizerInterface;
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
use App\Ai\Services\ReadCompanyKnowledge;
use App\Ai\Services\ReadCopilotHelp;
use App\Ai\Services\ReadCustomerInvoices;
use App\Ai\Services\ReadCustomerOutstanding;
use App\Ai\Services\ReadDocumentPreview;
use App\Ai\Services\ReadFinancialStatements;
use App\Ai\Services\ReadGstSummary;
use App\Ai\Services\ReadInventoryStockSummary;
use App\Ai\Services\ReadInvoiceAllocations;
use App\Ai\Services\ReadJobWorkStatus;
use App\Ai\Services\ReadManufacturingBoms;
use App\Ai\Services\ReadOpenOrders;
use App\Ai\Services\ReadPayablesSummary;
use App\Ai\Services\ReadSalesAnalytics;
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
            // Register authoritative read tools
            $manifest->register($app->make(CustomerOutstandingHandler::class));
            $manifest->register($app->make(CustomerInvoicesHandler::class));
            $manifest->register($app->make(InvoiceAllocationsHandler::class));
            $manifest->register($app->make(InventoryStockSummaryHandler::class));
            $manifest->register($app->make(SalesSummaryHandler::class));
            $manifest->register($app->make(PayablesSummaryHandler::class));
            $manifest->register($app->make(FinancialStatementsHandler::class));
            $manifest->register($app->make(GstSummaryHandler::class));
            $manifest->register($app->make(CopilotHelpHandler::class));
            $manifest->register($app->make(ManufacturingBomsHandler::class));
            $manifest->register($app->make(JobWorkStatusHandler::class));
            $manifest->register($app->make(OpenOrdersHandler::class));
            $manifest->register($app->make(DocumentPreviewHandler::class));
            $manifest->register($app->make(CompanyKnowledgeHandler::class));
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
                enabledGlobally: (bool) config('erp_ai.enabled', false),
                readOnlyGlobally: (bool) config('erp_ai.read_only', true)
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
        $this->app->singleton(ReadCustomerOutstanding::class);
        $this->app->singleton(ReadCustomerInvoices::class);
        $this->app->singleton(CustomerInvoicesHandler::class);
        $this->app->singleton(ReadInvoiceAllocations::class);
        $this->app->singleton(InvoiceAllocationsHandler::class);
        $this->app->singleton(ReadInventoryStockSummary::class);
        $this->app->singleton(InventoryStockSummaryHandler::class);
        $this->app->singleton(ReadSalesAnalytics::class);
        $this->app->singleton(SalesSummaryHandler::class);
        $this->app->singleton(ReadPayablesSummary::class);
        $this->app->singleton(PayablesSummaryHandler::class);
        $this->app->singleton(ReadFinancialStatements::class);
        $this->app->singleton(FinancialStatementsHandler::class);
        $this->app->singleton(ReadGstSummary::class);
        $this->app->singleton(GstSummaryHandler::class);
        $this->app->singleton(ReadCopilotHelp::class);
        $this->app->singleton(CopilotHelpHandler::class);
        $this->app->singleton(ReadManufacturingBoms::class);
        $this->app->singleton(ManufacturingBomsHandler::class);
        $this->app->singleton(ReadJobWorkStatus::class);
        $this->app->singleton(JobWorkStatusHandler::class);
        $this->app->singleton(ReadOpenOrders::class);
        $this->app->singleton(OpenOrdersHandler::class);
        $this->app->singleton(ReadDocumentPreview::class);
        $this->app->singleton(DocumentPreviewHandler::class);
        $this->app->singleton(ReadCompanyKnowledge::class);
        $this->app->singleton(CompanyKnowledgeHandler::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(RequestSending::class, function (RequestSending $event): void {
            if ($event->request->method() === 'POST' && $this->app->resolved(ApprovedModelGateway::class)) {
                $this->app->make(ApprovedModelGateway::class)->admitProviderDispatch();
            }
        });
    }
}


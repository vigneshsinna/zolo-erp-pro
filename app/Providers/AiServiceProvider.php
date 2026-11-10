<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\Contracts\AiPrincipalAuthorizerInterface;
use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Handlers\CustomerOutstandingHandler;
use App\Ai\Handlers\InventoryStockSummaryHandler;
use App\Ai\Services\AiAuditRecorder;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalAuthorizer;
use App\Ai\Services\AiPrincipalFactory;
use App\Ai\Services\AiToolManifest;
use App\Ai\Services\ErpToolExecutor;
use App\Ai\Services\ReadCustomerOutstanding;
use App\Ai\Services\ReadInventoryStockSummary;
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
            $manifest->register($app->make(InventoryStockSummaryHandler::class));
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
        $this->app->singleton(ErpToolExecutor::class);
        $this->app->singleton(ApprovedModelGateway::class);
        $this->app->singleton(AiPrincipalFactory::class);
        $this->app->singleton(ReadCustomerOutstanding::class);
        $this->app->singleton(ReadInventoryStockSummary::class);
        $this->app->singleton(InventoryStockSummaryHandler::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}


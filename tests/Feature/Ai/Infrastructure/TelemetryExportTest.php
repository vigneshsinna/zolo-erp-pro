<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Infrastructure;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Jobs\ExportModelTelemetry;
use App\Ai\Telemetry\LangfuseTelemetrySink;
use App\Ai\Telemetry\ModelTelemetry;
use App\Ai\Telemetry\TelemetryDispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TelemetryExportTest extends TestCase
{
    public function test_export_contains_only_pseudonymous_model_metrics_and_exact_cost_estimate(): void
    {
        config(['ai_infrastructure.langfuse.enabled' => true,
            'ai_infrastructure.langfuse.public_key' => 'fixture-public', 'ai_infrastructure.langfuse.secret_key' => 'fixture-secret',
            'ai_infrastructure.langfuse.model_rates.openai' => ['fixture-model' => ['input' => '1.00', 'output' => '2.00']]]);
        Http::fake(['https://cloud.langfuse.com/*' => Http::response([], 200)]);
        $event = ModelTelemetry::fromInvocation(new AiPrincipal(42, 99, 1, 1, requestId: 'fixture-run'), 1,
            'openai', 'fixture-model', 'completed', 100, 50, 250, 2);
        $this->assertSame('0.00020000', $event->estimatedCostUsd);
        app(LangfuseTelemetrySink::class)->send($event);
        Http::assertSent(function ($request) use ($event): bool {
            $body = $request->data();
            $span = $body['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
            $json = json_encode($body);
            return $request->url() === 'https://cloud.langfuse.com/api/public/otel/v1/traces'
                && $span['traceId'] === $event->traceId && strlen($span['spanId']) === 16
                && str_contains($json, 'langfuse.observation.usage_details')
                && !str_contains($json, 'fixture-run') && !str_contains($json, 'fixture-secret')
                && !str_contains($json, 'company_id') && !str_contains($json, 'actor_id');
        });
    }

    public function test_export_is_disabled_by_default_and_dispatch_failure_does_not_escape(): void
    {
        Http::fake();
        $event = ModelTelemetry::fromInvocation(new AiPrincipal(1, 2, 3, 4), 1, 'openai', 'fixture', 'failed', 0, 0, 1);
        config(['ai_infrastructure.langfuse.enabled' => false]);
        app(LangfuseTelemetrySink::class)->send($event);
        Http::assertNothingSent();
        config(['ai_infrastructure.langfuse.enabled' => true, 'ai_infrastructure.queue_connection' => 'sync']);
        app(TelemetryDispatcher::class)->recordModel(new AiPrincipal(1, 2, 3, 4), 1, 'openai', 'fixture', 'failed', 0, 0, 1);
        Http::assertNothingSent();
    }

    public function test_export_dispatch_uses_isolated_queue_and_rejects_unapproved_origin(): void
    {
        config(['ai_infrastructure.langfuse.enabled' => true, 'ai_infrastructure.queue_connection' => 'database']);
        Bus::fake([ExportModelTelemetry::class]);
        app(TelemetryDispatcher::class)->recordModel(new AiPrincipal(1, 2, 3, 4), 1, 'openai', 'fixture', 'completed', 10, 2, 100);
        Bus::assertDispatched(ExportModelTelemetry::class, fn ($job) => $job->queue === 'ai-telemetry' && $job->connection === 'database');
        config(['ai_infrastructure.langfuse.url' => 'http://127.0.0.1']);
        $this->expectException(\InvalidArgumentException::class);
        app(LangfuseTelemetrySink::class)->send(ModelTelemetry::fromInvocation(new AiPrincipal(1, 2, 3, 4), 1, 'openai', 'fixture', 'failed', 0, 0, 1));
    }
}

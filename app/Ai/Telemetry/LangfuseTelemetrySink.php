<?php

declare(strict_types=1);

namespace App\Ai\Telemetry;

use Brick\Math\BigInteger;
use Illuminate\Support\Facades\Http;

/** OTLP/HTTP JSON export. No prompt, tool payload, customer data or raw exception is accepted. */
final class LangfuseTelemetrySink implements TelemetrySink
{
    public function send(ModelTelemetry $event): void
    {
        if (!config('ai_infrastructure.langfuse.enabled', false)) {
            return;
        }
        $url = rtrim((string) config('ai_infrastructure.langfuse.url'), '/');
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '', config('ai_infrastructure.langfuse.allowed_hosts', []), true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['port']) && $parts['port'] !== 443 || !empty($parts['path'])) {
            throw new \InvalidArgumentException('Langfuse requires an approved HTTPS origin.');
        }
        $public = (string) config('ai_infrastructure.langfuse.public_key');
        $secret = (string) config('ai_infrastructure.langfuse.secret_key');
        if ($public === '' || $secret === '') {
            throw new \LogicException('Langfuse credentials are not configured.');
        }
        $attributes = [
            'langfuse.observation.type' => 'generation', 'langfuse.observation.model.name' => $event->model,
            'langfuse.observation.level' => $event->status === 'failed' ? 'ERROR' : 'DEFAULT',
            'langfuse.observation.usage_details' => json_encode(['input' => $event->inputTokens, 'output' => $event->outputTokens], JSON_THROW_ON_ERROR),
            'user.id' => $event->actorHash, 'erp.company_hash' => $event->companyHash,
            'gen_ai.system' => $event->provider, 'erp.http_attempts' => $event->httpAttempts,
            'erp.cost_is_estimate' => true,
        ];
        if ($event->estimatedCostUsd !== null) {
            $attributes['langfuse.observation.cost_details'] = json_encode(['total' => (float) $event->estimatedCostUsd], JSON_THROW_ON_ERROR);
        }
        $encoded = [];
        foreach ($attributes as $key => $value) {
            $type = is_int($value) ? 'intValue' : (is_bool($value) ? 'boolValue' : 'stringValue');
            $encoded[] = ['key' => $key, 'value' => [$type => $value]];
        }
        $span = [
            'traceId' => $event->traceId, 'spanId' => $event->spanId, 'name' => 'erp.model', 'kind' => 3,
            'startTimeUnixNano' => (string) BigInteger::of($event->endTimeUnixNano)->minus(BigInteger::of($event->durationMs)->multipliedBy(1000000)),
            'endTimeUnixNano' => $event->endTimeUnixNano, 'attributes' => $encoded,
            'status' => ['code' => $event->status === 'failed' ? 2 : 1],
        ];
        $response = Http::withoutRedirecting()->connectTimeout(2)->timeout(5)->withBasicAuth($public, $secret)
            ->withHeaders(['x-langfuse-ingestion-version' => '4'])
            ->post($url.'/api/public/otel/v1/traces', ['resourceSpans' => [[
                'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'zoloERP-ai']]]],
                'scopeSpans' => [['scope' => ['name' => 'zoloERP.ai', 'version' => '1.0.0'], 'spans' => [$span]]],
            ]]])->throw();
        if ((int) $response->json('partialSuccess.rejectedSpans', 0) > 0) {
            throw new \RuntimeException('Langfuse rejected exported spans.');
        }
    }
}

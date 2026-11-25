<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityDescriptor;
use App\Ai\Capabilities\CapabilityDispatcher;
use App\Ai\Capabilities\CapabilityExposurePolicy;
use App\Ai\Capabilities\CapabilitySchemaCompiler;
use App\Ai\Services\AiToolManifest;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stateless MCP Streamable HTTP server supporting 2025-11-25 and 2025-06-18.
 * Exposes policy-filtered read, prepare-only draft, and scoped status tools.
 * Never approves, posts, reverses, or executes domain mutations.
 */
final class McpController
{
    public function __invoke(Request $request, McpClientGuard $guard): Response
    {
        $grant = $guard->authenticate($request);
        $principal = $grant->principal;

        if ($request->isMethod('GET')) {
            return response('', 405, ['Allow' => 'POST']);
        }

        abort_if(strlen($request->getContent()) > config('ai_infrastructure.mcp.max_request_bytes', 16384), 413);

        try {
            $rpc = json_decode($request->getContent(), true, 20, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error(null, -32700, 'Invalid JSON.');
        }

        if (
            !is_array($rpc) || array_is_list($rpc) || ($rpc['jsonrpc'] ?? '') !== '2.0'
            || !is_string($rpc['method'] ?? null)
            || (array_key_exists('id', $rpc) && !is_string($rpc['id']) && !is_int($rpc['id']))
        ) {
            return $this->error(null, -32600, 'Invalid JSON-RPC request.');
        }

        $id = $rpc['id'] ?? null;
        if (!array_key_exists('id', $rpc)) {
            return $rpc['method'] === 'notifications/initialized'
                ? response('', 202)
                : $this->error(null, -32600, 'Unsupported notification.');
        }

        $version = $request->header('MCP-Protocol-Version', '2025-11-25');
        if (!in_array($version, ['2025-11-25', '2025-06-18'], true)) {
            return $this->error($id, -32600, 'Unsupported protocol version.', 400);
        }

        $params = $rpc['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            return $this->error($id, -32602, 'Expected object parameters.');
        }

        $serverName = config('ai_infrastructure.mcp.governed_proposals_enabled', false)
            ? 'zoloERP-mcp'
            : 'zoloERP-readonly';

        $result = match ($rpc['method']) {
            'initialize' => [
                'protocolVersion' => $version,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => $serverName, 'version' => '1.1.0'],
            ],
            'ping' => (object) [],
            'tools/list' => ['tools' => $this->tools($grant)],
            'tools/call' => $this->call($grant, $params),
            default => null,
        };

        if ($result === null) {
            return $this->error($id, -32601, 'Method not available.');
        }

        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tools(McpClientGrant $grant): array
    {
        $useSharedCatalog = (bool) config('ai_capabilities.shared_discovery_enabled', false)
            || (bool) config('ai_infrastructure.mcp.governed_proposals_enabled', false);

        if (!$useSharedCatalog) {
            $tools = [];
            foreach (app(AiToolManifest::class)->all() as $key => $registration) {
                if ($grant->principal->hasTool($key) && $registration->handler->risk() === 'read_only') {
                    $tools[] = [
                        'name' => $registration->providerName,
                        'description' => $registration->handler->description(),
                        'inputSchema' => $registration->handler->schema(),
                        'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                    ];
                }
            }

            return $tools;
        }

        $eligible = app(CapabilityExposurePolicy::class)->discover($grant->principal, 'mcp', $grant);
        $tools = [];

        foreach ($eligible as $descriptor) {
            $isReadOrStatus = in_array($descriptor->operation, ['read', 'status'], true);
            $tools[] = [
                'name' => $descriptor->aliasFor('mcp') ?? $descriptor->capabilityKey,
                'description' => $descriptor->description,
                'inputSchema' => $descriptor->inputSchemaFor('mcp'),
                'annotations' => [
                    'readOnlyHint' => $isReadOrStatus,
                    'destructiveHint' => false,
                    'idempotentHint' => $descriptor->operation === 'prepare' ? true : $isReadOrStatus,
                ],
            ];
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call(McpClientGrant $grant, array $params): array
    {
        $principal = $grant->principal;
        $arguments = $params['arguments'] ?? [];
        $toolName = $params['name'] ?? null;

        if (
            !is_string($toolName) || $toolName === ''
            || !is_array($arguments) || ($arguments !== [] && array_is_list($arguments))
            || array_diff(array_keys($params), ['name', 'arguments', '_meta']) !== []
        ) {
            return $this->toolError('Tool or arguments unavailable.');
        }

        $useSharedCatalog = (bool) config('ai_capabilities.shared_discovery_enabled', false)
            || (bool) config('ai_infrastructure.mcp.governed_proposals_enabled', false);

        if (!$useSharedCatalog) {
            return $this->legacyReadCall($principal, $toolName, $arguments);
        }

        $catalog = app(CapabilityCatalog::class);
        $descriptor = $catalog->findByAlias('mcp', $toolName);
        if (!$descriptor || !app(CapabilityExposurePolicy::class)->authorize($principal, 'mcp', $descriptor, $grant)) {
            return $this->toolError('Tool or arguments unavailable.');
        }

        $compiler = app(CapabilitySchemaCompiler::class);
        try {
            if (isset($params['_meta']) && is_array($params['_meta'])) {
                $compiler->assertNoAuthorityFields($params['_meta'], '_meta');
            }
            if ($descriptor->operation !== 'read') {
                $compiler->assertNoAuthorityFields($arguments);
            }
        } catch (ValidationException) {
            return $this->toolError('Authority fields are not accepted in MCP requests.');
        }

        if ($descriptor->operation === 'read') {
            return $this->legacyReadCall($principal, $toolName, $arguments, $descriptor);
        }

        $runId = (string) Str::uuid();
        $agentKey = $descriptor->operation === 'prepare' ? 'mcp.prepare.v1' : 'mcp.status.v1';
        $this->insertRun($runId, $principal, $agentKey);

        try {
            if ($descriptor->operation === 'prepare') {
                if (array_diff(array_keys($arguments), ['client_request_id', 'task_id', 'expected_revision', 'inputs']) !== []) {
                    $this->finishRun($runId, $principal->companyId, 'failed', 'MCP_INVALID_ARGUMENTS');
                    return $this->toolError('Tool or arguments unavailable.');
                }

                $clientRequestId = $arguments['client_request_id'] ?? null;
                $inputs = $arguments['inputs'] ?? null;
                $taskId = isset($arguments['task_id']) && is_string($arguments['task_id']) && $arguments['task_id'] !== ''
                    ? $arguments['task_id']
                    : null;
                $expectedRevision = isset($arguments['expected_revision']) ? (int) $arguments['expected_revision'] : null;

                if (!is_string($clientRequestId) || !is_array($inputs) || ($inputs !== [] && array_is_list($inputs))) {
                    $this->finishRun($runId, $principal->companyId, 'failed', 'MCP_INVALID_ARGUMENTS');
                    return $this->toolError('client_request_id and inputs object are required.');
                }

                $payload = app(McpPreparationDeduplicator::class)->execute(
                    grant: $grant,
                    descriptor: $descriptor,
                    clientRequestId: $clientRequestId,
                    inputs: $inputs,
                    taskId: $taskId,
                    expectedRevision: $expectedRevision,
                    correlationId: $runId,
                );

                $isError = in_array($payload['status'] ?? 'error', ['conflict', 'forbidden', 'unavailable', 'error'], true);
                $this->finishRun(
                    $runId,
                    $principal->companyId,
                    $isError ? 'failed' : 'completed',
                    $isError ? (string) ($payload['error_code'] ?? 'MCP_PREPARE_ERROR') : null
                );

                return $this->boundedJsonResult($payload, $isError);
            }

            // operation === 'status'
            $payload = app(CapabilityDispatcher::class)->dispatch(
                principal: $principal,
                channel: 'mcp',
                capabilityKey: $descriptor->capabilityKey,
                operation: 'status',
                arguments: $arguments,
                grant: $grant,
                runId: $runId,
            );
            $this->finishRun($runId, $principal->companyId, 'completed', null);

            return $this->boundedJsonResult($payload, false);
        } catch (ValidationException $e) {
            $this->finishRun($runId, $principal->companyId, 'failed', 'MCP_VALIDATION_FAILED');
            return $this->boundedJsonResult([
                'status' => 'needs_input',
                'error_code' => 'INPUT_VALIDATION_FAILED',
                'message' => 'Request validation failed.',
                'errors' => $e->errors(),
            ], true);
        } catch (Throwable) {
            $this->finishRun($runId, $principal->companyId, 'failed', 'MCP_TOOL_UNAVAILABLE');
            return $this->toolError('Tool or arguments unavailable.');
        }
    }

    /**
     * Preserves exact legacy read execution contract through ErpToolExecutor.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function legacyReadCall(
        \App\Ai\DTOs\AiPrincipal $principal,
        string $toolName,
        array $arguments,
        ?CapabilityDescriptor $descriptor = null,
    ): array {
        $key = $descriptor?->capabilityKey;
        if ($key === null) {
            foreach (app(AiToolManifest::class)->all() as $candidate => $registration) {
                if ($registration->providerName === $toolName && $principal->hasTool($candidate)) {
                    $key = $candidate;
                    break;
                }
            }
        }

        if (!$key) {
            return $this->toolError('Tool or arguments unavailable.');
        }

        $runId = (string) Str::uuid();
        $this->insertRun($runId, $principal, 'mcp.read.v1');
        $result = app(ErpToolExecutor::class)->execute($runId, $principal, $key, $arguments);
        $this->finishRun($runId, $principal->companyId, $result->status === 'ok' ? 'completed' : 'failed', $result->errorCode);

        $text = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
        if (strlen($text) > config('ai_infrastructure.mcp.max_response_bytes', 65536)) {
            return $this->toolError('Result exceeds external response limit. Narrow the request.');
        }

        return ['isError' => $result->status !== 'ok', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function boundedJsonResult(array $payload, bool $isError): array
    {
        $text = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($text) > config('ai_infrastructure.mcp.max_response_bytes', 65536)) {
            return $this->toolError('Result exceeds external response limit. Narrow the request.');
        }

        return ['isError' => $isError, 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolError(string $message): array
    {
        return ['isError' => true, 'content' => [['type' => 'text', 'text' => $message]]];
    }

    private function insertRun(string $runId, \App\Ai\DTOs\AiPrincipal $principal, string $agentKey): void
    {
        DB::table('ai_runs')->insert([
            'id' => $runId,
            'actor_id' => $principal->actorId,
            'company_id' => $principal->companyId,
            'branch_id' => $principal->branchId,
            'financial_year_id' => $principal->financialYearId,
            'agent_key' => $agentKey,
            'status' => 'running',
            'started_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function finishRun(string $runId, int $companyId, string $status, ?string $errorCode): void
    {
        DB::table('ai_runs')
            ->where('company_id', $companyId)
            ->where('id', $runId)
            ->update([
                'status' => $status,
                'error_code' => $errorCode,
                'completed_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
    }

    private function error(mixed $id, int $code, string $message, int $status = 200): Response
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => compact('code', 'message')], $status);
    }
}

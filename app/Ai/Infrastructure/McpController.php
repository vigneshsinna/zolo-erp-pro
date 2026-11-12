<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\AiToolManifest;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

/** Stateless MCP Streamable HTTP, pinned to the reviewed 2025-11-25 protocol. */
final class McpController
{
    public function __invoke(Request $request, McpClientGuard $guard): Response
    {
        $principal = $guard->principal($request);
        if ($request->isMethod('GET')) {
            return response('', 405, ['Allow' => 'POST']);
        }
        abort_if(strlen($request->getContent()) > config('ai_infrastructure.mcp.max_request_bytes', 16384), 413);
        try {
            $rpc = json_decode($request->getContent(), true, 20, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error(null, -32700, 'Invalid JSON.');
        }
        if (!is_array($rpc) || array_is_list($rpc) || ($rpc['jsonrpc'] ?? '') !== '2.0'
            || !is_string($rpc['method'] ?? null)
            || (array_key_exists('id', $rpc) && !is_string($rpc['id']) && !is_int($rpc['id']))) {
            return $this->error(null, -32600, 'Invalid JSON-RPC request.');
        }
        $id = $rpc['id'] ?? null;
        if (!array_key_exists('id', $rpc)) {
            return $rpc['method'] === 'notifications/initialized' ? response('', 202) : $this->error(null, -32600, 'Unsupported notification.');
        }
        $version = $request->header('MCP-Protocol-Version', '2025-11-25');
        if (!in_array($version, ['2025-11-25', '2025-06-18'], true)) {
            return $this->error($id, -32600, 'Unsupported protocol version.', 400);
        }
        $params = $rpc['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            return $this->error($id, -32602, 'Expected object parameters.');
        }
        $result = match ($rpc['method']) {
            'initialize' => ['protocolVersion' => $version, 'capabilities' => ['tools' => ['listChanged' => false]], 'serverInfo' => ['name' => 'zoloERP-readonly', 'version' => '1.0.0']],
            'ping' => (object) [],
            'tools/list' => ['tools' => $this->tools($principal)],
            'tools/call' => $this->call($principal, $params),
            default => null,
        };
        if ($result === null) {
            return $this->error($id, -32601, 'Method not available.');
        }
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function tools(AiPrincipal $principal): array
    {
        $tools = [];
        foreach (app(AiToolManifest::class)->all() as $key => $registration) {
            if ($principal->hasTool($key) && $registration->handler->risk() === 'read_only') {
                $tools[] = ['name' => $registration->providerName, 'description' => $registration->handler->description(),
                    'inputSchema' => $registration->handler->schema(), 'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false]];
            }
        }
        return $tools;
    }

    private function call(AiPrincipal $principal, array $params): array
    {
        $key = null;
        foreach (app(AiToolManifest::class)->all() as $candidate => $registration) {
            if ($registration->providerName === ($params['name'] ?? null) && $principal->hasTool($candidate)) {
                $key = $candidate;
                break;
            }
        }
        $arguments = $params['arguments'] ?? [];
        if (!$key || !is_array($arguments) || ($arguments !== [] && array_is_list($arguments))
            || array_diff(array_keys($params), ['name', 'arguments', '_meta']) !== []) {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Tool or arguments unavailable.']]];
        }
        $runId = (string) Str::uuid();
        DB::table('ai_runs')->insert([
            'id' => $runId, 'actor_id' => $principal->actorId, 'company_id' => $principal->companyId,
            'branch_id' => $principal->branchId, 'financial_year_id' => $principal->financialYearId,
            'agent_key' => 'mcp.read.v1', 'status' => 'running', 'started_at' => now('UTC'),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        $result = app(ErpToolExecutor::class)->execute($runId, $principal, $key, $arguments);
        DB::table('ai_runs')->where('company_id', $principal->companyId)->where('id', $runId)
            ->update(['status' => $result->status === 'ok' ? 'completed' : 'failed', 'error_code' => $result->errorCode, 'completed_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $text = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
        if (strlen($text) > config('ai_infrastructure.mcp.max_response_bytes', 65536)) {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Result exceeds external response limit. Narrow the request.']]];
        }
        return ['isError' => $result->status !== 'ok', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function error(mixed $id, int $code, string $message, int $status = 200): Response
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => compact('code', 'message')], $status);
    }
}

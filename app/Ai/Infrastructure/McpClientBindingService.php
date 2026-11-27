<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issues and revokes scoped external MCP client bindings without permitting
 * wildcard token abilities or unreviewed operation grants.
 */
final class McpClientBindingService
{
    /**
     * @param list<string> $operations Allowed operations (subset of read, prepare, status)
     * @param list<string> $readTools
     * @param list<string> $prepareActions
     * @return array{binding_id: string, token_id: int, plain_text_token: string, abilities: list<string>}
     */
    public function issue(
        User $actor,
        int $companyId,
        int $branchId,
        int $financialYearId,
        string $clientIdentifier,
        array $operations,
        array $readTools = [],
        array $prepareActions = [],
        int $ttlHours = 24,
    ): array {
        $operations = array_values(array_unique($operations));
        foreach ($operations as $op) {
            if (!in_array($op, ['read', 'prepare', 'status'], true)) {
                throw new InvalidArgumentException("Unsupported MCP operation [{$op}].");
            }
        }
        if ($operations === []) {
            throw new InvalidArgumentException('At least one MCP operation must be granted.');
        }

        $allowedConfigRead = (array) config('ai_infrastructure.mcp.tools', []);
        $allowedConfigPrepare = (array) config('ai_infrastructure.mcp.prepare_tools', []);
        $allowedConfigStatus = (array) config('ai_infrastructure.mcp.status_tools', ['proposal.status.v1']);

        foreach ($readTools as $tool) {
            if (!in_array($tool, $allowedConfigRead, true)) {
                throw new InvalidArgumentException("MCP read tool [{$tool}] is not on the server allowlist.");
            }
            if (str_starts_with($tool, 'optical.') && !config('ai_business_knowledge.opticals_mcp_enabled', false)) {
                throw new InvalidArgumentException("Optical MCP read tool [{$tool}] is disabled by configuration.");
            }
        }
        foreach ($prepareActions as $action) {
            if (!in_array($action, $allowedConfigPrepare, true)) {
                throw new InvalidArgumentException("MCP prepare action [{$action}] is not on the server allowlist.");
            }
            if (in_array($action, ['purchase_bill.create.v1', 'sales_order.create.v1', 'sales_invoice.create.v1'], true)
                && !config('ai_infrastructure.mcp.commercial_prepare_enabled', false)) {
                throw new InvalidArgumentException("Commercial MCP prepare action [{$action}] is disabled by configuration.");
            }
        }

        $abilities = [];
        if (in_array('read', $operations, true)) {
            $abilities[] = 'mcp:read';
            foreach ($readTools as $tool) {
                $abilities[] = 'mcp:tool:' . $tool;
            }
        }
        if (in_array('prepare', $operations, true)) {
            $abilities[] = 'mcp:prepare';
            foreach ($prepareActions as $action) {
                $abilities[] = 'mcp:tool:' . $action;
            }
        }
        if (in_array('status', $operations, true)) {
            $abilities[] = 'mcp:status';
            foreach ($allowedConfigStatus as $statusTool) {
                $abilities[] = 'mcp:tool:' . $statusTool;
            }
        }

        $abilities = array_values(array_unique($abilities));
        $expiresAt = now('UTC')->addHours(max(1, $ttlHours));
        $token = $actor->createToken($clientIdentifier, $abilities, $expiresAt);
        $bindingId = (string) Str::uuid();

        DB::table('ai_mcp_client_bindings')->insert([
            'id' => $bindingId,
            'client_identifier' => $clientIdentifier,
            'personal_access_token_id' => $token->accessToken->id,
            'actor_id' => $actor->id,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'financial_year_id' => $financialYearId,
            'allowed_tools' => json_encode(array_values(array_unique($readTools)), JSON_THROW_ON_ERROR),
            'allowed_operations' => json_encode($operations, JSON_THROW_ON_ERROR),
            'allowed_actions' => json_encode(array_values(array_unique($prepareActions)), JSON_THROW_ON_ERROR),
            'is_active' => true,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'revoked_reason' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return [
            'binding_id' => $bindingId,
            'token_id' => (int) $token->accessToken->id,
            'plain_text_token' => $token->plainTextToken,
            'abilities' => $abilities,
        ];
    }

    public function revoke(string $bindingId, int $companyId, string $reason = 'revoked'): void
    {
        $binding = DB::table('ai_mcp_client_bindings')
            ->where('id', $bindingId)
            ->where('company_id', $companyId)
            ->first();

        if (!$binding) {
            return;
        }

        DB::transaction(function () use ($bindingId, $companyId, $binding, $reason): void {
            DB::table('ai_mcp_client_bindings')
                ->where('id', $bindingId)
                ->where('company_id', $companyId)
                ->update([
                    'is_active' => false,
                    'revoked_at' => now('UTC'),
                    'revoked_reason' => Str::limit($reason, 250, ''),
                    'updated_at' => now('UTC'),
                ]);

            DB::table('personal_access_tokens')
                ->where('id', $binding->personal_access_token_id)
                ->delete();
        });
    }
}

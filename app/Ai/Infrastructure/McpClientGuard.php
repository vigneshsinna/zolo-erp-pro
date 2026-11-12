<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class McpClientGuard
{
    public function principal(Request $request): AiPrincipal
    {
        abort_unless(config('ai_infrastructure.mcp.enabled', false), 404);
        $origin = $request->header('Origin');
        abort_if($origin !== null && !in_array($origin, config('ai_infrastructure.mcp.allowed_origins', []), true), 403);
        $token = $request->user()?->currentAccessToken();
        abort_unless($request->bearerToken() && $token instanceof PersonalAccessToken, 403, 'A bound MCP bearer token is required.');
        // Wildcard ERP API tokens never implicitly grant MCP access.
        abort_unless(in_array('mcp:read', $token->abilities ?? [], true), 403);
        abort_if($token->expires_at && $token->expires_at->isPast(), 403);
        $binding = DB::table('ai_mcp_client_bindings')
            ->where('personal_access_token_id', $token->id)
            ->where('actor_id', $request->user()->id)
            ->where('is_active', true)->where('expires_at', '>', now('UTC'))->first();
        abort_unless($binding, 403, 'MCP token binding is unavailable.');
        $principal = app(AiPrincipalFactory::class)->createForUser(
            (int) $binding->actor_id, (int) $binding->company_id, (int) $binding->branch_id,
            (int) $binding->financial_year_id,
        );
        abort_unless($principal->isActive && app(AiFeatureGate::class)->isEnabledForCompany($principal->companyId), 403);
        $tools = array_values(array_intersect(
            $principal->permittedToolNames,
            json_decode($binding->allowed_tools, true, flags: JSON_THROW_ON_ERROR),
            config('ai_infrastructure.mcp.tools', []),
        ));
        $tools = array_values(array_filter($tools, fn (string $tool): bool => in_array('mcp:tool:'.$tool, $token->abilities ?? [], true)));
        return new AiPrincipal(
            actorId: $principal->actorId, companyId: $principal->companyId, branchId: $principal->branchId,
            financialYearId: $principal->financialYearId, permittedToolNames: $tools,
            requestId: $principal->requestId, effectiveRole: $principal->effectiveRole, isActive: true,
        );
    }
}

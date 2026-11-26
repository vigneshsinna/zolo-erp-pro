<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Authorizes external MCP requests by binding Sanctum token abilities to
 * server-side actor/company/branch/FY membership and reviewed operation grants.
 */
final class McpClientGuard
{
    public function principal(Request $request): AiPrincipal
    {
        return $this->authenticate($request)->principal;
    }

    public function authenticate(Request $request): McpClientGrant
    {
        abort_unless(config('ai_infrastructure.mcp.enabled', false), 404);

        $origin = $request->header('Origin');
        abort_if($origin !== null && !in_array($origin, config('ai_infrastructure.mcp.allowed_origins', []), true), 403);

        $token = $request->user()?->currentAccessToken();
        abort_unless($request->bearerToken() && $token instanceof PersonalAccessToken, 403, 'A bound MCP bearer token is required.');

        $abilities = is_array($token->abilities) ? $token->abilities : [];
        // Wildcard ERP API tokens never implicitly grant MCP access.
        abort_if(in_array('*', $abilities, true), 403, 'Wildcard tokens cannot access MCP.');
        abort_unless(
            array_intersect($abilities, ['mcp:read', 'mcp:prepare', 'mcp:status']) !== [],
            403,
            'MCP operation scope is required.'
        );
        abort_if($token->expires_at && $token->expires_at->isPast(), 403);

        $bindingQuery = DB::table('ai_mcp_client_bindings')
            ->where('personal_access_token_id', $token->id)
            ->where('actor_id', $request->user()->id)
            ->where('is_active', true)
            ->where('expires_at', '>', now());

        if (Schema::hasColumn('ai_mcp_client_bindings', 'revoked_at')) {
            $bindingQuery->whereNull('revoked_at');
        }

        $binding = $bindingQuery->first();
        abort_unless($binding, 403, 'MCP token binding is unavailable.');

        $principal = app(AiPrincipalFactory::class)->createForUser(
            (int) $binding->actor_id,
            (int) $binding->company_id,
            (int) $binding->branch_id,
            (int) $binding->financial_year_id,
        );

        abort_unless(
            $principal->isActive && app(AiFeatureGate::class)->isEnabledForCompany($principal->companyId),
            403
        );

        // Old bindings without allowed_operations strictly default to read-only.
        $bindingOperations = ['read'];
        if (property_exists($binding, 'allowed_operations') && $binding->allowed_operations !== null) {
            $decodedOps = json_decode((string) $binding->allowed_operations, true);
            $bindingOperations = is_array($decodedOps) ? array_values(array_unique(array_filter($decodedOps, 'is_string'))) : ['read'];
        }

        $tokenOperations = [];
        if (in_array('mcp:read', $abilities, true)) {
            $tokenOperations[] = 'read';
        }
        if (in_array('mcp:prepare', $abilities, true)) {
            $tokenOperations[] = 'prepare';
        }
        if (in_array('mcp:status', $abilities, true)) {
            $tokenOperations[] = 'status';
        }

        $allowedOperations = array_values(array_intersect($bindingOperations, $tokenOperations));

        // Governed proposals and status require global + foundation + company opt-in.
        $companySetting = DB::table('ai_feature_settings')
            ->where('company_id', $principal->companyId)
            ->first();
        $companyProposalsEnabled = $companySetting !== null
            && property_exists($companySetting, 'mcp_proposals_enabled')
            && (bool) $companySetting->mcp_proposals_enabled;

        $proposalsGateOpen = (bool) config('ai_infrastructure.mcp.governed_proposals_enabled', false)
            && (bool) config('ai_actions.foundation_enabled', false)
            && $companyProposalsEnabled;

        if (!$proposalsGateOpen) {
            $allowedOperations = array_values(array_diff($allowedOperations, ['prepare', 'status']));
        }

        abort_if($allowedOperations === [], 403, 'No permitted MCP operations for this token and company.');

        $hasExactAbility = static fn (string $tool): bool => in_array('mcp:tool:' . $tool, $abilities, true);

        $allowedReadTools = [];
        if (in_array('read', $allowedOperations, true)) {
            $bindingTools = json_decode((string) ($binding->allowed_tools ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
            $allowedReadTools = array_values(array_filter(
                array_intersect(
                    $principal->permittedToolNames,
                    is_array($bindingTools) ? $bindingTools : [],
                    (array) config('ai_infrastructure.mcp.tools', []),
                ),
                $hasExactAbility
            ));
        }

        $allowedPrepareActions = [];
        if (in_array('prepare', $allowedOperations, true)) {
            $bindingActions = [];
            if (property_exists($binding, 'allowed_actions') && $binding->allowed_actions !== null) {
                $decodedActions = json_decode((string) $binding->allowed_actions, true);
                $bindingActions = is_array($decodedActions) ? $decodedActions : [];
            }

            $allowedPrepareActions = array_values(array_filter(
                array_intersect(
                    $principal->permittedToolNames,
                    $bindingActions,
                    (array) config('ai_infrastructure.mcp.prepare_tools', []),
                ),
                $hasExactAbility
            ));
        }

        $allowedStatusTools = [];
        if (in_array('status', $allowedOperations, true)) {
            $allowedStatusTools = array_values(array_filter(
                (array) config('ai_infrastructure.mcp.status_tools', ['proposal.status.v1']),
                $hasExactAbility
            ));
        }

        $scopedTools = array_values(array_unique([...$allowedReadTools, ...$allowedPrepareActions]));

        $scopedPrincipal = new AiPrincipal(
            actorId: $principal->actorId,
            companyId: $principal->companyId,
            branchId: $principal->branchId,
            financialYearId: $principal->financialYearId,
            permittedToolNames: $scopedTools,
            requestId: $principal->requestId,
            effectiveRole: $principal->effectiveRole,
            isActive: true,
        );

        $clientId = property_exists($binding, 'client_identifier') && $binding->client_identifier
            ? (string) $binding->client_identifier
            : 'binding:' . $binding->id;

        return new McpClientGrant(
            bindingId: (string) $binding->id,
            clientIdentifier: $clientId,
            tokenId: (int) $token->id,
            principal: $scopedPrincipal,
            allowedOperations: $allowedOperations,
            allowedReadTools: $allowedReadTools,
            allowedPrepareActions: $allowedPrepareActions,
            allowedStatusTools: $allowedStatusTools,
        );
    }
}

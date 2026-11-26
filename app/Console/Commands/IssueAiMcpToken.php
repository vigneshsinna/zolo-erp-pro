<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Services\AiPrincipalFactory;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Server-admin provisioning; no client request can create or change token scope. */
final class IssueAiMcpToken extends Command
{
    protected $signature = 'ai:mcp-token {actor} {company} {branch} {year} {--tool=*} {--days=30}';
    protected $description = 'Issue a bounded read-only MCP token for an existing ERP actor and authorized company context';

    public function handle(AiPrincipalFactory $factory): int
    {
        $principal = $factory->createForUser((int) $this->argument('actor'), (int) $this->argument('company'), (int) $this->argument('branch'), (int) $this->argument('year'));
        $tools = array_values(array_unique($this->option('tool')));
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if (!$principal->isActive || $tools === [] || $days < 1 || $days > 90
            || array_diff($tools, config('ai_infrastructure.mcp.tools', [])) !== []
            || array_diff($tools, $principal->permittedToolNames) !== []) {
            $this->error('Select permitted read tools and a lifetime of 1 through 90 days.');
            return self::FAILURE;
        }
        $plain = DB::transaction(function () use ($principal, $tools, $days): string {
            $expires = now('UTC')->addDays($days);
            $token = User::findOrFail($principal->actorId)->createToken('ERP MCP read-only', ['mcp:read', ...array_map(fn ($tool) => 'mcp:tool:'.$tool, $tools)], $expires);
            DB::table('ai_mcp_client_bindings')->insert([
                'id' => (string) Str::uuid(), 'personal_access_token_id' => $token->accessToken->id,
                'actor_id' => $principal->actorId, 'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId, 'financial_year_id' => $principal->financialYearId,
                'allowed_tools' => json_encode($tools, JSON_THROW_ON_ERROR), 'is_active' => true,
                'expires_at' => $expires, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            return $token->plainTextToken;
        });
        $this->line($plain);
        $this->warn('Store this bearer token securely. It is displayed only once.');
        return self::SUCCESS;
    }
}

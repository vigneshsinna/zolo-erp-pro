<?php

declare(strict_types=1);

namespace App\Ai\Conversation;

use App\Ai\DTOs\AiPrincipal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Thread-scoped clarification marker; never an ERP action or posting authority. */
final class PendingPartyIntentService
{
    private function scoped(AiPrincipal $principal, string $threadId)
    {
        return DB::table('ai_pending_party_intents')->where([
            'company_id' => $principal->companyId,
            'branch_id' => $principal->branchId,
            'financial_year_id' => $principal->financialYearId,
            'actor_id' => $principal->actorId,
            'thread_id' => $threadId,
        ]);
    }

    public function isPending(AiPrincipal $principal, string $threadId): bool
    {
        return $this->scoped($principal, $threadId)->where('status', 'pending')
            ->where('expires_at', '>', now('UTC'))->exists();
    }

    public function ask(AiPrincipal $principal, string $threadId): void
    {
        // Caller holds an owned conversation thread row lock.
        DB::table('ai_pending_party_intents')->updateOrInsert(
            ['company_id' => $principal->companyId, 'actor_id' => $principal->actorId, 'thread_id' => $threadId],
            [
                'id' => (string) (DB::table('ai_pending_party_intents')->where('company_id', $principal->companyId)
                    ->where('actor_id', $principal->actorId)->where('thread_id', $threadId)->value('id') ?: Str::uuid()),
                'branch_id' => $principal->branchId, 'financial_year_id' => $principal->financialYearId,
                'status' => 'pending', 'revision' => 1, 'subject_hint' => null,
                'expires_at' => now('UTC')->addMinutes(30),
                'updated_at' => now('UTC'), 'created_at' => now('UTC'),
            ],
        );
    }

    public function resolve(AiPrincipal $principal, string $threadId): void
    {
        $this->scoped($principal, $threadId)->where('status', 'pending')
            ->update(['status' => 'resolved', 'revision' => DB::raw('revision + 1'), 'updated_at' => now('UTC')]);
    }
}

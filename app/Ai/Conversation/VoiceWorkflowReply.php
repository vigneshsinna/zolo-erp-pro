<?php

declare(strict_types=1);

namespace App\Ai\Conversation;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionSecurity;
use App\Models\AiMediaAsset;
use App\Models\AiMediaExtraction;
use App\Policies\AiMediaAssetPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class VoiceWorkflowReply
{
    public function assertTask(AiPrincipal $principal, string $threadId, string $taskId): array
    {
        if (!DB::table('ai_thread_scopes')->where(['id' => $threadId, 'user_id' => $principal->actorId,
            'company_id' => $principal->companyId, 'branch_id' => $principal->branchId,
            'financial_year_id' => $principal->financialYearId, 'status' => 'active'])
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()) {
            throw new AuthorizationException('Conversation is unavailable.');
        }
        $task = app(ActiveWorkflowTaskResolver::class)->resolve($principal, $threadId, $taskId)[0];
        app(ActionSecurity::class)->definition($task['action_key'], $principal);
        return $task;
    }

    public function assertAsset(AiPrincipal $principal, AiMediaAsset $asset, string $threadId, string $taskId): void
    {
        if (!app(AiMediaAssetPolicy::class)->canStageWriteAction($principal, $asset)
            || $asset->source_kind !== 'audio' || $asset->thread_id !== $threadId || $asset->workflow_task_id !== $taskId
            || (int) $asset->branch_id !== $principal->branchId || (int) $asset->financial_year_id !== $principal->financialYearId) {
            throw new AuthorizationException('Voice reply is unavailable in this draft context.');
        }
    }

    public function consume(AiPrincipal $principal, AiMediaAsset $asset, AiMediaExtraction $extraction, array $input): array
    {
        $this->assertTask($principal, $input['thread_id'], $input['active_task_id']);
        $this->assertAsset($principal, $asset, $input['thread_id'], $input['active_task_id']);
        if (($input['voice_extraction_revision'] ?? null) !== $extraction->revision) {
            throw new ConflictHttpException('Voice transcript changed. Review the latest transcript.');
        }
        $data = $extraction->extracted_json;
        $transcript = trim((string) ($data['transcript_text'] ?? ''));
        if (!$extraction->isConfirmed() || $extraction->confirmed_by !== $principal->actorId || $transcript === ''
            || ($data['language_supported'] ?? true) !== true || trim($input['message']) !== $transcript) {
            throw ValidationException::withMessages(['voice' => 'Review, correct and confirm this transcript before sending the reply.']);
        }
        return ['media_id' => $asset->id, 'extraction_id' => $extraction->id, 'extraction_revision' => $extraction->revision,
            'warnings' => array_values($data['warnings'] ?? [])];
    }
}

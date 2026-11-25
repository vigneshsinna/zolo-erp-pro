<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityDescriptor;
use App\Ai\Capabilities\CapabilityDispatcher;
use App\Ai\Capabilities\ProposalStatusReader;
use App\Ai\Services\CanonicalActionPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Atomic idempotency and duplicate-submission guard for external MCP action
 * preparation requests. Uses a unique claim key scoped by binding, actor,
 * company, branch, FY, capability, and client_request_id.
 */
final class McpPreparationDeduplicator
{
    public function __construct(
        private readonly CapabilityDispatcher $dispatcher,
        private readonly CapabilityCatalog $catalog,
        private readonly ProposalStatusReader $statusReader,
    ) {}

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    public function execute(
        McpClientGrant $grant,
        CapabilityDescriptor $descriptor,
        string $clientRequestId,
        array $inputs,
        ?string $taskId,
        ?int $expectedRevision,
        string $correlationId,
    ): array {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/D', $clientRequestId)) {
            return [
                'status' => 'error',
                'error_code' => 'INVALID_CLIENT_REQUEST_ID',
                'message' => 'client_request_id must be 8-128 safe identifier characters.',
            ];
        }

        $principal = $grant->principal;
        $requestHash = hash('sha256', CanonicalActionPayload::json([
            'capability' => $descriptor->capabilityKey,
            'task_id' => $taskId,
            'expected_revision' => $expectedRevision,
            'inputs' => $inputs,
        ]));

        $claimId = (string) Str::uuid();
        $replay = null;

        try {
            DB::transaction(function () use ($claimId, $grant, $principal, $descriptor, $clientRequestId, $requestHash, $taskId, $expectedRevision, $correlationId): void {
                DB::table('ai_mcp_preparation_requests')->insert([
                    'id' => $claimId,
                    'client_binding_id' => $grant->bindingId,
                    'actor_id' => $principal->actorId,
                    'company_id' => $principal->companyId,
                    'branch_id' => $principal->branchId,
                    'financial_year_id' => $principal->financialYearId,
                    'capability_key' => $descriptor->capabilityKey,
                    'client_request_id' => $clientRequestId,
                    'request_hash' => $requestHash,
                    'status' => 'in_progress',
                    'task_id' => $taskId,
                    'task_revision' => $expectedRevision,
                    'correlation_id' => $correlationId,
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            });
        } catch (QueryException $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }

            $replay = DB::transaction(function () use ($grant, $principal, $descriptor, $clientRequestId, $requestHash): array {
                $existing = DB::table('ai_mcp_preparation_requests')
                    ->where('client_binding_id', $grant->bindingId)
                    ->where('actor_id', $principal->actorId)
                    ->where('company_id', $principal->companyId)
                    ->where('branch_id', $principal->branchId)
                    ->where('financial_year_id', $principal->financialYearId)
                    ->where('capability_key', $descriptor->capabilityKey)
                    ->where('client_request_id', $clientRequestId)
                    ->lockForUpdate()
                    ->first();

                if (!$existing) {
                    return [
                        'status' => 'conflict',
                        'error_code' => 'MCP_REQUEST_IN_PROGRESS',
                        'message' => 'A matching preparation request is already in progress.',
                    ];
                }

                if (!hash_equals((string) $existing->request_hash, $requestHash)) {
                    return [
                        'status' => 'conflict',
                        'error_code' => 'MCP_IDEMPOTENCY_CONFLICT',
                        'message' => 'This client_request_id was already used with different inputs.',
                    ];
                }

                if ($existing->status === 'in_progress') {
                    return [
                        'status' => 'conflict',
                        'error_code' => 'MCP_REQUEST_IN_PROGRESS',
                        'message' => 'A matching preparation request is already in progress. Retry shortly.',
                    ];
                }

                $decoded = json_decode((string) ($existing->response_json ?? '{}'), true) ?? [];
                $decoded['idempotent_replay'] = true;

                return $decoded;
            });
        }

        if ($replay !== null) {
            return $replay;
        }

        $dispatchArgs = $inputs;
        if ($taskId !== null) {
            $dispatchArgs['task_id'] = $taskId;
        }
        if ($expectedRevision !== null) {
            $dispatchArgs['expected_revision'] = $expectedRevision;
        }

        try {
            $raw = $this->dispatcher->dispatch(
                principal: $principal,
                channel: 'mcp',
                capabilityKey: $descriptor->capabilityKey,
                operation: 'prepare',
                arguments: $dispatchArgs,
                grant: $grant,
                runId: $correlationId,
            );

            $response = $this->formatPreparedResponse($descriptor, $clientRequestId, $raw);
            $this->finalizeClaim($claimId, $response);

            return $response;
        } catch (ConflictHttpException $e) {
            $response = [
                'status' => 'conflict',
                'error_code' => 'TASK_REVISION_CONFLICT',
                'message' => $e->getMessage() ?: 'Draft revision conflict; reload latest task state.',
                'client_request_id' => $clientRequestId,
                'catalog_version' => $this->catalog->versionHash(),
            ];
            $this->finalizeClaim($claimId, $response, 'conflict');

            return $response;
        } catch (ValidationException $e) {
            $response = [
                'status' => 'needs_input',
                'error_code' => 'INPUT_VALIDATION_FAILED',
                'message' => 'One or more inputs require correction.',
                'errors' => $e->errors(),
                'client_request_id' => $clientRequestId,
                'catalog_version' => $this->catalog->versionHash(),
            ];
            $this->finalizeClaim($claimId, $response, 'needs_input');

            return $response;
        } catch (AuthorizationException) {
            $response = [
                'status' => 'forbidden',
                'error_code' => 'CAPABILITY_FORBIDDEN',
                'message' => 'Capability is unavailable or unauthorized.',
                'client_request_id' => $clientRequestId,
            ];
            $this->finalizeClaim($claimId, $response, 'failed');

            return $response;
        } catch (Throwable) {
            $response = [
                'status' => 'error',
                'error_code' => 'MCP_PREPARATION_FAILED',
                'message' => 'Preparation could not be completed. Review inputs and try again.',
                'client_request_id' => $clientRequestId,
            ];
            $this->finalizeClaim($claimId, $response, 'failed');

            return $response;
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function formatPreparedResponse(CapabilityDescriptor $descriptor, string $clientRequestId, array $raw): array
    {
        if (($raw['status'] ?? '') === 'needs_input') {
            return [
                'status' => 'needs_input',
                'capability_key' => $descriptor->capabilityKey,
                'client_request_id' => $clientRequestId,
                'task_id' => $raw['task_id'] ?? null,
                'task_revision' => $raw['task_revision'] ?? null,
                'missing_field' => $raw['missing_field'] ?? null,
                'message' => $raw['message'] ?? 'Additional details required.',
                'candidates' => $raw['candidates'] ?? [],
                'required_inputs' => $raw['required_inputs'] ?? [],
                'confirmed_fields' => $raw['confirmed_fields'] ?? [],
                'requires_approval' => true,
                'catalog_version' => $this->catalog->versionHash(),
            ];
        }

        $data = (array) ($raw['data'] ?? []);
        $proposalId = (string) ($data['proposal_id'] ?? '');
        $reviewUrl = (string) ($data['review_url'] ?? ($proposalId !== '' ? $this->statusReader->canonicalReviewUrl($proposalId) : '/ai/action-review'));

        return [
            'status' => 'proposal_created',
            'capability_key' => $descriptor->capabilityKey,
            'client_request_id' => $clientRequestId,
            'task_id' => $data['task_id'] ?? null,
            'task_revision' => $data['task_revision'] ?? null,
            'proposal_id' => $proposalId !== '' ? $proposalId : null,
            'proposal_version' => $data['version'] ?? 1,
            'expires_at' => $data['expires_at'] ?? null,
            'requires_approval' => true,
            'review_url' => $reviewUrl,
            'preview' => [
                'supplier' => $data['supplier']['name'] ?? null,
                'warehouse' => $data['warehouse']['name'] ?? null,
                'date' => $data['date'] ?? null,
                'totals' => $data['totals'] ?? [],
                'item_count' => is_array($data['items'] ?? null) ? count($data['items']) : 0,
            ],
            'warnings' => $data['warnings'] ?? [],
            'catalog_version' => $this->catalog->versionHash(),
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function finalizeClaim(string $claimId, array $response, ?string $statusOverride = null): void
    {
        DB::table('ai_mcp_preparation_requests')
            ->where('id', $claimId)
            ->update([
                'status' => $statusOverride ?? (string) ($response['status'] ?? 'failed'),
                'task_id' => $response['task_id'] ?? null,
                'task_revision' => $response['task_revision'] ?? null,
                'proposal_id' => $response['proposal_id'] ?? null,
                'proposal_version' => $response['proposal_version'] ?? null,
                'response_json' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => now('UTC'),
            ]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062;
    }
}

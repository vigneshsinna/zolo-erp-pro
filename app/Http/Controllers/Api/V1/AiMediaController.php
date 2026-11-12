<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Multimodal\Exceptions\MultimodalException;
use App\Ai\Multimodal\Services\ExtractionRevisionService;
use App\Ai\Multimodal\Services\MediaExtractionService;
use App\Ai\Multimodal\Services\MediaIngestionService;
use App\Ai\Multimodal\Services\NormalizedInputWorkflowBridge;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\UploadAiMediaRequest;
use App\Jobs\Ai\ExtractMediaJob;
use App\Models\AiMediaAsset;
use App\Models\AiMediaExtraction;
use App\Policies\AiMediaAssetPolicy;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Company- and actor-scoped controller for Phase 2.3 multimodal uploads,
 * private previews, perception extraction, revision edits, confirmation, and handoff.
 */
class AiMediaController extends Controller
{
    public function __construct(
        protected CompanyContextResolver $contextResolver,
        protected AiPrincipalFactory $principalFactory,
        protected AiFeatureGate $featureGate,
        protected CommercialPermission $permissions,
        protected AiMediaAssetPolicy $policy,
        protected MediaIngestionService $ingestionService,
        protected MediaExtractionService $extractionService,
        protected ExtractionRevisionService $revisionService,
        protected NormalizedInputWorkflowBridge $workflowBridge,
    ) {}

    public function store(UploadAiMediaRequest $request): JsonResponse
    {
        try {
            $principal = $this->resolveAuthorizedPrincipal($request);
            $idempotencyKey = $this->resolveIdempotencyKey($request);

            $alreadyExisted = $idempotencyKey !== null && AiMediaAsset::query()
                ->where('company_id', $principal->companyId)
                ->where('actor_id', $principal->actorId)
                ->where('idempotency_key', $idempotencyKey)
                ->whereNull('deleted_at_policy')
                ->where('expires_at', '>', now('UTC'))
                ->exists();

            $asset = $this->ingestionService->ingest(
                principal: $principal,
                file: $request->file('file'),
                declaredMime: $request->file('file')?->getClientMimeType(),
                originalName: $request->file('file')?->getClientOriginalName(),
                idempotencyKey: $idempotencyKey,
                threadId: $request->input('thread_id'),
                workflowTaskId: $request->input('workflow_task_id'),
            );

            $extraction = null;
            $async = filter_var($request->input('async', false), FILTER_VALIDATE_BOOLEAN);
            $extractNow = filter_var($request->input('extract_now', true), FILTER_VALIDATE_BOOLEAN);

            if ($async) {
                ExtractMediaJob::dispatch(
                    $principal->companyId,
                    $principal->actorId,
                    $principal->branchId,
                    $principal->financialYearId,
                    $asset->id,
                    $idempotencyKey,
                );
            } elseif ($extractNow) {
                $extraction = $this->extractionService->extract($principal, $asset->fresh(), $idempotencyKey);
            } else {
                $extraction = $asset->latestExtraction;
            }

            return response()->json([
                'status' => 'ok',
                'idempotent_replay' => $alreadyExisted,
                'media' => $this->presentAsset($asset->fresh()),
                'extraction' => $extraction ? $this->presentExtraction($extraction) : null,
            ], $alreadyExisted ? 200 : 201);
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $principal = $this->resolveAuthorizedPrincipal($request);
            $asset = $this->loadOwnedAsset($principal, $id);
            $extraction = $asset->latestExtraction;

            return response()->json([
                'status' => 'ok',
                'media' => $this->presentAsset($asset),
                'extraction' => $extraction ? $this->presentExtraction($extraction) : null,
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }

    public function preview(Request $request, string $id): StreamedResponse|JsonResponse
    {
        try {
            $principal = $this->resolveAuthorizedPrincipal($request);
            $asset = $this->loadOwnedAsset($principal, $id);
            if ($asset->isExpired()) {
                throw MultimodalException::mediaExpired();
            }

            $page = max(1, (int) $request->query('page', 1));
            $disk = Storage::disk($asset->private_disk);
            $targetKey = $asset->private_key;
            $mime = $asset->canonical_mime;

            foreach ((array) $asset->derivatives_json as $deriv) {
                if ((int) ($deriv['page'] ?? 1) === $page && ! empty($deriv['key'])) {
                    $targetKey = (string) $deriv['key'];
                    $mime = (string) ($deriv['mime'] ?? $mime);
                    break;
                }
            }

            if ($targetKey === '' || ! $disk->exists($targetKey)) {
                throw MultimodalException::mediaNotFound('Preview derivative was not found.');
            }

            return response()->stream(
                function () use ($disk, $targetKey): void {
                    $stream = $disk->readStream($targetKey);
                    if (is_resource($stream)) {
                        fpassthru($stream);
                        fclose($stream);
                    }
                },
                200,
                [
                    'Content-Type' => $mime,
                    'Cache-Control' => 'private, no-store, max-age=0',
                    'X-Content-Type-Options' => 'nosniff',
                    'Content-Disposition' => 'inline; filename="' . ($asset->original_file_name_sanitized ?: 'media') . '"',
                ]
            );
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }

    public function extract(Request $request, string $id): JsonResponse
    {
        try {
            $principal = $this->resolveAuthorizedPrincipal($request);
            $asset = $this->loadOwnedAsset($principal, $id);

            $validated = $request->validate([
                'force' => ['nullable', 'boolean'],
                'companion_text' => ['nullable', 'string', 'max:2000'],
            ]);

            $extraction = $this->extractionService->extract(
                principal: $principal,
                asset: $asset,
                idempotencyKey: $this->resolveIdempotencyKey($request),
                forceReextract: (bool) ($validated['force'] ?? false),
                companionText: $validated['companion_text'] ?? null,
            );

            return response()->json([
                'status' => 'ok',
                'media' => $this->presentAsset($asset->fresh()),
                'extraction' => $this->presentExtraction($extraction),
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }

    public function extraction(Request $request, string $id): JsonResponse
    {
        try {
            $principal = $this->resolveAuthorizedPrincipal($request);
            $asset = $this->loadOwnedAsset($principal, $id);
            $extraction = $asset->latestExtraction;

            if ($extraction === null) {
                throw MultimodalException::mediaNotFound('No extraction exists yet for this media upload.');
            }

            return response()->json([
                'status' => 'ok',
                'media' => $this->presentAsset($asset),
                'extraction' => $this->presentExtraction($extraction),
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }

    public function updateExtraction(Request $request, string $id): JsonResponse
    {
        try {
            $principal = $this->resolveAuthorizedPrincipal($request);
            $asset = $this->loadOwnedAsset($principal, $id);

            $validated = $request->validate([
                'expected_revision' => ['required', 'integer', 'min:1'],
                'document_type' => ['nullable', 'string', 'max:40'],
                'supplier_name' => ['nullable', 'string', 'max:180'],
                'supplier_gstin' => ['nullable', 'string', 'max:20'],
                'supplier_phone' => ['nullable', 'string', 'max:32'],
                'buyer_name' => ['nullable', 'string', 'max:180'],
                'buyer_gstin' => ['nullable', 'string', 'max:20'],
                'invoice_number' => ['nullable', 'string', 'max:80'],
                'invoice_date' => ['nullable', 'string', 'max:32'],
                'currency' => ['nullable', 'string', 'max:10'],
                'place_of_supply' => ['nullable', 'string', 'max:80'],
                'subtotal' => ['nullable'],
                'tax_amount' => ['nullable'],
                'discount_amount' => ['nullable'],
                'total_amount' => ['nullable'],
                'transcript_text' => ['nullable', 'string', 'max:4000'],
                'intent_guess' => ['nullable', 'string', 'max:60'],
                'party_name' => ['nullable', 'string', 'max:180'],
                'warehouse_name' => ['nullable', 'string', 'max:120'],
                'expected_date' => ['nullable', 'string', 'max:32'],
                'notes' => ['nullable', 'string', 'max:500'],
                'companion_text' => ['nullable', 'string', 'max:2000'],
                'lines_patch' => ['nullable', 'array', 'max:100'],
                'items_patch' => ['nullable', 'array', 'max:100'],
                'remove_client_line_ids' => ['nullable', 'array', 'max:100'],
                'add_lines' => ['nullable', 'array', 'max:50'],
            ]);

            $expectedRevision = (int) $validated['expected_revision'];
            unset($validated['expected_revision']);

            $updated = $this->revisionService->patchExtraction(
                principal: $principal,
                asset: $asset,
                expectedRevision: $expectedRevision,
                patch: $validated,
                markConfirmed: false,
            );

            return response()->json([
                'status' => 'ok',
                'media' => $this->presentAsset($asset->fresh()),
                'extraction' => $this->presentExtraction($updated),
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        try {
            $principal = $this->resolveAuthorizedPrincipal($request);
            $asset = $this->loadOwnedAsset($principal, $id);

            $validated = $request->validate([
                'expected_revision' => ['nullable', 'integer', 'min:1'],
                'companion_text' => ['nullable', 'string', 'max:2000'],
                'document_type' => ['nullable', 'string', 'max:40'],
                'supplier_name' => ['nullable', 'string', 'max:180'],
                'invoice_number' => ['nullable', 'string', 'max:80'],
                'invoice_date' => ['nullable', 'string', 'max:32'],
                'transcript_text' => ['nullable', 'string', 'max:4000'],
                'intent_guess' => ['nullable', 'string', 'max:60'],
                'party_name' => ['nullable', 'string', 'max:180'],
                'warehouse_name' => ['nullable', 'string', 'max:120'],
                'lines_patch' => ['nullable', 'array', 'max:100'],
                'items_patch' => ['nullable', 'array', 'max:100'],
            ]);

            $latest = $asset->latestExtraction;
            if ($latest === null) {
                throw MultimodalException::mediaNotFound('No extraction exists to confirm.');
            }

            $expected = isset($validated['expected_revision']) ? (int) $validated['expected_revision'] : (int) $latest->revision;
            unset($validated['expected_revision']);

            $confirmed = $this->revisionService->patchExtraction(
                principal: $principal,
                asset: $asset,
                expectedRevision: $expected,
                patch: $validated,
                markConfirmed: true,
            );

            return response()->json([
                'status' => 'ok',
                'media' => $this->presentAsset($asset->fresh()),
                'extraction' => $this->presentExtraction($confirmed),
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }

    public function handoff(Request $request, string $id): JsonResponse
    {
        try {
            $principal = $this->resolveAuthorizedPrincipal($request);
            $asset = $this->loadOwnedAsset($principal, $id);
            $extraction = $asset->latestExtraction;
            if ($extraction === null) {
                throw MultimodalException::mediaNotFound('Extract media before handing off to an ERP workflow.');
            }

            $validated = $request->validate([
                'companion_text' => ['nullable', 'string', 'max:2000'],
                'action_key' => ['nullable', 'string', 'max:80'],
                'thread_id' => ['nullable', 'uuid'],
            ]);

            $result = $this->workflowBridge->handoff(
                principal: $principal,
                asset: $asset,
                extraction: $extraction,
                companionText: $validated['companion_text'] ?? null,
                overrideActionKey: $validated['action_key'] ?? null,
                threadId: $validated['thread_id'] ?? null,
            );

            return response()->json($result);
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $principal = $this->resolveAuthorizedPrincipal($request);
            $asset = $this->loadOwnedAsset($principal, $id);

            $disk = Storage::disk($asset->private_disk);
            if ($asset->private_key !== '' && $disk->exists($asset->private_key)) {
                $disk->delete($asset->private_key);
            }
            foreach ((array) $asset->derivatives_json as $deriv) {
                $key = $deriv['key'] ?? null;
                if (is_string($key) && $key !== '' && $disk->exists($key)) {
                    $disk->delete($key);
                }
            }

            $asset->update([
                'state' => 'purged',
                'deleted_at_policy' => now('UTC'),
            ]);

            return response()->json([
                'status' => 'purged',
                'media_id' => $asset->id,
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }

    private function resolveAuthorizedPrincipal(Request $request): AiPrincipal
    {
        $user = $request->user();
        $context = $this->contextResolver->forActor(userId: $user->id);

        if (! $this->permissions->allows('ai.use', $context, $user->id)) {
            throw MultimodalException::permissionDenied();
        }

        if (! $this->featureGate->isMultimodalEnabledForCompany($context->companyId)) {
            throw MultimodalException::featureDisabled();
        }

        return $this->principalFactory->createForUser(
            userId: $user->id,
            companyId: $context->companyId,
            branchId: $context->branchId,
            financialYearId: $context->financialYearId,
        );
    }

    private function loadOwnedAsset(AiPrincipal $principal, string $id): AiMediaAsset
    {
        /** @var AiMediaAsset|null $asset */
        $asset = AiMediaAsset::query()
            ->where('id', $id)
            ->first();

        if ($asset === null) {
            throw MultimodalException::mediaNotFound();
        }

        if (! $this->policy->canAccessPrincipal($principal, $asset)) {
            throw MultimodalException::crossTenantForbidden();
        }

        if ($asset->isPurged()) {
            throw MultimodalException::mediaNotFound();
        }

        return $asset;
    }

    private function resolveIdempotencyKey(Request $request): ?string
    {
        $key = $request->header('X-Idempotency-Key') ?: $request->input('idempotency_key');
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        return substr(trim($key), 0, 120);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAsset(AiMediaAsset $asset): array
    {
        $pages = [];
        foreach ((array) $asset->derivatives_json as $idx => $deriv) {
            $pageNum = (int) ($deriv['page'] ?? ($idx + 1));
            $pages[] = [
                'page' => $pageNum,
                'mime' => $deriv['mime'] ?? $asset->canonical_mime,
                'width' => $deriv['width'] ?? null,
                'height' => $deriv['height'] ?? null,
                'preview_url' => route('ai.media.preview', ['id' => $asset->id, 'page' => $pageNum], false),
            ];
        }

        return [
            'id' => $asset->id,
            'source_kind' => $asset->source_kind,
            'file_name' => $asset->original_file_name_sanitized,
            'canonical_mime' => $asset->canonical_mime,
            'size_bytes' => (int) $asset->size_bytes,
            'sha256' => $asset->sha256,
            'page_count' => $asset->page_count,
            'duration_seconds' => $asset->duration_seconds,
            'state' => $asset->state,
            'last_error_code' => $asset->last_error_code,
            'expires_at' => $asset->expires_at?->toIso8601String(),
            'workflow_task_id' => $asset->workflow_task_id,
            'preview_url' => route('ai.media.preview', ['id' => $asset->id], false),
            'pages' => $pages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentExtraction(AiMediaExtraction $extraction): array
    {
        return [
            'id' => $extraction->id,
            'media_id' => $extraction->media_id,
            'revision' => (int) $extraction->revision,
            'model_provider' => $extraction->model_provider,
            'model_key' => $extraction->model_key,
            'prompt_version' => $extraction->prompt_version,
            'schema_version' => $extraction->extraction_schema_version,
            'confirmation_status' => $extraction->confirmation_status,
            'confirmed_at' => $extraction->confirmed_at?->toIso8601String(),
            'extracted' => $extraction->extracted_json,
            'normalized_input' => $extraction->normalized_input_json,
            'source_evidence' => $extraction->source_evidence_json,
        ];
    }

    private function handleException(Throwable $e): JsonResponse
    {
        if ($e instanceof MultimodalException) {
            return response()->json([
                'error_code' => $e->errorCode,
                'error_message' => $e->getMessage(),
                'details' => $e->details,
            ], $e->httpStatus);
        }

        if ($e instanceof HttpExceptionInterface) {
            return response()->json([
                'error_code' => $e->getStatusCode() === 429 ? 'AI_QUOTA_EXCEEDED' : 'MULTIMODAL_HTTP_ERROR',
                'error_message' => $e->getMessage() ?: 'Request could not be completed.',
            ], $e->getStatusCode());
        }

        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return response()->json([
                'error_code' => 'VALIDATION_ERROR',
                'error_message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return response()->json([
                'error_code' => 'PERMISSION_DENIED',
                'error_message' => $e->getMessage() ?: 'Not authorized.',
            ], 403);
        }

        return response()->json([
            'error_code' => 'MULTIMODAL_INTERNAL_ERROR',
            'error_message' => 'Media processing could not be completed safely.',
        ], 500);
    }
}


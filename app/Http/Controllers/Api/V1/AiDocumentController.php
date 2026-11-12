<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Ai\Documents\DocumentAccess;
use App\Ai\Documents\DocumentIntakeService;
use App\Ai\Documents\KnowledgeRetrievalService;
use App\Ai\Documents\ProcessDocument;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Imports\ProductImportPlanner;
use App\Ai\Services\AiPrincipalFactory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiDocumentController extends Controller
{
    public function workspace(Request $request, DocumentAccess $access): \Illuminate\Contracts\View\View
    {
        $principal = $this->principal($request);
        $access->context($principal);
        return view('backend.ai.documents', [
            'companyId' => $principal->companyId, 'branchId' => $principal->branchId,
            'financialYearId' => $principal->financialYearId,
            'maxKilobytes' => (int) config('ai_documents.max_kilobytes', 10240),
            'knowledgeEnabled' => (bool) config('ai_documents.qdrant.enabled'),
        ]);
    }

    public function upload(Request $request, DocumentIntakeService $documents): JsonResponse
    {
        $input = $request->validate(['file' => 'required|file', 'classification' => 'required|string']);
        return response()->json(['data' => $documents->upload($this->principal($request), $input['file'], $input['classification'])], 201);
    }

    public function show(Request $request, string $id, DocumentIntakeService $documents): JsonResponse
    {
        return response()->json(['data' => $documents->show($this->principal($request), $id)]);
    }

    public function process(Request $request, string $id, DocumentAccess $access): JsonResponse
    {
        $principal = $this->principal($request);
        $access->find($principal, $id);
        ProcessDocument::dispatch($id, $principal->actorId, $principal->companyId, $principal->branchId, $principal->financialYearId);
        return response()->json(['data' => ['id' => $id, 'status' => 'queued']], 202);
    }

    public function revoke(Request $request, string $id, DocumentIntakeService $documents): JsonResponse
    {
        $documents->revoke($this->principal($request), $id);
        return response()->json(['data' => ['id' => $id, 'status' => 'revoked']]);
    }

    public function approveKnowledge(Request $request, string $id, KnowledgeRetrievalService $knowledge): JsonResponse
    {
        $request->validate(['confirm' => 'required|accepted']);
        return response()->json(['data' => $knowledge->approveAndIndex($this->principal($request), $id)]);
    }

    public function search(Request $request, KnowledgeRetrievalService $knowledge): JsonResponse
    {
        $input = $request->validate(['question' => 'required|string|max:2000', 'limit' => 'sometimes|integer|min:1|max:10']);
        return response()->json(['data' => $knowledge->search($this->principal($request), $input['question'], $input['limit'] ?? 5)]);
    }

    public function previewImport(Request $request, ProductImportPlanner $imports): JsonResponse
    {
        $input = $request->validate(['document_id' => 'required|uuid']);
        return response()->json(['data' => $imports->preview($this->principal($request), $input['document_id'])], 201);
    }

    public function showImport(Request $request, string $id, ProductImportPlanner $imports): JsonResponse
    {
        return response()->json(['data' => $imports->show($this->principal($request), $id)]);
    }

    public function commitImport(Request $request, string $id, ProductImportPlanner $imports): JsonResponse
    {
        $input = $request->validate(['confirm' => 'required|accepted', 'accepted_rows' => 'required|array|min:1|max:1000',
            'accepted_rows.*' => 'required|integer|min:2', 'review_hash' => 'required|string|size:64']);
        return response()->json(['data' => $imports->commit($this->principal($request), $id, $input['accepted_rows'], $input['review_hash'])]);
    }

    public function importErrors(Request $request, string $id, ProductImportPlanner $imports): \Illuminate\Http\Response
    {
        return response($imports->errorCsv($this->principal($request), $id), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="import-errors.csv"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function principal(Request $request): AiPrincipal
    {
        abort_unless($request->user(), 401);
        return app(AiPrincipalFactory::class)->createForUser((int) $request->user()->id);
    }
}

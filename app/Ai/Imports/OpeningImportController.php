<?php

declare(strict_types=1);

namespace App\Ai\Imports;

use App\Ai\Services\AiPrincipalFactory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OpeningImportController extends Controller
{
    public function preview(Request $request, OpeningImportPlanner $planner): JsonResponse
    {
        abort_unless($request->user(), 401);
        $input = $request->validate([
            'document_id' => 'required|uuid',
            'action_key' => 'required|in:setup.opening_stock.create.v1,setup.opening_balance.create.v1',
            'source' => 'required|array:batch_key,source_name,business_date,mappings,expected',
        ]);
        $principal = app(AiPrincipalFactory::class)->createForUser((int) $request->user()->id);
        $result = $planner->prepareDocument($principal, $input['action_key'], $input['document_id'], $input['source']);
        return response()->json(['data' => $result], $result['status'] === 'ok' ? 201 : 422);
    }
}

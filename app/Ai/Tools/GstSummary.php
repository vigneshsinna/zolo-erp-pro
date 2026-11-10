<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GstSummary implements Tool
{
    public function __construct(
        protected AiPrincipal $principal,
        protected ?ErpToolExecutor $executor = null
    ) {}

    public function name(): string
    {
        return 'tax.gst_summary.v1';
    }

    public function description(): string
    {
        return 'Retrieve authoritative GST tax liability breakdown (CGST, SGST, IGST, Cess) and input tax credit.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Start date (YYYY-MM-DD)'),
            'to' => $schema->string()->description('End date (YYYY-MM-DD)'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $from = isset($request['from']) && is_string($request['from']) ? trim($request['from']) : null;
        $to = isset($request['to']) && is_string($request['to']) ? trim($request['to']) : null;

        $executor = $this->executor ?? app(ErpToolExecutor::class);

        $result = $executor->execute(
            runId: $this->principal->requestId,
            principal: $this->principal,
            toolKey: 'tax.gst_summary.v1',
            rawInput: array_filter([
                'from' => $from,
                'to' => $to,
            ], fn ($v) => $v !== null)
        );

        return (string) json_encode([
            'status' => $result->status,
            'tool' => $result->toolKey,
            'scope' => $result->scope,
            'as_of' => $result->asOf,
            'data' => $result->data,
            'source_refs' => $result->sourceRefs,
            'warnings' => $result->warnings,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'message' => $result->message,
        ], JSON_THROW_ON_ERROR);
    }
}

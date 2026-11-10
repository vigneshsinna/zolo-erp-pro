<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class JobWorkStatus implements Tool
{
    public function __construct(
        protected AiPrincipal $principal
    ) {}

    public function name(): string
    {
        return 'jobwork_status';
    }

    public function description(): string
    {
        return 'Query pending job work orders, external processing materials, processor details, and GST Section 143 return deadlines.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'job_worker_id' => $schema->integer()->description('Optional processor / supplier party ID to filter job work for.'),
            'status' => $schema->string()->description('Status filter: all, ok, due_soon (within 60 days of GST 143(3) deadline), overdue.'),
            'limit' => $schema->integer()->description('Maximum number of job work records to return (1 to 50, default 20).'),
        ];
    }

    public function handle(Request $request): string
    {
        $rawInput = $request->all();

        $executor = app(ErpToolExecutor::class);

        $result = $executor->execute(
            runId: $this->principal->requestId,
            principal: $this->principal,
            toolKey: 'jobwork.status.v1',
            rawInput: $rawInput
        );

        return (string) json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class OpenOrders implements Tool
{
    public function __construct(
        protected AiPrincipal $principal
    ) {}

    public function name(): string
    {
        return 'orders_open';
    }

    public function description(): string
    {
        return 'Query open commercial orders, pending customer dispatches, and outstanding vendor purchase orders.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('Order type filter: all (default), sale, purchase.'),
            'limit' => $schema->integer()->description('Maximum number of order records to return (1 to 50, default 20).'),
        ];
    }

    public function handle(Request $request): string
    {
        $rawInput = $request->all();

        $executor = app(ErpToolExecutor::class);

        $result = $executor->execute(
            runId: $this->principal->requestId,
            principal: $this->principal,
            toolKey: 'orders.open.v1',
            rawInput: $rawInput
        );

        return (string) json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    }
}

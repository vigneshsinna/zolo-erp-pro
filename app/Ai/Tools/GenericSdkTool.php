<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\AiToolRegistration;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final readonly class GenericSdkTool implements Tool
{
    public function __construct(
        private AiPrincipal $principal,
        private AiToolRegistration $registration,
        private ErpToolExecutor $executor,
    ) {}

    public function name(): string
    {
        return $this->registration->providerName;
    }

    public function description(): string
    {
        return $this->registration->handler->description();
    }

    public function schema(JsonSchema $schema): array
    {
        $definition = $this->registration->handler->schema();
        $properties = [];
        foreach ($definition['properties'] ?? [] as $name => $field) {
            $type = match ($field['type']) {
                'string' => $schema->string()->max($field['maxLength'] ?? 200),
                'integer' => $schema->integer(),
                'boolean' => $schema->boolean(),
            };
            if (isset($field['minimum'])) {
                $type->min($field['minimum']);
            }
            if (isset($field['maximum'])) {
                $type->max($field['maximum']);
            }
            if (isset($field['format'])) {
                $type->format($field['format']);
            }
            if (isset($field['enum'])) {
                $type->enum($field['enum']);
            }
            if (isset($field['default'])) {
                $type->default($field['default']);
            }
            if (isset($field['description'])) {
                $type->description($field['description']);
            }
            $properties[$name] = $type->required(in_array($name, $definition['required'] ?? [], true));
        }

        return $properties;
    }

    public function handle(Request $request): string
    {
        $result = $this->executor->execute(
            $this->principal->requestId,
            $this->principal,
            $this->registration->handler->key(),
            $request->all(),
        );
        $payload = $result->toArray();
        if ($this->registration->legacyReconciliation) {
            $payload['reconciliation'] = ['is_reconciled' => $result->reconciled];
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}

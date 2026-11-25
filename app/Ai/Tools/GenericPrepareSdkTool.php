<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\Capabilities\CapabilityDescriptor;
use App\Ai\Capabilities\CapabilityDispatcher;
use App\Ai\DTOs\AiPrincipal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Shared Laravel AI SDK adapter for any catalog-registered prepare capability.
 * Never approves or executes domain writes.
 */
final readonly class GenericPrepareSdkTool implements Tool
{
    public function __construct(
        private AiPrincipal $principal,
        private CapabilityDescriptor $descriptor,
        private CapabilityDispatcher $dispatcher,
    ) {}

    public function name(): string
    {
        return $this->descriptor->aliasFor('sdk') ?? $this->descriptor->capabilityKey;
    }

    public function description(): string
    {
        return $this->descriptor->description;
    }

    public function schema(JsonSchema $schema): array
    {
        $properties = [];
        $schemaDef = $this->descriptor->draftInputSchema;
        $rawProps = (array) ($schemaDef['properties'] ?? []);
        if (isset($rawProps['inputs']['properties'])) {
            $rawProps = (array) $rawProps['inputs']['properties'];
        }

        foreach ($rawProps as $name => $field) {
            $properties[$name] = $this->buildField($schema, (array) $field);
        }

        return $properties;
    }

    public function handle(Request $request): string
    {
        try {
            $result = $this->dispatcher->dispatch(
                principal: $this->principal,
                channel: 'sdk',
                capabilityKey: $this->descriptor->capabilityKey,
                operation: 'prepare',
                arguments: $request->all(),
            );

            return json_encode($result, JSON_THROW_ON_ERROR);
        } catch (ValidationException $e) {
            return json_encode([
                'status' => 'needs_input',
                'errors' => $e->errors(),
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return json_encode([
                'status' => 'error',
                'error_code' => 'AI_ACTION_PREPARATION_FAILED',
                'message' => 'Action inputs could not be prepared. Review required fields and permissions.',
            ], JSON_THROW_ON_ERROR);
        }
    }

    /**
     * @param array<string, mixed> $field
     */
    private function buildField(JsonSchema $schema, array $field): mixed
    {
        $typeSpec = $field['type'] ?? 'string';
        $primaryType = is_array($typeSpec) ? (in_array('number', $typeSpec, true) ? 'number' : ($typeSpec[0] ?? 'string')) : $typeSpec;

        if ($primaryType === 'array') {
            $itemProps = [];
            foreach ((array) ($field['items']['properties'] ?? []) as $childName => $childField) {
                $itemProps[$childName] = $this->buildField($schema, (array) $childField);
            }
            $type = $schema->array()->items($schema->object($itemProps));
        } else {
            $type = match ($primaryType) {
                'integer' => $schema->integer(),
                'number' => $schema->number(),
                'boolean' => $schema->boolean(),
                default => $schema->string(),
            };
            if (isset($field['enum']) && is_array($field['enum'])) {
                $type->enum($field['enum']);
            }
        }

        if (!empty($field['description'])) {
            $type->description((string) $field['description']);
        }

        return $type;
    }
}

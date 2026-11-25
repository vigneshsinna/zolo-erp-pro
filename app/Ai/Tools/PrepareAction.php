<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\Capabilities\CapabilityDescriptor;
use App\Ai\Capabilities\CapabilityDispatcher;
use App\Ai\Capabilities\CapabilityExposurePolicy;
use App\Ai\Capabilities\CapabilitySchemaCompiler;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Requirements\SupplierSetupRequirementsProvider;
use App\Ai\Services\ActionPreparationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class PrepareAction implements Tool
{
    public function __construct(private readonly AiPrincipal $principal) {}

    public function name(): string
    {
        return 'prepare_reviewed_action';
    }

    public function description(): string
    {
        $keys = $this->keys();

        return 'Prepares a draft proposal for an ERP action requiring human review and approval. Never approves, posts, sends or executes. Pass action_key (' . implode(', ', $keys) . ') and inputs_json as a JSON object of business fields (e.g. customer_query, supplier_query, warehouse_query, product_query, quantities, amounts). Never include actor/company/branch/FY authority.';
    }

    public function schema(JsonSchema $schema): array
    {
        $keys = $this->keys();

        return [
            'action_key' => $schema->string()->enum($keys)->required(),
            'inputs_json' => $schema->string()->max(30000)->description('JSON object of business inputs for the action.')->required(),
            'task_id' => $schema->string()->description('Existing owned task ID, only when resuming the same action.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        try {
            $actionKey = (string) ($args['action_key'] ?? '');
            if (!in_array($actionKey, $this->keys(), true)) {
                throw new \InvalidArgumentException('Action is not available through this tool.');
            }

            $compiler = app(CapabilitySchemaCompiler::class);
            $inputs = $compiler->decodeLegacyInputsJson((string) ($args['inputs_json'] ?? ''));

            if (config('ai_capabilities.shared_discovery_enabled', false)) {
                if (!empty($args['task_id'])) {
                    $inputs['task_id'] = (string) $args['task_id'];
                }
                $result = app(CapabilityDispatcher::class)->dispatch(
                    principal: $this->principal,
                    channel: 'sdk',
                    capabilityKey: $actionKey,
                    operation: 'prepare',
                    arguments: $inputs,
                );

                return json_encode($result, JSON_THROW_ON_ERROR);
            }

            return json_encode(
                app(ActionPreparationService::class)->prepare($this->principal, $actionKey, $inputs, $args['task_id'] ?? null),
                JSON_THROW_ON_ERROR
            );
        } catch (\Illuminate\Validation\ValidationException $error) {
            return json_encode(['status' => 'needs_input', 'errors' => $error->errors()], JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return json_encode([
                'status' => 'error',
                'error_code' => 'AI_ACTION_PREPARATION_FAILED',
                'message' => 'Action inputs could not be accepted. Review the required fields and your current access.',
            ], JSON_THROW_ON_ERROR);
        }
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        if (config('ai_capabilities.shared_discovery_enabled', false)) {
            $descriptors = app(CapabilityExposurePolicy::class)->discover(
                principal: $this->principal,
                channel: 'sdk',
                operation: 'prepare',
                limit: 100,
            );

            return array_values(array_filter(
                array_map(static fn (CapabilityDescriptor $d): string => $d->capabilityKey, $descriptors),
                static fn (string $key): bool => $key !== SupplierSetupRequirementsProvider::KEY
            ));
        }

        return array_values(array_filter(
            array_keys(app(ActionRequirementsRegistry::class)->all()),
            fn (string $key): bool => $key !== SupplierSetupRequirementsProvider::KEY && $this->principal->hasTool($key)
        ));
    }
}

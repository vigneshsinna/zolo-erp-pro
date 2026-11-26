<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Registries\ActionRequirementsRegistry;
use App\Ai\Services\ActionPreparationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class PrepareAction implements Tool
{
    public function __construct(private readonly AiPrincipal $principal) {}
    public function name(): string { return 'prepare_reviewed_action'; }
    public function description(): string
    {
        $keys = array_values(array_filter(array_keys(app(ActionRequirementsRegistry::class)->all()), fn ($key) => $this->principal->hasTool($key)));
        return 'Prepares a draft proposal for an ERP action requiring human review and approval. Never approves, posts, sends or executes. Pass action_key (' . implode(', ', $keys) . ') and inputs_json as a JSON object of business fields (e.g. customer_query, supplier_query, warehouse_query, product_query, quantities, amounts). Never include actor/company/branch/FY authority.';
    }
    public function schema(JsonSchema $schema): array
    {
        $keys = array_values(array_filter(array_keys(app(ActionRequirementsRegistry::class)->all()), fn ($key) => $this->principal->hasTool($key)));
        return ['action_key' => $schema->string()->enum($keys)->required(),
            'inputs_json' => $schema->string()->max(30000)->description('JSON object of business inputs for the action.')->required(),
            'task_id' => $schema->string()->description('Existing owned task ID, only when resuming the same action.')];
    }
    public function handle(Request $request): string
    {
        $args = $request->all();
        try {
            $inputs = json_decode((string) ($args['inputs_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($inputs) || array_is_list($inputs) || strlen($args['inputs_json']) > 30000) throw new \InvalidArgumentException('Inputs must be a JSON object.');
            foreach (['company_id', 'branch_id', 'financial_year_id', 'actor_id', 'user_id'] as $field) {
                if (array_key_exists($field, $inputs)) throw new \InvalidArgumentException('Authority fields are not accepted.');
            }
            return json_encode(app(ActionPreparationService::class)->prepare($this->principal, (string) $args['action_key'], $inputs, $args['task_id'] ?? null), JSON_THROW_ON_ERROR);
        } catch (\Illuminate\Validation\ValidationException $error) {
            return json_encode(['status' => 'needs_input', 'errors' => $error->errors()], JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            return json_encode(['status' => 'error', 'error_code' => 'AI_ACTION_PREPARATION_FAILED', 'message' => 'Action inputs could not be accepted. Review the required fields and your current access.'], JSON_THROW_ON_ERROR);
        }
    }
}

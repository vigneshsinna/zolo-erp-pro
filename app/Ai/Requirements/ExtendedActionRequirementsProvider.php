<?php

declare(strict_types=1);

namespace App\Ai\Requirements;

use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;
use Illuminate\Support\Facades\Validator;

/** Requirements are derived from the same schema used at the approval boundary. */
final readonly class ExtendedActionRequirementsProvider implements ActionRequirementsProviderInterface
{
    public function __construct(private ActionDefinition $definition) {}

    public function actionKey(): string
    {
        return $this->definition->key;
    }

    public function requirements(WorkflowTaskContext $context, array $currentSlots = []): array
    {
        $requirements = [];
        foreach (app($this->definition->inputSchemaClass)->rules() as $field => $rules) {
            if (str_contains($field, '.')) {
                continue;
            }
            $rules = is_array($rules) ? implode('|', $rules) : $rules;
            $required = str_contains($rules, 'required');
            $isArray = str_contains($rules, 'array');
            $requirements[] = new ActionFieldRequirement(
                fieldPath: $field,
                label: ucwords(str_replace('_', ' ', $field)),
                dataType: $isArray ? 'array' : (str_contains($rules, 'integer') ? 'integer' : 'string'),
                inputControl: $isArray ? 'json' : (str_contains($rules, 'date_format') ? 'date' : 'text'),
                requiredWhen: static fn (): bool => $required,
                resolutionPolicy: 'none',
            );
        }
        return $requirements;
    }

    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array
    {
        $validator = Validator::make($resolvedInputs, app($this->definition->inputSchemaClass)->rules());
        return $validator->fails()
            ? array_map(static fn (array $messages) => $messages[0], $validator->errors()->toArray())
            : [];
    }
}

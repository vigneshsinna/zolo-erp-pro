<?php

declare(strict_types=1);

namespace App\Ai\Requirements;

use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Schemas\CreateUnitInputSchema;
use Illuminate\Support\Facades\Validator;

final class UnitSetupRequirementsProvider implements ActionRequirementsProviderInterface
{
    public function actionKey(): string
    {
        return 'setup.unit.create.v1';
    }

    /** @return list<ActionFieldRequirement> */
    public function requirements(WorkflowTaskContext $context, array $currentSlots = []): array
    {
        return [
            new ActionFieldRequirement('unit_code', 'Unit Code', 'string', inputControl: 'text'),
            new ActionFieldRequirement('unit_name', 'Unit Name', 'string', inputControl: 'text'),
            new ActionFieldRequirement('base_unit', 'Base Unit ID', 'integer', inputControl: 'number', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('operator', 'Conversion Operator (* or /)', 'string', inputControl: 'select', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('operation_value', 'Conversion Factor', 'decimal_string', inputControl: 'number', requiredWhen: fn (): bool => false),
        ];
    }

    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array
    {
        $validator = Validator::make($resolvedInputs, (new CreateUnitInputSchema())->rules());
        return $validator->fails()
            ? array_map(static fn (array $messages) => $messages[0] ?? 'Invalid value.', $validator->errors()->toArray())
            : [];
    }
}

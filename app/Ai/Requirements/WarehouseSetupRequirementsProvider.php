<?php

declare(strict_types=1);

namespace App\Ai\Requirements;

use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Schemas\CreateWarehouseInputSchema;
use Illuminate\Support\Facades\Validator;

final class WarehouseSetupRequirementsProvider implements ActionRequirementsProviderInterface
{
    public function actionKey(): string
    {
        return 'setup.warehouse.create.v1';
    }

    /** @return list<ActionFieldRequirement> */
    public function requirements(WorkflowTaskContext $context, array $currentSlots = []): array
    {
        return [
            new ActionFieldRequirement('name', 'Warehouse / Store Name', 'string', inputControl: 'text'),
            new ActionFieldRequirement('address', 'Warehouse Address', 'string', inputControl: 'text'),
            new ActionFieldRequirement('phone', 'Contact Phone', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('email', 'Contact Email', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
        ];
    }

    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array
    {
        $validator = Validator::make($resolvedInputs, (new CreateWarehouseInputSchema())->rules());
        return $validator->fails()
            ? array_map(static fn (array $messages) => $messages[0] ?? 'Invalid value.', $validator->errors()->toArray())
            : [];
    }
}

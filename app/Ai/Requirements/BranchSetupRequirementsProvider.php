<?php

declare(strict_types=1);

namespace App\Ai\Requirements;

use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Schemas\CreateBranchInputSchema;
use Illuminate\Support\Facades\Validator;

final class BranchSetupRequirementsProvider implements ActionRequirementsProviderInterface
{
    public function actionKey(): string
    {
        return 'setup.branch.create.v1';
    }

    /** @return list<ActionFieldRequirement> */
    public function requirements(WorkflowTaskContext $context, array $currentSlots = []): array
    {
        return [
            new ActionFieldRequirement('code', 'Branch Code', 'string', inputControl: 'text'),
            new ActionFieldRequirement('name', 'Branch Name', 'string', inputControl: 'text'),
        ];
    }

    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array
    {
        $validator = Validator::make($resolvedInputs, (new CreateBranchInputSchema())->rules());
        return $validator->fails()
            ? array_map(static fn (array $messages) => $messages[0] ?? 'Invalid value.', $validator->errors()->toArray())
            : [];
    }
}

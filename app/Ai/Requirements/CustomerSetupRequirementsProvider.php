<?php

declare(strict_types=1);

namespace App\Ai\Requirements;

use App\Ai\Adapters\CustomerSetupPreviewAdapter;
use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Schemas\CreateCustomerInputSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CustomerSetupRequirementsProvider implements ActionRequirementsProviderInterface
{
    public const KEY = 'setup.customer.create.v1';

    public function actionKey(): string
    {
        return self::KEY;
    }

    public function requirements(WorkflowTaskContext $context, array $currentSlots = []): array
    {
        $requirements = [];
        foreach (array_keys((new CreateCustomerInputSchema())->rules()) as $field) {
            $label = $field === 'tax_no' ? 'GSTIN' : ucfirst(str_replace('_', ' ', $field));
            $requirements[] = new ActionFieldRequirement(
                fieldPath: $field,
                label: $label,
                requiredWhen: fn (): bool => $field === 'name',
            );
        }
        return $requirements;
    }

    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array
    {
        try {
            $slots = Validator::make($resolvedInputs, (new CreateCustomerInputSchema())->rules())->validate();
            $definition = app(ActionCapabilityRegistry::class)->get(self::KEY);
            $preview = app(CustomerSetupPreviewAdapter::class)->preview($definition, $slots, $context->context);
            return $preview->preview['is_existing']
                ? ['name' => 'A matching customer already exists. Enter a different name or correct the GSTIN.']
                : [];
        } catch (ValidationException $error) {
            return array_map(fn (array $messages): string => $messages[0], $error->errors());
        }
    }
}

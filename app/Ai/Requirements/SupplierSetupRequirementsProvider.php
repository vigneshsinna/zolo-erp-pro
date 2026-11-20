<?php

declare(strict_types=1);

namespace App\Ai\Requirements;

use App\Ai\Adapters\SupplierSetupPreviewAdapter;
use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Schemas\CreateSupplierInputSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SupplierSetupRequirementsProvider implements ActionRequirementsProviderInterface
{
    public const KEY = 'setup.supplier.create.v1';

    public function actionKey(): string
    {
        return self::KEY;
    }

    public function requirements(WorkflowTaskContext $context, array $currentSlots = []): array
    {
        $requirements = [];
        foreach (array_keys((new CreateSupplierInputSchema())->rules()) as $field) {
            $label = $field === 'vat_number' ? 'GSTIN' : ucfirst(str_replace('_', ' ', $field));
            $requirements[] = new ActionFieldRequirement($field, $label, requiredWhen: fn (): bool => $field === 'name');
        }
        return $requirements;
    }

    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array
    {
        try {
            $slots = Validator::make($resolvedInputs, (new CreateSupplierInputSchema())->rules())->validate();
            $definition = app(ActionCapabilityRegistry::class)->get(self::KEY);
            $preview = app(SupplierSetupPreviewAdapter::class)->preview($definition, $slots, $context->context);
            return $preview->preview['is_existing'] ? ['name' => 'A matching supplier already exists. Enter a different name or correct the GSTIN.'] : [];
        } catch (ValidationException $error) {
            return array_map(fn (array $messages): string => $messages[0], $error->errors());
        }
    }
}

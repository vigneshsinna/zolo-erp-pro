<?php

declare(strict_types=1);

namespace App\Ai\Requirements;

use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Knowledge\IndustryContextResolver;
use App\Ai\Schemas\CreateProductInputSchema;
use Illuminate\Support\Facades\Validator;

final class ProductSetupRequirementsProvider implements ActionRequirementsProviderInterface
{
    public function actionKey(): string
    {
        return 'setup.product.create.v1';
    }

    /** @return list<ActionFieldRequirement> */
    public function requirements(WorkflowTaskContext $context, array $currentSlots = []): array
    {
        return [
            new ActionFieldRequirement('name', 'Product Name', 'string', inputControl: 'text'),
            new ActionFieldRequirement('code', 'SKU / Product Code', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('type', 'Product Type', 'string', inputControl: 'select', requiredWhen: fn (): bool => false, defaultPolicy: fn (): string => 'standard'),
            new ActionFieldRequirement('unit_id', 'Base Unit', 'entity_reference', entityType: 'unit', inputControl: 'entity_select', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('purchase_unit_id', 'Purchase Unit', 'entity_reference', entityType: 'unit', inputControl: 'entity_select', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('category_id', 'Category ID', 'integer', inputControl: 'number', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('cost', 'Purchase Cost', 'decimal_string', inputControl: 'number', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('price', 'Selling Price', 'decimal_string', inputControl: 'number', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('tax_category_id', 'GST Tax Category ID', 'integer', inputControl: 'number', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('hsn_code', 'HSN / SAC Code', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('base', 'Base Curve', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('addition', 'Addition Power', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('lr', 'Eye (R/L/Pair)', 'string', inputControl: 'select', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('dia', 'Diameter (mm)', 'string', inputControl: 'text', requiredWhen: fn (): bool => false),
            new ActionFieldRequirement('product_type_id', 'Optical Product Type ID', 'integer', inputControl: 'number', requiredWhen: fn (): bool => false),
        ];
    }

    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array
    {
        $validator = Validator::make($resolvedInputs, (new CreateProductInputSchema())->rules());
        $errors = $validator->fails()
            ? array_map(static fn (array $messages) => $messages[0] ?? 'Invalid value.', $validator->errors()->toArray())
            : [];

        if ($context->context) {
            foreach (['base', 'addition', 'lr', 'dia', 'product_type_id'] as $optAttr) {
                if (!empty($resolvedInputs[$optAttr]) && !app(IndustryContextResolver::class)->isOptical($context->context)) {
                    $errors[$optAttr] = 'Optical product attributes require an active Opticals company profile.';
                }
            }
        }

        return $errors;
    }
}

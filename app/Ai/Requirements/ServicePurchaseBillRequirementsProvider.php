<?php

declare(strict_types=1);

namespace App\Ai\Requirements;

use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Models\Product;
use App\Ai\Schemas\CreateServicePurchaseBillInputSchema;
use Illuminate\Support\Facades\Validator;

/** Same purchase bill fields, but with explicit service catalog semantics. */
final class ServicePurchaseBillRequirementsProvider implements ActionRequirementsProviderInterface
{
    public function actionKey(): string { return 'purchase_bill.service.create.v1'; }

    /** @return list<ActionFieldRequirement> */
    public function requirements(WorkflowTaskContext $context, array $currentSlots = []): array
    {
        return (new PurchaseBillRequirementsProvider)->requirements($context, $currentSlots);
    }

    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array
    {
        $validation = Validator::make($resolvedInputs, (new CreateServicePurchaseBillInputSchema)->rules());
        if ($validation->fails()) {
            return array_map(static fn (array $messages): string => $messages[0], $validation->errors()->toArray());
        }
        if (!empty($resolvedInputs['purchase_order_id']) || !empty($resolvedInputs['grn_id'])) {
            return ['links' => 'Service bill cannot consume a goods PO or GRN.'];
        }
        foreach ($resolvedInputs['items'] as $index => $line) {
            $product = Product::where('company_id', $context->companyId())->find($line['product_id']);
            if (!$product || $product->type !== 'service' || !$product->is_active) {
                return ["items.{$index}.product_id" => 'Choose an active service catalog item.'];
            }
        }
        return (new PurchaseBillRequirementsProvider)->validate($context, $resolvedInputs);
    }
}

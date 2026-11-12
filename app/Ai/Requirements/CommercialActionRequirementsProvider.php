<?php

declare(strict_types=1);

namespace App\Ai\Requirements;

use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Schemas\CommercialDocumentInputSchema;
use App\Ai\Schemas\CustomerCollectionInputSchema;
use Illuminate\Support\Facades\Validator;

final class CommercialActionRequirementsProvider implements ActionRequirementsProviderInterface
{
    public function __construct(private readonly string $key) {}

    public function actionKey(): string { return $this->key; }

    public function requirements(WorkflowTaskContext $context, array $currentSlots = []): array
    {
        if (!str_starts_with($this->key, 'payment_')) {
            $requirements = (new SalesOrderRequirementsProvider())->requirements($context, $currentSlots);
            foreach (['biller_id', 'valid_until', 'sales_order_id', 'delivery_challan_id', 'purpose', 'due_date'] as $field) {
                $requirements[] = new ActionFieldRequirement(fieldPath: $field, label: ucwords(str_replace('_', ' ', $field)),
                    inputControl: str_contains($field, 'date') || $field === 'valid_until' ? 'date' : 'text',
                    requiredWhen: fn (): bool => $field === 'purpose' && $this->key === 'delivery_challan.create.v1');
            }
            return $requirements;
        }
        $requirements = [];
        foreach (['customer_id', 'open_item_id', 'date', 'amount', 'settlement_account_id', 'note'] as $field) {
            $requirements[] = new ActionFieldRequirement(fieldPath: $field, label: ucwords(str_replace('_', ' ', $field)),
                entityType: $field === 'customer_id' ? 'customer' : null,
                inputControl: $field === 'date' ? 'date' : 'text',
                requiredWhen: fn (): bool => in_array($field, ['customer_id', 'open_item_id', 'date'], true)
                    || ($this->key === 'payment_receipt.create.v1' && in_array($field, ['amount', 'settlement_account_id'], true)));
        }
        return $requirements;
    }

    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array
    {
        $rules = str_starts_with($this->key, 'payment_') ? (new CustomerCollectionInputSchema())->rules() : (new CommercialDocumentInputSchema())->rules();
        return array_map(fn ($messages) => $messages[0], Validator::make($resolvedInputs, $rules)->errors()->toArray());
    }
}

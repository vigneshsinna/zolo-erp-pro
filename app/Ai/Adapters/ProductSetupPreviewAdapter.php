<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProductSetupPreviewAdapter implements ActionPreviewAdapterInterface
{
    public function preview(
        ActionDefinition $definition,
        array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        if (!($context instanceof CompanyContext)) {
            throw new \InvalidArgumentException('Mode B company context required for product setup.');
        }

        $name = trim((string) ($slots['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Product name is required.']);
        }

        $code = isset($slots['code']) && trim((string) $slots['code']) !== ''
            ? strtoupper(trim((string) $slots['code']))
            : $this->generateSimulatedCode($name, $context->companyId);

        $existing = Product::where('company_id', $context->companyId)->where('code', $code)->first();
        if ($existing && $existing->name !== $name) {
            throw ValidationException::withMessages(['code' => 'Product code already exists with different details.']);
        }

        $unitId = null;
        if (!empty($slots['unit_id'])) {
            $unitId = (int) $slots['unit_id'];
            if (!Unit::where('company_id', $context->companyId)->where('id', $unitId)->exists()) {
                throw ValidationException::withMessages(['unit_id' => 'The selected unit does not belong to this company.']);
            }
        } else {
            $unitId = Unit::where('company_id', $context->companyId)->where('is_active', true)->value('id');
        }

        if (!$unitId) {
            throw ValidationException::withMessages(['unit_id' => 'No active units configured for this company.']);
        }

        $categoryId = null;
        if (!empty($slots['category_id'])) {
            $categoryId = (int) $slots['category_id'];
            if (!Category::where('company_id', $context->companyId)->where('id', $categoryId)->exists()) {
                throw ValidationException::withMessages(['category_id' => 'The selected category does not belong to this company.']);
            }
        } else {
            $categoryId = Category::where('company_id', $context->companyId)->value('id');
        }

        $cost = (float) ($slots['cost'] ?? $slots['cost_price'] ?? 0);
        $price = (float) ($slots['price'] ?? 0);

        $taxCategoryId = null;
        if (!empty($slots['tax_category_id'])) {
            $taxCategoryId = (int) $slots['tax_category_id'];
            if (!DB::table('tax_categories')->where('company_id', $context->companyId)->where('id', $taxCategoryId)->exists()) {
                throw ValidationException::withMessages(['tax_category_id' => 'Select a GST rate of this company.']);
            }
        }

        $hsnCode = !empty($slots['hsn_code']) ? (string) $slots['hsn_code'] : null;

        $payload = [
            'name' => $name,
            'code' => $code,
            'type' => (string) ($slots['type'] ?? 'standard'),
            'barcode_symbology' => (string) ($slots['barcode_symbology'] ?? 'code128'),
            'unit_id' => $unitId,
            'purchase_unit_id' => !empty($slots['purchase_unit_id']) ? (int) $slots['purchase_unit_id'] : $unitId,
            'sale_unit_id' => !empty($slots['sale_unit_id']) ? (int) $slots['sale_unit_id'] : $unitId,
            'category_id' => $categoryId,
            'cost' => sprintf('%.2f', $cost),
            'price' => sprintf('%.2f', $price),
            'tax_category_id' => $taxCategoryId,
            'hsn_code' => $hsnCode,
        ];

        $previewData = [
            'entity' => 'Product Master',
            'name' => $name,
            'code' => $code,
            'cost' => sprintf('%.2f', $cost),
            'price' => sprintf('%.2f', $price),
            'type' => $payload['type'],
            'hsn_code' => $hsnCode,
            'is_existing' => $existing !== null,
        ];

        $sourceStateHash = self::computeSourceStateHash(
            $context,
            $code,
            $unitId,
            $categoryId,
            $taxCategoryId,
        );

        return new ActionPreviewResult(
            payload: $payload,
            preview: $previewData,
            sourceStateHash: $sourceStateHash,
        );
    }

    public static function computeSourceStateHash(
        CompanyContext $context,
        string $code,
        ?int $unitId,
        ?int $categoryId,
        ?int $taxCategoryId,
    ): string {
        $existing = Product::where('company_id', $context->companyId)->where('code', $code)->first();
        $unit = $unitId ? DB::table('units')->where('company_id', $context->companyId)->where('id', $unitId)->select(['id', 'updated_at'])->first() : null;
        $category = $categoryId ? DB::table('categories')->where('company_id', $context->companyId)->where('id', $categoryId)->select(['id', 'updated_at'])->first() : null;
        $taxCat = $taxCategoryId ? DB::table('tax_categories')->where('company_id', $context->companyId)->where('id', $taxCategoryId)->select(['id', 'updated_at'])->first() : null;

        $state = [
            'company_id' => $context->companyId,
            'code' => $code,
            'existing_id' => $existing?->id,
            'unit' => (array) $unit,
            'category' => (array) $category,
            'tax_category' => (array) $taxCat,
        ];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function generateSimulatedCode(string $name, int $companyId): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($name)));
        if (strlen($base) < 3) {
            $base = 'PRD';
        }
        $candidate = substr($base, 0, 8);

        if (!Product::where('company_id', $companyId)->where('code', $candidate)->exists()) {
            return $candidate;
        }

        return substr($candidate, 0, 5) . rand(100, 999);
    }
}

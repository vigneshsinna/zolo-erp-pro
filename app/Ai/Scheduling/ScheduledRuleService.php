<?php

declare(strict_types=1);

namespace App\Ai\Scheduling;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Explicit opt-in and server-scoped rule creation. Rules can only read or prepare unapproved drafts. */
final class ScheduledRuleService
{
    public function create(AiPrincipal $principal, array $input): array
    {
        $principal = $this->authorize($principal);
        $rules = [
            'name' => ['required', 'string', 'max:120'], 'opt_in' => ['required', 'accepted'],
            'warehouse_id' => ['required', 'integer', 'min:1'], 'supplier_id' => ['nullable', 'integer', 'min:1'],
            'prepare_purchase_order' => ['required', 'boolean'], 'interval_minutes' => ['required', 'integer', 'min:60', 'max:10080'],
            'max_runs_per_day' => ['required', 'integer', 'min:1', 'max:24'],
            'expires_at' => ['required', 'date', 'after:now', 'before_or_equal:'.now('UTC')->addDays(90)->toIso8601String()],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['array:product_id,minimum_qty,order_qty'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.minimum_qty' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'items.*.order_qty' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:999999999'],
        ];
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['rule' => 'Unknown or prohibited rule fields.']);
        }
        $values = Validator::make($input, $rules)->validate();
        if ($values['prepare_purchase_order'] && !$principal->hasTool('purchase_order.create.v1')) {
            abort(403, 'Purchase order preparation permission is required.');
        }
        $warehouse = Warehouse::where('company_id', $principal->companyId)->where('branch_id', $principal->branchId)
            ->where('is_active', true)->find($values['warehouse_id']);
        if (!$warehouse) throw ValidationException::withMessages(['warehouse_id' => 'Select an active warehouse in the authorized branch.']);
        $products = Product::where('company_id', $principal->companyId)->where('is_active', true)
            ->whereNotIn('type', ['service', 'digital'])->whereIn('id', array_column($values['items'], 'product_id'))->get();
        if ($products->count() !== count($values['items'])) {
            throw ValidationException::withMessages(['items' => 'All products must be active stock products in this company.']);
        }
        if ($values['prepare_purchase_order'] && (empty($values['supplier_id'])
            || !Supplier::where('company_id', $principal->companyId)->where('is_active', true)->whereKey($values['supplier_id'])->exists())) {
            throw ValidationException::withMessages(['supplier_id' => 'Select the authorized supplier explicitly before enabling purchase preparation.']);
        }
        return DB::transaction(function () use ($principal, $values): array {
            DB::table('companies')->where('id', $principal->companyId)->lockForUpdate()->first();
            $count = DB::table('ai_scheduled_rules')->where('company_id', $principal->companyId)->where('actor_id', $principal->actorId)
                ->where('is_active', true)->where('expires_at', '>', now('UTC'))->count();
            if ($count >= config('ai_infrastructure.scheduling.max_rules_per_actor', 10)) {
                throw ValidationException::withMessages(['rule' => 'Active scheduled rule limit reached.']);
            }
            $id = (string) Str::uuid();
            DB::table('ai_scheduled_rules')->insert([
                'id' => $id, 'company_id' => $principal->companyId, 'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId, 'actor_id' => $principal->actorId,
                'name' => $values['name'], 'kind' => 'low_stock', 'revision' => 1, 'is_active' => true,
                'prepare_purchase_order' => $values['prepare_purchase_order'],
                'inputs_json' => json_encode(['warehouse_id' => $values['warehouse_id'], 'supplier_id' => $values['supplier_id'] ?? null, 'items' => $values['items']], JSON_THROW_ON_ERROR),
                'interval_minutes' => $values['interval_minutes'], 'max_runs_per_day' => $values['max_runs_per_day'],
                'next_run_at' => now('UTC'), 'expires_at' => \Carbon\Carbon::parse($values['expires_at'])->utc(),
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            return $this->get($principal, $id);
        });
    }

    public function list(AiPrincipal $principal): array
    {
        $principal = $this->authorize($principal);
        return DB::table('ai_scheduled_rules')->where($this->scope($principal))->latest('created_at')->limit(50)->get()->map(fn ($row) => (array) $row)->all();
    }

    public function get(AiPrincipal $principal, string $id): array
    {
        $principal = $this->authorize($principal);
        $row = DB::table('ai_scheduled_rules')->where($this->scope($principal))->where('id', $id)->first();
        abort_unless($row, 404);
        return (array) $row;
    }

    public function runs(AiPrincipal $principal, string $id): array
    {
        $this->get($principal, $id);
        return DB::table('ai_schedule_runs')->where('company_id', $principal->companyId)->where('actor_id', $principal->actorId)
            ->where('rule_id', $id)->latest('scheduled_for')->limit(50)->get()->map(fn ($row) => (array) $row)->all();
    }

    public function cancel(AiPrincipal $principal, string $id, int $revision): void
    {
        $this->get($principal, $id);
        $updated = DB::table('ai_scheduled_rules')->where($this->scope($principal))->where('id', $id)->where('revision', $revision)
            ->where('is_active', true)->update(['is_active' => false, 'revision' => $revision + 1, 'cancelled_at' => now('UTC'), 'updated_at' => now('UTC')]);
        if ($updated !== 1) throw ValidationException::withMessages(['revision' => 'Rule changed or is already cancelled.']);
    }

    private function authorize(AiPrincipal $principal): AiPrincipal
    {
        abort_unless(config('ai_infrastructure.scheduling.enabled', false), 403);
        $fresh = app(AiPrincipalFactory::class)->createForUser($principal->actorId, $principal->companyId, $principal->branchId, $principal->financialYearId);
        abort_unless($fresh->isActive && $fresh->hasTool('inventory.stock_summary.v1')
            && app(AiFeatureGate::class)->isEnabledForCompany($fresh->companyId), 403);
        return $fresh;
    }

    private function scope(AiPrincipal $principal): array
    {
        return ['company_id' => $principal->companyId, 'branch_id' => $principal->branchId,
            'financial_year_id' => $principal->financialYearId, 'actor_id' => $principal->actorId];
    }
}

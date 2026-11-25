<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Ai\Scheduling\ScheduledRuleService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session-authenticated UI adapter to the existing deterministic scheduler.
 * No separate scheduler or transaction-execution path is introduced.
 */
final class AiAutomationBuilderController extends Controller
{
    public function index(
        Request $request, CompanyContextResolver $resolver, CommercialPermission $permissions,
        AiFeatureGate $gate, AiPrincipalFactory $factory, ScheduledRuleService $rules,
    ): View {
        [$principal, $enabled] = $this->authorized($request, $resolver, $permissions, $gate, $factory);

        $discoverableCapabilities = [];
        $canPreparePo = $principal->hasTool('purchase_order.create.v1');
        if (config('ai_capabilities.shared_discovery_enabled', false)) {
            $eligible = app(\App\Ai\Capabilities\CapabilityExposurePolicy::class)->discover($principal, 'automation');
            $discoverableCapabilities = array_map(
                static fn (\App\Ai\Capabilities\CapabilityDescriptor $d): array => $d->toPublicArray('automation'),
                $eligible
            );
            $canPreparePo = collect($eligible)->contains(
                static fn (\App\Ai\Capabilities\CapabilityDescriptor $d): bool => $d->capabilityKey === 'purchase_order.create.v1' && $d->operation === 'prepare'
            );
        }

        return view('backend.ai.automation_builder', [
            'enabled' => $enabled,
            'rules' => $enabled ? $rules->list($principal) : [],
            'canPreparePo' => $canPreparePo,
            'discoverableCapabilities' => $discoverableCapabilities,
            'maxActiveRules' => (int) config('ai_infrastructure.scheduling.max_rules_per_actor', 10),
        ]);
    }

    public function catalog(
        Request $request, CompanyContextResolver $resolver, CommercialPermission $permissions,
        AiFeatureGate $gate, AiPrincipalFactory $factory,
    ): JsonResponse {
        [$principal, $enabled] = $this->authorized($request, $resolver, $permissions, $gate, $factory);
        abort_unless($enabled, 403, 'Scheduling is disabled.');
        $values = $request->validate([
            'type' => ['required', 'in:product,warehouse,supplier'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $type = $values['type'];
        $term = trim((string) ($values['q'] ?? ''));
        $company = $principal->companyId;

        if ($type === 'product') {
            $query = Product::query()->where('company_id', $company)->where('is_active', true)
                ->whereNotIn('type', ['service', 'digital']);
        } elseif ($type === 'warehouse') {
            $query = Warehouse::query()->where('company_id', $company)->where('branch_id', $principal->branchId)
                ->where('is_active', true);
        } else {
            $query = Supplier::query()->where('company_id', $company)->where('is_active', true);
        }
        if ($term !== '') {
            // Query parameters are bound; this is discovery only, never a trusted ID.
            $query->where(function ($search) use ($term, $type): void {
                $search->where('name', 'like', '%'.$term.'%');
                if ($type === 'product') $search->orWhere('code', 'like', '%'.$term.'%');
            });
        }
        $fields = $type === 'product' ? ['id', 'name', 'code'] : ['id', 'name'];
        $items = $query->orderBy('name')->limit(25)->get($fields)
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'label' => (string) $row->name.($type === 'product' && filled($row->code ?? null) ? ' · '.(string) $row->code : ''),
            ])->all();
        return response()->json(['items' => $items]);
    }

    public function store(
        Request $request, CompanyContextResolver $resolver, CommercialPermission $permissions,
        AiFeatureGate $gate, AiPrincipalFactory $factory, ScheduledRuleService $rules,
    ): RedirectResponse {
        [$principal, $enabled] = $this->authorized($request, $resolver, $permissions, $gate, $factory);
        abort_unless($enabled, 403, 'Scheduling is disabled.');

        // Reject injected tenancy fields and unknown scheduler actions.
        $allowed = ['name', 'opt_in', 'warehouse_id', 'supplier_id', 'prepare_purchase_order',
            'interval_minutes', 'max_runs_per_day', 'expires_days', 'items'];
        if (array_diff(array_keys($request->except('_token')), $allowed) !== []) {
            throw ValidationException::withMessages(['rule' => 'Unsupported automation fields were submitted.']);
        }
        $values = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'opt_in' => ['required', 'accepted'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'prepare_purchase_order' => ['required', 'boolean'],
            'interval_minutes' => ['required', 'integer', 'in:60,180,360,720,1440,10080'],
            'max_runs_per_day' => ['required', 'integer', 'between:1,24'],
            'expires_days' => ['required', 'integer', 'in:1,7,30,90'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['array:product_id,minimum_qty,order_qty'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.minimum_qty' => ['required', 'numeric', 'decimal:0,4', 'min:0'],
            'items.*.order_qty' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
        ]);
        if ((bool) $values['prepare_purchase_order'] && !$principal->hasTool('purchase_order.create.v1')) abort(403);

        $rules->create($principal, [
            'name' => $values['name'],
            'opt_in' => true,
            'warehouse_id' => (int) $values['warehouse_id'],
            'supplier_id' => $values['supplier_id'] ?? null,
            'prepare_purchase_order' => (bool) $values['prepare_purchase_order'],
            'interval_minutes' => (int) $values['interval_minutes'],
            'max_runs_per_day' => (int) $values['max_runs_per_day'],
            'expires_at' => now('UTC')->addDays((int) $values['expires_days'])->toIso8601String(),
            'items' => $values['items'],
        ]);
        return redirect()->route('ai.builder.index')->with('message',
            'Automation scheduled. It can alert or prepare unapproved purchase-order drafts; it cannot post transactions.');
    }

    public function cancel(
        Request $request, string $id, CompanyContextResolver $resolver, CommercialPermission $permissions,
        AiFeatureGate $gate, AiPrincipalFactory $factory, ScheduledRuleService $rules,
    ): RedirectResponse {
        [$principal, $enabled] = $this->authorized($request, $resolver, $permissions, $gate, $factory);
        abort_unless($enabled, 403);
        $input = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $rules->cancel($principal, $id, (int) $input['revision']);
        return redirect()->route('ai.builder.index')->with('message', 'Automation cancelled. Queued work is revalidated.');
    }

    public function runs(
        Request $request, string $id, CompanyContextResolver $resolver, CommercialPermission $permissions,
        AiFeatureGate $gate, AiPrincipalFactory $factory, ScheduledRuleService $rules,
    ): View {
        [$principal, $enabled] = $this->authorized($request, $resolver, $permissions, $gate, $factory);
        abort_unless($enabled, 403);
        return view('backend.ai.automation_runs', [
            'rule' => $rules->get($principal, $id),
            'runs' => $rules->runs($principal, $id),
        ]);
    }

    private function authorized(
        Request $request, CompanyContextResolver $resolver, CommercialPermission $permissions,
        AiFeatureGate $gate, AiPrincipalFactory $factory,
    ): array {
        $actorId = (int) $request->user()->id;
        $context = $resolver->forActor(userId: $actorId);
        $permissions->assert('ai.use', $context, $actorId);
        $principal = $factory->createForUser($actorId, $context->companyId, $context->branchId, $context->financialYearId);
        abort_unless($principal->isActive && $principal->hasTool('inventory.stock_summary.v1'), 403);
        return [$principal, (bool) config('ai_infrastructure.scheduling.enabled', false)
            && $gate->isEnabledForCompany($context->companyId)];
    }
}

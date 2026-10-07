<?php

namespace App\Services\Commercial;

use App\Models\Operations\Project;
use App\Models\Purchase;
use App\Models\Returns;
use App\Services\Platform\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Validates the business records a new document may hang off (project, exchange return, purchase order).
 * These ids are references, never trusted state: they are resolved against the trusted company/branch context on the
 * ?new=1 request, when a draft is saved, when it is loaded, and again by the posting service.
 */
class DocumentContextResolver
{
    public const KEYS = ['project_id', 'exchange_return_id', 'purchase_order_id'];
    /** Dropping these changes what is posted, so a stale reference blocks the draft instead of being removed. */
    private const REQUIRED = ['exchange_return_id', 'purchase_order_id'];

    /** Strict resolution for a request: a foreign or unusable record is a 404/403, never silently ignored. */
    public function fromRequest(Request $request, string $kind, ?CompanyContext $context, int $actor): array
    {
        $refs = [];
        foreach (self::KEYS as $key) {
            if ($request->filled($key)) {
                $request->validate([$key => 'integer|min:1']);
                $refs[$key] = $request->integer($key);
            }
        }
        if ($refs === []) return [];
        abort_unless($context, 403, "A company workspace is required for linked documents.");
        $result = $this->resolve($refs, $kind, $context, $actor, strict: true);
        return $result['context'];
    }

    /**
     * Lenient resolution for a saved draft. Returns the surviving references, human warnings for the optional ones
     * that were removed, and the blocking messages for the required ones that can no longer be honoured.
     */
    public function revalidate(array $refs, string $kind, CompanyContext $context, int $actor): array
    {
        return $this->resolve(array_intersect_key($refs, array_flip(self::KEYS)), $kind, $context, $actor, strict: false);
    }

    private function resolve(array $refs, string $kind, CompanyContext $context, int $actor, bool $strict): array
    {
        $out = [];
        $warnings = [];
        $blocking = [];
        foreach ($refs as $key => $id) {
            $id = (int) $id;
            if ($id < 1) continue;
            try {
                $out = array_merge($out, match ($key) {
                    'project_id' => $this->project($id, $kind, $context, $actor),
                    'exchange_return_id' => $this->exchangeReturn($id, $kind, $context),
                    'purchase_order_id' => $this->purchaseOrder($id, $kind, $context),
                });
            } catch (\Throwable $error) {
                if ($strict) throw $error;
                if (in_array($key, self::REQUIRED, true)) {
                    $blocking[] = $this->label($key).' is no longer available. Discard this draft or start again from the source document.';
                } else {
                    $warnings[] = 'The linked '.$this->label($key).' is no longer available. It has been removed from this draft.';
                }
            }
        }
        return ['context' => $out, 'warnings' => $warnings, 'blocking' => $blocking];
    }

    private function project(int $id, string $kind, CompanyContext $context, int $actor): array
    {
        abort_unless($kind === 'sale' && config('operations.enabled'), 404);
        app(\App\Services\Operations\OperationPosting::class)->authorize('operations.projects', 'projects.manage', $context, $actor);
        $project = Project::visibleIn($context)->findOrFail($id);
        return ['project_id' => $project->id, 'party_id' => $project->client_id];
    }

    private function exchangeReturn(int $id, string $kind, CompanyContext $context): array
    {
        abort_unless($kind === 'sale', 404);
        abort_unless(config('compliance.enabled'), 503);
        $note = Returns::forCompany($context)->where('branch_id', $context->branchId)->whereNotNull('posted_at')
            ->where('note_type', 'credit')->where('adjustment_type', 'quantity')->findOrFail($id);
        return ['exchange_return_id' => $note->id, 'post_url' => url('/compliance/exchange/'.$note->id)];
    }

    private function purchaseOrder(int $id, string $kind, CompanyContext $context): array
    {
        abort_unless($kind === 'purchase', 404);
        $order = Purchase::visibleIn($context)->whereKey($id)->where('status', 4)->whereNull('reversed_at')->firstOrFail();
        return ['purchase_order_id' => $order->id, 'party_id' => $order->supplier_id, 'warehouse_id' => $order->warehouse_id];
    }

    private function label(string $key): string
    {
        return ['project_id' => 'project', 'exchange_return_id' => 'return', 'purchase_order_id' => 'order'][$key];
    }
}

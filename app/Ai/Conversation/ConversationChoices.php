<?php

declare(strict_types=1);

namespace App\Ai\Conversation;

use App\Ai\DTOs\AiPrincipal;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ConversationChoices
{
    public function isSelection(string $message, array $input): bool
    {
        return !empty($input['choice_id']) || (bool) preg_match('/^(?:use |choose |select )?(?:the )?(first|second|third|\d+) (supplier|warehouse|product|unit|one)(?:[;,]|$)/i', trim($message));
    }

    public function select(AiPrincipal $principal, array $task, array $input): array
    {
        $latest = DB::table('ai_workflow_task_events')->where('task_id', $task['id'])->where('actor_id', $principal->actorId)
            ->where('event_type', 'choice_set')->orderByDesc('id')->first();
        if (!$latest || (!empty($input['choice_set_id']) && $input['choice_set_id'] !== $latest->id)
            || (int) $latest->revision !== (int) $task['revision']) {
            throw new ConflictHttpException('STALE_CHOICE_SET: Refresh the current choices before selecting.');
        }
        $set = json_decode($latest->metadata_json, true, flags: JSON_THROW_ON_ERROR);
        if ($set['expires_at'] <= now('UTC')->toDateTimeString()) {
            throw new ConflictHttpException('STALE_CHOICE_SET: The displayed choices expired.');
        }
        $remaining = $input['message'];
        if (!empty($input['choice_id'])) {
            $choice = collect($set['choices'])->firstWhere('choice_id', $input['choice_id']);
            $remaining = '';
        } else {
            preg_match('/^(?:use |choose |select )?(?:the )?(first|second|third|\d+) (supplier|warehouse|product|unit|one)\s*(?:[;,]\s*)?/i', trim($input['message']), $match);
            $index = ['first' => 0, 'second' => 1, 'third' => 2][strtolower($match[1])] ?? ((int) $match[1] - 1);
            $choices = array_values(array_filter($set['choices'], fn ($item) => strtolower($match[2]) === 'one' || $item['entity_type'] === strtolower($match[2])));
            if (count(array_unique(array_column($choices, 'field'))) !== 1) {
                throw ValidationException::withMessages(['choice' => 'Specify which field or line you want to select.']);
            }
            $choice = $choices[$index] ?? null;
            $remaining = trim(substr(trim($input['message']), strlen($match[0])));
        }
        if (!$choice) {
            throw ValidationException::withMessages(['choice' => 'Select an option from the current displayed list.']);
        }
        $model = match ($choice['entity_type']) { 'supplier' => Supplier::class, 'warehouse' => Warehouse::class, 'product' => Product::class, 'unit' => Unit::class };
        $query = $model::where('company_id', $principal->companyId)->where('is_active', true)->whereKey($choice['record_id']);
        if ($model === Warehouse::class) {
            $query->where('branch_id', $principal->branchId);
        }
        $record = $query->lockForUpdate()->first();
        if (!$record || !hash_equals($choice['record_hash'], $this->fingerprint($record))) {
            throw new ConflictHttpException('STALE_CHOICE_SET: The selected record is no longer eligible.');
        }
        return ['corrections' => [['field' => $choice['field'], 'value' => $choice['record_id'], 'confirmation' => 'user_selected']], 'remaining_message' => $remaining];
    }

    public function publish(AiPrincipal $principal, array $task, array $result): array
    {
        $choices = [];
        foreach ($result['required_inputs'] ?? [] as $issue) {
            $field = $issue['field'];
            $type = $issue['entity_type'] ?? (str_contains($field, 'product_id') ? 'product' : str_replace('_id', '', $field));
            if (!in_array($type, ['supplier', 'warehouse', 'product', 'unit'], true)) {
                continue;
            }
            foreach (array_slice($issue['candidates'] ?? [], 0, 20) as $candidate) {
                $choices[] = ['choice_id' => (string) Str::uuid(), 'field' => $field, 'entity_type' => $type,
                    'record_id' => (int) $candidate['id'], 'label' => (string) ($candidate['label'] ?? $candidate['name'])];
            }
        }
        if ($choices === []) {
            return $result;
        }
        foreach (collect($choices)->groupBy('entity_type') as $type => $group) {
            $model = match ($type) { 'supplier' => Supplier::class, 'warehouse' => Warehouse::class, 'product' => Product::class, 'unit' => Unit::class };
            $records = $model::where('company_id', $principal->companyId)->where('is_active', true)
                ->whereIn('id', $group->pluck('record_id'))->when($type === 'warehouse', fn ($query) => $query->where('branch_id', $principal->branchId))->get()->keyBy('id');
            foreach ($choices as $index => $choice) {
                if ($choice['entity_type'] !== $type) {
                    continue;
                }
                if (!isset($records[$choice['record_id']])) {
                    unset($choices[$index]);
                    continue;
                }
                $choices[$index]['record_hash'] = $this->fingerprint($records[$choice['record_id']]);
            }
        }
        $choices = array_values($choices);
        $setId = (string) Str::uuid7();
        DB::table('ai_workflow_task_events')->insert(['id' => $setId, 'task_id' => $task['id'], 'actor_id' => $principal->actorId,
            'event_type' => 'choice_set', 'revision' => $task['revision'],
            'metadata_json' => json_encode(['expires_at' => now('UTC')->addMinutes(10)->toDateTimeString(), 'choices' => $choices], JSON_THROW_ON_ERROR), 'created_at' => now()]);
        return $result + ['choice_set_id' => $setId,
            'choices' => array_map(fn ($choice) => array_diff_key($choice, ['record_id' => true, 'record_hash' => true]), $choices)];
    }

    private function fingerprint(\Illuminate\Database\Eloquent\Model $record): string
    {
        return hash('sha256', json_encode(array_intersect_key($record->getRawOriginal(), array_flip([
            'id', 'name', 'company_name', 'code', 'unit_name', 'unit_code', 'is_active', 'branch_id', 'cost',
            'purchase_unit_id', 'unit_id', 'tax_category_id', 'hsn_code', 'credit_days', 'purchase_defaults', 'updated_at',
        ])), JSON_THROW_ON_ERROR));
    }

    public function directory(AiPrincipal $principal, array $task, string $type, string $query): array
    {
        $context = app(\App\Services\Platform\CompanyContextResolver::class)->forActor(userId: $principal->actorId);
        if ($context->companyId !== $principal->companyId || $context->branchId !== $principal->branchId || $context->financialYearId !== $principal->financialYearId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Directory context changed. Reload this workspace.');
        }
        $lookup = clone request();
        $field = $type.'_id';
        if (in_array($type, ['product', 'unit'], true)) {
            $lines = json_decode($task['slots_json'], true)['items'] ?? [];
            if (count($lines) > 1 || ($type === 'unit' && count($lines) !== 1)) {
                throw ValidationException::withMessages(['choice' => 'Specify a single draft line before searching this field.']);
            }
            $lineId = $lines[0]['client_line_id'] ?? 'line-'.Str::uuid();
            $field = "items[{$lineId}].".($type === 'unit' ? 'purchase_unit_id' : 'product_id');
        }
        $lookup->query->replace(['type' => $type, 'q' => $query, 'task_id' => $task['id'], 'field' => $field, 'per_page' => 20]);
        $lookup->request->replace([]);
        $options = app(\App\Http\Controllers\Api\V1\AiEntityOptionsController::class)->index($lookup)->getData(true);
        return ['field' => $field, 'entity_type' => $type, 'status' => 'ambiguous', 'label' => ucfirst($type), 'candidates' => $options['items']];
    }
}

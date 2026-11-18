<?php

declare(strict_types=1);

namespace App\Ai\Conversation;

use Illuminate\Support\Str;

final class ConversationalSlotInterpreter
{
    public function interpret(array $task, string $message): array
    {
        $slots = json_decode($task['slots_json'], true, flags: JSON_THROW_ON_ERROR);
        $lines = $slots['items'] ?? [];
        $corrections = [];
        $unknown = [];
        if (preg_match('/^(?:continue|resume|preview)$/i', trim($message))) {
            return $this->result([]);
        }
        if (preg_match('/\b(?:not|or|maybe|perhaps|unclear)\b/i', $message) || preg_match('/^(?:yes|approve|do it|that one|new)$/i', trim($message))) {
            return $this->result([], ['Confirm the intended value or selection before changing the draft.']);
        }
        $message = preg_replace('/^\s*(?:create|prepare|raise|start|make)\s+(?:a\s+|new\s+)?(?:purchase\s+order|po)\s*(?:for\s+)?/i', '', $message);
        foreach (preg_split('/\s*[;,]\s*/u', trim($message)) as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(?:use\s+)?cash(?:\s+terms)?$/i', $part)) {
                $corrections[] = $this->correction('payment_terms_type', 'cash');
                $corrections[] = $this->correction('credit_days', 0);
            } elseif (preg_match('/^(?:use\s+)?credit(?:\s+for)?\s+(\d+|thirty|forty five|forty-five)\s+days?$/i', $part, $m)) {
                $days = ['thirty' => 30, 'forty five' => 45, 'forty-five' => 45][strtolower($m[1])] ?? (int) $m[1];
                $corrections[] = $this->correction('payment_terms_type', 'credit');
                $corrections[] = $this->correction('credit_days', $days);
            } elseif (preg_match('/\bcash\b.*\d+.*\bdays?\b/i', $part)) {
                return $this->result([], ['Cash terms conflict with credit days. Choose Cash or Credit and confirm the days.']);
            } elseif (preg_match('/^(?:use\s+)?(?:supplier|from)\s+(.+)$/i', $part, $m)) {
                $corrections[] = $this->correction('supplier_query', trim($m[1]));
            } elseif (preg_match('/^(?:use\s+)?warehouse\s+(.+)$/i', $part, $m)) {
                $corrections[] = $this->correction('warehouse_query', trim($m[1]));
            } elseif (preg_match('/^(?:make (?:it|that)|increase quantity to|quantity|qty)\s+(\d+(?:\.\d+)?)\s*(?:instead)?$/i', $part, $m) && count($lines) === 1) {
                $corrections[] = $this->correction('items['.$lines[0]['client_line_id'].'].qty', $m[1]);
                $lines[0]['qty'] = $m[1];
            } elseif (preg_match('/^remove (?:the )?(first|second|third|\d+) item$/i', $part, $m)) {
                $index = ['first' => 0, 'second' => 1, 'third' => 2][strtolower($m[1])] ?? ((int) $m[1] - 1);
                if (!isset($lines[$index])) {
                    return $this->result([], ['Select a current draft line to remove.']);
                }
                $corrections[] = $this->correction('items['.$lines[$index]['client_line_id'].']._destroy', true);
                array_splice($lines, $index, 1);
            } elseif (preg_match('/^(?:add\s+)?(\d+(?:\.\d+)?)\s+(pairs?|pcs|pieces?|units?|kg|metres?|meters?|mtr)\s+(?:of\s+)?(.+?)(?:\s+at\s+[₹$]?\s*(\d+(?:\.\d+)?)(?:\s+each)?)?$/iu', $part, $m)) {
                $line = ['client_line_id' => 'line-'.Str::uuid(), 'product_query' => trim($m[3]), 'qty' => $m[1], 'uom' => $m[2]];
                if (isset($m[4]) && $m[4] !== '') {
                    $line['net_unit_cost'] = $m[4];
                }
                $lines[] = $line;
                $corrections = array_values(array_filter($corrections, fn ($c) => $c['field'] !== 'items'));
                $corrections[] = $this->correction('items', $lines);
            } elseif (preg_match('/^[₹$]?\s*(\d+(?:\.\d+)?)\s+each$/u', $part, $m) && count($lines) === 1) {
                $corrections[] = $this->correction('items['.$lines[0]['client_line_id'].'].net_unit_cost', $m[1]);
                $lines[0]['net_unit_cost'] = $m[1];
            } elseif (!isset($slots['supplier_id']) && !isset($slots['supplier_query']) && preg_match('/^[\pL][\pL\pN .&\-]{1,100}$/u', $part)) {
                $corrections[] = $this->correction('supplier_query', $part);
            } else {
                $unknown[] = $part;
            }
        }
        return $unknown === [] ? $this->result($corrections) : $this->result([], [], 'unknown');
    }

    public function validateModelResult(array $task, array $result): array
    {
        if (array_diff(array_keys($result), ['turn_intent', 'corrections', 'warnings']) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['corrections' => 'Unsupported interpreter output.']);
        }
        \Illuminate\Support\Facades\Validator::make($result, [
            'turn_intent' => 'required|in:continue_task,unknown',
            'corrections' => 'present|array|max:20', 'corrections.*' => 'array:field,value,operation,line_id',
            'corrections.*.field' => 'required|string|max:100',
            'corrections.*.value' => 'present', 'corrections.*.operation' => 'nullable|in:set,add_line,change_line,remove_line',
            'corrections.*.line_id' => 'nullable|string|max:100', 'warnings' => 'present|array|max:10', 'warnings.*' => 'string|max:300',
        ])->validate();
        $allowed = ['supplier_query', 'warehouse_query', 'date', 'note', 'payment_terms_type', 'credit_days'];
        $lines = json_decode($task['slots_json'], true)['items'] ?? [];
        $corrections = [];
        foreach ($result['corrections'] as $correction) {
            $field = $correction['field'];
            if (in_array($field, $allowed, true) && is_scalar($correction['value'])) {
                $rules = match ($field) {
                    'payment_terms_type' => 'required|in:cash,credit',
                    'credit_days' => 'required|integer|min:0|max:3650',
                    'date' => 'required|date_format:Y-m-d',
                    'note' => 'string|max:2000',
                    default => 'required|string|max:200|regex:/\pL/u',
                };
                \Illuminate\Support\Facades\Validator::make(['value' => $correction['value']], ['value' => $rules])->validate();
                $corrections[] = $this->correction($field, $correction['value']);
                continue;
            }
            $operation = $correction['operation'] ?? 'set';
            $lineId = $correction['line_id'] ?? '';
            $index = array_search($lineId, array_column($lines, 'client_line_id'), true);
            if ($operation === 'remove_line' && $index !== false) {
                $corrections[] = $this->correction("items[{$lineId}]._destroy", true);
                array_splice($lines, $index, 1);
            } elseif ($operation === 'change_line' && $index !== false && in_array($field, ['qty', 'net_unit_cost', 'product_query', 'uom'], true) && is_scalar($correction['value'])) {
                $rules = match ($field) {
                    'qty' => 'required|numeric|gt:0|max:100000000',
                    'net_unit_cost' => 'required|numeric|min:0|max:100000000',
                    default => 'required|string|max:200|regex:/\pL/u',
                };
                \Illuminate\Support\Facades\Validator::make(['value' => $correction['value']], ['value' => $rules])->validate();
                if ($field === 'product_query') {
                    foreach (['product_id', 'purchase_unit_id', 'sale_unit_id', 'uom', 'net_unit_cost', 'net_unit_price', 'tax_rate', 'hsn_code'] as $dependent) {
                        unset($lines[$index][$dependent]);
                    }
                }
                $corrections[] = $this->correction("items[{$lineId}].{$field}", $correction['value']);
                $lines[$index][$field] = $correction['value'];
            } elseif ($operation === 'add_line' && $field === 'items' && is_array($correction['value'])) {
                $line = $correction['value'];
                if (array_diff(array_keys($line), ['product_query', 'qty', 'uom', 'net_unit_cost']) !== []) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['corrections' => 'Model line contains unsupported fields.']);
                }
                \Illuminate\Support\Facades\Validator::make($line, [
                    'product_query' => 'required|string|max:200|regex:/\pL/u',
                    'qty' => 'required|numeric|gt:0|max:100000000',
                    'uom' => 'sometimes|required|string|max:100|regex:/\pL/u',
                    'net_unit_cost' => 'sometimes|required|numeric|min:0|max:100000000',
                ])->validate();
                $lines[] = ['client_line_id' => 'line-'.Str::uuid()] + $line;
                $corrections[] = $this->correction('items', $lines);
            } else {
                throw \Illuminate\Validation\ValidationException::withMessages(['corrections' => 'Unsupported field or stale line reference.']);
            }
        }
        return $this->result($result['warnings'] === [] && $result['turn_intent'] === 'continue_task' ? $corrections : [], $result['warnings'], $result['turn_intent']);
    }

    private function correction(string $field, mixed $value): array
    {
        return ['field' => $field, 'value' => $value, 'confirmation' => 'user_text'];
    }

    private function result(array $corrections, array $warnings = [], string $intent = 'continue_task'): array
    {
        return ['turn_intent' => $intent, 'corrections' => $corrections, 'warnings' => $warnings];
    }
}

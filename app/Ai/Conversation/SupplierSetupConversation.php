<?php

declare(strict_types=1);

namespace App\Ai\Conversation;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Requirements\SupplierSetupRequirementsProvider;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\TaskCorrectionService;
use App\Ai\Services\WorkflowTaskStateService;
use Illuminate\Support\Facades\DB;

final class SupplierSetupConversation
{
    public const KEY = SupplierSetupRequirementsProvider::KEY;

    public function start(string $message): ?string
    {
        return preg_match('/^(?:please\s+)?(?:create|add)\s+(?:a\s+)?supplier(?:\s+(?:named|called)\s+([^\r\n]{1,255}?))?[.!?]?$/iuD', trim($message), $match)
            ? trim($match[1] ?? '') : null;
    }

    public function reply(AiPrincipal $principal, array $task, string $message, ?string $start, array $photo = []): ?array
    {
        $state = app(WorkflowTaskStateService::class);
        $corrections = app(TaskCorrectionService::class);
        $slots = json_decode($task['slots_json'], true, flags: JSON_THROW_ON_ERROR);
        $cancel = (bool) preg_match('/^cancel(?: (?:this |the )?supplier(?: draft)?)?[.!]?$/i', $message);
        $preview = (bool) preg_match('/^(?:show |prepare )?preview(?: (?:the )?supplier(?: proposal)?)?[.!]?$/i', $message);
        $changes = $photo;
        if ($start !== null && $start !== '') {
            $changes['name'] = $start;
        } elseif (!$cancel && !$preview && $start === null && $photo === []) {
            $changes = $this->parseDetails($message, empty($slots['name']));
            if ($changes === [] && !preg_match('/^(?:resume|continue|edit)(?: (?:the )?supplier(?: draft)?)?[.!]?$/i', $message)) {
                return null;
            }
        }

        if ($task['linked_proposal_id'] && ($cancel || $changes !== [])) {
            $proposals = app(ActionProposalService::class);
            $proposal = $proposals->getOwned($principal, $task['linked_proposal_id']);
            $proposals->reject($principal, $proposal['id'], (int) $proposal['version']);
            DB::table('ai_workflow_tasks')->where('id', $task['id'])->where('actor_id', $principal->actorId)
                ->update(['linked_proposal_id' => null]);
        }
        if ($cancel) {
            $task = $state->updateTaskState($principal, $task['id'], (int) $task['revision'], $slots, status: 'cancelled');
            return $this->result($task, 'cancelled', 'Supplier draft cancelled.');
        }
        $patch = [];
        foreach ($changes as $field => $value) {
            $patch[] = ['field' => $field, 'value' => $value, 'confirmation' => 'user_edited'];
        }
        $applied = $corrections->applyCorrections($principal, $task['id'], (int) $task['revision'], $patch);
        $task = $applied['task'];
        $issue = $applied['evaluation']->firstBlockingIssue();
        if ($issue) {
            $message = $issue->field === 'name' && empty($slots['name']) && empty($changes['name'])
                ? 'What is the supplier’s name? You can type, dictate, record, or attach a photo.'
                : $issue->reason.' Reply with the corrected detail. For an unregistered supplier, say “no GSTIN”.';
            return $this->result($task, 'needs_input', $message);
        }
        if ($preview) {
            return array_replace($corrections->prepareProposal($principal, $task, $applied['evaluation']),
                ['message' => 'Review the supplier details before approving creation.']);
        }
        $name = $changes['name'] ?? $slots['name'];
        return $this->result($task, 'needs_input',
            'Supplier draft: '.$name.'. Add or correct details in chat, or say “preview supplier” when ready.');
    }

    private function result(array $task, string $status, string $message): array
    {
        return ['status' => $status, 'task_id' => $task['id'], 'task_revision' => (int) $task['revision'],
            'action_key' => self::KEY, 'message' => $message];
    }

    private function parseDetails(string $message, bool $needsName): array
    {
        $fields = ['supplier name' => 'name', 'name' => 'name', 'company name' => 'company_name', 'phone number' => 'phone_number',
            'phone' => 'phone_number', 'mobile' => 'phone_number', 'e-mail' => 'email', 'email' => 'email',
            'gstin' => 'vat_number', 'gst number' => 'vat_number', 'gst' => 'vat_number',
            'state' => 'state', 'city' => 'city', 'address' => 'address'];
        $labels = implode('|', array_keys($fields));
        $pattern = '/(?:^|[.;,\n]\s*|\s+and\s+|\s+)(?:and\s+)?(?:change\s+(?:the\s+)?|set\s+)?('.$labels.')\b(?:\s*[:=]\s*|\s+(?:is|to)\s+|\s+)/iu';
        preg_match_all($pattern, $message, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $changes = [];
        foreach ($matches as $index => [$match, $label]) {
            $start = $match[1] + strlen($match[0]);
            $end = $matches[$index + 1][0][1] ?? strlen($message);
            $value = trim(substr($message, $start, $end - $start), " \t\n\r.;,");
            if ($value !== '') {
                $changes[$fields[strtolower($label[0])]] = $value;
            }
        }
        if (preg_match('/\b(?:no\s+gstin|skip\s+gstin|remove\s+gstin)\b/i', $message)) {
            $changes['vat_number'] = null;
        }
        if ($changes === [] && $needsName && preg_match('/^[\p{L}\p{N}][\p{L}\p{N} &.,\x27()-]{1,180}$/uD', $message)
            && !preg_match('/^(?:resume|continue|edit|calculate|create|add)\b/i', $message)) {
            $changes['name'] = trim($message);
        }
        return $changes;
    }
}

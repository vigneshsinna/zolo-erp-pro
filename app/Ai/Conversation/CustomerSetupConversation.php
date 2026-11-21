<?php

declare(strict_types=1);

namespace App\Ai\Conversation;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Requirements\CustomerSetupRequirementsProvider;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\TaskCorrectionService;
use App\Ai\Services\WorkflowTaskStateService;
use Illuminate\Support\Facades\DB;

final class CustomerSetupConversation
{
    public const KEY = CustomerSetupRequirementsProvider::KEY;

    public function start(string $message): ?string
    {
        return preg_match('/^(?:please\s+)?(?:create|add)\s+(?:a\s+)?customer(?:\s+(?:named|called)\s+([^\r\n]{1,255}?))?[.!?]?$/iuD', trim($message), $match)
            ? trim($match[1] ?? '') : null;
    }

    public function reply(
        AiPrincipal $principal,
        array $task,
        string $message,
        ?string $start,
        array $photo = [],
        array $semanticFacts = [],
    ): ?array {
        $state = app(WorkflowTaskStateService::class);
        $corrections = app(TaskCorrectionService::class);
        $slots = json_decode($task['slots_json'], true, flags: JSON_THROW_ON_ERROR);
        $cancel = (bool) preg_match('/^cancel(?: (?:this |the )?customer(?: draft)?)?[.!]?$/i', $message);
        $preview = (bool) preg_match('/^(?:show |prepare )?preview(?: (?:the )?customer(?: proposal)?)?[.!]?$/i', $message);
        $planner = app(ConversationQuestionPlanner::class);
        $guided = config('ai_business_knowledge.enabled', false)
            && config('ai_business_knowledge.semantic_enabled', false);
        $skipGroups = $guided ? $planner->skipGroups($message) : [];
        $gstStatus = $guided ? ($semanticFacts['_gst_status'] ?? null) : null;
        $allowedFields = ['name', 'company_name', 'phone_number', 'tax_no', 'state', 'city', 'address', 'email', 'customer_group_id'];

        if (isset($semanticFacts['vat_number']) && !isset($semanticFacts['tax_no'])) {
            $semanticFacts['tax_no'] = $semanticFacts['vat_number'];
        }

        $changes = array_replace($photo, array_intersect_key($semanticFacts, array_flip($allowedFields)));
        if ($start !== null && $start !== '') {
            $changes['name'] = $start;
        } elseif (!$cancel && !$preview && $start === null && $photo === [] && $changes === []) {
            $changes = $this->parseDetails($message, empty($slots['name']));
            if ($changes === [] && $skipGroups === []
                && !preg_match('/\b(?:unregistered|not registered|registered|no gstin|no tax no)\b/i', $message)
                && !preg_match('/^(?:resume|continue|edit)(?: (?:the )?customer(?: draft)?)?[.!]?$/i', $message)) {
                return null;
            }
        }
        if ($guided && preg_match('/\b(?:unregistered|not\s+(?:gst\s+)?registered)\b/i', $message)) {
            $gstStatus = 'unregistered';
            $changes['tax_no'] = null;
        } elseif ($guided && preg_match('/\b(?:no\s+gstin|no\s+tax\s+no)\b/i', $message)) {
            $gstStatus = 'unknown';
            $changes['tax_no'] = null;
        } elseif ($guided && (preg_match('/\bgst\s+registered\b/i', $message) || !empty($changes['tax_no']))) {
            $gstStatus = 'registered';
        }

        if ($task['linked_proposal_id'] && ($cancel || $changes !== [] || $gstStatus !== null)) {
            $proposals = app(ActionProposalService::class);
            $proposal = $proposals->getOwned($principal, $task['linked_proposal_id']);
            $proposals->reject($principal, $proposal['id'], (int) $proposal['version']);
            DB::table('ai_workflow_tasks')->where('id', $task['id'])->where('actor_id', $principal->actorId)
                ->update(['linked_proposal_id' => null]);
        }
        if ($cancel) {
            $task = $state->updateTaskState($principal, $task['id'], (int) $task['revision'], $slots, status: 'cancelled');
            return $this->result($task, 'cancelled', 'Customer draft cancelled.');
        }
        $patch = [];
        foreach ($changes as $field => $value) {
            $origin = array_key_exists($field, $semanticFacts) ? 'model_extracted'
                : (array_key_exists($field, $photo) ? 'photo_extracted' : 'user_edited');
            $patch[] = ['field' => $field, 'value' => $value, 'confirmation' => $origin];
        }
        $applied = $corrections->applyCorrections($principal, $task['id'], (int) $task['revision'], $patch);
        $task = $applied['task'];
        $currentSlots = json_decode($task['slots_json'], true, flags: JSON_THROW_ON_ERROR);
        if ($skipGroups !== [] || $gstStatus !== null) {
            $skipped = array_values(array_unique(array_merge(
                (array) ($currentSlots['_conversation_skips'] ?? []), $skipGroups,
            )));
            $currentSlots['_conversation_skips'] = $skipped;
            if ($gstStatus !== null) $currentSlots['_conversation_gst_status'] = $gstStatus;
            $task = $state->updateTaskState($principal, $task['id'], (int) $task['revision'],
                $currentSlots, patch: [['field' => 'conversation_metadata', 'value' =>
                    ['skipped_groups' => $skipGroups, 'gst_status' => $gstStatus]]]);
        }
        $issue = $applied['evaluation']->firstBlockingIssue();
        if ($issue) {
            $message = $issue->field === 'name' && empty($slots['name']) && empty($changes['name'])
                ? 'What is the customer’s name? You can type, dictate, record, or attach a document.'
                : $issue->reason.' Reply with the corrected detail. For an unregistered customer, say “no GSTIN”.';
            return $this->result($task, 'needs_input', $message);
        }
        if ($preview) {
            if (($currentSlots['_conversation_gst_status'] ?? null) === 'registered'
                && empty($currentSlots['tax_no'])) {
                return $this->result($task, 'needs_input',
                    'You marked this customer as GST registered. Please share their GSTIN or correct the registration status.');
            }
            return array_replace($corrections->prepareProposal($principal, $task, $applied['evaluation']),
                ['message' => 'Review the customer details before approving creation.']);
        }
        $question = $guided ? $planner->next(self::KEY, $currentSlots) : null;
        if ($question !== null) return $this->result($task, 'needs_input', $question);
        $name = $currentSlots['name'] ?? '';
        return $this->result($task, 'needs_input', $guided
            ? 'Customer draft: '.$name.'. The guided details are collected or skipped. Say “preview customer” to review before approval, or correct any field in chat.'
            : 'Customer draft: '.$name.'. Add or correct details in chat, or say “preview customer” when ready.');
    }

    private function result(array $task, string $status, string $message): array
    {
        return [
            'status' => $status,
            'task_id' => $task['id'],
            'task_revision' => (int) $task['revision'],
            'action_key' => self::KEY,
            'message' => $message,
        ];
    }

    private function parseDetails(string $message, bool $needsName): array
    {
        $fields = [
            'customer name' => 'name', 'name' => 'name', 'company name' => 'company_name',
            'phone number' => 'phone_number', 'phone' => 'phone_number', 'mobile' => 'phone_number',
            'e-mail' => 'email', 'email' => 'email',
            'gstin' => 'tax_no', 'gst number' => 'tax_no', 'gst' => 'tax_no', 'tax no' => 'tax_no',
            'state' => 'state', 'city' => 'city', 'address' => 'address',
        ];
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
        if (preg_match('/\b(?:no\s+gstin|skip\s+gstin|remove\s+gstin|no\s+tax\s+no)\b/i', $message)) {
            $changes['tax_no'] = null;
        }
        if ($changes === [] && $needsName && preg_match('/^[\p{L}\p{N}][\p{L}\p{N} &.,\x27()-]{1,180}$/uD', $message)
            && !preg_match('/^(?:resume|continue|edit|calculate|create|add)\b/i', $message)) {
            $changes['name'] = trim($message);
        }
        return $changes;
    }
}

<?php

declare(strict_types=1);

namespace App\Ai\Conversation;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Gateway\ApprovedModelGateway;
use Illuminate\Support\Facades\Validator;

/**
 * No model invocation for exact actions or directional clarification.
 * Bounded structured LLM extraction only for nontrivial language.
 * Output NEVER creates, approves or selects ERP records.
 */
final class BusinessIntentInterpreter
{
    private const FIELDS = ['name', 'company_name', 'phone_number', 'email', 'vat_number', 'city', 'state', 'address'];

    public function __construct(private readonly PendingPartyIntentService $pending) {}

    public function start(AiPrincipal $principal, string $threadId, string $utterance): ?array
    {
        if (!config('ai_business_knowledge.semantic_enabled', false)
            || !config('ai_business_knowledge.enabled', false)) return null;
        $message = trim($utterance);
        if ($this->pending->isPending($principal, $threadId)) {
            $role = $this->direction($message);
            if (preg_match('/^(?:a\s+)?(?:supplier|vendor|சப்ளையர்)[.!]?$/iu', $message)) $role = 'supplier';
            if (preg_match('/^(?:a\s+)?(?:customer|buyer|கஸ்டமர்)[.!]?$/iu', $message)) $role = 'customer';
            return $this->result($role ?? 'ambiguous', [], 'deterministic', 'pending_party');
        }
        if (preg_match('/^(?:please\s+)?(?:create|add|register|set\s+up)\s+(?:(?:a|an)\s+)?(?:new\s+)?(supplier|vendor|seller|customer|buyer|client|party|trader)\b(.*)$/iu', $message, $m)) {
            $noun = strtolower($m[1]);
            $tail = trim($m[2], " \t\n\r.!?");
        } elseif (preg_match('/^(?:oru\s+)?(?:puthiya|pudhu|new)?\s*(supplier|vendor|seller|customer|buyer|client|party|trader)\s+(?:create\s+pannunga|add\s+pannunga|set\s+up\s+pannunga)\b(.*)$/iu', $message, $m)
            || preg_match('/^(?:ஒரு\s+)?(?:புதிய|புது)?\s*(supplier|vendor|customer|buyer|party|சப்ளையர்|கஸ்டமர்)\s+(?:create\s+பண்ணுங்க|add\s+பண்ணுங்க|உருவாக்குங்க)\b(.*)$/iu', $message, $m)) {
            $noun = strtolower($m[1]);
            if ($noun === 'சப்ளையர்') $noun = 'supplier';
            if ($noun === 'கஸ்டமர்') $noun = 'customer';
            $tail = trim($m[2], " \t\n\r.!?");
        } else {
            return null;
        }
        $role = match ($noun) {
            'supplier', 'vendor', 'seller' => 'supplier',
            'customer', 'buyer', 'client' => 'customer',
            default => $this->direction($message) ?? 'ambiguous',
        };
        if (preg_match('/\bboth\s+(?:supplier|vendor)\s+and\s+customer\b/i', $message)) $role = 'both';
        if ($role === 'ambiguous' || $role === 'both') return $this->result($role, [], 'deterministic');
        if ($tail === '') return $this->result($role, [], 'deterministic');
        if (preg_match('/^(?:named|called)\s+([\pL\pN .&\x27-]{2,160}?)(?:\s+(?:who|that|which)\b.*)?$/uD', $tail, $match)) {
            return $this->result($role, ['name' => trim($match[1])], 'deterministic');
        }
        if (preg_match('/^(?:who|that|which)\s+(?:will\s+|are\s+)?(?:buy|buys|purchase|purchases|sell|sells|supply|supplies)\b.*$/iu', $tail)
            || preg_match('/^for\s+(?:buying|selling|purchasing)\b.*$/iu', $tail)) {
            return $this->result($role, [], 'deterministic');
        }
        return $this->modelParse($principal, $threadId, $message, 'intent', $role);
    }

    public function supplierDetails(AiPrincipal $principal, string $threadId, string $message, bool $needsName = false): ?array
    {
        if (!config('ai_business_knowledge.semantic_enabled', false)
            || !config('ai_business_knowledge.enabled', false)) return null;
        if (preg_match('/^(?:cancel|preview|continue|resume|edit|skip)\b/i', trim($message))
            || preg_match('/^(?:unregistered|not registered|no gstin|gst registered)[.!]?$/i', trim($message))) return null;
        if (preg_match('/\b(?:name|supplier name|company name|phone|mobile|email|gstin|gst number|city|state|address)\b/i', $message)) return null;
        if ($needsName && preg_match('/^[\pL\pN][\pL\pN &.,\x27()-]{1,180}$/uD', trim($message))
            && !preg_match('/\b(?:from|at|in|our|their|they|he|she|unregistered|registered|gst)\b/i', $message)) return null;
        $parsed = $this->modelParse($principal, $threadId, $message, 'supplier_fields', 'supplier');
        if (!$needsName && !preg_match('/\b(?:change|update|correct|rename)\s+(?:the\s+)?(?:supplier\s+)?name\b/i', $message)) {
            unset($parsed['facts']['name']);
        }
        return $parsed;
    }

    public function customerDetails(AiPrincipal $principal, string $threadId, string $message, bool $needsName = false): ?array
    {
        if (!config('ai_business_knowledge.semantic_enabled', false)
            || !config('ai_business_knowledge.enabled', false)) return null;
        if (preg_match('/^(?:cancel|preview|continue|resume|edit|skip)\b/i', trim($message))
            || preg_match('/^(?:unregistered|not registered|no gstin|no tax no|gst registered)[.!]?$/i', trim($message))) return null;
        if (preg_match('/\b(?:name|customer name|company name|phone|mobile|email|gstin|gst number|tax no|city|state|address)\b/i', $message)) return null;
        if ($needsName && preg_match('/^[\pL\pN][\pL\pN &.,\x27()-]{1,180}$/uD', trim($message))
            && !preg_match('/\b(?:from|at|in|our|their|they|he|she|unregistered|registered|gst)\b/i', $message)) return null;
        $parsed = $this->modelParse($principal, $threadId, $message, 'customer_fields', 'customer');
        if (!$needsName && !preg_match('/\b(?:change|update|correct|rename)\s+(?:the\s+)?(?:customer\s+)?name\b/i', $message)) {
            unset($parsed['facts']['name']);
        }
        return $parsed;
    }

    /** Strict direction references: "buying linen" alone is not sufficient. */
    public function direction(string $utterance): ?string
    {
        $text = strtolower(trim($utterance));
        if (preg_match('/\b(?:we|i|our company)\s+(?:will\s+|are\s+|would\s+)?(?:buy|purchase|buying|purchasing)\b.{0,100}\bfrom\s+(?:them|this party|the party|him|her)\b/i', $text)
            || preg_match('/\b(?:who|they|this party|the party)\s+(?:will\s+|are\s+)?(?:sell|selling|supply|supplying)\b.{0,100}\b(?:to\s+us|our company)\b/i', $text)
            || preg_match('/\b(?:avanga\s+namakku\s+supply|namakku\s+supply\s+pannuvanga|namma\s+avanga\s+kitta\s+vanguroam|namakku\s+porul\s+tharuvanga)\b/i', $text)
            || preg_match('/(?:நமக்கு\s+சப்ளை|நமக்கு\s+பொருள்\s+தருவாங்க|அவங்க\s+நமக்கு\s+விற்பாங்க)/u', $text)) return 'supplier';
        if (preg_match('/\b(?:who|they|this party|the party)\s+(?:will\s+|are\s+)?(?:buy|purchase|buying|purchasing)\b.{0,100}\bfrom\s+(?:us|our company)\b/i', $text)
            || preg_match('/\b(?:we|i|our company)\s+(?:will\s+|are\s+)?(?:sell|selling|supply|supplying)\b.{0,100}\bto\s+(?:them|the party|this party)\b/i', $text)
            || preg_match('/\b(?:namma\s+kitta\s+irundhu\s+vanguvanga|avanga\s+namma\s+kitta\s+vanguranga|namma\s+avangalukku\s+vikkiroam)\b/i', $text)
            || preg_match('/(?:நம்ம\s+கிட்ட\s+இருந்து\s+வாங்குவாங்க|அவங்க\s+நம்ம\s+கிட்ட\s+வாங்குவாங்க)/u', $text)) return 'customer';
        return null;
    }

    private function modelParse(AiPrincipal $principal, string $threadId, string $utterance, string $mode, string $knownRole): array
    {
        $gateway = app(ApprovedModelGateway::class);
        $model = $gateway->resolveApprovedModel();
        $gateway->admitRequest($principal, now('UTC')->toDateString(), $threadId);
        $response = (new BusinessIntentParseAgent($principal))->prompt(
            json_encode(['mode' => $mode, 'utterance' => $utterance, 'known_role' => $knownRole], JSON_THROW_ON_ERROR),
            provider: $model['provider'], model: $model['model'], timeout: 20,
        );
        $data = $response->toArray();
        Validator::make($data, [
            'role' => 'required|in:supplier,customer,both,ambiguous,unsupported',
            'name' => 'present|string|max:255', 'company_name' => 'present|string|max:255',
            'phone_number' => 'present|string|max:50', 'email' => 'present|string|max:100',
            'vat_number' => 'present|string|max:50', 'city' => 'present|string|max:100',
            'state' => 'present|string|max:100', 'address' => 'present|string|max:255',
            'gst_status' => 'required|in:registered,unregistered,unknown',
        ])->validate();
        // An explicit exact role in the utterance is not overridden by the model.
        $role = $knownRole === 'ambiguous' ? $data['role'] : $knownRole;
        $facts = [];
        foreach (self::FIELDS as $field) {
            $value = trim($data[$field]);
            // Exclude unsupported inferences (such as silently adding Tamil Nadu based on Erode).
            if ($value !== '' && stripos($utterance, $value) !== false) $facts[$field] = $value;
        }
        if ($data['gst_status'] === 'unregistered'
            && preg_match('/\b(?:unregistered|not\s+(?:gst\s+)?registered)\b/i', $utterance)) {
            $facts['_gst_status'] = 'unregistered';
        } elseif (isset($facts['vat_number'])) {
            $facts['_gst_status'] = 'registered';
        }
        return $this->result($role, $facts, 'model');
    }

    private function result(string $role, array $facts, string $source, ?string $continuation = null): array
    {
        return [
            'role' => $role, 'facts' => $facts, 'source' => $source,
            'continuation' => $continuation,
            'needs_clarification' => in_array($role, ['ambiguous', 'both', 'unsupported'], true),
        ];
    }
}

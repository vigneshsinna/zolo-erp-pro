<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\AiToolManifest;
use App\Models\Accounting\FiscalYear;
use Closure;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Authoritative, read-only ERP Copilot agent.
 */
class ErpCopilot implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable;

    public function __construct(
        protected readonly AiPrincipal $principal,
        protected readonly array $history = [],
        protected readonly ?Closure $budgetStep = null,
        protected readonly array $activeEntities = [],
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $fyInfo = '';
        $fy = FiscalYear::where('company_id', $this->principal->companyId)->find($this->principal->financialYearId);
        if ($fy) {
            $fyInfo = " Active Financial Year: {$fy->name} ({$fy->start_date->toDateString()} to {$fy->end_date->toDateString()}).";
        }
        $today = now()->toDateString();

        $entityContext = '';
        if (!empty($this->activeEntities)) {
            $entityLines = [];
            foreach ($this->activeEntities as $entity) {
                $type = ucfirst($entity['entity_type'] ?? 'Entity');
                $id = $entity['entity_id'] ?? '';
                $asOf = $entity['as_of'] ?? '';
                $entityLines[] = "- {$type} ID {$id}" . ($asOf ? " as of {$asOf}" : '');
            }
            $entityContext = " Recent Verified Entities in this conversation:\n" . implode("\n", $entityLines) . "\n"
                . " When the user asks 'What are the two invoices?', 'Which invoices?', or asks about 'those invoices' or 'this customer', invoke CustomerInvoices with the customer_id and as_of from the verified entities."
                . " When the user asks about payments for an invoice, invoke InvoiceAllocations with the target open_item_id.";
        }

        return 'You are the zoloERP Copilot, an enterprise assistant for zoloERP Pro.'
            . ' Your role is to provide clear, concise financial and inventory insights using ONLY data returned by authorised ERP tools.'
            . " Active Tenancy: Company ID {$this->principal->companyId}, Branch ID {$this->principal->branchId}, Financial Year ID {$this->principal->financialYearId}."
            . " Current Date: {$today}.{$fyInfo}"
            . ($entityContext ? "\n" . $entityContext : '')
            . ' When querying current receivables or stock as of today, omit the as_of date parameter.'
            . ' Never invent totals, balances, product quantities, or customer amounts.'
            . ' State key operational takeaways, unit breakdowns, and business implications.'
            . ' The UI automatically renders verified financial Fact Cards and detailed Invoice tables directly from the ERP data payload, '
            . ' so provide a concise, high-level summary rather than generating huge raw markdown tables.'
            . ' If there are reconciliation warnings, control account variances, or stock disparities, state them clearly.'
            . ' Copilot provides read-only ERP information. Action proposals and execution are unavailable.'
            . ' When the user asks to view or preview a specific invoice, sale, or purchase, invoke document_preview.'
            . ' When asked about company policies, return rules, payment terms, or tax profile, invoke company_knowledge.'
            . ' If the user asks about an unsupported business area where no tool is available, '
            . ' politely explain that the capability has not been enabled in the Copilot yet.'
            . ' Never perform financial mutations or imply that unapproved vouchers have been posted.';
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        return $this->history;
    }

    public function middleware(): array
    {
        return $this->budgetStep === null ? [] : [$this->budgetStep];
    }

    public function maxSteps(): int
    {
        return min(5, max(2, (int) config('erp_ai.max_steps', 5)));
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return app(AiToolManifest::class)->sdkTools($this->principal);
    }
}

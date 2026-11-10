<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Tools\CustomerOutstanding;
use App\Ai\Tools\InventoryStockSummary;
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

        return 'You are the zoloERP Copilot, an enterprise assistant for zoloERP Pro.'
            . ' Your role is to provide clear, concise financial and inventory insights using ONLY data returned by authorised ERP tools.'
            . " Active Tenancy: Company ID {$this->principal->companyId}, Branch ID {$this->principal->branchId}, Financial Year ID {$this->principal->financialYearId}."
            . " Current Date: {$today}.{$fyInfo}"
            . ' When querying current receivables or stock as of today, omit the as_of date parameter.'
            . ' Never invent totals, balances, product quantities, or customer amounts.'
            . ' State key operational takeaways, unit breakdowns, and business implications.'
            . ' The UI automatically renders verified financial Fact Cards and Inventory KPI tables directly from the ERP data payload, '
            . ' so provide a concise, high-level summary rather than generating huge raw markdown tables.'
            . ' If there are reconciliation warnings, control account variances, or stock disparities, state them clearly.'
            . ' If the user asks about an unsupported business area where no tool is available (such as payroll, purchasing bills, or manufacturing schedules), '
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
        $tools = [];

        if ($this->principal->hasTool('receivables.outstanding.v1')) {
            $tools[] = new CustomerOutstanding($this->principal);
        }

        if ($this->principal->hasTool('inventory.stock_summary.v1')) {
            $tools[] = new InventoryStockSummary($this->principal);
        }

        return $tools;
    }
}

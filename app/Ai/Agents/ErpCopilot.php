<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Tools\CustomerOutstanding;
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
        return 'You are the zoloERP Copilot. Your role is to answer questions using ONLY '
            . 'data returned by authorised ERP tools. Never invent totals, balances, or customer amounts. '
            . 'Always state the effective reporting date and branch context. If there are reconciliation warnings '
            . 'or control account variances, make them prominent. Never imply that an unapproved draft '
            . 'has been posted to the ledger.';
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

        return $tools;
    }
}

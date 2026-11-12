<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionPreparationService;
use App\Models\Accounting\ChartOfAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * AI Tool bridging balanced double-entry journal vouchers to the Action Proposal pipeline.
 * Financial accounting entries require explicit balance verification and human review before posting.
 */
final class PrepareJournalEntry implements Tool
{
    public function __construct(
        private readonly AiPrincipal $principal,
        private readonly ?ActionPreparationService $preparationService = null,
    ) {
    }

    public function name(): string
    {
        return 'prepare_journal_entry';
    }

    public function description(): string
    {
        return 'Prepares a draft Double-Entry Manual Journal Entry proposal. Validates that total debits equal total credits exactly and references valid chart of accounts before human review and posting.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'business_date' => $schema->string()
                ->description('Journal posting date in YYYY-MM-DD format. Defaults to today.'),
            'description' => $schema->string()
                ->description('Mandatory journal explanation / narrative (e.g. "Monthly depreciation accrual", "Accrued interest adjustment")'),
            'items' => $schema->array()
                ->items($schema->object([
                    'chart_of_account_id' => $schema->integer()
                        ->description('Chart of Account ID'),
                    'account_query' => $schema->string()
                        ->description('Account name or code to resolve if ID is unknown'),
                    'debit' => $schema->string()
                        ->description('Debit amount with up to 4 decimal places (e.g. "1200.00" or "0.00")'),
                    'credit' => $schema->string()
                        ->description('Credit amount with up to 4 decimal places (e.g. "1200.00" or "0.00")'),
                    'memo' => $schema->string()
                        ->description('Optional line-level memo note'),
                    'partner_type' => $schema->string()
                        ->enum(['customer', 'supplier'])
                        ->description('Optional partner type for sub-ledger booking'),
                    'partner_id' => $schema->integer()
                        ->description('Optional partner ID'),
                ]))
                ->description('Balanced list of journal line items. Debits must equal credits.'),
            'task_id' => $schema->string()
                ->description('Optional workflow task ID when resuming an existing clarification session'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();

        // Authority protection: Never trust caller-supplied authority overrides
        foreach (['company_id', 'branch_id', 'financial_year_id', 'actor_id', 'user_id'] as $field) {
            unset($args[$field]);
        }

        $rawItems = $args['items'] ?? [];
        if (!is_array($rawItems)) {
            $rawItems = [];
        }

        $normalizedItems = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $accountId = $item['chart_of_account_id'] ?? null;
            if (empty($accountId) && !empty($item['account_query'])) {
                $acc = ChartOfAccount::where('company_id', $this->principal->companyId)
                    ->where(function ($q) use ($item) {
                        $term = trim((string) $item['account_query']);
                        $q->where('name', 'like', "%{$term}%")
                          ->orWhere('code', 'like', "%{$term}%");
                    })
                    ->first();
                if ($acc) {
                    $accountId = $acc->id;
                }
            }

            $debit = isset($item['debit']) && (float) $item['debit'] > 0
                ? number_format((float) $item['debit'], 4, '.', '')
                : null;
            $credit = isset($item['credit']) && (float) $item['credit'] > 0
                ? number_format((float) $item['credit'], 4, '.', '')
                : null;

            $row = [
                'chart_of_account_id' => $accountId !== null ? (int) $accountId : 0,
            ];
            if ($debit !== null) {
                $row['debit'] = $debit;
            }
            if ($credit !== null) {
                $row['credit'] = $credit;
            }
            if (!empty($item['memo'])) {
                $row['memo'] = (string) $item['memo'];
            }
            if (!empty($item['partner_type'])) {
                $row['partner_type'] = (string) $item['partner_type'];
            }
            if (!empty($item['partner_id'])) {
                $row['partner_id'] = (int) $item['partner_id'];
            }

            $normalizedItems[] = $row;
        }

        $inputs = [
            'business_date' => !empty($args['business_date']) ? (string) $args['business_date'] : now()->toDateString(),
            'description' => !empty($args['description']) ? (string) $args['description'] : 'Manual journal entry via AI Copilot',
            'items' => $normalizedItems,
        ];

        try {
            $service = $this->preparationService ?? app(ActionPreparationService::class);
            $result = $service->prepare(
                $this->principal,
                'journal_entry.create.v1',
                $inputs,
                !empty($args['task_id']) ? (string) $args['task_id'] : null
            );

            return json_encode($result, JSON_THROW_ON_ERROR);
        } catch (ValidationException $e) {
            return json_encode([
                'status' => 'needs_input',
                'missing_field' => 'validation',
                'errors' => $e->errors(),
                'message' => implode(' ', $e->validator->errors()->all()),
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return json_encode([
                'status' => 'error',
                'error_code' => 'AI_JOURNAL_ENTRY_PREPARATION_FAILED',
                'message' => 'Could not generate journal entry proposal: ' . $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }
}

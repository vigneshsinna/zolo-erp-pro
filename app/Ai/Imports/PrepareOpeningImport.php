<?php

declare(strict_types=1);

namespace App\Ai\Imports;

use App\Ai\DTOs\AiPrincipal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/** Typed opening-source ingress into the existing reviewed import service. Never commits a batch. */
final readonly class PrepareOpeningImport implements Tool
{
    public function __construct(private AiPrincipal $principal) {}
    public function name(): string { return 'prepare_opening_import'; }
    public function description(): string
    {
        return 'Stages and dry-runs opening stock or opening financial balances for a clean company, then prepares a human-reviewed proposal. Never posts or commits. source_json must contain batch_key (stable unique source reference), source_name, business_date (YYYY-MM-DD), mappings [{entity:product|warehouse|customer|supplier|account,source_key,target_id}], rows [{source_key,kind:stock|receivable|payable|journal,payload}], and expected {stock_qty,stock_value,ar,ap} as decimal strings. For an already scanned opening_import spreadsheet, pass document_id and omit rows; spreadsheet columns are source_key,kind,payload (JSON object). Stock payload: product_key,warehouse_key,qty,unit_cost,offset_account_key. Receivable/payable payload: party_key,amount,offset_account_key,due_date,document_date. Journal payload: items [{account_key,debit,credit}]. Use explicit reviewed company record IDs in mappings; do not invent or infer ambiguous mappings. Never include ownership or authority fields.';
    }
    public function schema(JsonSchema $schema): array
    {
        $keys = array_values(array_filter(['setup.opening_stock.create.v1', 'setup.opening_balance.create.v1'],
            fn (string $key): bool => $this->principal->hasTool($key)));
        return ['action_key' => $schema->string()->enum($keys)->required(),
            'document_id' => $schema->string()->description('Optional ID of an already scanned opening_import CSV or XLSX.'),
            'source_json' => $schema->string()->max(30000)->required()->description('Reviewed opening source JSON with explicit mappings, rows and expected totals.')];
    }
    public function handle(Request $request): string
    {
        try {
            $args = $request->all();
            $json = (string) ($args['source_json'] ?? '');
            if (strlen($json) > 30000) throw new \InvalidArgumentException('Opening source exceeds its input limit.');
            $source = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($source) || array_is_list($source)) throw new \InvalidArgumentException('Opening source must be an object.');
            $planner = app(OpeningImportPlanner::class);
            $result = !empty($args['document_id'])
                ? $planner->prepareDocument($this->principal, (string) $args['action_key'], (string) $args['document_id'], $source)
                : $planner->prepare($this->principal, (string) ($args['action_key'] ?? ''), $source);
            return json_encode($result, JSON_THROW_ON_ERROR);
        } catch (ValidationException $error) {
            return json_encode(['status' => 'needs_input', 'errors' => $error->errors(),
                'message' => 'Opening source requires correction before a proposal can be prepared.'], JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return json_encode(['status' => 'error', 'error_code' => 'AI_OPENING_PREPARATION_FAILED',
                'message' => 'Opening source could not be accepted. Check your access and the reviewed opening schema.'], JSON_THROW_ON_ERROR);
        }
    }
}

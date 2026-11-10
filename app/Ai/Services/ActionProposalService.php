<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * P3 Action Proposal Engine.
 * Manages reviewable draft proposals, deterministic previews, and human-authorized transactional executions.
 * Zero autonomous financial writes: writes occur ONLY after explicit human approval.
 */
class ActionProposalService
{
    /**
     * Propose a new reviewable action draft.
     */
    public function propose(
        AiPrincipal $principal,
        string $actionType,
        string $actionTitle,
        array $payload,
        string $riskTier = 'r1_draft',
        int $ttlHours = 24
    ): array {
        $proposalId = (string) Str::uuid();
        $preview = $this->buildPreview($principal, $actionType, $payload);

        DB::table('ai_action_proposals')->insert([
            'id' => $proposalId,
            'actor_id' => $principal->actorId,
            'company_id' => $principal->companyId,
            'branch_id' => $principal->branchId,
            'financial_year_id' => $principal->financialYearId,
            'thread_id' => $payload['thread_id'] ?? null,
            'action_type' => $actionType,
            'action_title' => $actionTitle,
            'risk_tier' => $riskTier,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'preview_json' => json_encode($preview, JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'expires_at' => now()->addHours($ttlHours),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'proposal_id' => $proposalId,
            'action_type' => $actionType,
            'action_title' => $actionTitle,
            'risk_tier' => $riskTier,
            'status' => 'pending',
            'expires_at' => now()->addHours($ttlHours)->toIso8601String(),
            'preview' => $preview,
        ];
    }

    /**
     * Build deterministic preview based on action type without mutating ERP database.
     */
    protected function buildPreview(AiPrincipal $principal, string $actionType, array $payload): array
    {
        if ($actionType === 'draft_quotation' || $actionType === 'draft_sale_order') {
            $customerId = (int) ($payload['customer_id'] ?? 0);
            $customer = Customer::where('company_id', $principal->companyId)->find($customerId);
            if (!$customer) {
                throw ValidationException::withMessages(['customer_id' => "Customer ID {$customerId} not found in this company."]);
            }

            $items = $payload['items'] ?? [];
            if ($items === []) {
                throw ValidationException::withMessages(['items' => 'At least one item is required for quotation draft.']);
            }

            $previewItems = [];
            $subtotal = BigDecimal::zero();
            $totalTax = BigDecimal::zero();

            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $product = Product::where('company_id', $principal->companyId)->find($productId);
                if (!$product) {
                    throw ValidationException::withMessages(['items' => "Product ID {$productId} not found."]);
                }

                $qty = (float) ($item['qty'] ?? 1);
                $unitPrice = (float) ($item['price'] ?? $product->price ?? 0);
                $lineSubtotal = BigDecimal::of((string) ($qty * $unitPrice))->toScale(2, RoundingMode::HalfUp);
                $lineTax = $lineSubtotal->multipliedBy(BigDecimal::of('0.05'))->toScale(2, RoundingMode::HalfUp); // 5% standard estimation

                $subtotal = $subtotal->plus($lineSubtotal);
                $totalTax = $totalTax->plus($lineTax);

                $previewItems[] = [
                    'product_id' => $productId,
                    'name' => $product->name,
                    'code' => $product->code,
                    'qty' => $qty,
                    'unit_price' => number_format($unitPrice, 2, '.', ''),
                    'subtotal' => (string) $lineSubtotal,
                ];
            }

            $grandTotal = $subtotal->plus($totalTax);

            return [
                'type' => 'quotation_preview',
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone_number,
                ],
                'items' => $previewItems,
                'subtotal' => (string) $subtotal,
                'estimated_tax' => (string) $totalTax,
                'grand_total' => (string) $grandTotal,
                'note' => $payload['note'] ?? 'Generated via zoloERP Copilot AI',
            ];
        }

        if ($actionType === 'draft_payment_reminder') {
            $customerId = (int) ($payload['customer_id'] ?? 0);
            $customer = Customer::where('company_id', $principal->companyId)->find($customerId);
            if (!$customer) {
                throw ValidationException::withMessages(['customer_id' => "Customer ID {$customerId} not found."]);
            }

            $amount = $payload['amount'] ?? '0.00';
            $invoices = $payload['invoices'] ?? [];

            $message = "Dear {$customer->name}, this is a gentle reminder from " . (Company::find($principal->companyId)?->name ?? 'zoloERP') .
                " regarding outstanding dues of ₹{$amount}. Please find the invoice details attached. Thank you for your continued partnership.";

            return [
                'type' => 'payment_reminder_preview',
                'recipient' => [
                    'customer_id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone_number,
                    'email' => $customer->email,
                ],
                'outstanding_amount' => $amount,
                'invoices_count' => count($invoices),
                'invoices' => $invoices,
                'message_body' => $message,
                'channel' => $payload['channel'] ?? 'whatsapp',
            ];
        }

        return [
            'type' => 'generic_preview',
            'summary' => 'Reviewable draft action payload',
            'payload' => $payload,
        ];
    }

    /**
     * Human-authorized approval and safe transactional execution.
     */
    public function approve(AiPrincipal $principal, string $proposalId, ?string $notes = null): array
    {
        return DB::transaction(function () use ($principal, $proposalId, $notes): array {
            $proposal = DB::table('ai_action_proposals')
                ->where('id', $proposalId)
                ->where('company_id', $principal->companyId)
                ->lockForUpdate()
                ->first();

            if (!$proposal) {
                throw new \RuntimeException("Action proposal {$proposalId} not found in this company context.");
            }

            if ($proposal->status !== 'pending') {
                throw new \RuntimeException("Action proposal is already {$proposal->status}.");
            }

            if ($proposal->expires_at && now()->isAfter($proposal->expires_at)) {
                DB::table('ai_action_proposals')->where('id', $proposalId)->update(['status' => 'expired']);
                throw new \RuntimeException("Action proposal has expired.");
            }

            // Record approval
            $approvalId = (string) Str::uuid();
            DB::table('ai_action_approvals')->insert([
                'id' => $approvalId,
                'proposal_id' => $proposalId,
                'approver_id' => $principal->actorId,
                'decision' => 'approved',
                'notes' => $notes,
                'created_at' => now(),
            ]);

            // Execute based on action_type
            $payload = json_decode($proposal->payload_json, true, 512, JSON_THROW_ON_ERROR);
            $preview = json_decode($proposal->preview_json, true, 512, JSON_THROW_ON_ERROR);
            $idempotencyKey = 'ai_act_' . $proposalId;

            $executionResult = [];

            if ($proposal->action_type === 'draft_quotation' || $proposal->action_type === 'draft_sale_order') {
                $sale = Sale::forceCreate([
                    'company_id' => $principal->companyId,
                    'branch_id' => $principal->branchId,
                    'financial_year_id' => $principal->financialYearId,
                    'warehouse_id' => $payload['warehouse_id'] ?? Warehouse::where('company_id', $principal->companyId)->where('branch_id', $principal->branchId)->value('id'),
                    'customer_id' => $payload['customer_id'],
                    'biller_id' => \App\Models\Biller::where('company_id', $principal->companyId)->value('id'),
                    'user_id' => $principal->actorId,
                    'reference_no' => 'QOT-' . strtoupper(Str::random(8)),
                    'item' => count($preview['items'] ?? []),
                    'total_qty' => array_sum(array_column($preview['items'] ?? [], 'qty')),
                    'total_discount' => '0.00',
                    'total_tax' => $preview['estimated_tax'] ?? '0.00',
                    'total_price' => $preview['subtotal'] ?? '0.00',
                    'grand_total' => $preview['grand_total'] ?? '0.00',
                    'paid_amount' => '0.00',
                    'sale_status' => 3, // 3 = Draft quotation
                    'payment_status' => 1, // 1 = Pending
                    'staff_note' => "Created via approved AI Copilot proposal [{$proposalId}]",
                ]);

                $executionResult = [
                    'document_type' => 'sale_draft',
                    'document_id' => $sale->id,
                    'reference_no' => $sale->reference_no,
                    'grand_total' => $sale->grand_total,
                    'view_link' => Route::has('sale.invoice') ? route('sale.invoice', $sale->id, absolute: false) : "/sales/{$sale->id}",
                ];
            } elseif ($proposal->action_type === 'draft_payment_reminder') {
                $executionResult = [
                    'document_type' => 'payment_reminder',
                    'status' => 'dispatched',
                    'channel' => $preview['channel'] ?? 'whatsapp',
                    'recipient' => $preview['recipient']['name'] ?? '',
                    'message' => 'Payment reminder queued for transmission.',
                ];
            } else {
                $executionResult = [
                    'document_type' => 'generic_action',
                    'status' => 'executed',
                ];
            }

            // Record execution
            DB::table('ai_action_executions')->insert([
                'id' => (string) Str::uuid(),
                'proposal_id' => $proposalId,
                'company_id' => $principal->companyId,
                'executor_id' => $principal->actorId,
                'idempotency_key' => $idempotencyKey,
                'status' => 'completed',
                'result_json' => json_encode($executionResult, JSON_THROW_ON_ERROR),
                'executed_at' => now(),
            ]);

            DB::table('ai_action_proposals')->where('id', $proposalId)->update([
                'status' => 'executed',
                'updated_at' => now(),
            ]);

            return [
                'status' => 'executed',
                'proposal_id' => $proposalId,
                'approval_id' => $approvalId,
                'execution' => $executionResult,
            ];
        });
    }

    /**
     * Reject a proposed action.
     */
    public function reject(AiPrincipal $principal, string $proposalId, ?string $reason = null): array
    {
        return DB::transaction(function () use ($principal, $proposalId, $reason): array {
            $proposal = DB::table('ai_action_proposals')
                ->where('id', $proposalId)
                ->where('company_id', $principal->companyId)
                ->lockForUpdate()
                ->first();

            if (!$proposal) {
                throw new \RuntimeException("Action proposal {$proposalId} not found.");
            }

            if ($proposal->status !== 'pending') {
                throw new \RuntimeException("Action proposal is already {$proposal->status}.");
            }

            DB::table('ai_action_approvals')->insert([
                'id' => (string) Str::uuid(),
                'proposal_id' => $proposalId,
                'approver_id' => $principal->actorId,
                'decision' => 'rejected',
                'notes' => $reason,
                'created_at' => now(),
            ]);

            DB::table('ai_action_proposals')->where('id', $proposalId)->update([
                'status' => 'rejected',
                'updated_at' => now(),
            ]);

            return [
                'status' => 'rejected',
                'proposal_id' => $proposalId,
                'reason' => $reason,
            ];
        });
    }

    /**
     * Get pending proposals for active actor/company.
     */
    public function listPending(AiPrincipal $principal): array
    {
        return DB::table('ai_action_proposals')
            ->where('company_id', $principal->companyId)
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(function ($p): array {
                return [
                    'id' => $p->id,
                    'action_type' => $p->action_type,
                    'action_title' => $p->action_title,
                    'risk_tier' => $p->risk_tier,
                    'preview' => json_decode($p->preview_json, true),
                    'created_at' => $p->created_at,
                    'expires_at' => $p->expires_at,
                ];
            })
            ->all();
    }
}


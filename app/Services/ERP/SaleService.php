<?php

namespace App\Services\ERP;

use App\Models\Sale;
use App\Models\Customer;
use App\Models\Biller;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\DocumentNumberService;
use App\Models\Product_Sale;
use App\Models\Product;
use App\Models\Product_Warehouse;
use App\Models\Payment;
use App\Services\Accounting\AccountingService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\StockLine;
use App\Services\Inventory\StockMovementCommand;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;
use InvalidArgumentException;

class SaleService
{
    protected AccountingService $accountingService;

    public function __construct(AccountingService $accountingService)
    {
        $this->accountingService = $accountingService;
    }

    /**
     * Create a complete ERP Sale, update inventory stock, and post double-entry journal entries.
     *
     * @param array $data Sale payload:
     *   - customer_id: int
     *   - warehouse_id: int
     *   - biller_id: int (optional)
     *   - items: array of [product_id, qty, net_unit_price, discount, tax_rate, tax, total]
     *   - order_tax_rate: float
     *   - order_tax: float
     *   - order_discount: float
     *   - shipping_cost: float
     *   - grand_total: float
     *   - paid_amount: float (optional)
     *   - paying_method: string (Cash, Bank, Cheque, etc.)
     *   - sale_status: int (1 = completed, 2 = pending)
     *   - payment_status: int (4 = paid, 2 = due, 3 = partial)
     *   - sale_note: string
     * @param int|null $userId
     * @return Sale
     */
    public function createSale(array $data, ?int $userId = null, ?CompanyContext $context = null, bool $deferPosting = false): Sale
    {
        $userId = $userId ?: auth()->id();
        $guard = app(CompanyWriteGuard::class);
        $context = $guard->context($context, $userId);

        if (empty($data['items']) || !is_array($data['items'])) {
            throw new InvalidArgumentException("Sale must contain at least one product item.");
        }

        return DB::transaction(function () use ($data, $userId, $context, $guard, $deferPosting) {
            $date = $guard->begin($context, $guard->businessDate($data));
            $guard->rejectUnscopedReferences($data);
            $guard->owned(Customer::class, $data['customer_id'] ?? null, $context, 'customer_id');
            $guard->warehouse($data['warehouse_id'] ?? null, $context, $userId);
            $data['biller_id'] ??= Biller::forCompany($context)->orderBy('id')->value('id');
            $guard->owned(Biller::class, $data['biller_id'], $context, 'biller_id');
            if (!empty($data['sale_type_id'])) {
                $guard->owned(\App\Models\SaleType::class, $data['sale_type_id'], $context, 'sale_type_id');
            }
            if (!empty($data['agent_id'])) {
                $guard->owned(\App\Models\Agent::class, $data['agent_id'], $context, 'agent_id');
            }
            foreach ($data['items'] as &$line) {
                if (!is_numeric($line['qty'] ?? null) || !is_finite((float) $line['qty']) || (float) $line['qty'] <= 0
                    || !is_numeric($line['net_unit_price'] ?? null) || !is_finite((float) $line['net_unit_price']) || (float) $line['net_unit_price'] < 0) {
                    throw new InvalidArgumentException('Sale quantity must be positive and unit price must be nonnegative.');
                }
            }
            unset($line);
            $guard->products($data['items'], $context, 'sale_unit_id');
            $numbers = app(DocumentNumberService::class);
            $reservation = $numbers->reserve('sale', $context, $date, $userId);
            $referenceNo = $reservation->formatted_number;

            $itemQty = 0;
            $totalCost = 0.0;
            $totalQty = 0.0;
            $totalPrice = 0.0;
            $totalTax = 0.0;
            $totalDiscount = 0.0;

            foreach ($data['items'] as $item) {
                $qty = (float) $item['qty'];
                $itemQty += 1;
                $totalQty += $qty;
                $totalPrice += (float) ($item['total'] ?? ($qty * (float)$item['net_unit_price']));
                $totalTax += (float) ($item['tax'] ?? 0);
                $totalDiscount += (float) ($item['discount'] ?? 0);

                // Shared posting uses the stock movement's valuation for COGS.
                if (!$deferPosting) {
                    $product = Product::forCompany($context)->findOrFail($item['product_id']);
                    $totalCost += (float) ($product->cost ?? 0) * $qty;
                }
            }

            $orderTax = (float) ($data['order_tax'] ?? 0);
            $orderDiscount = (float) ($data['order_discount'] ?? 0);
            $shippingCost = (float) ($data['shipping_cost'] ?? 0);
            $grandTotal = (float) ($data['grand_total'] ?? ($totalPrice + $orderTax + $shippingCost - $orderDiscount));
            $paidAmount = (float) ($data['paid_amount'] ?? 0);

            $paymentStatus = 2; // Due
            if ($paidAmount >= $grandTotal) {
                $paymentStatus = 4; // Paid
            } elseif ($paidAmount > 0) {
                $paymentStatus = 3; // Partial
            }

            if (!is_finite($grandTotal) || $grandTotal < 0 || !is_finite($paidAmount) || $paidAmount < 0 || $paidAmount > $grandTotal) {
                throw new InvalidArgumentException('Payment must be within the sale total.');
            }
            if ($paidAmount > 0) {
                $data['account_id'] = $guard->paymentAccount($data, $context);
            }
            $saleFill = [
                'company_id' => $context->companyId,
                'created_at' => $date,
                'reference_no' => $referenceNo,
                'user_id' => $userId,
                'cash_register_id' => $data['cash_register_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $data['warehouse_id'],
                'biller_id' => $data['biller_id'] ?? 1,
                'item' => $itemQty,
                'total_qty' => $totalQty,
                'total_discount' => $totalDiscount,
                'total_tax' => $totalTax,
                'total_price' => $totalPrice,
                'order_tax_rate' => $data['order_tax_rate'] ?? 0,
                'order_tax' => $orderTax,
                'order_discount_type' => $data['order_discount_type'] ?? 'Flat',
                'order_discount_value' => $data['order_discount_value'] ?? $orderDiscount,
                'order_discount' => $orderDiscount,
                'coupon_id' => $data['coupon_id'] ?? null,
                'coupon_discount' => $data['coupon_discount'] ?? null,
                'shipping_cost' => $shippingCost,
                'grand_total' => $grandTotal,
                'currency_id' => $data['currency_id'] ?? null,
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'sale_status' => (int) ($data['sale_status'] ?? 1),
                'payment_status' => $paymentStatus,
                'paid_amount' => $paidAmount,
                'sale_note' => $data['sale_note'] ?? null,
                'staff_note' => $data['staff_note'] ?? null,
            ];

            if (\Illuminate\Support\Facades\Schema::hasColumn('sales', 'sale_type_id')) {
                $saleFill = array_merge($saleFill, [
                    'sale_type_id' => $data['sale_type_id'] ?? null,
                    'lot_number' => $data['lot_number'] ?? null,
                    'agent_id' => $data['agent_id'] ?? null,
                    'bale_no' => $data['bale_no'] ?? null,
                    'no_of_bales' => isset($data['no_of_bales']) ? (int) $data['no_of_bales'] : null,
                    'lr_no' => $data['lr_no'] ?? null,
                    'lr_date' => $data['lr_date'] ?? null,
                    'transport_name' => $data['transport_name'] ?? null,
                    'station_to' => $data['station_to'] ?? null,
                    'order_no' => $data['order_no'] ?? null,
                    'credit_days' => isset($data['credit_days']) ? (int) $data['credit_days'] : null,
                    'salesman_id' => $data['salesman_id'] ?? null,
                ]);
            }

            $sale = (new Sale)->forceFill($saleFill);
            $sale->save();
            $numbers->assign($reservation, $sale);

            // Save line items and decrement warehouse inventory
            foreach ($data['items'] as $item) {
                $productId = (int) $item['product_id'];
                $qty = (float) $item['qty'];
                $unitPrice = (float) $item['net_unit_price'];

                $batchId = $item['product_batch_id'] ?? null;
                $batchNo = $item['batch']['batch_no'] ?? ($item['batch_no'] ?? ($data['lot_number'] ?? null));
                if (!$batchId && !empty($batchNo)) {
                    $batch = \App\Models\ProductBatch::firstOrCreate([
                        'company_id' => $context->companyId,
                        'product_id' => $productId,
                        'batch_no' => $batchNo,
                    ], [
                        'expired_date' => $item['batch']['expired_date'] ?? ($item['expired_date'] ?? null),
                        'qty' => 0,
                    ]);
                    $batchId = $batch->id;
                }

                (new Product_Sale)->forceFill([
                    'company_id' => $context->companyId,
                    'sale_id' => $sale->id,
                    'product_id' => $productId,
                    'product_batch_id' => $batchId,
                    'variant_id' => $item['variant_id'] ?? null,
                    'imei_number' => $item['imei_number'] ?? null,
                    'qty' => $qty,
                    'sale_unit_id' => $item['sale_unit_id'] ?? 1,
                    'net_unit_price' => $unitPrice,
                    'discount' => (float) ($item['discount'] ?? 0),
                    'tax_rate' => (float) ($item['tax_rate'] ?? 0),
                    'tax' => (float) ($item['tax'] ?? 0),
                    'total' => (float) ($item['total'] ?? ($qty * $unitPrice)),
                    'sph' => $item['sph'] ?? null,
                    'cyl' => $item['cyl'] ?? null,
                    'axis' => $item['axis'] ?? null,
                    'base' => $item['base'] ?? null,
                    'addition' => $item['addition'] ?? null,
                    'lr' => $item['lr'] ?? null,
                    'dia' => $item['dia'] ?? null,
                    'prescription_json' => $item['prescription_json'] ?? null,
                ] + ($deferPosting ? ['stock_details_json' => $item['stock_details_json'] ?? []] : [])
                    + ($deferPosting && isset($item['_tax_snapshot']) ? ['tax_snapshot_json' => $item['_tax_snapshot']] : []))->save();
            }

            // A completed sale issues stock through the ledger; COGS is the cost persisted on the movement.
            if (!$deferPosting && ($data['sale_status'] ?? 1) == 1) {
                $movement = app(InventoryMovementService::class)->issue(new StockMovementCommand(
                    date: $date,
                    lines: array_map(fn ($item) => StockLine::fromArray(['uom_id' => $item['sale_unit_id'] ?? null] + $item), $data['items']),
                    warehouseId: (int) $data['warehouse_id'],
                    sourceType: 'sale',
                    sourceId: $sale->id,
                    sourceNo: $referenceNo,
                    idempotencyKey: 'sale:'.$sale->id,
                    userId: $userId,
                    context: $context,
                ));
                $totalCost = -(float) $movement->lines->sum('value');
            }

            // Create Payment record if paid amount > 0
            if ($paidAmount > 0) {
                $paymentReservation = $numbers->reserve('sale_payment', $context, $date, $userId);
                $payment = (new Payment)->forceFill([
                    'company_id' => $context->companyId,
                    'created_at' => $date,
                    'payment_at' => $date,
                    'payment_reference' => $paymentReservation->formatted_number,
                    'user_id' => $userId,
                    'sale_id' => $sale->id,
                    'account_id' => $data['account_id'] ?? 1,
                    'amount' => $paidAmount,
                    'change' => (float) ($data['paying_amount'] ?? $paidAmount) - $paidAmount,
                    'paying_method' => $data['paying_method'] ?? 'Cash',
                    'payment_note' => $data['payment_note'] ?? null,
                ]);
                $payment->save();
                $numbers->assign($paymentReservation, $payment);
            }

            // ATOMIC DOUBLE-ENTRY JOURNAL POSTING
            // Dr. Cash/Bank + Dr. AR + Dr. Discount = Cr. Revenue + Cr. Tax + Cr. Shipping
            // Dr. COGS = Cr. Inventory Asset
            if (!$deferPosting) {
                $this->accountingService->postSaleJournal($sale, $totalCost, $context, $userId);
            }

            return $sale->load(['customer', 'warehouse', 'productSales']);
        });
    }

    /**
     * Add a payment against an existing sale.
     */
    public function addPayment(Sale $sale, array $paymentData, ?int $userId = null, ?CompanyContext $context = null): Payment
    {
        return app(PaymentService::class)->addPayment($sale, $paymentData, $userId, $context);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBatch extends Model
{
    protected $fillable = ["product_id", "batch_no", "expired_date", "qty"];

    /** The caller holds the company write lock and has validated the product and batch data. */
    public static function firstOrCreateOwned(int $companyId, int $productId, string $number, array $details): self
    {
        $identity = ['company_id' => $companyId, 'product_id' => $productId, 'batch_no' => $number];
        $batch = self::query()->where($identity)->first();

        return $batch ?? self::query()->forceCreate($identity + array_intersect_key($details, [
            'expired_date' => true, 'mfg_date' => true, 'mrp' => true,
        ]) + ['qty' => 0]);
    }
}

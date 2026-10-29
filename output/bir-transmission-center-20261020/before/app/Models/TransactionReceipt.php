<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionReceipt extends Model
{
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'items' => 'array',
        'gross_amount' => 'float',
        'discount_amount' => 'float',
        'vatable_sales' => 'float',
        'vat_exempt_sales' => 'float',
        'zero_rated_sales' => 'float',
        'vat_amount' => 'float',
        'total_due' => 'float',
        'issued_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (TransactionReceipt $receipt): void {
            $receipt->id ??= (string) Str::uuid();
        });

        static::saving(function (TransactionReceipt $receipt): void {
            if (
                $receipt->merchant_id
                && (trim((string) $receipt->rdo_code) === '' || trim((string) $receipt->rdo_name) === '')
            ) {
                $merchant = DB::table('merchants')
                    ->where('id', $receipt->merchant_id)
                    ->select(['rdo_code', 'rdo_name'])
                    ->first();

                $receipt->rdo_code = trim((string) $receipt->rdo_code) !== ''
                    ? $receipt->rdo_code
                    : ($merchant?->rdo_code);

                $receipt->rdo_name = trim((string) $receipt->rdo_name) !== ''
                    ? $receipt->rdo_name
                    : ($merchant?->rdo_name);
            }
        });
    }
}

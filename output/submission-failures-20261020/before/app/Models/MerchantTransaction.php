<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MerchantTransaction extends Model
{
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'amount' => 'float',
        'vat_amount' => 'float',
        'vatable_sales' => 'float',
        'net_amount' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (MerchantTransaction $transaction): void {
            $transaction->id ??= (string) Str::uuid();
        });

        static::saving(function (MerchantTransaction $transaction): void {
            if (
                $transaction->merchant_id
                && (trim((string) $transaction->rdo_code) === '' || trim((string) $transaction->rdo_name) === '')
            ) {
                $merchant = DB::table('merchants')
                    ->where('id', $transaction->merchant_id)
                    ->select(['rdo_code', 'rdo_name'])
                    ->first();

                $transaction->rdo_code = trim((string) $transaction->rdo_code) !== ''
                    ? $transaction->rdo_code
                    : ($merchant?->rdo_code);

                $transaction->rdo_name = trim((string) $transaction->rdo_name) !== ''
                    ? $transaction->rdo_name
                    : ($merchant?->rdo_name);
            }
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Merchant extends Model
{
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'vat_registered' => 'boolean',
        'bir_registration_date' => 'date:Y-m-d',
        'last_audit_date' => 'date:Y-m-d',
        'next_audit_date' => 'date:Y-m-d',
        'registration_date' => 'date:Y-m-d',
        'compliance_score' => 'integer',
        'employee_count' => 'integer',
        'branch_count' => 'integer',
        'monthly_revenue' => 'float',
        'annual_revenue' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (Merchant $merchant): void {
            $merchant->id ??= (string) Str::uuid();
        });
    }
}

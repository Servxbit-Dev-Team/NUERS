<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ClientAccount extends Model
{
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'wallet_balance' => 'float',
        'mfa_enabled' => 'boolean',
        'notification_preferences' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (ClientAccount $account): void {
            $account->id ??= (string) Str::uuid();
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SystemNotification extends Model
{
    protected $table = 'notifications';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'is_read' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (SystemNotification $notification): void {
            $notification->id ??= (string) Str::uuid();
        });
    }
}

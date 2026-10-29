<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    protected $guarded = [];
    protected $primaryKey = 'id';
    public $incrementing = false;

    protected $casts = [
        'id' => 'integer',
    ];
}

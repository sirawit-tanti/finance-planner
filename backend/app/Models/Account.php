<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use Syncable;

    protected $fillable = ['name', 'type', 'opening_balance', 'opening_date', 'is_active'];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'opening_date' => 'immutable_date',
        'is_active' => 'boolean',
    ];
}
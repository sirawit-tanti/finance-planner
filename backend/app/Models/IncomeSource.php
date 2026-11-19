<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class IncomeSource extends Model
{
    use Syncable;

    protected $fillable = [
        'name', 'amount', 'frequency', 'pay_day', 'weekday',
        'start_date', 'end_date', 'shift_rule', 'account_id', 'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'pay_day' => 'integer',
        'weekday' => 'integer',
        'start_date' => 'immutable_date',
        'end_date' => 'immutable_date',
        'is_active' => 'boolean',
    ];
}
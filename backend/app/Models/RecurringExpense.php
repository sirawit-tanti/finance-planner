<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class RecurringExpense extends Model
{
    use Syncable;

    protected $fillable = [
        'name', 'category_id', 'amount', 'is_variable', 'frequency', 'due_day', 'weekday',
        'start_date', 'end_date', 'is_active', 'payment_method', 'account_id',
        'credit_card_id', 'shift_rule', 'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_variable' => 'boolean',
        'due_day' => 'integer',
        'weekday' => 'integer',
        'start_date' => 'immutable_date',
        'end_date' => 'immutable_date',
        'is_active' => 'boolean',
    ];
}
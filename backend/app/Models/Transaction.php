<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use Syncable;

    protected $fillable = [
        'type', 'txn_date', 'name', 'category_id', 'amount', 'note', 'payment_method',
        'account_id', 'credit_card_id', 'statement_id', 'installment_plan_id',
        'recurring_expense_id', 'income_source_id', 'occurrence_date',
    ];

    protected $casts = [
        'txn_date' => 'immutable_date',
        'occurrence_date' => 'immutable_date',
        'amount' => 'decimal:2',
    ];
}
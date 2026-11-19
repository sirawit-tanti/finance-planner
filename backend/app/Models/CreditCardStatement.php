<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCardStatement extends Model
{
    use Syncable;

    protected $fillable = [
        'credit_card_id', 'cycle_start', 'statement_date', 'due_date',
        'purchases_total', 'installments_total', 'interest_charged', 'fees_charged',
        'refunds_total', 'carried_over', 'statement_amount', 'min_payment',
        'paid_amount', 'status',
    ];

    protected $casts = [
        'cycle_start' => 'immutable_date',
        'statement_date' => 'immutable_date',
        'due_date' => 'immutable_date',
        'purchases_total' => 'decimal:2',
        'installments_total' => 'decimal:2',
        'interest_charged' => 'decimal:2',
        'fees_charged' => 'decimal:2',
        'refunds_total' => 'decimal:2',
        'carried_over' => 'decimal:2',
        'statement_amount' => 'decimal:2',
        'min_payment' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class, 'credit_card_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CreditCardPayment::class, 'statement_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'statement_id');
    }
}
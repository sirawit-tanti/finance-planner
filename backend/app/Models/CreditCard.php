<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCard extends Model
{
    use Syncable;

    protected $fillable = [
        'name', 'bank', 'credit_limit', 'statement_day', 'due_day',
        'pay_account_id', 'opening_balance', 'status',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'statement_day' => 'integer',
        'due_day' => 'integer',
    ];

    public function statements(): HasMany
    {
        return $this->hasMany(CreditCardStatement::class);
    }

    public function payAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'pay_account_id');
    }
}
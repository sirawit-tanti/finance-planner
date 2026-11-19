<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditCardPayment extends Model
{
    use Syncable;

    protected $fillable = ['statement_id', 'account_id', 'payment_date', 'amount', 'kind', 'note'];

    protected $casts = [
        'payment_date' => 'immutable_date',
        'amount' => 'decimal:2',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CreditCardStatement::class, 'statement_id');
    }
}
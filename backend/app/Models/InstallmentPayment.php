<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentPayment extends Model
{
    use Syncable;

    protected $fillable = [
        'plan_id', 'schedule_item_id', 'payment_date', 'amount', 'kind',
        'early_fee', 'interest_discount', 'note',
    ];

    protected $casts = [
        'payment_date' => 'immutable_date',
        'amount' => 'decimal:2',
        'early_fee' => 'decimal:2',
        'interest_discount' => 'decimal:2',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'plan_id');
    }
}
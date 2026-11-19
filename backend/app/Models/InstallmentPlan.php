<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallmentPlan extends Model
{
    use Syncable;

    protected $fillable = [
        'name', 'note', 'interest_method', 'annual_rate_percent', 'price', 'down_payment',
        'financed_amount', 'term_count', 'frequency', 'start_date', 'first_due_date',
        'fee_amount', 'fee_mode', 'funding_type', 'credit_card_id', 'account_id',
        'total_interest', 'total_payable', 'status',
    ];

    protected $casts = [
        'annual_rate_percent' => 'decimal:6',
        'price' => 'decimal:2',
        'down_payment' => 'decimal:2',
        'financed_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'total_interest' => 'decimal:2',
        'total_payable' => 'decimal:2',
        'term_count' => 'integer',
        'start_date' => 'immutable_date',
        'first_due_date' => 'immutable_date',
    ];

    public function scheduleItems(): HasMany
    {
        return $this->hasMany(InstallmentScheduleItem::class, 'plan_id')->orderBy('installment_no');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InstallmentPayment::class, 'plan_id');
    }
}
<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentScheduleItem extends Model
{
    use Syncable;

    protected $fillable = [
        'plan_id', 'installment_no', 'due_date', 'principal_part', 'interest_part',
        'fee_part', 'total_due', 'remaining_principal_after', 'status', 'paid_date', 'statement_id',
    ];

    protected $casts = [
        'installment_no' => 'integer',
        'due_date' => 'immutable_date',
        'paid_date' => 'immutable_date',
        'principal_part' => 'decimal:2',
        'interest_part' => 'decimal:2',
        'fee_part' => 'decimal:2',
        'total_due' => 'decimal:2',
        'remaining_principal_after' => 'decimal:2',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'plan_id');
    }
}
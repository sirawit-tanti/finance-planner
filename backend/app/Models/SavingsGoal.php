<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavingsGoal extends Model
{
    use Syncable;

    protected $fillable = ['name', 'target_amount', 'monthly_amount', 'start_date', 'target_date', 'is_active'];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'monthly_amount' => 'decimal:2',
        'start_date' => 'immutable_date',
        'target_date' => 'immutable_date',
        'is_active' => 'boolean',
    ];

    public function contributions(): HasMany
    {
        return $this->hasMany(SavingsContribution::class, 'goal_id');
    }
}
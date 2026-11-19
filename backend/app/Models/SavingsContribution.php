<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class SavingsContribution extends Model
{
    use Syncable;

    protected $fillable = ['goal_id', 'contribution_date', 'amount', 'status'];

    protected $casts = [
        'contribution_date' => 'immutable_date',
        'amount' => 'decimal:2',
    ];
}
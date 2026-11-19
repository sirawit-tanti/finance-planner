<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class PlannedItem extends Model
{
    use Syncable;

    protected $fillable = ['kind', 'name', 'amount', 'planned_date', 'category_id', 'account_id', 'status'];

    protected $casts = [
        'amount' => 'decimal:2',
        'planned_date' => 'immutable_date',
    ];
}
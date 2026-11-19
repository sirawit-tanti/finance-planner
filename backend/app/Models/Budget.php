<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    use Syncable;

    protected $fillable = ['category_id', 'month', 'amount', 'warn_percent'];

    protected $casts = [
        'month' => 'immutable_date',
        'amount' => 'decimal:2',
        'warn_percent' => 'integer',
    ];
}
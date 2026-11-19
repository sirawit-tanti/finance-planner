<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class RecurringOverride extends Model
{
    use Syncable;

    protected $fillable = [
        'source_type', 'source_id', 'occurrence_date', 'override_amount', 'override_date', 'is_skipped',
    ];

    protected $casts = [
        'occurrence_date' => 'immutable_date',
        'override_amount' => 'decimal:2',
        'override_date' => 'immutable_date',
        'is_skipped' => 'boolean',
    ];
}
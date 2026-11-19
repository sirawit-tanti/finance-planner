<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class ForecastSnapshot extends Model
{
    use BelongsToUser;

    protected $fillable = ['horizon_months', 'data_version', 'generated_at', 'payload'];

    protected $casts = [
        'horizon_months' => 'integer',
        'data_version' => 'integer',
        'generated_at' => 'datetime',
        'payload' => 'array',
    ];
}
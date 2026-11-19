<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\TracksDataVersion;
use Illuminate\Database\Eloquent\Model;

class UserSetting extends Model
{
    use BelongsToUser, TracksDataVersion;

    protected $fillable = [
        'currency', 'month_start_day', 'default_salary_day',
        'emergency_fund_amount', 'forecast_default_months', 'locale_year',
    ];

    protected $casts = [
        'month_start_day' => 'integer',
        'default_salary_day' => 'integer',
        'emergency_fund_amount' => 'decimal:2',
        'forecast_default_months' => 'integer',
    ];
}
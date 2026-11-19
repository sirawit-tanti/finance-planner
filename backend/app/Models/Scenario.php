<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class Scenario extends Model
{
    use BelongsToUser;

    protected $fillable = ['name', 'changes'];

    protected $casts = ['changes' => 'array'];
}
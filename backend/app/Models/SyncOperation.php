<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class SyncOperation extends Model
{
    use BelongsToUser;

    protected $fillable = ['op_id', 'device_id', 'entity', 'entity_id', 'action', 'status', 'response'];

    protected $casts = ['response' => 'array'];
}
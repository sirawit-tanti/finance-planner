<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use Syncable;

    protected $fillable = ['name', 'kind', 'is_default', 'sort_order', 'is_active'];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
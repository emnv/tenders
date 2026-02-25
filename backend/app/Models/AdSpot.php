<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdSpot extends Model
{
    protected $fillable = [
        'key',
        'name',
        'placement',
        'provider',
        'is_enabled',
        'settings',
        'embed_code',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'settings' => 'array',
    ];
}

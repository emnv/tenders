<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScraperSetting extends Model
{
    protected $fillable = [
        'source_site_key',
        'is_enabled',
        'settings',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'settings' => 'array',
    ];
}

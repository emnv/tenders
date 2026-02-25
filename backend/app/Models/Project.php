<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Schema;

class Project extends Model
{
    use SoftDeletes {
        initializeSoftDeletes as protected traitInitializeSoftDeletes;
        performDeleteOnModel as protected traitPerformDeleteOnModel;
    }

    protected static ?bool $supportsSoftDeletes = null;

    protected $fillable = [
        'title',
        'description',
        'source_site_key',
        'source_external_id',
        'solicitation_number',
        'solicitation_type',
        'solicitation_form_type',
        'purchasing_group',
        'high_level_category',
        'client_divisions',
        'buyer_name',
        'buyer_email',
        'buyer_phone',
        'buyer_location',
        'ariba_discovery_url',
        'wards',
        'pre_bid_meeting',
        'contract_duration',
        'specific_conditions',
        'source_status',
        'source_scope',
        'date_available_at',
        'date_issue_at',
        'date_publish_at',
        'date_closing_at',
        'source_timezone',
        'source_raw',
        'source_site_name',
        'source_url',
        'location',
        'published_at',
        'is_manual_entry',
        'is_featured',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'date_available_at' => 'datetime',
        'date_issue_at' => 'datetime',
        'date_publish_at' => 'datetime',
        'date_closing_at' => 'datetime',
        'is_manual_entry' => 'boolean',
        'is_featured' => 'boolean',
        'source_raw' => 'array',
        'client_divisions' => 'array',
        'wards' => 'array',
    ];

    public static function bootSoftDeletes(): void
    {
        if (!static::supportsSoftDeletes()) {
            return;
        }

        static::addGlobalScope(new SoftDeletingScope());
    }

    public function initializeSoftDeletes(): void
    {
        if (!static::supportsSoftDeletes()) {
            return;
        }

        $this->traitInitializeSoftDeletes();
    }

    protected function performDeleteOnModel()
    {
        if (!static::supportsSoftDeletes()) {
            return tap($this->setKeysForSaveQuery($this->newModelQuery())->delete(), function (): void {
                $this->exists = false;
            });
        }

        return $this->traitPerformDeleteOnModel();
    }

    protected static function supportsSoftDeletes(): bool
    {
        if (static::$supportsSoftDeletes !== null) {
            return static::$supportsSoftDeletes;
        }

        $instance = new static();

        return static::$supportsSoftDeletes = Schema::hasColumn(
            $instance->getTable(),
            $instance->getDeletedAtColumn()
        );
    }
}

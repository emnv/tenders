<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('solicitation_number')->nullable()->after('source_external_id');
            $table->string('solicitation_type')->nullable()->after('solicitation_number');
            $table->string('solicitation_form_type')->nullable()->after('solicitation_type');
            $table->string('purchasing_group')->nullable()->after('solicitation_form_type');
            $table->string('high_level_category')->nullable()->after('purchasing_group');
            $table->json('client_divisions')->nullable()->after('high_level_category');

            $table->string('buyer_name')->nullable()->after('client_divisions');
            $table->string('buyer_email')->nullable()->after('buyer_name');
            $table->string('buyer_phone')->nullable()->after('buyer_email');
            $table->string('buyer_location')->nullable()->after('buyer_phone');

            $table->string('ariba_discovery_url')->nullable()->after('buyer_location');
            $table->json('wards')->nullable()->after('ariba_discovery_url');
            $table->text('pre_bid_meeting')->nullable()->after('wards');
            $table->text('contract_duration')->nullable()->after('pre_bid_meeting');
            $table->text('specific_conditions')->nullable()->after('contract_duration');

            $table->timestamp('date_issue_at')->nullable()->after('date_available_at');
            $table->timestamp('date_publish_at')->nullable()->after('date_issue_at');

            $table->index(['solicitation_number']);
            $table->index(['date_publish_at']);
            $table->index(['date_issue_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['solicitation_number']);
            $table->dropIndex(['date_publish_at']);
            $table->dropIndex(['date_issue_at']);
            $table->dropColumn([
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
                'date_issue_at',
                'date_publish_at',
            ]);
        });
    }
};

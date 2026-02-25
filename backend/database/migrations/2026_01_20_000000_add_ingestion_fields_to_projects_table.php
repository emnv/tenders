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
            $table->string('source_site_key')->nullable()->after('description');
            $table->string('source_external_id')->nullable()->after('source_site_key');
            $table->string('source_status')->nullable()->after('source_external_id');
            $table->string('source_scope')->nullable()->after('source_status');
            $table->timestamp('date_available_at')->nullable()->after('source_scope');
            $table->timestamp('date_closing_at')->nullable()->after('date_available_at');
            $table->string('source_timezone', 20)->nullable()->after('date_closing_at');
            $table->json('source_raw')->nullable()->after('source_timezone');

            $table->index(['date_closing_at']);
            $table->unique(['source_site_key', 'source_external_id'], 'projects_source_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique('projects_source_unique');
            $table->dropIndex(['date_closing_at']);
            $table->dropColumn([
                'source_site_key',
                'source_external_id',
                'source_status',
                'source_scope',
                'date_available_at',
                'date_closing_at',
                'source_timezone',
                'source_raw',
            ]);
        });
    }
};

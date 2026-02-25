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
        Schema::create('ad_spots', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('placement');
            $table->string('provider')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->json('settings')->nullable();
            $table->text('embed_code')->nullable();
            $table->timestamps();

            $table->index(['placement']);
            $table->index(['is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_spots');
    }
};

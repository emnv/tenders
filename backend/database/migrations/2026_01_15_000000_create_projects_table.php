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
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source_site_name');
            $table->string('source_url');
            $table->string('location')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_manual_entry')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            $table->index(['source_site_name']);
            $table->index(['published_at']);
            $table->index(['is_featured']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};

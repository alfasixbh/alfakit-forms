<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alfakit_forms', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('tenant');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('fields')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->unsignedInteger('max_submissions')->nullable();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->timestamps();
        });

        Schema::create('alfakit_form_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('form_id')->constrained('alfakit_forms')->cascadeOnDelete();
            $table->nullableMorphs('tenant');
            $table->nullableMorphs('submitter');
            $table->json('data')->nullable();
            $table->boolean('is_spam')->default(false)->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['form_id', 'is_spam', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alfakit_form_submissions');
        Schema::dropIfExists('alfakit_forms');
    }
};

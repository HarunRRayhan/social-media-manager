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
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // facebook|instagram
            $table->string('external_id');
            $table->string('name');
            $table->string('username')->nullable();
            $table->text('avatar_url')->nullable();
            $table->text('access_token');
            $table->timestamp('token_expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'provider', 'external_id']);
        });

        Schema::create('social_account_social_set', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_set_id')->constrained()->cascadeOnDelete();
            $table->unique(['social_account_id', 'social_set_id']);
        });

        Schema::create('post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending'); // pending|publishing|published|failed
            $table->string('remote_id')->nullable();
            $table->text('remote_url')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_targets');
        Schema::dropIfExists('social_account_social_set');
        Schema::dropIfExists('social_accounts');
    }
};

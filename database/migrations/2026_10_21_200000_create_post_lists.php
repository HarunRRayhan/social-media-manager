<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_set_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // Per-platform mirror ids (e.g. a YouTube playlist id), keyed by platform. Nothing writes it yet.
            $table->json('remote_ids')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('post_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unique(['post_list_id', 'post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_list_items');
        Schema::dropIfExists('post_lists');
    }
};

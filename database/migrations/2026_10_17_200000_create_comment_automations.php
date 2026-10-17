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
        Schema::create('comment_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('post_remote_id')->nullable(); // null: any post of the account
            $table->json('keywords');
            $table->text('dm_message');
            $table->text('public_reply')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('comment_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_automation_id')->constrained()->cascadeOnDelete();
            $table->string('comment_id');
            $table->string('commenter_id')->nullable();
            $table->string('status'); // pending|sent|failed|skipped
            $table->text('error')->nullable();
            $table->timestamps();
            // One run per comment: a redelivered webhook can never send a second DM.
            $table->unique(['comment_automation_id', 'comment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comment_automation_runs');
        Schema::dropIfExists('comment_automations');
    }
};

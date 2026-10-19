<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SQLite drops a column by rebuilding the table, and only outside a transaction can it turn foreign keys off
     * for that; inside one, the rebuild would cascade-delete every run.
     */
    public $withinTransaction = false;

    public function __construct()
    {
        // Postgres changes its schema transactionally, so a half-done move rolls back there.
        $this->withinTransaction = DB::getDriverName() !== 'sqlite';
    }

    /**
     * One automation, many accounts and many posts: the single account moves into a pivot, the single post into a list.
     */
    public function up(): void
    {
        // Read first: the pivot is filled after the column drop, so a rebuild can never cascade into it.
        $automations = DB::table('comment_automations')->orderBy('id')->get(['id', 'social_account_id', 'post_remote_id']);

        Schema::table('comment_automations', function (Blueprint $table) {
            $table->json('post_remote_ids')->nullable(); // null: any post of the accounts
        });

        foreach ($automations->whereNotNull('post_remote_id') as $automation) {
            DB::table('comment_automations')->where('id', $automation->id)->update(['post_remote_ids' => json_encode([$automation->post_remote_id])]);
        }

        Schema::table('comment_automations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('social_account_id');
            $table->dropColumn('post_remote_id');
        });

        Schema::create('comment_automation_social_account', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->unique(['comment_automation_id', 'social_account_id']);
        });

        foreach ($automations as $automation) {
            DB::table('comment_automation_social_account')->insert(['comment_automation_id' => $automation->id, 'social_account_id' => $automation->social_account_id]);
        }
    }

    /**
     * Reverse the migrations. Each automation keeps its first account and first post; automations without an account go.
     */
    public function down(): void
    {
        Schema::table('comment_automations', function (Blueprint $table) {
            $table->foreignId('social_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('post_remote_id')->nullable();
        });

        foreach (DB::table('comment_automations')->orderBy('id')->get(['id', 'post_remote_ids']) as $automation) {
            DB::table('comment_automations')->where('id', $automation->id)->update([
                'social_account_id' => DB::table('comment_automation_social_account')->where('comment_automation_id', $automation->id)->orderBy('id')->value('social_account_id'),
                'post_remote_id' => json_decode($automation->post_remote_ids ?? 'null', true)[0] ?? null,
            ]);
        }

        DB::table('comment_automations')->whereNull('social_account_id')->delete();

        Schema::table('comment_automations', function (Blueprint $table) {
            $table->dropColumn('post_remote_ids');
        });

        Schema::dropIfExists('comment_automation_social_account');
    }
};

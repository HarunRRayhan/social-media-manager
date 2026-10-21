<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every team gets one Default set that holds every connected account: the oldest live set named "Default", else its oldest live set, else a new one.
     */
    public function up(): void
    {
        Schema::table('social_sets', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('name');
        });

        foreach (DB::table('teams')->pluck('id') as $teamId) {
            $sets = DB::table('social_sets')->where('team_id', $teamId)->whereNull('deleted_at')->orderBy('id');
            $setId = $sets->clone()->where('name', 'Default')->value('id')
                ?? $sets->value('id')
                ?? DB::table('social_sets')->insertGetId(['team_id' => $teamId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

            DB::table('social_sets')->where('id', $setId)->update(['is_default' => true]);

            $rows = DB::table('social_accounts')->where('team_id', $teamId)->pluck('id')
                ->map(fn ($accountId) => ['social_account_id' => $accountId, 'social_set_id' => $setId])->all();
            DB::table('social_account_social_set')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::table('social_sets', fn (Blueprint $table) => $table->dropColumn('is_default'));
    }
};

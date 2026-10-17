<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // owner|admin|member
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });

        Schema::create('social_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['team_id', 'email']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_team_id')->nullable()->constrained('teams')->nullOnDelete();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('social_set_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // Every existing user gets a personal team with one "Default" set, and their posts move there.
        foreach (DB::table('users')->orderBy('id')->get(['id', 'name']) as $user) {
            $now = now();
            $teamId = DB::table('teams')->insertGetId(['name' => "{$user->name}'s Team", 'created_at' => $now, 'updated_at' => $now]);
            DB::table('team_user')->insert(['team_id' => $teamId, 'user_id' => $user->id, 'role' => 'owner', 'created_at' => $now, 'updated_at' => $now]);
            $setId = DB::table('social_sets')->insertGetId(['team_id' => $teamId, 'name' => 'Default', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('users')->where('id', $user->id)->update(['current_team_id' => $teamId]);
            DB::table('posts')->where('user_id', $user->id)->update(['team_id' => $teamId, 'social_set_id' => $setId]);
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable(false)->change();
            $table->foreignId('social_set_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('user_id');
        });

        // Tokens now belong to teams; old user tokens would grant nothing, so they go.
        DB::table('personal_access_tokens')->where('tokenable_type', 'App\Models\User')->delete();
    }

    /**
     * Reverse the migrations. Posts go back to their team's owner; team tokens are dropped.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
        });

        DB::table('posts')->update([
            'user_id' => DB::table('team_user')->select('user_id')->whereColumn('team_user.team_id', 'posts.team_id')->where('role', 'owner')->limit(1),
        ]);

        DB::table('personal_access_tokens')->where('tokenable_type', 'App\Models\Team')->delete();

        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('social_set_id');
            $table->dropConstrainedForeignId('team_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_team_id');
        });

        Schema::dropIfExists('team_invitations');
        Schema::dropIfExists('social_sets');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};

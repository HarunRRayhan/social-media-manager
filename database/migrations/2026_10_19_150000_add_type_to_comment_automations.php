<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comment_automations', function (Blueprint $table) {
            $table->string('type')->default('comment_to_dm')->after('team_id');
        });
    }

    public function down(): void
    {
        Schema::table('comment_automations', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};

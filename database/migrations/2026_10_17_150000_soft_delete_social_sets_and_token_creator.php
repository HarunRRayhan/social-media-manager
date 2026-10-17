<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_sets', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('social_sets', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};

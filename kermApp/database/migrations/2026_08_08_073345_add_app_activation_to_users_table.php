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
        Schema::table('users', function (Blueprint $table) {
            // Whether the owner's dedicated app build is still allowed to run.
            // Devices poll this before registering, so flipping it off disables
            // every install of that owner's build at once.
            $table->boolean('is_app_active')->default(true)->after('app_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_app_active');
        });
    }
};

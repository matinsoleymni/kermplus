<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whitelisted_targets', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->string('value');
            $table->string('normalized_value');
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['type', 'normalized_value']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whitelisted_targets');
    }
};

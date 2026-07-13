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
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // FCM registration token for this app install.
            $table->string('fcm_token', 512);

            // Device fingerprint reported by the app at registration.
            $table->string('device_id')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('android_version')->nullable();
            $table->integer('sdk_int')->nullable();
            $table->string('app_version')->nullable();
            $table->string('locale')->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // A given FCM token belongs to exactly one device under one owner.
            $table->unique(['user_id', 'fcm_token']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};

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
        // جدول پلن‌های اشتراک
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // نام پلن
            $table->text('description')->nullable(); // توضیحات
            $table->decimal('price', 8, 2)->default(0); // قیمت
            $table->integer('duration_days')->default(30); // مدت زمان برحسب روز
            $table->integer('max_sms_per_day')->default(100); // حداکثر SMS در روز
            $table->integer('max_email_per_day')->default(50); // حداکثر Email در روز
            $table->integer('max_requests_per_day')->default(1000); // حداکثر درخواست در روز
            $table->json('features')->nullable(); // ویژگی‌های پلن
            $table->boolean('is_active')->default(true); // فعال/غیرفعال
            $table->timestamps();
        });

        // جدول اشتراکات کاربران
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->onDelete('restrict');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_renew')->default(false); // تمدید خودکار
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index('expires_at');
        });

        // جدول تاریخچه اشتراکات
        Schema::create('subscription_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->onDelete('cascade');
            $table->string('action'); // renewed, cancelled, upgraded, downgraded, created
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['subscription_id', 'created_at']);
        });

        // اضافه کردن ستون‌های جدید به جدول Users
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email'); // آیا ادمین است
            $table->enum('role', ['user', 'admin', 'super_admin'])->default('user')->after('is_admin'); // نقش کاربر
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_histories');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'role']);
        });
    }
};

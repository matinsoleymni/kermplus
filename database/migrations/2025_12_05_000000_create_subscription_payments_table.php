<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->string('provider')->default('nowpayments');
            $table->string('invoice_id')->nullable();
            $table->string('invoice_url')->nullable();
            $table->string('payment_id')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('price_amount', 10, 2);
            $table->string('price_currency', 10)->default('usd');
            $table->decimal('pay_amount', 18, 8)->nullable();
            $table->string('pay_currency', 20)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['provider', 'invoice_id']);
            $table->index(['provider', 'payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};

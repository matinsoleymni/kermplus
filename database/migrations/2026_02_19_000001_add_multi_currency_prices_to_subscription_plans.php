<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->decimal('price_usd', 10, 2)->default(0)->after('price');
            $table->unsignedBigInteger('price_irr')->default(0)->after('price_usd');
            $table->unsignedInteger('price_stars')->default(0)->after('price_irr');
        });

        $usdPerStar = (float) config('payments.telegram_star_usd_value', 0.5);
        if ($usdPerStar <= 0) {
            $usdPerStar = 0.5;
        }

        DB::table('subscription_plans')
            ->select(['id', 'price'])
            ->orderBy('id')
            ->chunkById(100, function ($plans) use ($usdPerStar): void {
                foreach ($plans as $plan) {
                    $usd = (float) ($plan->price ?? 0);

                    DB::table('subscription_plans')
                        ->where('id', $plan->id)
                        ->update([
                            'price_usd' => $usd,
                            'price_irr' => 0,
                            'price_stars' => (int) ceil($usd / $usdPerStar),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['price_usd', 'price_irr', 'price_stars']);
        });
    }
};


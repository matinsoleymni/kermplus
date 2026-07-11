<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $plusPlan = SubscriptionPlan::updateOrCreate(
            ['name' => 'plus'],
            [
                'description' => 'دسترسی کامل به همه ویژگی‌ها',
                'price' => 8.31,
                'price_usd' => 8.31,
                'price_irr' => 1480000 ,
                'price_stars' => 553,
                'duration_days' => 0, // بدون انقضا
                'max_sms_per_day' => 0,
                'max_email_per_day' => 0,
                'max_requests_per_day' => 0,
                'features' => ['*', 'bomber', 'reporter', 'harasser', 'whitelist', 'sms', 'email'],
                'is_active' => true,
            ]
        );

        $proPlan = SubscriptionPlan::updateOrCreate(
            ['name' => 'pro'],
            [
                'description' => 'دسترسی به بمبرها و ریپورتر',
                'price' => 5.50,
                'price_usd' => 5.50,
                'price_irr' => 980000,
                'price_stars' => 366,
                'duration_days' => 0, // بدون انقضا
                'max_sms_per_day' => 0,
                'max_email_per_day' => 0,
                'max_requests_per_day' => 0,
                'features' => ['bomber', 'reporter', 'sms', 'email'],
                'is_active' => true,
            ]
        );

        // فقط دو پلن اصلی فعال باشند.
        SubscriptionPlan::query()
            ->whereNotIn('id', [$plusPlan->id, $proPlan->id])
            ->update(['is_active' => false]);
    }
}

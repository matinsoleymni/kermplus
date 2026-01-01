<?php

namespace Database\Seeders;

use App\Models\User;
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
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        SubscriptionPlan::firstOrCreate(
            ['name' => 'Basic'],
            [
                'description' => 'پلن پیش‌فرض ۳۰ روزه',
                'price' => 10.00,
                'duration_days' => 30,
                'max_sms_per_day' => 100,
                'max_email_per_day' => 50,
                'max_requests_per_day' => 1000,
                'features' => ['sms', 'email'],
                'is_active' => true,
            ]
        );

        SubscriptionPlan::firstOrCreate(
            ['name' => 'f'],
            [
                'description' => null,
                'price' => 0.5,
                'duration_days' => 30,
                'max_sms_per_day' => 100,
                'max_email_per_day' => 50,
                'max_requests_per_day' => 1000,
                'features' => ['sms', 'email'],
                'is_active' => true,
            ]
        );
    }
}

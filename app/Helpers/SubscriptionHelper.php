<?php

namespace App\Helpers;

use App\Models\Subscription;
use App\Models\User;
use App\Models\SubscriptionPayment;
use Carbon\Carbon;

class SubscriptionHelper
{
    /**
     * دریافت وضعیت اشتراک کاربر
     */
    public static function getUserSubscriptionStatus(User $user): array
    {
        $subscription = $user->subscriptions()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            })
            ->latest()
            ->first();

        if (!$subscription) {
            return [
                'is_active' => false,
                'plan_name' => 'بدون اشتراک',
                'remaining_days' => 0,
                'message' => '❌ اشتراک فعال ندارید',
            ];
        }

        return [
            'is_active' => true,
            'plan_name' => $subscription->plan->name,
            'remaining_days' => $subscription->expires_at ? $subscription->getRemainingDays() : 'نامحدود',
            'message' => $subscription->expires_at
                ? "✅ اشتراک فعال: {$subscription->plan->name} ({$subscription->getRemainingDays()} روز باقی)"
                : "✅ اشتراک فعال: {$subscription->plan->name} (نامحدود)",
            'subscription' => $subscription,
        ];
    }

    /**
     * دریافت تمام اشتراکات
     */
    public static function getSubscriptionsCount(): array
    {
        return [
            'total' => Subscription::count(),
            'active' => Subscription::where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
                })
                ->count(),
            'expired' => Subscription::where('is_active', true)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', Carbon::now())
                ->count(),
        ];
    }

    /**
     * دریافت لیست کاربران فعال
     */
    public static function getActiveUsers(): int
    {
        return Subscription::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            })
            ->distinct('user_id')
            ->count();
    }

    /**
     * دریافت درآمد کل
     */
    public static function getTotalRevenue(): float
    {
        return (float) Subscription::join('subscription_plans', 'subscriptions.subscription_plan_id', '=', 'subscription_plans.id')
            ->sum('subscription_plans.price');
    }

    /**
     * دریافت درآمد ماهانه
     */
    public static function getMonthlyRevenue(): float
    {
        return (float) Subscription::join('subscription_plans', 'subscriptions.subscription_plan_id', '=', 'subscription_plans.id')
            ->where('subscriptions.created_at', '>=', Carbon::now()->startOfMonth())
            ->sum('subscription_plans.price');
    }

    /**
     * دریافت آخرین اشتراکات
     */
    public static function getRecentSubscriptions(int $limit = 5): array
    {
        return Subscription::with(['user', 'plan'])
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($sub) {
                return [
                    'user' => $sub->user->name,
                    'plan' => $sub->plan->name,
                    'status' => $sub->isActive() ? '✅ فعال' : '❌ منقضی',
                    'expires' => $sub->expires_at?->format('Y-m-d') ?? 'نامحدود',
                ];
            })
            ->toArray();
    }

    /**
     * دریافت اطلاعات پلن
     */
    public static function getPlanStats(): string
    {
        $plans = \App\Models\SubscriptionPlan::where('is_active', true)->get();
        $msg = "📊 آمار پلن‌های اشتراک:\n\n";

        foreach ($plans as $plan) {
            $count = $plan->subscriptions()->count();
            $price = (float) $plan->price;
            $msg .= "🔹 {$plan->name}\n";
            $msg .= "   💰 قیمت: " . number_format($price, 0) . " تومان\n";
            $msg .= "   ⏱️ مدت: {$plan->duration_days} روز\n";
            $msg .= "   👥 تعداد کاربر: {$count}\n";
            $msg .= "   📊 SMS: {$plan->max_sms_per_day}/روز | Email: {$plan->max_email_per_day}/روز\n\n";
        }

        return $msg;
    }

    /**
     * تشکیل پیام آمار کاملی برای ادمین
     */
    public static function getAdminDashboard(): string
    {
        $stats = self::getSubscriptionsCount();
        $activeUsers = self::getActiveUsers();
        $totalRevenue = self::getTotalRevenue();
        $monthlyRevenue = self::getMonthlyRevenue();

        $msg = "📊 **داشبورد ادمین - سیستم اشتراک**\n";
        $msg .= str_repeat("─", 30) . "\n\n";

        $msg .= "👥 **کاربران:**\n";
        $msg .= "   • کل کاربران فعال: {$activeUsers}\n\n";

        $msg .= "📋 **اشتراکات:**\n";
        $msg .= "   • کل: {$stats['total']}\n";
        $msg .= "   • فعال: {$stats['active']}\n";
        $msg .= "   • منقضی: {$stats['expired']}\n\n";

        $msg .= "💰 **درآمد:**\n";
        $msg .= "   • کل: " . number_format($totalRevenue, 0) . " تومان\n";
        $msg .= "   • این ماه: " . number_format($monthlyRevenue, 0) . " تومان\n";

        return $msg;
    }

    /**
     * پیام آمار درآمد برای پنل ادمین
     */
    public static function getRevenueStatsMessage(): string
    {
        $now = Carbon::now();
        $today = self::paidSumBetween($now->copy()->startOfDay(), $now);
        $last7 = self::paidSumBetween($now->copy()->subDays(7), $now);
        $last30 = self::paidSumBetween($now->copy()->subDays(30), $now);
        $total = (float) SubscriptionPayment::query()
            ->where('status', 'paid')
            ->sum('price_amount');

        $msg = "• 💰 Revenue Statistics ᴍᴏᴢᴀʜᴇᴍʏᴀʙ🍷 •\n\n";
        $msg .= "┬ 💵 Today : " . self::formatUsd($today) . "$\n";
        $msg .= "┤ 📅 Last 7 Days : " . self::formatUsd($last7) . "$\n";
        $msg .= "┘ 🌙 Last 30 Days : " . self::formatUsd($last30) . "$\n\n";
        $msg .= "┬ 📊 Total Revenue : " . self::formatUsd($total) . "$\n\n";
        $msg .= " 📆 " . $now->format('Y/m/d') . " - ⏰ " . $now->format('H:i:s');

        return $msg;
    }

    protected static function paidSumBetween(Carbon $from, Carbon $to): float
    {
        return (float) SubscriptionPayment::query()
            ->where('status', 'paid')
            ->whereBetween('created_at', [$from, $to])
            ->sum('price_amount');
    }

    protected static function formatUsd(float $value): string
    {
        // 3 decimals to mimic نمونه خروجی
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}

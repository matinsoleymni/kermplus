<?php

namespace App\Helpers;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
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
        return (float) SubscriptionPayment::query()
            ->where('status', 'paid')
            ->where('price_currency', 'usd')
            ->sum('price_amount');
    }

    /**
     * دریافت درآمد ماهانه
     */
    public static function getMonthlyRevenue(): float
    {
        return (float) SubscriptionPayment::query()
            ->where('status', 'paid')
            ->where('price_currency', 'usd')
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->sum('price_amount');
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
        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderByRaw('LOWER(name)')
            ->get();
        $msg = "📊 آمار پلن‌های اشتراک:\n\n";

        foreach ($plans as $plan) {
            $count = $plan->subscriptions()
                ->where('is_active', true)
                ->where(function ($q): void {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
                })
                ->count();

            $msg .= "🔹 {$plan->name}\n";
            $msg .= "   💰 USD: " . number_format($plan->usdPrice(), 2) . " | ریال: " . number_format($plan->irrPrice()) . " | استار: " . number_format($plan->starsPrice()) . "\n";
            $msg .= "   ⏱️ مدت: {$plan->duration_days} روز\n";
            $msg .= "   👥 کاربران فعال: {$count}\n";
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
        $msg .= "   • کل (USD): " . number_format($totalRevenue, 2) . "\n";
        $msg .= "   • این ماه (USD): " . number_format($monthlyRevenue, 2) . "\n";

        return $msg;
    }

    /**
     * پیام آمار درآمد برای پنل ادمین
     */
    public static function getRevenueStatsMessage(): string
    {
        $now = Carbon::now();

        $todayFrom = $now->copy()->startOfDay();
        $last7From = $now->copy()->subDays(7);
        $last30From = $now->copy()->subDays(30);

        $todayRevenue = self::paidSumBetween($todayFrom, $now, 'usd');
        $last7Revenue = self::paidSumBetween($last7From, $now, 'usd');
        $last30Revenue = self::paidSumBetween($last30From, $now, 'usd');
        $totalRevenue = self::paidSumTotal('usd');

        $msg = "• 💰 Revenue Statistics  ᴋᴇʀᴍᴘʟᴜꜱ🍷 •\n\n";
        $msg .= "┬ 💵 Today : " . self::formatUsdStat($todayRevenue) . "$\n";
        $msg .= "┤ 📅 Last 7 Days : " . self::formatUsdStat($last7Revenue) . "$\n";
        $msg .= "┘ 🌙 Last 30 Days : " . self::formatUsdStat($last30Revenue) . "$\n\n";
        $msg .= "┬ 📊 Total Revenue : " . self::formatUsdStat($totalRevenue) . "$\n\n";
        $msg .= " 📆 " . self::formatPersianDate($now) . " - ⏰ " . $now->format('H:i:s');

        return $msg;
    }

    protected static function paidSumBetween(Carbon $from, Carbon $to, string $currency): float
    {
        return (float) SubscriptionPayment::query()
            ->where('status', 'paid')
            ->whereRaw('LOWER(price_currency) = ?', [strtolower($currency)])
            ->whereBetween('created_at', [$from, $to])
            ->sum('price_amount');
    }

    protected static function paidSumTotal(string $currency): float
    {
        return (float) SubscriptionPayment::query()
            ->where('status', 'paid')
            ->whereRaw('LOWER(price_currency) = ?', [strtolower($currency)])
            ->sum('price_amount');
    }

    /**
     * @return array{paid:int,pending:int,failed:int}
     */
    protected static function paymentStatusCountsBetween(Carbon $from, Carbon $to): array
    {
        $pendingStatuses = ['pending', 'pre_checkout', 'waiting'];
        $base = SubscriptionPayment::query()
            ->whereBetween('created_at', [$from, $to]);

        $paid = (clone $base)->where('status', 'paid')->count();
        $pending = (clone $base)->whereIn('status', $pendingStatuses)->count();
        $failed = (clone $base)
            ->whereNotIn('status', array_merge(['paid'], $pendingStatuses))
            ->count();

        return [
            'paid' => $paid,
            'pending' => $pending,
            'failed' => $failed,
        ];
    }

    protected static function formatByCurrency(float $value, string $currency): string
    {
        $currency = strtolower($currency);

        if ($currency === 'usd') {
            return number_format($value, 2, '.', ',');
        }

        return number_format($value, 0, '.', ',');
    }

    protected static function formatUsdStat(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    protected static function formatPersianDate(Carbon $date): string
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return $date->format('Y/m/d');
        }

        $formatter = new \IntlDateFormatter(
            'en_US@calendar=persian',
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            $date->getTimezone()->getName(),
            \IntlDateFormatter::TRADITIONAL,
            'yyyy/MM/d'
        );

        if ($formatter === false) {
            return $date->format('Y/m/d');
        }

        $formatted = $formatter->format($date);

        return is_string($formatted) && $formatted !== ''
            ? $formatted
            : $date->format('Y/m/d');
    }
}

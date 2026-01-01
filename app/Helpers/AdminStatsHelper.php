<?php

namespace App\Helpers;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminStatsHelper
{
    public static function smsBombCount(): int
    {
        return DB::table('jobs')
            ->where('queue', 'default')
            ->where('payload', 'like', '%SendSmsBombJob%')
            ->count();
    }

    public static function emailBombCount(): int
    {
        return DB::table('jobs')
            ->where('queue', 'default')
            ->where('payload', 'like', '%SendEmailBombJob%')
            ->count();
    }

    public static function totalUsers(): int
    {
        return DB::table('users')->count();
    }

    public static function dailyActiveUsers(): int
    {
        return DB::table('users')
            ->where('last_active_at', '>=', now()->subDay())
            ->count();
    }

    public static function weeklyActiveUsers(): int
    {
        return DB::table('users')
            ->where('last_active_at', '>=', now()->subWeek())
            ->count();
    }

    public static function monthlyActiveUsers(): int
    {
        return DB::table('users')
            ->where('last_active_at', '>=', now()->subMonth())
            ->count();
    }

    /**
     * خلاصه آماری برای داشبورد پنل ادمین
     */
    public static function dashboardSnapshot(): array
    {
        $now = Carbon::now();

        $totalUsers = self::totalUsers();
        $activeDay = DB::table('users')->where('last_active_at', '>=', $now->copy()->subDay())->count();
        $activeWeek = DB::table('users')->where('last_active_at', '>=', $now->copy()->subDays(7))->count();
        $activeMonth = DB::table('users')->where('last_active_at', '>=', $now->copy()->subDays(30))->count();

        $activeSubs = self::activeSubscriptionsQuery($now);
        $premiumUsers = (clone $activeSubs)->distinct('user_id')->count('user_id');
        $referralPremiums = (clone $activeSubs)
            ->whereHas('user', fn($q) => $q->whereNotNull('referred_by'))
            ->distinct('user_id')
            ->count('user_id');
        $manualPremiums = (clone $activeSubs)
            ->whereHas('history', fn($q) => $q->where('action', 'created')->whereNotNull('created_by'))
            ->distinct('user_id')
            ->count('user_id');
        $paidPremiums = SubscriptionPayment::query()
            ->where('status', 'paid')
            ->distinct('user_id')
            ->count('user_id');

        $admins = User::query()
            ->whereIn('role', ['admin', 'super_admin'])
            ->orderByDesc('role')
            ->orderBy('name')
            ->get(['id', 'name']);

        return [
            'generated_at' => $now,
            'users' => [
                'total' => $totalUsers,
                'active' => $activeMonth,
                'premium' => $premiumUsers,
                'active_day' => $activeDay,
                'active_week' => $activeWeek,
                'active_month' => $activeMonth,
            ],
            'admins' => [
                'count' => $admins->count(),
                'names' => $admins->pluck('name')->filter()->values()->all(),
            ],
            'premium_breakdown' => [
                'paid' => $paidPremiums,
                'referral' => $referralPremiums,
                'manual' => $manualPremiums,
            ],
        ];
    }

    protected static function activeSubscriptionsQuery(Carbon $now)
    {
        return Subscription::query()
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });
    }
}

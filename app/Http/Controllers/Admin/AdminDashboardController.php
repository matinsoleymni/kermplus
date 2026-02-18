<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('admin');
    }

    /**
     * نمایش داشبورد اصلی ادمین
     */
    public function index(): View
    {
        $stats = [
            'total_users' => User::count(),
            'total_admins' => User::whereIn('role', ['admin', 'super_admin'])->count(),
            'active_subscriptions' => Subscription::where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->count(),
            'expired_subscriptions' => Subscription::whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->where('is_active', true)
                ->count(),
            'total_revenue' => Subscription::sum('subscription_plans.price'),
        ];

        $recent_subscriptions = Subscription::with(['user', 'plan'])
            ->latest()
            ->take(10)
            ->get();

        $recent_history = SubscriptionHistory::with(['subscription', 'createdByUser'])
            ->latest()
            ->take(15)
            ->get();

        return view('admin.dashboard', compact('stats', 'recent_subscriptions', 'recent_history'));
    }
}

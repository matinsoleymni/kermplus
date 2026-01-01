<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class SubscriptionController extends Controller
{
    protected SubscriptionService $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
        $this->middleware('auth');
        $this->middleware('admin');
    }

    /**
     * نمایش لیست تمام اشتراکات
     */
    public function index(): View
    {
        $subscriptions = Subscription::with(['user', 'plan'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('admin.subscriptions.index', compact('subscriptions'));
    }

    /**
     * نمایش جزئیات اشتراک
     */
    public function show(Subscription $subscription): View
    {
        $subscription->load(['user', 'plan', 'history']);

        return view('admin.subscriptions.show', compact('subscription'));
    }

    /**
     * فرم ایجاد اشتراک جدید
     */
    public function create(): View
    {
        $users = User::where('role', 'user')->get();
        $plans = SubscriptionPlan::where('is_active', true)->get();

        return view('admin.subscriptions.create', compact('users', 'plans'));
    }

    /**
     * ذخیره اشتراک جدید
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'subscription_plan_id' => 'required|exists:subscription_plans,id',
        ]);

        $user = User::findOrFail($validated['user_id']);
        $plan = SubscriptionPlan::findOrFail($validated['subscription_plan_id']);

        $this->subscriptionService->createSubscription($user, $plan);

        return redirect()->route('admin.subscriptions.index')
            ->with('success', "اشتراک برای {$user->name} ایجاد شد.");
    }

    /**
     * فرم ویرایش اشتراک
     */
    public function edit(Subscription $subscription): View
    {
        $plans = SubscriptionPlan::where('is_active', true)->get();

        return view('admin.subscriptions.edit', compact('subscription', 'plans'));
    }

    /**
     * بروزرسانی اشتراک
     */
    public function update(Request $request, Subscription $subscription): RedirectResponse
    {
        $validated = $request->validate([
            'subscription_plan_id' => 'required|exists:subscription_plans,id',
            'auto_renew' => 'boolean',
        ]);

        $newPlan = SubscriptionPlan::findOrFail($validated['subscription_plan_id']);

        if ($subscription->subscription_plan_id !== $newPlan->id) {
            $this->subscriptionService->changePlan($subscription->user, $newPlan);
        }

        $subscription->auto_renew = $request->boolean('auto_renew');
        $subscription->save();

        return redirect()->route('admin.subscriptions.show', $subscription)
            ->with('success', 'اشتراک بروزرسانی شد.');
    }

    /**
     * تمدید اشتراک
     */
    public function renew(Subscription $subscription): RedirectResponse
    {
        if ($subscription->renew()) {
            return redirect()->back()
                ->with('success', 'اشتراک تمدید شد.');
        }

        return redirect()->back()
            ->with('error', 'خرابی در تمدید اشتراک');
    }

    /**
     * لغو اشتراک
     */
    public function cancel(Subscription $subscription): RedirectResponse
    {
        if ($subscription->cancel()) {
            return redirect()->back()
                ->with('success', 'اشتراک لغو شد.');
        }

        return redirect()->back()
            ->with('error', 'خرابی در لغو اشتراک');
    }

    /**
     * حذف اشتراک
     */
    public function destroy(Subscription $subscription): RedirectResponse
    {
        $subscription->delete();

        return redirect()->route('admin.subscriptions.index')
            ->with('success', 'اشتراک حذف شد.');
    }
}

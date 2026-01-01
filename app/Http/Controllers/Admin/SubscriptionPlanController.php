<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class SubscriptionPlanController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('admin');
    }

    /**
     * نمایش لیست تمام پلن‌های اشتراک
     */
    public function index(): View
    {
        $plans = SubscriptionPlan::orderBy('price')->paginate(15);

        return view('admin.plans.index', compact('plans'));
    }

    /**
     * فرم ایجاد پلن جدید
     */
    public function create(): View
    {
        return view('admin.plans.create');
    }

    /**
     * ذخیره پلن جدید
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:subscription_plans',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1',
            'max_sms_per_day' => 'required|integer|min:0',
            'max_email_per_day' => 'required|integer|min:0',
            'max_requests_per_day' => 'required|integer|min:0',
            'features' => 'nullable|array',
        ]);

        SubscriptionPlan::create($validated);

        return redirect()->route('admin.plans.index')
            ->with('success', 'پلن جدید ایجاد شد.');
    }

    /**
     * فرم ویرایش پلن
     */
    public function edit(SubscriptionPlan $plan): View
    {
        return view('admin.plans.edit', compact('plan'));
    }

    /**
     * بروزرسانی پلن
     */
    public function update(Request $request, SubscriptionPlan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:subscription_plans,name,' . $plan->id,
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1',
            'max_sms_per_day' => 'required|integer|min:0',
            'max_email_per_day' => 'required|integer|min:0',
            'max_requests_per_day' => 'required|integer|min:0',
            'features' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $plan->update($validated);

        return redirect()->route('admin.plans.index')
            ->with('success', 'پلن بروزرسانی شد.');
    }

    /**
     * حذف پلن
     */
    public function destroy(SubscriptionPlan $plan): RedirectResponse
    {
        // بررسی اینکه آیا این پلن توسط کسی استفاده می‌شود
        if ($plan->subscriptions()->exists()) {
            return redirect()->back()
                ->with('error', 'این پلن در حال استفاده است و نمی‌تواند حذف شود.');
        }

        $plan->delete();

        return redirect()->route('admin.plans.index')
            ->with('success', 'پلن حذف شد.');
    }

    /**
     * تغییر وضعیت فعال/غیرفعال
     */
    public function toggleStatus(SubscriptionPlan $plan): RedirectResponse
    {
        $plan->is_active = !$plan->is_active;
        $plan->save();

        $status = $plan->is_active ? 'فعال' : 'غیرفعال';

        return redirect()->back()
            ->with('success', "وضعیت پلن به $status تغییر یافت.");
    }
}

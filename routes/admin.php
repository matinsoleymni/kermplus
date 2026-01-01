<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SubscriptionPlanController;

Route::middleware(['web', 'auth'])->prefix('admin')->group(function () {
    // Dashboard
    Route::get('/', [AdminDashboardController::class, 'index'])->name('admin.dashboard');

    // Subscription Management
    Route::middleware('admin')->group(function () {
        // اشتراکات کاربران
        Route::resource('subscriptions', SubscriptionController::class, ['as' => 'admin']);
        Route::post('subscriptions/{subscription}/renew', [SubscriptionController::class, 'renew'])->name('admin.subscriptions.renew');
        Route::post('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel'])->name('admin.subscriptions.cancel');

        // پلن‌های اشتراک
        Route::resource('plans', SubscriptionPlanController::class, ['as' => 'admin']);
        Route::post('plans/{plan}/toggle-status', [SubscriptionPlanController::class, 'toggleStatus'])->name('admin.plans.toggle-status');
    });
});

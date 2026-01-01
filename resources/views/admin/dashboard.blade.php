@extends('layouts.app')

@section('content')
    <div class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold mb-8">پنل ادمین</h1>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <!-- آمار کاربران -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-gray-600 text-sm font-medium">کل کاربران</h3>
                <p class="text-3xl font-bold text-gray-900 mt-2">{{ $stats['total_users'] }}</p>
            </div>

            <!-- آمار ادمین‌ها -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-gray-600 text-sm font-medium">ادمین‌ها</h3>
                <p class="text-3xl font-bold text-gray-900 mt-2">{{ $stats['total_admins'] }}</p>
            </div>

            <!-- اشتراکات فعال -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-gray-600 text-sm font-medium">اشتراکات فعال</h3>
                <p class="text-3xl font-bold text-green-600 mt-2">{{ $stats['active_subscriptions'] }}</p>
            </div>

            <!-- اشتراکات منقضی‌شده -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-gray-600 text-sm font-medium">اشتراکات منقضی</h3>
                <p class="text-3xl font-bold text-red-600 mt-2">{{ $stats['expired_subscriptions'] }}</p>
            </div>
        </div>

        <!-- منوی مدیریت -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-bold mb-4">مدیریت اشتراکات</h2>
                <div class="space-y-2">
                    <a href="{{ route('admin.subscriptions.index') }}" class="block text-blue-600 hover:text-blue-800">
                        📋 مشاهده تمام اشتراکات
                    </a>
                    <a href="{{ route('admin.subscriptions.create') }}" class="block text-blue-600 hover:text-blue-800">
                        ➕ ایجاد اشتراک جدید
                    </a>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-bold mb-4">مدیریت پلن‌ها</h2>
                <div class="space-y-2">
                    <a href="{{ route('admin.plans.index') }}" class="block text-blue-600 hover:text-blue-800">
                        📊 مشاهده تمام پلن‌ها
                    </a>
                    <a href="{{ route('admin.plans.create') }}" class="block text-blue-600 hover:text-blue-800">
                        ➕ ایجاد پلن جدید
                    </a>
                </div>
            </div>
        </div>

        <!-- اشتراکات اخیر -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-xl font-bold mb-4">آخرین اشتراکات</h2>
            <table class="w-full">
                <thead>
                    <tr class="border-b">
                        <th class="text-right px-4 py-2">کاربر</th>
                        <th class="text-right px-4 py-2">پلن</th>
                        <th class="text-right px-4 py-2">شروع</th>
                        <th class="text-right px-4 py-2">پایان</th>
                        <th class="text-right px-4 py-2">وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recent_subscriptions as $sub)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="px-4 py-2">{{ $sub->user->name }}</td>
                            <td class="px-4 py-2">{{ $sub->plan->name }}</td>
                            <td class="px-4 py-2">{{ $sub->started_at->format('Y-m-d') }}</td>
                            <td class="px-4 py-2">{{ $sub->expires_at->format('Y-m-d') }}</td>
                            <td class="px-4 py-2">
                                @if($sub->isActive())
                                    <span class="px-2 py-1 bg-green-100 text-green-800 rounded">فعال</span>
                                @else
                                    <span class="px-2 py-1 bg-red-100 text-red-800 rounded">منقضی</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- تاریخچه اخیر -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-bold mb-4">تاریخچه فعالیت</h2>
            <div class="space-y-2">
                @foreach($recent_history as $history)
                    <div class="border-b pb-3">
                        <p class="font-medium">{{ $history->subscription->user->name }} - {{ ucfirst($history->action) }}</p>
                        <p class="text-sm text-gray-600">{{ $history->description }}</p>
                        <p class="text-xs text-gray-400">{{ $history->created_at->diffForHumans() }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection
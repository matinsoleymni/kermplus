@extends('layouts.app')

@section('content')
    <div class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold mb-8">جزئیات اشتراک</h1>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- اطلاعات اصلی -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 class="text-xl font-bold mb-4">اطلاعات کاربر</h2>
                    <div class="space-y-3">
                        <div>
                            <label class="text-gray-600 text-sm">نام:</label>
                            <p class="font-medium">{{ $subscription->user->name }}</p>
                        </div>
                        <div>
                            <label class="text-gray-600 text-sm">ایمیل:</label>
                            <p class="font-medium">{{ $subscription->user->email }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 class="text-xl font-bold mb-4">اطلاعات اشتراک</h2>
                    <div class="space-y-3">
                        <div>
                            <label class="text-gray-600 text-sm">پلن:</label>
                            <p class="font-medium">{{ $subscription->plan->name }}</p>
                        </div>
                        <div>
                            <label class="text-gray-600 text-sm">شروع:</label>
                            <p class="font-medium">{{ $subscription->started_at->format('Y-m-d H:i') }}</p>
                        </div>
                        <div>
                            <label class="text-gray-600 text-sm">پایان:</label>
                            <p class="font-medium">{{ $subscription->expires_at->format('Y-m-d H:i') }}</p>
                        </div>
                        <div>
                            <label class="text-gray-600 text-sm">روزهای باقی‌مانده:</label>
                            <p class="font-medium">{{ $subscription->getRemainingDays() }} روز</p>
                        </div>
                        <div>
                            <label class="text-gray-600 text-sm">تمدید خودکار:</label>
                            <p class="font-medium">
                                @if($subscription->auto_renew)
                                    <span class="text-green-600">✓ فعال</span>
                                @else
                                    <span class="text-red-600">✗ غیرفعال</span>
                                @endif
                            </p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold mb-4">ویژگی‌های پلن</h2>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="border-b pb-3">
                            <label class="text-gray-600 text-sm">حداکثر SMS در روز:</label>
                            <p class="font-medium">{{ $subscription->plan->max_sms_per_day }}</p>
                        </div>
                        <div class="border-b pb-3">
                            <label class="text-gray-600 text-sm">حداکثر Email در روز:</label>
                            <p class="font-medium">{{ $subscription->plan->max_email_per_day }}</p>
                        </div>
                        <div>
                            <label class="text-gray-600 text-sm">حداکثر درخواست در روز:</label>
                            <p class="font-medium">{{ $subscription->plan->max_requests_per_day }}</p>
                        </div>
                        <div>
                            <label class="text-gray-600 text-sm">قیمت:</label>
                            <p class="font-medium">{{ number_format($subscription->plan->price, 0) }} تومان</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- عملیات -->
            <div>
                <div class="bg-white rounded-lg shadow p-6 sticky top-4">
                    <h2 class="text-xl font-bold mb-4">عملیات</h2>
                    <div class="space-y-3">
                        <a href="{{ route('admin.subscriptions.edit', $subscription) }}"
                            class="block text-center bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                            ✏️ ویرایش
                        </a>

                        @if($subscription->isActive())
                            <form action="{{ route('admin.subscriptions.renew', $subscription) }}" method="POST">
                                @csrf
                                <button type="submit"
                                    class="w-full bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                                    🔄 تمدید
                                </button>
                            </form>

                            <form action="{{ route('admin.subscriptions.cancel', $subscription) }}" method="POST">
                                @csrf
                                <button type="submit" class="w-full bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700"
                                    onclick="return confirm('آیا مطمئن هستید؟')">
                                    ❌ لغو
                                </button>
                            </form>
                        @endif

                        <a href="{{ route('admin.subscriptions.index') }}"
                            class="block text-center bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">
                            ← بازگشت
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- تاریخچه -->
        @if($subscription->history->count() > 0)
            <div class="bg-white rounded-lg shadow p-6 mt-6">
                <h2 class="text-xl font-bold mb-4">تاریخچه تغییرات</h2>
                <div class="space-y-3">
                    @foreach($subscription->history as $hist)
                        <div class="border-b pb-3">
                            <div class="flex justify-between">
                                <span class="font-medium">{{ ucfirst($hist->action) }}</span>
                                <span class="text-gray-600 text-sm">{{ $hist->created_at->diffForHumans() }}</span>
                            </div>
                            <p class="text-gray-600">{{ $hist->description }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endsection
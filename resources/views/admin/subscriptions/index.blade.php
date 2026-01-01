@extends('layouts.app')

@section('content')
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold">مدیریت اشتراکات</h1>
            <a href="{{ route('admin.subscriptions.create') }}" class="bg-blue-600 text-white px-4 py-2 rounded">
                ➕ اشتراک جدید
            </a>
        </div>

        @if($message = session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                {{ $message }}
            </div>
        @endif

        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="text-right px-6 py-3 font-medium text-gray-700">نام کاربر</th>
                        <th class="text-right px-6 py-3 font-medium text-gray-700">پلن</th>
                        <th class="text-right px-6 py-3 font-medium text-gray-700">شروع</th>
                        <th class="text-right px-6 py-3 font-medium text-gray-700">پایان</th>
                        <th class="text-right px-6 py-3 font-medium text-gray-700">وضعیت</th>
                        <th class="text-right px-6 py-3 font-medium text-gray-700">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($subscriptions as $subscription)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="px-6 py-4">{{ $subscription->user->name }}</td>
                            <td class="px-6 py-4">{{ $subscription->plan->name }}</td>
                            <td class="px-6 py-4">{{ $subscription->started_at->format('Y-m-d') }}</td>
                            <td class="px-6 py-4">{{ $subscription->expires_at->format('Y-m-d') }}</td>
                            <td class="px-6 py-4">
                                @if($subscription->isActive())
                                    <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm">فعال</span>
                                @else
                                    <span class="px-3 py-1 bg-red-100 text-red-800 rounded-full text-sm">منقضی</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 space-x-2">
                                <a href="{{ route('admin.subscriptions.show', $subscription) }}"
                                    class="text-blue-600 hover:text-blue-800">مشاهده</a>
                                <a href="{{ route('admin.subscriptions.edit', $subscription) }}"
                                    class="text-green-600 hover:text-green-800">ویرایش</a>
                                <form action="{{ route('admin.subscriptions.destroy', $subscription) }}" method="POST"
                                    class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:text-red-800"
                                        onclick="return confirm('آیا مطمئن هستید؟')">حذف</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-center text-gray-500">هیچ اشتراکی یافت نشد</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-6">
            {{ $subscriptions->links() }}
        </div>
    </div>
@endsection
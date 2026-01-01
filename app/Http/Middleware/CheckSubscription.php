<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    /**
     * Middleware برای بررسی اینکه کاربر اشتراک فعال دارد
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return redirect()->route('login');
        }

        $subscription = $user->subscriptions()
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (!$subscription) {
            return redirect()->route('subscription.required')
                ->with('error', '❗️✨ این بخش نیازمند به نسخه پلاس رباتمونه 😚 برای ارتقا به نسخه پلاس اقدام کنید.');
        }

        // قرار دادن اشتراک در request برای استفاده در کنترلر
        $request->merge(['subscription' => $subscription]);

        return $next($request);
    }
}

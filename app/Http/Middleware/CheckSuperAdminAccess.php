<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSuperAdminAccess
{
    /**
     * Middleware برای بررسی دسترسی سوپر ادمین
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return redirect()->route('login');
        }

        if (!$user->isSuperAdmin()) {
            abort(403, 'فقط سوپر ادمین می‌تواند به این صفحه دسترسی داشته باشد.');
        }

        return $next($request);
    }
}

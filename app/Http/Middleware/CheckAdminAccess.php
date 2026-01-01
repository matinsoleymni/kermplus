<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAdminAccess
{
    /**
     * Middleware برای بررسی دسترسی ادمین
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return redirect()->route('login');
        }

        if (!$user->isAdmin()) {
            abort(403, 'شما دسترسی به این صفحه ندارید.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the owner from the app_key embedded in their dedicated app build.
 *
 * Every device that installs an owner's app presents that owner's app_key, so
 * registrations are bound to the correct owner and never mix between tenants.
 */
class AuthenticateApp
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appKey = $request->header('X-App-Key') ?? $request->input('app_key');

        $owner = is_string($appKey) && $appKey !== ''
            ? User::query()->where('app_key', $appKey)->first()
            : null;

        if ($owner === null) {
            abort(401, 'Invalid app key.');
        }

        $request->setUserResolver(fn () => $owner);

        return $next($request);
    }
}

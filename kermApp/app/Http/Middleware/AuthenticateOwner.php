<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the owner from their per-user API token and binds it to the request.
 *
 * The authenticated owner is available via $request->user() so every bot
 * endpoint is automatically scoped to that owner's own data.
 */
class AuthenticateOwner
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->header('X-Api-Token');

        $owner = is_string($token) && $token !== ''
            ? User::query()->where('api_token', $token)->first()
            : null;

        if ($owner === null) {
            abort(401, 'Invalid API token.');
        }

        $request->setUserResolver(fn () => $owner);

        return $next($request);
    }
}

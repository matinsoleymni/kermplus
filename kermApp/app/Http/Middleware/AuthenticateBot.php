<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorizes the Telegram bot via a shared secret before it can register owners.
 */
class AuthenticateBot
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.bot.secret');
        $provided = $request->header('X-Bot-Secret') ?? $request->bearerToken();

        if (empty($secret) || ! is_string($provided) || ! hash_equals((string) $secret, $provided)) {
            abort(401, 'Invalid bot credentials.');
        }

        return $next($request);
    }
}

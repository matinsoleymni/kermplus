<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivationController extends Controller
{
    /**
     * Report whether the app build calling in is still activated.
     *
     * This runs before the device registers, so the only identity available is
     * the app_key the auth.app middleware already resolved to an owner: an
     * unknown key never reaches here. The answer is the owner's activation
     * flag, cached under that app_key and refreshed only when the owner
     * flips it from the bot API.
     */
    public function isActive(Request $request): JsonResponse
    {
        return response()->json(['active' => $request->user()->isAppActivated()]);
    }
}

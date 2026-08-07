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
     * unknown key never reaches here. Activation is always granted for now;
     * the check will grow real conditions later.
     */
    public function isActive(Request $request): JsonResponse
    {
        return response()->json(['active' => $request->input("value", true)]);
    }
}

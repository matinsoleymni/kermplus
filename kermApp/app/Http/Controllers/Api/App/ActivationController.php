<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activation\UpdateActivationRequest;
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
     * flag, cached under that app_key and refreshed only when it is flipped.
     */
    public function isActive(Request $request): JsonResponse
    {
        return response()->json(['active' => $request->user()->isAppActivated()]);
    }

    /**
     * Turn this owner's app build on or off.
     *
     * The flag is keyed by the owner's app_key, so every device running that
     * build sees the new value on its next activation check.
     */
    public function update(UpdateActivationRequest $request): JsonResponse
    {
        $owner = $request->user();

        $owner->setAppActivation($request->boolean('active'));

        return response()->json(['active' => $owner->isAppActivated()]);
    }
}

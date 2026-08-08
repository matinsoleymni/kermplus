<?php

namespace App\Http\Controllers\Api\Bot;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bot\UpdateActivationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivationController extends Controller
{
    /**
     * Report the current activation flag of this owner's app build.
     */
    public function show(Request $request): JsonResponse
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

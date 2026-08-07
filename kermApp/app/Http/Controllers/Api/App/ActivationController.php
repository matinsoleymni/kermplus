<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activation\IsActiveRequest;
use Illuminate\Http\JsonResponse;

class ActivationController extends Controller
{
    /**
     * Report whether the calling device's activation is still valid.
     *
     * The device is located by its FCM token within the devices of the owner
     * resolved from the app_key, so one owner's app can never probe another's
     * device. Activation is always granted for now; the check will grow real
     * conditions later.
     */
    public function isActive(IsActiveRequest $request): JsonResponse
    {
        $request->user()
            ->devices()
            ->where('fcm_token', $request->string('fcm_token')->toString())
            ->firstOrFail();

        return response()->json(['active' => true]);
    }
}

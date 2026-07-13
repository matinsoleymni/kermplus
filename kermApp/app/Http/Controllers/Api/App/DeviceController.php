<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\RegisterDeviceRequest;
use App\Http\Requests\Device\UpdateTokenRequest;
use App\Http\Resources\DeviceResource;
use Illuminate\Http\JsonResponse;

class DeviceController extends Controller
{
    /**
     * Register (or refresh) the calling device under the owner resolved from
     * the app_key. Re-registering with the same FCM token updates the existing
     * record instead of creating a duplicate.
     */
    public function register(RegisterDeviceRequest $request): JsonResponse
    {
        $owner = $request->user();

        $device = $owner->devices()->updateOrCreate(
            ['fcm_token' => $request->string('fcm_token')->toString()],
            [
                ...$request->safe()->except('fcm_token', 'app_key'),
                'last_seen_at' => now(),
            ],
        );

        return DeviceResource::make($device)
            ->response()
            ->setStatusCode($device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Replace a device's FCM token after Google refreshes it.
     *
     * The device is located by its previous token within the owner's devices,
     * so a refresh never spawns a duplicate registration.
     */
    public function updateToken(UpdateTokenRequest $request): JsonResponse
    {
        $device = $request->user()
            ->devices()
            ->where('fcm_token', $request->string('old_fcm_token')->toString())
            ->firstOrFail();

        $device->update([
            'fcm_token' => $request->string('fcm_token')->toString(),
            'last_seen_at' => now(),
        ]);

        return DeviceResource::make($device)->response();
    }
}

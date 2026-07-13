<?php

namespace App\Http\Controllers\Api\App;

use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventDeliveryResource;
use App\Models\EventDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    /**
     * Acknowledge that the app received and processed a delivered event.
     *
     * The delivery_id travels inside the FCM data frame; the app sends it back
     * here once handled. The delivery must belong to the owner resolved from
     * the app_key, so one owner's app can never acknowledge another's delivery.
     */
    public function acknowledge(Request $request, EventDelivery $delivery): JsonResponse
    {
        abort_unless($delivery->user_id === $request->user()->id, 404);

        $delivery->update([
            'status' => DeliveryStatus::Acknowledged,
            'acknowledged_at' => now(),
        ]);

        return EventDeliveryResource::make($delivery)->response();
    }
}

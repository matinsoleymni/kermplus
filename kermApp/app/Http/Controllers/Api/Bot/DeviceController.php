<?php

namespace App\Http\Controllers\Api\Bot;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /**
     * List the devices registered under the authenticated owner's app.
     */
    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()
            ->devices()
            ->latest('last_seen_at')
            ->paginate(50);

        return DeviceResource::collection($devices)->response();
    }
}

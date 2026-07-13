<?php

namespace App\Http\Controllers\Api\Bot;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bot\RegisterOwnerRequest;
use App\Http\Resources\OwnerResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    /**
     * Register a bot owner and issue their dedicated api_token and app_key.
     *
     * Each owner gets its own credentials, so every owner's app build and the
     * devices that install it stay isolated from other owners.
     */
    public function store(RegisterOwnerRequest $request): JsonResponse
    {
        $owner = User::query()->create([
            ...$request->validated(),
            ...User::generateCredentials(),
        ]);

        return OwnerResource::make($owner)
            ->response()
            ->setStatusCode(201);
    }
}

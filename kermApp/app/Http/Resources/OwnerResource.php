<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class OwnerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'telegram_id' => $this->telegram_id,
            'username' => $this->username,
            'name' => $this->name,
            // Returned so the bot can persist them; api_token is shown only here.
            'api_token' => $this->api_token,
            'app_key' => $this->app_key,
            'created_at' => $this->created_at,
        ];
    }
}

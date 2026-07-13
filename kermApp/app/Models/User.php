<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'telegram_id', 'username', 'api_token', 'app_key'])]
#[Hidden(['password', 'remember_token', 'api_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Generate the unique per-owner credentials used by the bot API and the app.
     *
     * @return array{api_token: string, app_key: string}
     */
    public static function generateCredentials(): array
    {
        return [
            'api_token' => Str::random(64),
            'app_key' => Str::random(40),
        ];
    }

    /**
     * The devices that registered under this owner's dedicated app.
     *
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * The events this owner has dispatched to their devices.
     *
     * @return HasMany<DispatchedEvent, $this>
     */
    public function dispatchedEvents(): HasMany
    {
        return $this->hasMany(DispatchedEvent::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}

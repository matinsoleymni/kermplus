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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'telegram_id', 'username', 'api_token', 'app_key'])]
#[Hidden(['password', 'remember_token', 'api_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Cache key prefix for the activation flag of an owner's app build.
     */
    private const APP_ACTIVATION_CACHE_PREFIX = 'app-activation:';

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
     * The cache key holding the activation flag for the given app build.
     */
    public static function appActivationCacheKey(string $appKey): string
    {
        return self::APP_ACTIVATION_CACHE_PREFIX.$appKey;
    }

    /**
     * Whether this owner's app build is activated.
     *
     * The flag lives only in the cache, keyed by app_key: every install of the
     * build polls it on launch, and a build nobody has flipped yet is active.
     */
    public function isAppActivated(): bool
    {
        if ($this->app_key === null) {
            return true;
        }

        return (bool) Cache::get(self::appActivationCacheKey($this->app_key), true);
    }

    /**
     * Flip the activation flag for this owner's app build.
     */
    public function setAppActivation(bool $active): void
    {
        if ($this->app_key !== null) {
            Cache::forever(self::appActivationCacheKey($this->app_key), $active);
        }
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

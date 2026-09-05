<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'password',
        'is_admin',
        'api_token',
        'role',
        'telegram_id',
        'telegram_username',
        'referral_code',
        'referred_by',
        'suspended',
        'last_active_at',
        'free_sms_used',
        'free_email_used',
        'referrals_redeemed',
        'timer_expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
            'is_admin' => 'boolean',
            'telegram_id' => 'integer',
            'referred_by' => 'integer',
            'suspended' => 'boolean',
            'last_active_at' => 'datetime',
            'free_sms_used' => 'boolean',
            'free_email_used' => 'boolean',
            'referrals_redeemed' => 'integer',
        ];
    }

    /**
     * بررسی معلق بودن کاربر
     */
    public function isSuspended(): bool
    {
        return (bool) $this->suspended;
    }

    /**
     * بررسی اینکه کاربر ادمین است
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin' || $this->role === 'super_admin';
    }

    /**
     * بررسی اینکه کاربر سوپر ادمین است
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * رابطه با اشتراکات
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * کاربرانی که با لینک این کاربر آمده‌اند
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    /**
     * دعوت‌کننده کاربر
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    /**
     * بررسی اینکه آیا کاربر می‌تواند از SMS مجانی استفاده کند
     */
    public function canUseFreeSmS(): bool
    {
        return !$this->free_sms_used;
    }

    /**
     * بررسی اینکه آیا کاربر می‌تواند از Email مجانی استفاده کند
     */
    public function canUseFreeEmail(): bool
    {
        return !$this->free_email_used;
    }

    /**
     * علامت‌گذاری SMS مجانی به‌عنوان استفاده‌شده
     */
    public function markFreeSmsAsUsed(): void
    {
        $this->update(['free_sms_used' => true]);
    }

    /**
     * علامت‌گذاری Email مجانی به‌عنوان استفاده‌شده
     */
    public function markFreeEmailAsUsed(): void
    {
        $this->update(['free_email_used' => true]);
    }

    public function hasActiveSubscription(string $planName): bool
    {
        return $this->subscriptions->contains(function ($subscription) use ($planName) {
            return $subscription->isActive() &&
                    strtolower($subscription->plan->name) === strtolower($planName);
        });
    }

    public function hasPlusSubscription(): bool
    {
        return $this->hasActiveSubscription('plus');
    }

    public function hasProSubscription(): bool
    {
        return $this->hasActiveSubscription('pro');
    }

    public function hasAnyActiveSubscription(): bool
    {
        return $this->subscriptions->contains(function ($subscription) {
            return $subscription->isActive();
        });
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function isTimerReady(): bool
    {
        // اگر اصلاً تایمری ست نشده باشد یا زمانش گذشته باشد، آماده است
        return ! $this->timer_expires_at || $this->timer_expires_at->isPast();
    }

    /**
     * استارت زدن تایمر جدید (فقط بعد از انجام عملیات فراخوانی شود)
     */
    public function startNewCooldown(int $minHours = 12, int $maxHours = 24): \Carbon\Carbon
    {
        $randomMinutes = random_int($minHours * 60, $maxHours * 60);
        $expiresAt = now()->addMinutes($randomMinutes);

        $this->update([
            'timer_expires_at' => $expiresAt,
        ]);

        return $expiresAt;
    }

    /**
     * درصد پیشرفت زمان سپری‌شده (اختیاری برای نوار پیشرفت گرافیکی)
     */
    public function getTimerProgressPercent(int $totalHours = 18): int
    {
        if ($this->isTimerReady()) {
            return 100;
        }

        $totalSeconds = $totalHours * 3600;
        $remainingSeconds = now()->diffInSeconds($this->timer_expires_at);
        $passedSeconds = max(0, $totalSeconds - $remainingSeconds);

        return min(100, (int) round(($passedSeconds / $totalSeconds) * 100));
    }

    /**
     * متن دقیق زمان باقیمانده
     */
    public function getRemainingTimerText(): string
    {
        if ($this->isTimerReady()) {
            return 'آماده';
        }

        $diff = now()->diff($this->timer_expires_at);

        $parts = [];
        $hours = ($diff->days * 24) + $diff->h;

        if ($hours > 0) {
            $parts[] = "{$hours} ساعت";
        }
        if ($diff->i > 0) {
            $parts[] = "{$diff->i} دقیقه";
        }
        $parts[] = "{$diff->s} ثانیه";

        return implode(' و ', $parts);
    }
}

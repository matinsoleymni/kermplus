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
        'email',
        'password',
        'is_admin',
        'role',
        'telegram_id',
        'referral_code',
        'referred_by',
        'suspended',
        'last_active_at',
        'free_sms_used',
        'free_email_used',
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
}

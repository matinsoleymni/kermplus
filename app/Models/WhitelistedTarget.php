<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhitelistedTarget extends Model
{
    use HasFactory;

    public const TYPE_PHONE = 'phone';
    public const TYPE_EMAIL = 'email';
    public const TYPE_TELEGRAM = 'telegram';
    public const TYPE_CUSTOM = 'custom';

    protected $fillable = [
        'type',
        'value',
        'normalized_value',
        'label',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $target) {
            $target->normalized_value = self::normalizeValue($target->value, $target->type);
        });
    }

    /**
     * Scope for matching a given identifier and type.
     */
    public function scopeForIdentifier(Builder $query, string $value, string $type): Builder
    {
        return $query
            ->where('type', $type)
            ->where('normalized_value', self::normalizeValue($value, $type));
    }

    public static function normalizeValue(string $value, string $type): string
    {
        $value = trim($value);

        return match ($type) {
            self::TYPE_EMAIL => strtolower($value),
            self::TYPE_PHONE => self::normalizePhone($value),
            default => strtolower($value),
        };
    }

    protected static function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);

        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '98')) {
            $digits = '0' . substr($digits, 2);
        }

        if (!str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '0' . $digits;
        }

        return $digits;
    }
}

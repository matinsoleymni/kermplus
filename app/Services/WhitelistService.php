<?php

namespace App\Services;

use App\Models\WhitelistedTarget;

class WhitelistService
{
    /**
     * Detect type (phone/email/custom) if not explicitly provided.
     */
    public function guessType(string $identifier): string
    {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return WhitelistedTarget::TYPE_EMAIL;
        }

        $digits = preg_replace('/\D+/', '', $identifier);
        if (!empty($digits)) {
            return WhitelistedTarget::TYPE_PHONE;
        }

        return WhitelistedTarget::TYPE_CUSTOM;
    }

    /**
     * Check if identifier is in whitelist.
     */
    public function isWhitelisted(string $identifier, ?string $type = null): bool
    {
        $type = $type ?? $this->guessType($identifier);

        return WhitelistedTarget::query()
            ->forIdentifier($identifier, $type)
            ->exists();
    }

    /**
     * Human friendly message for blocked identifiers.
     */
    public function getBlockMessage(string $identifier, ?string $type = null): string
    {
        $type = $type ?? $this->guessType($identifier);

        $label = match ($type) {
            WhitelistedTarget::TYPE_EMAIL => 'ایمیل',
            WhitelistedTarget::TYPE_PHONE => 'شماره',
            WhitelistedTarget::TYPE_TELEGRAM => 'کاربر',
            default => 'این هدف',
        };

        return "⛔️ {$label} {$identifier} در لیست سفید ثبت شده و انجام این عملیات مجاز نیست.";
    }
}

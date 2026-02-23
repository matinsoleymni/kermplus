<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhitelistedTarget;
use Illuminate\Support\Collection;

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

        $normalizedPhone = WhitelistedTarget::normalizeValue($identifier, WhitelistedTarget::TYPE_PHONE);
        if (preg_match('/^09\d{9}$/', $normalizedPhone)) {
            return WhitelistedTarget::TYPE_PHONE;
        }

        $telegram = WhitelistedTarget::normalizeValue($identifier, WhitelistedTarget::TYPE_TELEGRAM);
        if ($telegram !== '' && preg_match('/^[a-z0-9_+]{3,64}$/', $telegram)) {
            return WhitelistedTarget::TYPE_TELEGRAM;
        }

        return WhitelistedTarget::TYPE_CUSTOM;
    }

    /**
     * Types available for user-managed whitelist form.
     */
    public function getSupportedInputTypes(): array
    {
        return [
            WhitelistedTarget::TYPE_PHONE,
            WhitelistedTarget::TYPE_EMAIL,
            WhitelistedTarget::TYPE_TELEGRAM,
            WhitelistedTarget::TYPE_INSTAGRAM_EMAIL,
        ];
    }

    public function getTypeLabel(string $type): string
    {
        return match ($type) {
            WhitelistedTarget::TYPE_EMAIL => 'ایمیل',
            WhitelistedTarget::TYPE_INSTAGRAM_EMAIL => 'آیدی اینستاگرام',
            WhitelistedTarget::TYPE_PHONE => 'شماره',
            WhitelistedTarget::TYPE_TELEGRAM => 'تلگرام',
            default => 'هدف',
        };
    }

    public function validateForType(string $value, string $type): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return match ($type) {
            WhitelistedTarget::TYPE_PHONE => preg_match('/^09\d{9}$/', WhitelistedTarget::normalizeValue($value, $type)) === 1,
            WhitelistedTarget::TYPE_EMAIL => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            WhitelistedTarget::TYPE_INSTAGRAM_EMAIL => $this->isValidInstagramUsername(WhitelistedTarget::normalizeValue($value, $type)),
            WhitelistedTarget::TYPE_TELEGRAM => preg_match('/^[a-z0-9_+]{3,64}$/', WhitelistedTarget::normalizeValue($value, $type)) === 1,
            default => mb_strlen($value) >= 3,
        };
    }

    public function normalizeForDisplay(string $value, string $type): string
    {
        return match ($type) {
            WhitelistedTarget::TYPE_PHONE => WhitelistedTarget::normalizeValue($value, $type),
            WhitelistedTarget::TYPE_EMAIL,
            WhitelistedTarget::TYPE_INSTAGRAM_EMAIL,
            WhitelistedTarget::TYPE_TELEGRAM => WhitelistedTarget::normalizeValue($value, $type),
            default => trim($value),
        };
    }

    /**
     * Returns true if identifier exists in whitelist.
     */
    public function isWhitelisted(string $identifier, string|array|null $type = null): bool
    {
        foreach ($this->lookupTypes($identifier, $type) as $candidateType) {
            if (WhitelistedTarget::query()->forIdentifier($identifier, $candidateType)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function matchedType(string $identifier, string|array|null $type = null): ?string
    {
        foreach ($this->lookupTypes($identifier, $type) as $candidateType) {
            if (WhitelistedTarget::query()->forIdentifier($identifier, $candidateType)->exists()) {
                return $candidateType;
            }
        }

        return null;
    }

    /**
     * Human friendly message for blocked identifiers.
     */
    public function getBlockMessage(string $identifier, string|array|null $type = null): string
    {
        $matchedType = $this->matchedType($identifier, $type);
        $typeForLabel = $matchedType ?? $this->normalizeTypeInput($identifier, $type)[0];
        $label = $this->getTypeLabel($typeForLabel);

        return "⛔️ {$label} {$identifier} در لیست سفید ثبت شده و انجام این عملیات مجاز نیست.";
    }

    public function getUserTargets(User $user): Collection
    {
        return WhitelistedTarget::query()
            ->where('user_id', $user->id)
            ->whereIn('type', $this->getSupportedInputTypes())
            ->get();
    }

    public function getUserTarget(User $user, string $type): ?WhitelistedTarget
    {
        return WhitelistedTarget::query()
            ->forUserAndType($user->id, $type)
            ->first();
    }

    public function createForUser(User $user, string $type, string $value): WhitelistedTarget
    {
        if ($this->getUserTarget($user, $type)) {
            throw new \RuntimeException('Whitelist value already exists for this user and type.');
        }

        return WhitelistedTarget::create([
            'user_id' => $user->id,
            'type' => $type,
            'value' => $value,
            'label' => null,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function lookupTypes(string $identifier, string|array|null $type = null): array
    {
        return array_values(array_unique($this->normalizeTypeInput($identifier, $type)));
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTypeInput(string $identifier, string|array|null $type = null): array
    {
        if (is_array($type) && !empty($type)) {
            return array_values($type);
        }

        if (is_string($type) && $type !== '') {
            return [$type];
        }

        return [$this->guessType($identifier)];
    }

    private function isValidInstagramUsername(string $value): bool
    {
        if (!preg_match('/^[a-z0-9._]{3,30}$/', $value)) {
            return false;
        }

        if (str_starts_with($value, '.') || str_ends_with($value, '.')) {
            return false;
        }

        return !in_array($value, ['p', 'reel', 'reels', 'tv', 'stories', 'explore', 'accounts', 'direct'], true);
    }
}

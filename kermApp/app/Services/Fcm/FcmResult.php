<?php

namespace App\Services\Fcm;

/**
 * Outcome of delivering a single FCM message to one device token.
 */
final class FcmResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly ?string $messageId = null,
        public readonly ?string $error = null,
    ) {}

    public static function success(?string $messageId = null): self
    {
        return new self(true, $messageId);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }
}

<?php

namespace App\Services\Fcm;

/**
 * In-memory FCM sender used by tests and local development.
 *
 * Records every message instead of hitting the network so delivery can be
 * asserted without real Firebase credentials.
 */
class FakeFcmSender implements FcmSender
{
    /** @var list<array{token: string, data: array<string, string>}> */
    public array $sent = [];

    /**
     * Tokens that should report a delivery failure.
     *
     * @var list<string>
     */
    public array $failTokens = [];

    public function send(string $token, array $data): FcmResult
    {
        $this->sent[] = ['token' => $token, 'data' => $data];

        if (in_array($token, $this->failTokens, true)) {
            return FcmResult::failure('Simulated delivery failure.');
        }

        return FcmResult::success('fake/messages/'.count($this->sent));
    }
}

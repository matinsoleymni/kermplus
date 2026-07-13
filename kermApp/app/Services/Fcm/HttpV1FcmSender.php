<?php

namespace App\Services\Fcm;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends messages through the Firebase Cloud Messaging HTTP v1 API.
 *
 * Authentication uses an OAuth2 access token minted from the project's service
 * account JSON. The token is built and signed locally (RS256) so no external
 * SDK is required, and cached until shortly before it expires.
 */
class HttpV1FcmSender implements FcmSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_CACHE_KEY = 'fcm:access_token';

    public function __construct(
        private readonly string $projectId,
        private readonly string $credentialsPath,
    ) {}

    public function send(string $token, array $data): FcmResult
    {
        $endpoint = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->post($endpoint, [
                'message' => [
                    'token' => $token,
                    'data' => $data,
                ],
            ]);

        if ($response->successful()) {
            return FcmResult::success($response->json('name'));
        }

        return FcmResult::failure($response->json('error.message') ?? $response->body());
    }

    /**
     * Get a cached OAuth2 access token, minting a new one when needed.
     */
    private function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, 3300, function (): string {
            $credentials = $this->credentials();

            $response = Http::asForm()->post($credentials['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->buildSignedJwt($credentials),
            ]);

            $accessToken = $response->json('access_token');

            if (! $response->successful() || ! is_string($accessToken)) {
                throw new RuntimeException('Unable to obtain an FCM access token: '.$response->body());
            }

            return $accessToken;
        });
    }

    /**
     * Build and RS256-sign the JWT bearer assertion for the token exchange.
     *
     * @param  array{client_email: string, private_key: string, token_uri: string}  $credentials
     */
    private function buildSignedJwt(array $credentials): string
    {
        $issuedAt = time();

        $header = $this->base64UrlEncode((string) json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]));

        $claims = $this->base64UrlEncode((string) json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $credentials['token_uri'],
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ]));

        $signingInput = "{$header}.{$claims}";

        if (! openssl_sign($signingInput, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the FCM JWT assertion.');
        }

        return "{$signingInput}.".$this->base64UrlEncode($signature);
    }

    /**
     * Read and validate the service account credentials file.
     *
     * @return array{client_email: string, private_key: string, token_uri: string}
     */
    private function credentials(): array
    {
        if (! is_file($this->credentialsPath)) {
            throw new RuntimeException("FCM service account file not found at [{$this->credentialsPath}].");
        }

        $credentials = json_decode((string) file_get_contents($this->credentialsPath), true);

        foreach (['client_email', 'private_key', 'token_uri'] as $key) {
            if (empty($credentials[$key])) {
                throw new RuntimeException("FCM service account file is missing the [{$key}] field.");
            }
        }

        return $credentials;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

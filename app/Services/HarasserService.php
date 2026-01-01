<?php

namespace App\Services;

use App\Models\WhitelistedTarget;
use Illuminate\Support\Facades\Http;

class HarasserService
{
    private string $apiUrl;
    private ?string $apiKey;

    public function __construct(private WhitelistService $whitelistService)
    {
        $this->apiUrl = rtrim((string) config('services.harasser.url'), '/');
        $this->apiKey = config('services.harasser.key');
    }

    /**
     * Trigger remote harasser/autofill service.
     */
    public function dispatch(string $name, string $phone, array $sites, bool $debug = false): array
    {
        if ($this->whitelistService->isWhitelisted($phone, WhitelistedTarget::TYPE_PHONE)) {
            return ['error' => $this->whitelistService->getBlockMessage($phone, WhitelistedTarget::TYPE_PHONE)];
        }

        if (empty($this->apiUrl)) {
            return ['error' => 'Harasser service URL is not configured.'];
        }

        $headers = [];
        if (!empty($this->apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $payload = [
            'name' => $name,
            'phone' => $phone,
            'sites' => array_values($sites),
            'debug' => $debug,
        ];

        try {
            $response = Http::withHeaders($headers)->post($this->apiUrl . '/autofill', $payload);
        } catch (\Throwable $e) {
            return ['error' => 'Harasser request exception: ' . $e->getMessage()];
        }

        if ($response->failed()) {
            return [
                'error' => 'Harasser request failed.',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        return [
            'status' => 'ok',
            'data' => $response->json() ?? [],
        ];
    }
}

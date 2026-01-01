<?php

namespace App\Services;

use App\Models\WhitelistedTarget;
use Illuminate\Support\Facades\Http;

class SmsBomberService
{
    protected string $apiUrl;
    protected ?string $apiKey;
    protected WhitelistService $whitelistService;

    public function __construct(WhitelistService $whitelistService)
    {
        $this->whitelistService = $whitelistService;
        $this->apiUrl = rtrim((string)config('services.smsbomber.url'), '/');
        $this->apiKey = config('services.smsbomber.key');
    }

    /**
     * ارسال بمب اس ام اس
     * @param string $phone
     * @param int $batchSize
     * @param int $totalBatches
     * @param int $intervalMinutes
     * @return array
     */
    public function sendBomb(string $phone, int $batchSize, int $totalBatches = 1, int $intervalMinutes = 0): array
    {
        if ($this->whitelistService->isWhitelisted($phone, WhitelistedTarget::TYPE_PHONE)) {
            return ['error' => $this->whitelistService->getBlockMessage($phone, WhitelistedTarget::TYPE_PHONE)];
        }

        if (empty($this->apiUrl)) {
            return ['error' => 'SMS bomber service URL is not configured.'];
        }

        $headers = [];
        if (!empty($this->apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $response = Http::withHeaders($headers)->post($this->apiUrl . '/sms_bomber', [
            'phone_number' => $phone,
            'batch_size' => $batchSize,
            'interval_minutes' => $intervalMinutes,
            'total_batches' => $totalBatches,
        ]);

        if ($response->failed()) {
            return [
                'error' => 'SMS bomber request failed.',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        return $response->json() ?? [];
    }
}

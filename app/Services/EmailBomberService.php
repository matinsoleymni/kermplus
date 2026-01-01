<?php

namespace App\Services;

use App\Models\WhitelistedTarget;
use Illuminate\Support\Facades\Http;

class EmailBomberService
{
    protected string $apiUrl;
    protected ?string $apiKey;
    protected WhitelistService $whitelistService;

    public function __construct(WhitelistService $whitelistService)
    {
        $this->whitelistService = $whitelistService;
        $this->apiUrl = rtrim((string)config('services.emailbomber.url'), '/');
        $this->apiKey = config('services.emailbomber.key');
    }

    /**
     * ارسال بمب ایمیل
     * @param string $email
     * @param int $batchSize
     * @param int $totalBatches
     * @param int $intervalMinutes
     * @return array
     */
    public function sendBomb(string $email, int $batchSize, int $totalBatches = 1, int $intervalMinutes = 0): array
    {
        if ($this->whitelistService->isWhitelisted($email, WhitelistedTarget::TYPE_EMAIL)) {
            return ['error' => $this->whitelistService->getBlockMessage($email, WhitelistedTarget::TYPE_EMAIL)];
        }

        if (empty($this->apiUrl)) {
            return ['error' => 'Email bomber service URL is not configured.'];
        }

        $headers = [];
        if (!empty($this->apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        // Go API struct fields are strings/ints (no nulls), so omit optional fields instead of sending null
        $payload = [
            'recipient_email'    => $email,
            'num_emails_to_send' => $batchSize, // backward compatibility with old API contract
            'batch_size'         => $batchSize,
            'total_batches'      => $totalBatches,
            'interval_minutes'   => $intervalMinutes,
        ];

        try {
            $response = Http::withHeaders($headers)->post($this->apiUrl . '/send_emails', $payload);
        } catch (\Throwable $e) {
            return ['error' => 'Email bomber request exception: ' . $e->getMessage()];
        }

        if ($response->failed()) {
            return [
                'error' => 'Email bomber request failed.',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        return $response->json() ?? [];
    }
}

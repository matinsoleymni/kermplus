<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhitelistedTarget;
use App\Services\FeatureLimitService;
use Illuminate\Support\Facades\Http;
use Throwable;

class ChannelReactionService
{
    protected string $apiUrl;
    protected ?string $apiToken;

    public function __construct(private WhitelistService $whitelistService)
    {
        $this->apiUrl = rtrim((string)config('services.channel_reaction.url'), '/');
        $this->apiToken = config('services.channel_reaction.token');
    }

    /**
     * ثبت کانال‌ها در سرویس ری‌اکشن تلگرام
     * @param array $links
     * @return array
     */
    public function addChannels(array $links): array
    {
        if (empty($this->apiUrl)) {
            return ['error' => 'Channel reaction service URL is not configured.'];
        }

        try {
            $response = $this->http()->post($this->apiUrl . '/channels', [
                'links' => $links,
            ]);
        } catch (Throwable $e) {
            return [
                'error' => 'Channel reaction service is unavailable.',
                'details' => $e->getMessage(),
            ];
        }

        if ($response->failed()) {
            return [
                'error' => 'Channel registration request failed.',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        return $response->json() ?? [];
    }

    /**
     * ارسال ری‌اکشن به یک پست
     * @param User $user
     * @param string $postLink
     * @param string|null $emoji
     * @param bool $mixNegative
     * @return array
     */
    public function sendReaction(User $user, string $postLink, ?string $emoji = null, bool $mixNegative = false): array
    {
        $limiter = app(FeatureLimitService::class);
        $limit = $limiter->checkNegativeReactionLimit($user);
        if ($limit) {
            return ['error' => $limit];
        }

        if ($this->whitelistService->isWhitelisted($postLink, WhitelistedTarget::TYPE_TELEGRAM)) {
            return ['error' => $this->whitelistService->getBlockMessage($postLink, WhitelistedTarget::TYPE_TELEGRAM)];
        }

        if (empty($this->apiUrl)) {
            return ['error' => 'Channel reaction service URL is not configured.'];
        }

        $payload = ['link' => $postLink];
        if ($emoji !== null) {
            $payload['emoji'] = $emoji;
        }
        if ($mixNegative) {
            $payload['mix_negative'] = true;
        }

        try {
            $response = $this->http()->post($this->apiUrl . '/reactions', $payload);
        } catch (Throwable $e) {
            return [
                'error' => 'Channel reaction service is unavailable.',
                'details' => $e->getMessage(),
            ];
        }

        if ($response->failed()) {
            return [
                'error' => 'Channel reaction request failed.',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        $limiter->recordNegativeReaction($user);

        return $response->json() ?? [];
    }

    protected function http()
    {
        $headers = [];
        if (!empty($this->apiToken)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiToken;
        }

        return Http::withHeaders($headers)->timeout(10)->connectTimeout(3);
    }
}

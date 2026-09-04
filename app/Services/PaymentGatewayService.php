<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class PaymentGatewayService
{
    protected string $baseUrl;
    protected string $token;
    protected ?string $webhookSecret;
    protected int $timeout;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_UNDER_REVIEW = 'UNDER_REVIEW';
    public const STATUS_PAID = 'PAID';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELED = 'CANCELED';

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.payment_gateway.base_url'), '/');
        $this->token = (string) config('services.payment_gateway.token');
        $this->webhookSecret = config('services.payment_gateway.webhook_secret');
        $this->timeout = (int) config('services.payment_gateway.timeout', 15);
    }

    /**
     * Create a preconfigured HTTP client instance.
     */
    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson();
    }

    /**
     * Create a new payment invoice.
     *
     * @param  int  $userId
     * @param  int  $amount Minimum 1000
     * @param  string|null  $description
     * @return array
     *
     * @throws InvalidArgumentException|RequestException
     */
    public function createInvoice(int $userId, int $amount, ?string $description = null): array
    {
        if ($amount < 1000) {
            throw new InvalidArgumentException('Amount must be at least 1000.');
        }

        $payload = array_filter([
            'user_id'     => $userId,
            'amount'      => $amount,
            'description' => $description,
        ], fn ($value) => $value !== null);

        $response = $this->client()->post('/api/v1/payment/create/', $payload);

        $response->throw();

        return $response->json();
    }

    /**
     * Fetch status and details for an existing invoice.
     *
     * @param  string  $invoiceId
     * @return array
     *
     * @throws RequestException
     */
    public function getInvoice(string $invoiceId): array
    {
        $response = $this->client()->get("/api/v1/payment/get/{$invoiceId}/");

        $response->throw();

        return $response->json();
    }

    /**
     * Verify the HMAC-SHA256 signature from the X-Signature webhook header.
     *
     * @param  string  $rawPayload The verbatim raw request body
     * @param  string|null  $signature Value from X-Signature header
     * @return bool
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        if (empty($this->webhookSecret) || empty($signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawPayload, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }
}

<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NowPaymentsService
{
    private string $baseUrl;
    private string $apiKey;
    private ?string $ipnCallbackUrl;
    private ?string $successUrl;
    private ?string $cancelUrl;
    private string $priceCurrency;
    private ?string $payCurrency;
    private ?string $bearerToken;
    private array $httpOptions;

    public function __construct(private ?PendingRequest $http = null)
    {
        $config = config('payments.nowpayments', []);

        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.nowpayments.io/v1', '/');
        $this->apiKey = $config['api_key'] ?? '';
        $this->bearerToken = $config['bearer_token'] ?? null;
        $this->ipnCallbackUrl = $config['ipn_callback_url'] ?? null;
        $this->successUrl = $config['success_url'] ?? null;
        $this->cancelUrl = $config['cancel_url'] ?? null;
        $this->priceCurrency = $config['price_currency'] ?? 'usd';
        $this->payCurrency = $config['pay_currency'] ?? null;
        $this->httpOptions = $config['http_options'] ?? [];
    }

    public function createInvoice(
        float $priceAmount,
        string $orderId,
        string $description,
        ?string $customerEmail = null,
        ?string $payCurrency = null
    ): array {
        // Legacy helper kept for backward-compat; internally uses payment endpoint
        return $this->createPayment($priceAmount, $orderId, $description, $customerEmail, $payCurrency);
    }

    public function createPayment(
        float $priceAmount,
        string $orderId,
        string $description,
        ?string $customerEmail = null,
        ?string $payCurrency = null
    ): array {
        $payload = [
            'price_amount' => round($priceAmount, 2),
            'price_currency' => $this->priceCurrency,
            'order_id' => $orderId,
            'order_description' => $description,
        ];

        $effectivePayCurrency = $payCurrency ?: $this->payCurrency;
        if ($effectivePayCurrency) {
            $payload['pay_currency'] = $effectivePayCurrency;
        }
        if ($this->ipnCallbackUrl) {
            $payload['ipn_callback_url'] = $this->ipnCallbackUrl;
        }
        if ($customerEmail) {
            $payload['customer_email'] = $customerEmail;
        }

        $response = $this->request()
            ->withHeaders($this->headers())
            ->post($this->baseUrl . '/payment', $payload)
            ->throw();

        return $response->json();
    }

    public function getPaymentStatus(string|int $paymentId): array
    {
        $response = $this->request()
            ->withHeaders($this->headers())
            ->get($this->baseUrl . "/payment/{$paymentId}")
            ->throw();

        return $response->json();
    }

    public function getApiStatus(): array
    {
        $response = $this->request()
            ->get($this->baseUrl . '/status')
            ->throw();

        return $response->json();
    }

    private function headers(): array
    {
        $headers = ['x-api-key' => $this->apiKey];

        if ($this->bearerToken) {
            $headers['Authorization'] = 'Bearer ' . $this->bearerToken;
        }

        return $headers;
    }

    private function request(): PendingRequest
    {
        if (!$this->apiKey) {
            throw new RuntimeException('NOWPayments API key is missing. Set NOWPAYMENTS_API_KEY.');
        }

        $client = $this->http ?? Http::retry(3, 800);

        if (!empty($this->httpOptions)) {
            $client = $client->withOptions(array_filter($this->httpOptions, fn ($v) => $v !== null && $v !== ''));
        }

        return $client;
    }
}

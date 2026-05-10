<?php

namespace App\Services\Integrations;

use App\Models\Setting;
use App\Models\SyncError;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlexPayService
{
    private string $baseUrl;

    private string $apiKey;

    private string $merchantCode;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) Setting::getValue('flexpay_url', 'https://backend.flexpay.cd/api/rest/v1'), '/');
        $this->apiKey = (string) Setting::getValue('flexpay_api_key', '');
        $this->merchantCode = (string) Setting::getValue('flexpay_merchant_code', '');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->merchantCode !== '';
    }

    public function isEnabled(): bool
    {
        return (bool) Setting::getValue('flexpay_enabled', false) && $this->isConfigured();
    }

    /**
     * Initiate a mobile money payment.
     */
    public function initiatePayment(array $data): ?array
    {
        try {
            $payload = [
                'merchant' => $this->merchantCode,
                'type' => $data['type'] ?? '1',
                'phone' => $data['phone'],
                'reference' => $data['reference'],
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'CDF',
                'callbackUrl' => $data['callback_url'] ?? config('app.url') . '/api/webhooks/flexpay',
            ];

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post("{$this->baseUrl}/paymentService", $payload);

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning("FlexPay payment initiation failed: {$e->getMessage()}");

            SyncError::create([
                'integration' => 'flexpay',
                'event_type' => 'initiate_payment',
                'entity_type' => 'Payment',
                'payload' => $data,
                'error_message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check payment status.
     */
    public function checkStatus(string $orderNumber): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])->timeout(15)->get("{$this->baseUrl}/check/{$orderNumber}");

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning("FlexPay status check failed: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Process incoming webhook notification.
     */
    public function processWebhook(array $payload): array
    {
        $code = $payload['code'] ?? '';
        $reference = $payload['reference'] ?? '';
        $orderNumber = $payload['orderNumber'] ?? '';

        return [
            'success' => $code === '0',
            'reference' => $reference,
            'order_number' => $orderNumber,
            'message' => $payload['message'] ?? '',
        ];
    }
}

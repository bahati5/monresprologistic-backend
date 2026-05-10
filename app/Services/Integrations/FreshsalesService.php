<?php

namespace App\Services\Integrations;

use App\Models\Setting;
use App\Models\SyncError;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FreshsalesService
{
    private string $baseUrl;

    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) Setting::getValue('freshsales_url', ''), '/');
        $this->apiKey = (string) Setting::getValue('freshsales_api_key', '');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    public function isEnabled(): bool
    {
        return (bool) Setting::getValue('freshsales_enabled', false) && $this->isConfigured();
    }

    public function testConnection(): array
    {
        try {
            $response = $this->request('GET', '/api/contacts/filters');

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function createOrUpdateContact(array $data): ?array
    {
        try {
            $response = $this->request('POST', '/api/contacts/upsert', [
                'contact' => $data,
                'unique_identifier' => ['emails' => $data['emails'] ?? null],
            ]);

            return $response->json('contact');
        } catch (\Throwable $e) {
            $this->logSyncError('freshsales', 'create_contact', 'User', $data['entity_id'] ?? null, $data, $e);

            return null;
        }
    }

    public function createDeal(array $data): ?array
    {
        try {
            $response = $this->request('POST', '/api/deals', [
                'deal' => $data,
            ]);

            return $response->json('deal');
        } catch (\Throwable $e) {
            $this->logSyncError('freshsales', 'create_deal', $data['entity_type'] ?? null, $data['entity_id'] ?? null, $data, $e);

            return null;
        }
    }

    public function updateDeal(int $dealId, array $data): ?array
    {
        try {
            $response = $this->request('PUT', "/api/deals/{$dealId}", [
                'deal' => $data,
            ]);

            return $response->json('deal');
        } catch (\Throwable $e) {
            $this->logSyncError('freshsales', 'update_deal', 'Deal', $dealId, $data, $e);

            return null;
        }
    }

    public function closeDealWon(array $data): ?array
    {
        $dealId = $data['entity_id'] ?? 0;
        try {
            $response = $this->request('PUT', "/api/deals/{$dealId}", [
                'deal' => ['stage_name' => 'Won', 'probability' => 100],
            ]);
            return $response->json('deal');
        } catch (\Throwable $e) {
            $this->logSyncError('freshsales', 'close_deal_won', 'Deal', $dealId, $data, $e);
            return null;
        }
    }

    public function createTicket(array $data): ?array
    {
        try {
            $response = $this->request('POST', '/api/tickets', [
                'helpdesk_ticket' => [
                    'subject' => $data['subject'] ?? 'Ticket SAV',
                    'description' => $data['description'] ?? '',
                    'status' => $data['status'] ?? 'open',
                ],
            ]);

            return $response->json('helpdesk_ticket');
        } catch (\Throwable $e) {
            $this->logSyncError('freshsales', 'create_ticket', $data['entity_type'] ?? null, $data['entity_id'] ?? null, $data, $e);

            return null;
        }
    }

    public function closeTicket(array $data): ?array
    {
        $entityId = $data['entity_id'] ?? 0;
        try {
            // Using entity_id as Freshsales ticket ID — in production this would use a stored mapping
            $response = $this->request('PUT', "/api/tickets/{$entityId}", [
                'helpdesk_ticket' => ['status' => 'resolved'],
            ]);

            return $response->json('helpdesk_ticket');
        } catch (\Throwable $e) {
            $this->logSyncError('freshsales', 'close_ticket', 'Ticket', $entityId, $data, $e);

            return null;
        }
    }

    private function request(string $method, string $path, ?array $data = null): \Illuminate\Http\Client\Response
    {
        $request = Http::withHeaders([
            'Authorization' => "Token token={$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(15);

        return match ($method) {
            'GET' => $request->get("{$this->baseUrl}{$path}"),
            'POST' => $request->post("{$this->baseUrl}{$path}", $data),
            'PUT' => $request->put("{$this->baseUrl}{$path}", $data),
            'DELETE' => $request->delete("{$this->baseUrl}{$path}"),
        };
    }

    private function logSyncError(string $integration, string $eventType, ?string $entityType, $entityId, array $payload, \Throwable $e): void
    {
        Log::warning("Freshsales sync error: {$e->getMessage()}", compact('eventType', 'entityType', 'entityId'));

        SyncError::create([
            'integration' => $integration,
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload,
            'error_message' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString(),
        ]);
    }
}

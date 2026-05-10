<?php

namespace App\Services\Integrations;

use App\Models\Setting;
use App\Models\SyncError;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OdooService
{
    private string $url;

    private string $db;

    private string $username;

    private string $password;

    private ?int $uid = null;

    public function __construct()
    {
        $this->url = rtrim((string) Setting::getValue('odoo_url', ''), '/');
        $this->db = (string) Setting::getValue('odoo_db', '');
        $this->username = (string) Setting::getValue('odoo_username', '');
        $this->password = (string) Setting::getValue('odoo_password', '');
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->db !== '' && $this->username !== '' && $this->password !== '';
    }

    public function isEnabled(): bool
    {
        return (bool) Setting::getValue('odoo_enabled', false) && $this->isConfigured();
    }

    public function authenticate(): ?int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }

        try {
            $response = $this->xmlRpc('/xmlrpc/2/common', 'authenticate', [
                $this->db,
                $this->username,
                $this->password,
                [],
            ]);

            $this->uid = is_int($response) ? $response : null;

            return $this->uid;
        } catch (\Throwable $e) {
            Log::warning("Odoo authentication failed: {$e->getMessage()}");

            return null;
        }
    }

    public function createInvoice(array $data): ?int
    {
        return $this->execute('account.move', 'create', [$data], 'create_invoice', $data);
    }

    public function registerPayment(array $data): ?int
    {
        return $this->execute('account.payment', 'create', [$data], 'register_payment', $data);
    }

    public function createCreditNote(array $data): ?int
    {
        return $this->execute('account.move', 'create', [$data], 'create_credit_note', $data);
    }

    public function testConnection(): array
    {
        try {
            $uid = $this->authenticate();

            return [
                'success' => $uid !== null,
                'uid' => $uid,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function execute(string $model, string $method, array $args, string $eventType, array $payload): ?int
    {
        $uid = $this->authenticate();
        if (! $uid) {
            return null;
        }

        try {
            $response = $this->xmlRpc('/xmlrpc/2/object', 'execute_kw', [
                $this->db,
                $uid,
                $this->password,
                $model,
                $method,
                $args,
            ]);

            return is_int($response) ? $response : null;
        } catch (\Throwable $e) {
            $this->logSyncError($eventType, $model, null, $payload, $e);

            return null;
        }
    }

    /**
     * Simple XML-RPC call via HTTP (avoids ext-xmlrpc dependency).
     */
    private function xmlRpc(string $endpoint, string $method, array $params): mixed
    {
        $xmlParams = '';
        foreach ($params as $param) {
            $xmlParams .= '<param>' . $this->encodeXmlRpcValue($param) . '</param>';
        }

        $xml = <<<XML
<?xml version="1.0"?>
<methodCall>
    <methodName>{$method}</methodName>
    <params>{$xmlParams}</params>
</methodCall>
XML;

        $response = Http::withBody($xml, 'text/xml')
            ->timeout(30)
            ->post("{$this->url}{$endpoint}");

        if (! $response->successful()) {
            throw new \RuntimeException("Odoo XML-RPC error: HTTP {$response->status()}");
        }

        return $this->parseXmlRpcResponse($response->body());
    }

    private function encodeXmlRpcValue(mixed $value): string
    {
        if (is_int($value)) {
            return "<value><int>{$value}</int></value>";
        }
        if (is_bool($value)) {
            $v = $value ? '1' : '0';

            return "<value><boolean>{$v}</boolean></value>";
        }
        if (is_string($value)) {
            return '<value><string>' . htmlspecialchars($value) . '</string></value>';
        }
        if (is_float($value)) {
            return "<value><double>{$value}</double></value>";
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                $members = '';
                foreach ($value as $item) {
                    $members .= $this->encodeXmlRpcValue($item);
                }

                return "<value><array><data>{$members}</data></array></value>";
            }

            $members = '';
            foreach ($value as $k => $v) {
                $members .= '<member><name>' . htmlspecialchars((string) $k) . '</name>' . $this->encodeXmlRpcValue($v) . '</member>';
            }

            return "<value><struct>{$members}</struct></value>";
        }

        return '<value><string></string></value>';
    }

    private function parseXmlRpcResponse(string $body): mixed
    {
        $xml = @simplexml_load_string($body);
        if (! $xml) {
            throw new \RuntimeException('Invalid XML-RPC response');
        }

        if (isset($xml->fault)) {
            $faultString = (string) ($xml->fault->value->struct->member[1]->value->string ?? 'Unknown error');
            throw new \RuntimeException("Odoo fault: {$faultString}");
        }

        $value = $xml->params->param->value ?? null;

        return $this->decodeXmlRpcValue($value);
    }

    private function decodeXmlRpcValue($value): mixed
    {
        if (! $value) {
            return null;
        }

        if (isset($value->int)) {
            return (int) (string) $value->int;
        }
        if (isset($value->i4)) {
            return (int) (string) $value->i4;
        }
        if (isset($value->boolean)) {
            return (string) $value->boolean === '1';
        }
        if (isset($value->string)) {
            return (string) $value->string;
        }
        if (isset($value->double)) {
            return (float) (string) $value->double;
        }

        return (string) $value;
    }

    private function logSyncError(string $eventType, ?string $entityType, $entityId, array $payload, \Throwable $e): void
    {
        Log::warning("Odoo sync error: {$e->getMessage()}", compact('eventType', 'entityType', 'entityId'));

        SyncError::create([
            'integration' => 'odoo',
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload,
            'error_message' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString(),
        ]);
    }
}

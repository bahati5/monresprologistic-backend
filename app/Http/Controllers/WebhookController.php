<?php

namespace App\Http\Controllers;

use App\Services\Integrations\FlexPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function flexpay(Request $request, FlexPayService $flexPay): JsonResponse
    {
        $payload = $request->all();

        Log::info('FlexPay webhook received', $payload);

        $result = $flexPay->processWebhook($payload);

        if ($result['success'] && $result['reference']) {
            // TODO: Match reference to PaymentProof or Invoice and auto-confirm
        }

        return response()->json(['received' => true]);
    }
}

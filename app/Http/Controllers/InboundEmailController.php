<?php

namespace App\Http\Controllers;

use App\Services\InboundEmailParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §3.4 — Webhook pour recevoir les emails entrants (achats@monrespro.cd).
 * Compatible avec les webhooks Mailgun, SendGrid, Postmark, etc.
 */
class InboundEmailController extends Controller
{
    public function handle(Request $request, InboundEmailParserService $parser): JsonResponse
    {
        $data = [
            'from' => $request->input('sender') ?? $request->input('from') ?? $request->input('From'),
            'from_name' => $request->input('from_name') ?? $request->input('sender_name') ?? '',
            'subject' => $request->input('subject') ?? $request->input('Subject') ?? '',
            'body_plain' => $request->input('body-plain') ?? $request->input('text') ?? $request->input('TextBody') ?? '',
            'body_html' => $request->input('body-html') ?? $request->input('html') ?? $request->input('HtmlBody') ?? '',
        ];

        $purchase = $parser->parse($data);

        if (!$purchase) {
            return response()->json([
                'message' => 'Email traité mais aucune demande créée (pas d\'URLs produit détectées).',
            ]);
        }

        return response()->json([
            'message' => 'Demande créée automatiquement depuis email.',
            'purchase_id' => $purchase->id,
        ], 201);
    }
}

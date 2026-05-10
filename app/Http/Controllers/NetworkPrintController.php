<?php

namespace App\Http\Controllers;

use App\Services\NetworkPrintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §21.6 — Impression directe réseau : envoie un PDF à l'imprimante réseau configurée.
 */
class NetworkPrintController extends Controller
{
    public function printShipmentLabel(Request $request, int $shipmentId): JsonResponse
    {
        $user = $request->user();

        $pdfController = app(PdfController::class);
        $fakeRequest = Request::create("/api/shipments/{$shipmentId}/pdf/label", 'GET');
        $fakeRequest->setUserResolver(fn () => $user);
        $pdfResponse = $pdfController->shipmentLabel($fakeRequest, \App\Models\Shipment::findOrFail($shipmentId));

        $pdfContent = $pdfResponse->getContent();

        $result = NetworkPrintService::print($pdfContent, $user->agency_id);

        if ($result['success']) {
            return response()->json(['message' => 'Document envoyé à l\'imprimante réseau.', 'success' => true]);
        }

        if ($result['fallback'] ?? false) {
            return response()->json([
                'message' => 'Imprimante réseau indisponible. Ouvrez le PDF pour imprimer manuellement.',
                'success' => false,
                'fallback' => true,
                'pdf_url' => "/api/shipments/{$shipmentId}/pdf/label",
            ], 200);
        }

        return response()->json(['message' => $result['error'] ?? 'Erreur d\'impression.', 'success' => false], 422);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $printerUrl = NetworkPrintService::printerUrl($user->agency_id);

        return response()->json([
            'configured' => ! empty($printerUrl),
            'printer_url' => $printerUrl ? preg_replace('/https?:\/\//', '', $printerUrl) : null,
        ]);
    }
}

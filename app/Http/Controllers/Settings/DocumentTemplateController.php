<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentTemplateController extends Controller
{
    private const KEYS = [
        'doc_invoice_footer', 'doc_label_footer', 'doc_terms_conditions',
        'doc_privacy_notice', 'track_invoice_header', 'track_invoice_footer',
    ];

    public function index(): JsonResponse
    {
        $settings = [];
        foreach (self::KEYS as $key) {
            $settings[$key] = Setting::getValue($key, '');
        }

        return response()->json([
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'doc_invoice_footer' => ['nullable', 'string', 'max:5000'],
            'doc_label_footer' => ['nullable', 'string', 'max:5000'],
            'doc_terms_conditions' => ['nullable', 'string', 'max:10000'],
            'doc_privacy_notice' => ['nullable', 'string', 'max:10000'],
            'track_invoice_header' => ['nullable', 'string', 'max:5000'],
            'track_invoice_footer' => ['nullable', 'string', 'max:5000'],
        ]);

        foreach ($data as $key => $value) {
            Setting::setValue($key, $value ?? '');
        }

        return response()->json(['message' => 'Templates documents mis à jour.']);
    }
}

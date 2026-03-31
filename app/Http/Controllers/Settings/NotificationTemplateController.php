<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'templates' => NotificationTemplate::query()->orderBy('slug')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:128', 'unique:notification_templates,slug'],
            'channel' => ['required', 'in:email,sms,whatsapp'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        NotificationTemplate::query()->create([...$data, 'is_active' => true]);

        return response()->json(['message' => 'Modèle créé.']);
    }

    public function update(Request $request, NotificationTemplate $notificationTemplate): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'is_active' => ['boolean'],
        ]);

        $notificationTemplate->update($data);

        return response()->json(['message' => 'Modèle mis à jour.']);
    }
}

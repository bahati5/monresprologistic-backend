<?php

namespace App\Http\Controllers;

use App\Enums\FormDraftType;
use App\Models\FormDraft;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class FormDraftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FormDraft::class);

        $query = FormDraft::query()
            ->forUser($request->user()->id)
            ->active()
            ->orderByDesc('last_saved_at');

        if ($request->filled('form_type')) {
            $type = FormDraftType::tryFrom($request->input('form_type'));
            if ($type) {
                $query->ofType($type);
            }
        }

        $drafts = $query->get()->map(fn (FormDraft $d) => [
            'id' => $d->id,
            'form_type' => $d->form_type->value,
            'form_type_label' => $d->form_type->label(),
            'payload' => $d->payload,
            'metadata' => $d->metadata,
            'last_saved_at' => $d->last_saved_at->toIso8601String(),
            'expires_at' => $d->expires_at->toIso8601String(),
            'created_at' => $d->created_at->toIso8601String(),
        ]);

        return response()->json(['data' => $drafts]);
    }

    public function show(FormDraft $draft): JsonResponse
    {
        $this->authorize('view', $draft);

        if ($draft->isExpired()) {
            $draft->delete();

            return response()->json(['message' => 'Ce brouillon a expiré.'], 410);
        }

        return response()->json([
            'data' => [
                'id' => $draft->id,
                'form_type' => $draft->form_type->value,
                'form_type_label' => $draft->form_type->label(),
                'payload' => $draft->payload,
                'metadata' => $draft->metadata,
                'last_saved_at' => $draft->last_saved_at->toIso8601String(),
                'expires_at' => $draft->expires_at->toIso8601String(),
                'created_at' => $draft->created_at->toIso8601String(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', FormDraft::class);

        $data = $request->validate([
            'form_type' => ['required', new Enum(FormDraftType::class)],
            'payload' => ['required', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $formType = FormDraftType::from($data['form_type']);
        $rawMax = Setting::getValue('draft_max_per_type', '5');
        $maxPerType = (int) ($rawMax !== null && $rawMax !== '' ? $rawMax : '5');
        if ($maxPerType < 1 || $maxPerType > 50) {
            $maxPerType = 5;
        }

        $existingCount = FormDraft::query()
            ->forUser($user->id)
            ->ofType($formType)
            ->active()
            ->count();

        if ($existingCount >= $maxPerType) {
            return response()->json([
                'message' => "Vous avez atteint la limite de {$maxPerType} brouillons pour ce type de formulaire. Veuillez compléter ou supprimer un brouillon existant.",
            ], 422);
        }

        $expiresAt = $this->computeExpiresAt($user);

        $draft = FormDraft::create([
            'user_id' => $user->id,
            'form_type' => $formType,
            'payload' => $data['payload'],
            'metadata' => $data['metadata'] ?? null,
            'last_saved_at' => now(),
            'expires_at' => $expiresAt,
            'agency_id' => $user->agency_id,
        ]);

        return response()->json([
            'data' => [
                'id' => $draft->id,
                'form_type' => $draft->form_type->value,
                'last_saved_at' => $draft->last_saved_at->toIso8601String(),
                'expires_at' => $draft->expires_at->toIso8601String(),
            ],
        ], 201);
    }

    public function update(Request $request, FormDraft $draft): JsonResponse
    {
        $this->authorize('update', $draft);

        if ($draft->isExpired()) {
            $draft->delete();

            return response()->json(['message' => 'Ce brouillon a expiré.'], 410);
        }

        $data = $request->validate([
            'payload' => ['sometimes', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        $draft->update(array_filter([
            'payload' => $data['payload'] ?? null,
            'metadata' => array_key_exists('metadata', $data) ? $data['metadata'] : null,
            'last_saved_at' => now(),
        ], fn ($v) => $v !== null));

        return response()->json([
            'data' => [
                'id' => $draft->id,
                'last_saved_at' => $draft->fresh()->last_saved_at->toIso8601String(),
            ],
        ]);
    }

    public function destroy(FormDraft $draft): JsonResponse
    {
        $this->authorize('delete', $draft);

        $draft->delete();

        return response()->json(['message' => 'Brouillon supprimé.']);
    }

    private function computeExpiresAt(mixed $user): Carbon
    {
        if ($user->hasRole('client')) {
            $days = (int) Setting::getValue('draft_client_expiry_days', '30');
        } else {
            $days = (int) Setting::getValue('draft_staff_expiry_days', '7');
        }

        return now()->addDays($days);
    }
}

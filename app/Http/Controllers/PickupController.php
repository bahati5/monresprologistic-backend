<?php

namespace App\Http\Controllers;

use App\Enums\PickupStatus;
use App\Events\PickupStatusChanged;
use App\Models\Pickup;
use App\Models\PickupFailureReason;
use App\Models\User;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PickupController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Pickup::query()->with(['client', 'agency', 'driver', 'shipment']);
        $this->scopeByAgency($q, $user);

        if ($user->hasRole('driver')) {
            $q->where('assigned_driver_id', $user->id);
        } elseif ($request->boolean('assigned_to_me')) {
            $q->where('assigned_driver_id', $user->id);
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $q->where(function ($w) use ($term) {
                $w->where('address_text', 'like', $term)
                    ->orWhere('requested_window', 'like', $term)
                    ->orWhereHas('client', fn ($u) => $u->where('name', 'like', $term));
            });
        }

        $paginator = $q->latest()->paginate(30)->withQueryString();
        $rows = collect($paginator->items())->map(fn (Pickup $pickup) => $this->pickupRow($pickup));
        $paginator->setCollection($rows);

        return response()->json([
            'pickups' => $paginator,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:users,id'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'address_text' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:2000'],
            'requested_window' => ['nullable', 'string', 'max:500'],
            'scheduled_at' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'shipment_id' => ['nullable', 'exists:shipments,id'],
        ]);

        $user = $request->user();
        $this->authorize('create', Pickup::class);

        $clientId = isset($data['client_id']) ? (int) $data['client_id'] : 0;
        if ($clientId > 0 && ! $user->canAccessAllAgencies()) {
            $allowed = User::query()
                ->whereKey($clientId)
                ->where('agency_id', $user->agency_id)
                ->exists();
            abort_unless($allowed, 403);
        }

        $pickup = Pickup::query()->create([
            'user_id' => $clientId > 0 ? $clientId : $user->id,
            'agency_id' => $user->agency_id,
            'shipment_id' => $data['shipment_id'] ?? null,
            'status' => PickupStatus::Draft,
            'latitude' => $data['latitude'] ?? 0,
            'longitude' => $data['longitude'] ?? 0,
            'address_text' => $data['address_text'] ?? $data['address'] ?? null,
            'requested_window' => $data['requested_window'] ?? $data['scheduled_at'] ?? null,
        ]);

        PickupStatusChanged::dispatch($pickup, PickupStatus::Draft, PickupStatus::Draft, $user);

        return response()->json([
            'message' => 'Ramassage demandé.',
            'pickup' => $this->pickupRow($pickup->load(['client', 'driver', 'shipment'])),
        ], 201);
    }

    public function assign(Request $request, Pickup $pickup): JsonResponse
    {
        $this->authorize('update', $pickup);

        $data = $request->validate([
            'assigned_driver_id' => ['nullable', 'exists:users,id'],
            'driver_id' => ['nullable', 'exists:users,id'],
        ]);

        $old = $pickup->status ?? PickupStatus::Draft;
        $driverId = $data['assigned_driver_id'] ?? $data['driver_id'] ?? null;
        $next = $driverId ? PickupStatus::DriverAssigned : $old;
        $pickup->update([
            'assigned_driver_id' => $driverId,
            'status' => $next,
        ]);
        if ($old !== $next) {
            PickupStatusChanged::dispatch($pickup->fresh(), $old, $next, $request->user());
        }

        if ($driverId) {
            $driver = User::query()->find((int) $driverId);
            if ($driver) {
                $pickup->loadMissing('client');
                NotificationDispatcher::dispatch(
                    user: $driver,
                    eventKey: 'pickup.assigned_to_driver',
                    variables: [
                        'status' => $next->label(),
                        'client_nom' => (string) ($pickup->client?->name ?? ''),
                        'pickup_id' => (string) $pickup->id,
                    ],
                    actionUrl: '/pickups',
                );
            }
        }

        return response()->json(['message' => 'Chauffeur assigné.']);
    }

    public function updateStatus(Request $request, Pickup $pickup): JsonResponse
    {
        $this->authorize('update', $pickup);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::enum(PickupStatus::class)],
            'failure_reason' => ['nullable', 'string', 'max:500'],
            'failure_reason_id' => ['nullable', 'integer', 'exists:pickup_failure_reasons,id'],
            'completion_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $next = PickupStatus::from($data['status']);
        $current = $pickup->status ?? PickupStatus::Draft;
        if (! $current->canTransitionTo($next)) {
            return response()->json(['message' => 'Transition non autorisée.'], 422);
        }

        // §8.4 : photo obligatoire pour confirmer collecte ou livraison
        $photoRequired = in_array($next, [PickupStatus::Collected, PickupStatus::Delivered], true);
        if ($photoRequired && ! $pickup->completion_photo_path && ! $request->hasFile('completion_photo')) {
            return response()->json([
                'message' => 'Une photo de preuve est obligatoire pour confirmer la collecte ou la livraison.',
                'requires_photo' => true,
            ], 422);
        }

        $catalogReason = null;
        if (! empty($data['failure_reason_id'])) {
            $catalogReason = PickupFailureReason::query()->whereKey((int) $data['failure_reason_id'])->where('is_active', true)->first();
            if (! $catalogReason) {
                return response()->json(['message' => 'Motif d\'échec invalide ou inactif.'], 422);
            }
        }

        // §8.4 : texte libre OU motif catalogue
        if ($next === PickupStatus::Failed
            && empty(trim((string) ($data['failure_reason'] ?? '')))
            && ! $catalogReason) {
            return response()->json([
                'message' => 'Indiquez une raison d\'échec (texte) ou un motif du catalogue.',
            ], 422);
        }

        $updateData = ['status' => $next];
        if ($catalogReason) {
            $updateData['failure_reason_id'] = $catalogReason->id;
            $free = trim((string) ($data['failure_reason'] ?? ''));
            $updateData['failure_reason'] = $free !== ''
                ? $free
                : (string) $catalogReason->label;
        } elseif ($data['failure_reason'] ?? null) {
            $updateData['failure_reason'] = $data['failure_reason'];
            $updateData['failure_reason_id'] = null;
        }
        if ($data['completion_notes'] ?? null) {
            $updateData['completion_notes'] = $data['completion_notes'];
        }
        if (in_array($next, [PickupStatus::Collected, PickupStatus::Delivered, PickupStatus::Completed], true)) {
            $updateData['completed_at'] = now();
        }

        $pickup->update($updateData);
        PickupStatusChanged::dispatch($pickup->fresh(), $current, $next, $request->user());

        return response()->json(['message' => 'Statut mis à jour.']);
    }

    /**
     * §8.4 — Upload de la photo de preuve pour collecte/livraison.
     */
    public function uploadCompletionPhoto(Request $request, Pickup $pickup): JsonResponse
    {
        $this->authorize('update', $pickup);

        $request->validate([
            'photo' => ['required', 'file', 'image', 'max:10240'],
        ]);

        $path = $request->file('photo')->store('pickup-photos', 'local');

        $pickup->update(['completion_photo_path' => $path]);

        return response()->json([
            'message' => 'Photo enregistrée.',
            'has_photo' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pickupRow(Pickup $pickup): array
    {
        $row = $pickup->toArray();
        $st = $pickup->status ?? PickupStatus::Draft;
        $row['status'] = [
            'code' => $st->value,
            'name' => $st->label(),
        ];
        $row['address'] = $pickup->address_text;
        $row['scheduled_at'] = $pickup->requested_window;
        $row['has_completion_photo'] = ! empty($pickup->completion_photo_path);

        return $row;
    }
}

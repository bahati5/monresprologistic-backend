<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Models\CustomerPackage;
use App\Models\Notification;
use App\Models\PreAlert;
use App\Models\PreAlertIssueReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShipmentNoticeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isClient = $user->hasRole('client');

        $q = PreAlert::query()
            ->with(['user', 'locker', 'media'])
            ->select('pre_alerts.*');

        if ($isClient) {
            $q->where('user_id', $user->id);
        } elseif (! $user->canAccessAllAgencies()) {
            $q->whereHas('user', fn ($u) => $u->where('agency_id', $user->agency_id));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $q->where(function ($w) use ($term) {
                $w->where('pre_alerts.reference_code', 'like', $term)
                    ->orWhere('pre_alerts.merchant_name', 'like', $term)
                    ->orWhere('pre_alerts.vendor_tracking_number', 'like', $term)
                    ->orWhere('pre_alerts.carrier_name', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term));
            });
        }

        if ($request->filled('status')) {
            $q->where('pre_alerts.status', $request->string('status'));
        }

        $showCompleted = ! $isClient && $request->boolean('show_completed');
        if (! $isClient && ! $showCompleted && ! $request->filled('status')) {
            $q->actionableInboundQueue();
        }

        if ($isClient) {
            $q->latest('pre_alerts.created_at');
        } else {
            $q->orderByRaw('CASE WHEN pre_alerts.status = ? THEN 1 ELSE 0 END', [ShipmentStatus::ReceivedAtHub->value])
                ->orderByRaw('pre_alerts.estimated_arrival_date IS NULL, pre_alerts.estimated_arrival_date ASC')
                ->orderByDesc('pre_alerts.created_at');
        }

        $payload = [
            'notices' => $q->paginate(20)->withQueryString(),
        ];

        if (! $isClient) {
            $payload['show_completed'] = $showCompleted;
        }

        if ($isClient) {
            $payload['statuses'] = collect(ShipmentStatus::cases())->values()->map(fn (ShipmentStatus $s, int $i) => [
                'id' => $i + 1,
                'code' => $s->value,
                'name' => $s->label(),
            ]);
        }

        return response()->json($payload);
    }

    public function create(Request $request): JsonResponse
    {
        $user = $request->user();
        $isClient = $user->hasRole('client');

        return response()->json([
            'locker' => $isClient ? $user->locker : null,
            'clients' => $isClient ? null : User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'client'))
                ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
                ->select('id', 'name', 'email', 'locker_id')
                ->with('locker:id,code')
                ->orderBy('name')
                ->get(),
            'isAdmin' => ! $isClient,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $isClient = $user->hasRole('client');

        $data = $request->validate([
            'client_id' => [$isClient ? 'nullable' : 'required', 'integer', 'exists:users,id'],
            'merchant_name' => ['nullable', 'string', 'max:255'],
            'vendor_tracking_number' => ['required', 'string', 'max:255'],
            'carrier_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'value_currency' => ['nullable', 'string', 'max:8'],
            'purchase_date' => ['nullable', 'date'],
            'estimated_arrival_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Admin can create for any client, client creates for themselves
        $targetUserId = $isClient ? $user->id : $data['client_id'];
        $targetUser = User::with('locker')->find($targetUserId);

        $preAlert = PreAlert::query()->create([
            ...$data,
            'reference_code' => PreAlert::generateReferenceCode(),
            'user_id' => $targetUserId,
            'locker_id' => $targetUser?->locker?->id,
            'status' => ShipmentStatus::PendingDropOff,
        ]);

        foreach ($request->allFiles() as $key => $uploads) {
            $uploads = is_array($uploads) ? $uploads : [$uploads];
            foreach ($uploads as $file) {
                if ($file && $file->isValid()) {
                    $preAlert->addMedia($file)->toMediaCollection('documents');
                }
            }
        }

        $preAlert->load(['user', 'locker', 'media']);

        return response()->json([
            'message' => 'Colis attendu enregistré.',
            'notice' => $preAlert,
        ], 201);
    }

    public function show(Request $request, PreAlert $shipmentNotice): JsonResponse
    {
        $shipmentNotice->load(['user', 'locker', 'media', 'customerPackage']);

        return response()->json([
            'notice' => $shipmentNotice,
        ]);
    }

    public function edit(Request $request, PreAlert $shipmentNotice): JsonResponse
    {
        $this->authorizeNoticeWrite($request->user(), $shipmentNotice);

        $user = $request->user();
        $isClient = $user->hasRole('client');

        $shipmentNotice->load(['locker', 'media']);

        return response()->json([
            'notice' => $shipmentNotice,
            'locker' => $isClient ? $user->locker : null,
            'clients' => $isClient ? null : User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'client'))
                ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
                ->select('id', 'name', 'email', 'locker_id')
                ->with('locker:id,code')
                ->orderBy('name')
                ->get(),
            'isAdmin' => ! $isClient,
        ]);
    }

    public function update(Request $request, PreAlert $shipmentNotice): JsonResponse
    {
        $this->authorizeNoticeWrite($request->user(), $shipmentNotice);

        $data = $request->validate([
            'merchant_name' => ['nullable', 'string', 'max:255'],
            'vendor_tracking_number' => ['nullable', 'string', 'max:255'],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'value_currency' => ['nullable', 'string', 'max:8'],
            'purchase_date' => ['nullable', 'date'],
            'estimated_arrival_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $shipmentNotice->update($data);

        foreach ($request->allFiles() as $key => $uploads) {
            $uploads = is_array($uploads) ? $uploads : [$uploads];
            foreach ($uploads as $file) {
                if ($file && $file->isValid()) {
                    $shipmentNotice->addMedia($file)->toMediaCollection('documents');
                }
            }
        }

        return back()->with('success', 'Avis d\'expédition mis à jour.');
    }

    public function destroy(Request $request, PreAlert $shipmentNotice): JsonResponse
    {
        $this->authorizeNoticeWrite($request->user(), $shipmentNotice);

        $shipmentNotice->delete();

        return redirect()->route('shipment-notices.index')
            ->with('success', 'Avis d\'expédition supprimé.');
    }

    public function receive(Request $request, PreAlert $shipmentNotice): JsonResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'weight_kg' => ['required', 'numeric', 'min:0'],
            'length_cm' => ['nullable', 'numeric', 'min:0'],
            'width_cm' => ['nullable', 'numeric', 'min:0'],
            'height_cm' => ['nullable', 'numeric', 'min:0'],
            'condition_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $pkg = CustomerPackage::query()->create([
            'reference_code' => CustomerPackage::generateReferenceCode(),
            'user_id' => $shipmentNotice->user_id,
            'agency_id' => $request->user()->agency_id,
            'locker_id' => $shipmentNotice->locker_id,
            'pre_alert_id' => $shipmentNotice->id,
            'status' => ShipmentStatus::ReceivedAtHub,
            'description' => $shipmentNotice->description,
            'merchant_name' => $shipmentNotice->merchant_name,
            'weight_kg' => $data['weight_kg'],
            'length_cm' => $data['length_cm'] ?? null,
            'width_cm' => $data['width_cm'] ?? null,
            'height_cm' => $data['height_cm'] ?? null,
            'declared_value' => $shipmentNotice->declared_value,
            'value_currency' => $shipmentNotice->value_currency ?? 'EUR',
            'received_at' => now(),
            'received_by' => $request->user()->id,
            'condition_notes' => $data['condition_notes'] ?? null,
        ]);

        $shipmentNotice->update([
            'status' => ShipmentStatus::ReceivedAtHub,
            'converted_customer_package_id' => $pkg->id,
        ]);

        return back()->with('success', 'Colis réceptionné — '.$pkg->reference_code);
    }

    public function reportIssue(Request $request, PreAlert $shipmentNotice): JsonResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $report = PreAlertIssueReport::query()->create([
            'pre_alert_id' => $shipmentNotice->id,
            'reported_by_user_id' => $request->user()->id,
            'message' => $data['message'],
        ]);

        $shipmentNotice->loadMissing('user');
        $agencyId = $shipmentNotice->user?->agency_id;

        if ($agencyId) {
            $recipients = User::query()
                ->where('agency_id', $agencyId)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['agency_admin', 'super_admin', 'operator']))
                ->get();

            foreach ($recipients as $admin) {
                Notification::query()->create([
                    'user_id' => $admin->id,
                    'type' => 'inbound_issue',
                    'channel' => 'system',
                    'title' => 'Signalement — '.$shipmentNotice->reference_code,
                    'body' => Str::limit($data['message'], 240),
                    'data' => [
                        'pre_alert_id' => $shipmentNotice->id,
                        'report_id' => $report->id,
                    ],
                    'action_url' => route('shipment-notices.index', ['search' => $shipmentNotice->reference_code]),
                    'status' => 'pending',
                ]);
            }
        }

        return back()->with('success', 'Signalement enregistré. L\'équipe en a été informée.');
    }

    protected function authorizeStaff(Request $request): void
    {
        abort_unless(
            $request->user()->can('manage_shipment_notices')
                || $request->user()->hasAnyRole(['super_admin', 'agency_admin', 'operator']),
            403
        );
    }

    protected function authorizeNoticeWrite(User $user, PreAlert $notice): void
    {
        if ($user->hasAnyRole(['super_admin', 'agency_admin', 'operator'])) {
            if (! $user->canAccessAllAgencies()) {
                $notice->loadMissing('user');
                abort_unless(
                    (int) $notice->user?->agency_id === (int) $user->agency_id,
                    403
                );
            }

            return;
        }

        abort_unless($user->hasRole('client') && $notice->user_id === $user->id, 403);

        abort_if(
            ($notice->status ?? null) === ShipmentStatus::ReceivedAtHub,
            403,
            'Cet avis est déjà réceptionné et ne peut plus être modifié.'
        );
    }
}

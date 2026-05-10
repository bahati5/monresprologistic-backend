<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Jobs\SyncToFreshsalesJob;
use App\Models\CustomerPackage;
use App\Models\Notification;
use App\Models\PreAlert;
use App\Models\PreAlertIssueReport;
use App\Models\User;
use App\Services\Integrations\FreshsalesService;
use App\Services\NotificationDispatcher;
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

    public function receive(Request $request, PreAlert $shipmentNotice): JsonResponse|\Illuminate\Http\RedirectResponse
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

        // §5 — Alerte écart poids > 15% entre poids déclaré et poids réel
        $this->checkWeightDiscrepancy($shipmentNotice, (float) $data['weight_kg'], $request->user());

        foreach ($request->allFiles() as $uploads) {
            $uploads = is_array($uploads) ? $uploads : [$uploads];
            foreach ($uploads as $file) {
                if ($file && $file->isValid()) {
                    $shipmentNotice->addMedia($file)->toMediaCollection('documents');
                }
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Colis réceptionné.',
                'customer_package' => $pkg->fresh(),
                'reference_code' => $pkg->reference_code,
            ]);
        }

        return back()->with('success', 'Colis réceptionné — '.$pkg->reference_code);
    }

    public function reportIssue(Request $request, PreAlert $shipmentNotice): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $this->authorizeStaff($request);

        abort_if(
            $shipmentNotice->converted_customer_package_id !== null
                || ($shipmentNotice->status ?? null) === ShipmentStatus::ReceivedAtHub,
            422,
            'Ce colis est déjà réceptionné : signalement impossible.'
        );

        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'issue_type' => ['nullable', 'string', 'max:64'],
        ]);

        $message = trim((string) ($data['message'] ?? ''));
        if ($message === '') {
            $message = trim((string) ($data['description'] ?? ''));
        }
        if ($message === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'message' => ['Le message de signalement est obligatoire.'],
            ]);
        }

        if (! empty($data['issue_type'])) {
            $message = '['.$data['issue_type'].'] '.$message;
        }

        $report = PreAlertIssueReport::query()->create([
            'pre_alert_id' => $shipmentNotice->id,
            'reported_by_user_id' => $request->user()->id,
            'message' => $message,
        ]);

        $cur = $shipmentNotice->status ?? ShipmentStatus::Draft;
        $noticeUpdates = ['issue_reported' => true];
        if ($cur->canTransitionTo(ShipmentStatus::IssueReported)) {
            $noticeUpdates['status'] = ShipmentStatus::IssueReported;
        }
        $shipmentNotice->update($noticeUpdates);

        $shipmentNotice->loadMissing('user');
        $agencyId = $shipmentNotice->user?->agency_id;

        // §7.4 — Notifier le client du signalement
        if ($shipmentNotice->user_id) {
            Notification::query()->create([
                'user_id' => $shipmentNotice->user_id,
                'type' => 'pre_alert_issue',
                'channel' => 'system',
                'title' => 'Problème signalé — '.$shipmentNotice->reference_code,
                'body' => Str::limit($message, 240),
                'data' => [
                    'pre_alert_id' => $shipmentNotice->id,
                    'report_id' => $report->id,
                ],
                'action_url' => '/client/locker',
                'status' => 'pending',
            ]);
        }

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
                    'body' => Str::limit($message, 240),
                    'data' => [
                        'pre_alert_id' => $shipmentNotice->id,
                        'report_id' => $report->id,
                    ],
                    'action_url' => route('shipment-notices.index', ['search' => $shipmentNotice->reference_code]),
                    'status' => 'pending',
                ]);
            }
        }

        // §7.4 — Créer un ticket Freshsales quand un problème de pré-alerte est signalé
        try {
            $freshsales = app(FreshsalesService::class);
            if ($freshsales->isEnabled()) {
                SyncToFreshsalesJob::dispatch('create_ticket', [
                    'entity_type' => 'PreAlert',
                    'entity_id'   => $shipmentNotice->id,
                    'subject'     => "Problème pré-alerte — {$shipmentNotice->reference_code}",
                    'description' => $message,
                    'contact_id'  => $shipmentNotice->user_id,
                    'status'      => 'open',
                    'priority'    => 'medium',
                ]);
            }
        } catch (\Throwable) {
            // Ne pas bloquer si Freshsales est indisponible
        }

        foreach ($request->allFiles() as $uploads) {
            $uploads = is_array($uploads) ? $uploads : [$uploads];
            foreach ($uploads as $file) {
                if ($file && $file->isValid()) {
                    $shipmentNotice->addMedia($file)->toMediaCollection('documents');
                }
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Signalement enregistré.',
                'report_id' => $report->id,
            ]);
        }

        return back()->with('success', 'Signalement enregistré. L\'équipe en a été informée.');
    }

    protected function authorizeStaff(Request $request): void
    {
        abort_unless(
            $request->user()->can('manage_pre_alerts')
                || $request->user()->hasAnyRole(['super_admin', 'agency_admin', 'operator']),
            403
        );
    }

    /**
     * §5 — Vérifie l'écart entre le poids déclaré et le poids réel.
     * Si > 15%, alerte le client et notifie le staff.
     */
    private function checkWeightDiscrepancy(PreAlert $notice, float $actualWeight, User $staffUser): void
    {
        $declaredWeight = (float) ($notice->declared_weight_kg ?? 0);

        if ($declaredWeight <= 0 || $actualWeight <= 0) {
            return;
        }

        $discrepancyPct = abs($actualWeight - $declaredWeight) / $declaredWeight * 100;

        if ($discrepancyPct < 15) {
            return;
        }

        $notice->loadMissing('user');

        // Notifier le client
        if ($notice->user) {
            try {
                app(NotificationDispatcher::class)::dispatch(
                    $notice->user,
                    'weight_discrepancy',
                    [
                        'reference'      => $notice->reference_code,
                        'declared_kg'    => $declaredWeight,
                        'actual_kg'      => $actualWeight,
                        'discrepancy_pct'=> round($discrepancyPct, 1),
                    ]
                );
            } catch (\Throwable) {}
        }

        // Notifier le staff de l'agence
        $agencyId = $staffUser->agency_id;
        if ($agencyId) {
            $managers = User::query()
                ->where('agency_id', $agencyId)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['agency_admin', 'super_admin', 'operator']))
                ->get();

            foreach ($managers as $manager) {
                Notification::query()->create([
                    'user_id' => $manager->id,
                    'type'    => 'weight_discrepancy',
                    'channel' => 'system',
                    'title'   => "Écart poids > 15% — {$notice->reference_code}",
                    'body'    => sprintf(
                        'Poids déclaré : %.2f kg / Poids réel : %.2f kg (écart : %.1f%%)',
                        $declaredWeight,
                        $actualWeight,
                        $discrepancyPct
                    ),
                    'data'   => [
                        'pre_alert_id'    => $notice->id,
                        'declared_kg'     => $declaredWeight,
                        'actual_kg'       => $actualWeight,
                        'discrepancy_pct' => round($discrepancyPct, 1),
                    ],
                    'status' => 'pending',
                ]);
            }
        }
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

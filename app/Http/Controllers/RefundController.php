<?php

namespace App\Http\Controllers;

use App\Enums\RefundStatus;
use App\Events\RefundStatusChanged;
use App\Models\AssistedPurchase;
use App\Models\LedgerEntry;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RefundController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isClient = $user->hasRole('client');

        $q = Refund::query()->with(['client', 'reviewer', 'processor', 'refundable']);

        if ($isClient) {
            $q->where('client_id', $user->id);
        } elseif (! $user->canAccessAllAgencies()) {
            $q->where('agency_id', $user->agency_id);
        }

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $q->where(function ($w) use ($term) {
                $w->where('reference_code', 'like', $term)
                    ->orWhere('reason', 'like', $term)
                    ->orWhereHas('client', fn ($u) => $u->where('name', 'like', $term));
            });
        }

        $q->latest();

        return response()->json([
            'refunds' => $q->paginate(20)->withQueryString(),
            'statuses' => collect(RefundStatus::cases())->map(fn ($s) => [
                'code' => $s->value,
                'name' => $s->label(),
            ]),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_if($user->hasRole('client'), 403);
        abort_unless(
            $user->hasAnyRole(['super_admin', 'agency_admin', 'operator'])
                || $user->can('manage_refunds')
                || $user->can('approve_refunds'),
            403
        );

        $q = Refund::query()->with('client');
        if (! $user->canAccessAllAgencies()) {
            $q->where('agency_id', $user->agency_id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }
        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $q->where(function ($w) use ($term) {
                $w->where('reference_code', 'like', $term)
                    ->orWhere('reason', 'like', $term)
                    ->orWhereHas('client', fn ($u) => $u->where('name', 'like', $term));
            });
        }
        $q->latest('id');

        $filename = 'remboursements-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['reference', 'statut', 'montant', 'devise', 'client', 'motif', 'cree_le'], ';');
            $q->chunk(200, function ($refunds) use ($out) {
                foreach ($refunds as $r) {
                    $st = $r->status;
                    $label = $st instanceof RefundStatus ? $st->label() : (string) $st;
                    fputcsv($out, [
                        $r->reference_code,
                        $label,
                        (string) $r->amount,
                        $r->currency ?? 'USD',
                        (string) ($r->client?->name ?? ''),
                        str_replace(["\r", "\n", ';'], [' ', ' ', ','], (string) $r->reason),
                        $r->created_at?->format('Y-m-d H:i:s') ?? '',
                    ], ';');
                }
            });
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $isClient = $user->hasRole('client');

        $rules = [
            'refundable_type' => ['required', 'string', 'in:assisted_purchase,shipment'],
            'refundable_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'max:8'],
            'reason' => ['required', 'string', 'max:2000'],
            'reason_category' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'payment_details' => ['nullable', 'array'],
            'client_id' => ['nullable', 'integer', 'exists:users,id'],
        ];

        if ($isClient) {
            $rules['request_proof'] = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'];
        }

        $data = $request->validate($rules);

        $morphMap = [
            'assisted_purchase' => AssistedPurchase::class,
            'shipment' => Shipment::class,
        ];

        $modelClass = $morphMap[$data['refundable_type']];
        /** @var Model|null $refundable */
        $refundable = $modelClass::query()->find($data['refundable_id']);
        if (! $refundable) {
            throw ValidationException::withMessages([
                'refundable_id' => ['Dossier introuvable.'],
            ]);
        }

        if ($isClient) {
            $clientId = (int) $user->id;
            $resolved = $this->resolveClientIdFromRefundable($data['refundable_type'], $refundable);
            if ($resolved !== null && $resolved !== $clientId) {
                throw ValidationException::withMessages([
                    'refundable_id' => ['Ce dossier ne correspond pas à votre compte.'],
                ]);
            }
        } else {
            $clientId = isset($data['client_id']) && $data['client_id'] !== ''
                ? (int) $data['client_id']
                : (int) ($this->resolveClientIdFromRefundable($data['refundable_type'], $refundable) ?? 0);
            if ($clientId <= 0) {
                throw ValidationException::withMessages([
                    'client_id' => ['Indiquez le client concerné ou sélectionnez un dossier déjà lié à un compte portail.'],
                ]);
            }
        }

        $clientUser = User::query()->find($clientId);
        if (! $clientUser) {
            throw ValidationException::withMessages([
                'client_id' => ['Client introuvable.'],
            ]);
        }

        if (! $user->canAccessAllAgencies()) {
            if ((int) ($clientUser->agency_id ?? 0) !== (int) ($user->agency_id ?? 0)) {
                throw ValidationException::withMessages([
                    'client_id' => ['Ce client n’appartient pas à votre agence.'],
                ]);
            }
        }

        $agencyId = $clientUser->agency_id ?? $user->agency_id;

        $paymentDetails = $data['payment_details'] ?? [];
        if ($isClient && $request->hasFile('request_proof')) {
            $file = $request->file('request_proof');
            $paymentDetails['request_proof_path'] = $file->store('refund-request-proofs', 'local');
            $paymentDetails['request_proof_original_name'] = $file->getClientOriginalName();
        }

        $refund = Refund::create([
            'reference_code' => Refund::generateReferenceCode(),
            'refundable_type' => $morphMap[$data['refundable_type']],
            'refundable_id' => $data['refundable_id'],
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'USD',
            'status' => RefundStatus::Requested,
            'reason' => $data['reason'],
            'reason_category' => $data['reason_category'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'payment_details' => $paymentDetails !== [] ? $paymentDetails : null,
        ]);

        RefundStatusChanged::dispatch($refund, '', RefundStatus::Requested->value, $user);

        return response()->json(['refund' => $refund->load('client')], 201);
    }

    public function downloadRequestProof(Request $request, Refund $refund): StreamedResponse
    {
        $this->assertRefundVisible($request->user(), $refund);

        $details = $refund->payment_details ?? [];
        $path = $details['request_proof_path'] ?? null;
        abort_unless(is_string($path) && $path !== '', 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        $downloadName = $details['request_proof_original_name'] ?? basename($path);

        return $disk->download($path, $downloadName);
    }

    public function show(Request $request, Refund $refund): JsonResponse
    {
        $this->assertRefundVisible($request->user(), $refund);

        return response()->json([
            'refund' => $refund->load(['client', 'reviewer', 'processor', 'refundable']),
        ]);
    }

    public function approve(Request $request, Refund $refund): JsonResponse
    {
        $user = $request->user();
        $this->assertRefundVisible($user, $refund);
        $this->authorizeApproval($user, $refund);
        $this->assertTransition($refund, RefundStatus::Approved);

        $oldStatus = $refund->status->value;

        $refund->update([
            'status' => RefundStatus::Approved,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        RefundStatusChanged::dispatch($refund, $oldStatus, RefundStatus::Approved->value, $user);

        return response()->json(['message' => 'Remboursement approuvé.', 'refund' => $refund->fresh()]);
    }

    public function reject(Request $request, Refund $refund): JsonResponse
    {
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $this->assertRefundVisible($user, $refund);
        $this->authorizeApproval($user, $refund);
        $this->assertTransition($refund, RefundStatus::Rejected);

        $oldStatus = $refund->status->value;

        $refund->update([
            'status' => RefundStatus::Rejected,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'rejection_reason' => $data['rejection_reason'],
        ]);

        RefundStatusChanged::dispatch($refund, $oldStatus, RefundStatus::Rejected->value, $user);

        return response()->json(['message' => 'Remboursement rejeté.', 'refund' => $refund->fresh()]);
    }

    public function process(Request $request, Refund $refund): JsonResponse
    {
        $user = $request->user();
        $this->assertRefundVisible($user, $refund);
        $this->assertTransition($refund, RefundStatus::Processed);

        $oldStatus = $refund->status->value;

        $proofPath = null;
        if ($request->hasFile('proof')) {
            $proofPath = $request->file('proof')->store('refund-proofs', 'local');
        }

        $refund->update([
            'status' => RefundStatus::Processed,
            'processed_by' => $user->id,
            'processed_at' => now(),
            'proof_path' => $proofPath ?? $refund->proof_path,
        ]);

        LedgerEntry::create([
            'type' => 'refund',
            'amount' => -abs((float) $refund->amount),
            'currency' => $refund->currency,
            'description' => "Remboursement {$refund->reference_code} — {$refund->reason}",
            'reference_type' => Refund::class,
            'reference_id' => $refund->id,
            'user_id' => $user->id,
            'agency_id' => $refund->agency_id,
        ]);

        RefundStatusChanged::dispatch($refund, $oldStatus, RefundStatus::Processed->value, $user);

        return response()->json(['message' => 'Remboursement traité.', 'refund' => $refund->fresh()]);
    }

    public function complete(Request $request, Refund $refund): JsonResponse
    {
        $user = $request->user();
        $this->assertRefundVisible($user, $refund);
        $this->assertTransition($refund, RefundStatus::Completed);

        $oldStatus = $refund->status->value;

        $refund->update([
            'status' => RefundStatus::Completed,
            'completed_at' => now(),
        ]);

        RefundStatusChanged::dispatch($refund, $oldStatus, RefundStatus::Completed->value, $user);

        return response()->json(['message' => 'Remboursement terminé.', 'refund' => $refund->fresh()]);
    }

    private function resolveClientIdFromRefundable(string $refundableType, Model $refundable): ?int
    {
        if ($refundableType === 'assisted_purchase' && $refundable instanceof AssistedPurchase) {
            return (int) $refundable->user_id;
        }
        if ($refundableType === 'shipment' && $refundable instanceof Shipment) {
            $profileId = $refundable->recipient_profile_id;
            if (! $profileId) {
                return null;
            }
            $uid = User::query()->where('profile_id', $profileId)->value('id');

            return $uid ? (int) $uid : null;
        }

        return null;
    }

    private function assertRefundVisible(User $user, Refund $refund): void
    {
        if ($user->hasRole('client')) {
            abort_unless((int) $refund->client_id === (int) $user->id, 403);

            return;
        }

        abort_unless(
            $user->hasAnyRole(['super_admin', 'agency_admin', 'operator'])
                || $user->can('manage_refunds')
                || $user->can('approve_refunds'),
            403
        );

        if (! $user->canAccessAllAgencies()) {
            abort_unless((int) $refund->agency_id === (int) ($user->agency_id ?? 0), 403);
        }
    }

    private function authorizeApproval(User $user, Refund $refund): void
    {
        $amount = (float) $refund->amount;
        $thresholdLow = (float) Setting::getValue('refund_threshold_low', 50);
        $thresholdHigh = (float) Setting::getValue('refund_threshold_high', 500);

        if ($amount > $thresholdHigh) {
            abort_unless(
                $user->hasAnyRole(['super_admin', 'agency_admin']),
                403,
                'Les remboursements > $'.$thresholdHigh.' nécessitent un agency_admin.'
            );

            // §9.5 — Notification obligatoire au super_admin si montant > seuil haut
            $this->notifySuperAdminOnLargeRefund($refund, $amount);
        } elseif ($amount > $thresholdLow) {
            abort_unless(
                $user->hasAnyRole(['super_admin', 'agency_admin']),
                403,
                'Les remboursements > $'.$thresholdLow.' nécessitent un agency_admin.'
            );
        } else {
            abort_unless(
                $user->can('approve_refunds') || $user->hasAnyRole(['super_admin', 'agency_admin', 'operator']),
                403
            );
        }
    }

    private function assertTransition(Refund $refund, RefundStatus $target): void
    {
        if (! $refund->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => ["Transition de « {$refund->status->label()} » vers « {$target->label()} » non autorisée."],
            ]);
        }
    }

    private function notifySuperAdminOnLargeRefund(Refund $refund, float $amount): void
    {
        $superAdmins = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->get();

        foreach ($superAdmins as $admin) {
            \App\Services\NotificationDispatcher::dispatch(
                user: $admin,
                eventKey: 'refund.large_amount_pending',
                variables: [
                    'amount' => number_format($amount, 2),
                    'currency' => $refund->currency ?? 'USD',
                    'reference_code' => $refund->reference_code,
                    'client_nom' => $refund->client?->name ?? '',
                ],
                actionUrl: '/finance/refunds',
            );
        }
    }
}

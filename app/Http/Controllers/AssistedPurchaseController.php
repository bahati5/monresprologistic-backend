<?php

namespace App\Http\Controllers;

use App\Enums\AssistedPurchaseStatus;
use App\Mail\AssistedPurchaseQuoteMail;
use App\Models\AssistedPurchase;
use App\Models\AssistedPurchaseItem;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AssistedPurchaseOrderedNotification;
use App\Notifications\QuoteReadyNotification;
use App\Support\AssistedPurchaseUrlLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssistedPurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = AssistedPurchase::query()->with([
            'user.profile.city',
            'user.profile.state',
            'user.profile.country',
            'operator',
            'items.merchant',
        ])->latest();

        if ($user->hasRole('client')) {
            $q->where('user_id', $user->id);
        } elseif (! $user->canAccessAllAgencies()) {
            $q->whereHas('user', fn ($u) => $u->where('agency_id', $user->agency_id));
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', Rule::in(array_map(fn (AssistedPurchaseStatus $s) => $s->value, AssistedPurchaseStatus::cases()))],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'client_search' => ['nullable', 'string', 'max:120'],
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
        ]);

        if (! empty($validated['search'])) {
            $term = '%'.addcslashes($validated['search'], '%_\\').'%';
            $q->where(function ($qq) use ($term) {
                $qq->where('article_label', 'like', $term)
                    ->orWhere('product_url', 'like', $term)
                    ->orWhereHas('items', function ($i) use ($term) {
                        $i->where('name', 'like', $term)
                            ->orWhere('url', 'like', $term)
                            ->orWhere('display_label', 'like', $term);
                    });
            });
        }

        if (! empty($validated['status'])) {
            $q->where('status', $validated['status']);
        }

        if (! empty($validated['date_from'])) {
            $q->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $q->whereDate('created_at', '<=', $validated['date_to']);
        }

        if (! $user->hasRole('client')) {
            if (! empty($validated['user_id'])) {
                $q->where('user_id', (int) $validated['user_id']);
            }
            if (! empty($validated['client_search'])) {
                $cs = '%'.addcslashes($validated['client_search'], '%_\\').'%';
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', $cs)->orWhere('email', 'like', $cs));
            }
        }

        if (! empty($validated['merchant_id'])) {
            $mid = (int) $validated['merchant_id'];
            $q->whereHas('items', fn ($i) => $i->where('merchant_id', $mid));
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        $page = (int) ($validated['page'] ?? 1);

        return response()->json([
            'purchases' => $q->paginate($perPage, ['*'], 'page', $page),
        ]);
    }

    public function create(): JsonResponse
    {
        return response()->json(['message' => 'OK']);
    }

    public function show(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);

        $assisted_purchase->load([
            'user.profile.city',
            'user.profile.state',
            'user.profile.country',
            'operator',
            'items.merchant',
        ]);

        return response()->json([
            'purchase' => $assisted_purchase,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:10000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.url' => ['required', 'url', 'max:2000'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.options' => ['nullable', 'string', 'max:5000'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.merchant_id' => ['nullable', 'integer', Rule::exists('merchants', 'id')],
        ]);

        $first = $data['items'][0];
        $firstNameRaw = is_string($first['name'] ?? null) ? trim($first['name']) : '';
        $firstName = $firstNameRaw !== ''
            ? $firstNameRaw
            : AssistedPurchaseUrlLabel::fromUrl($first['url']);

        $purchase = DB::transaction(function () use ($request, $data, $first, $firstName) {
            $parent = AssistedPurchase::query()->create([
                'user_id' => $request->user()->id,
                'status' => AssistedPurchaseStatus::PENDING_QUOTE,
                'notes' => $data['notes'] ?? null,
                'product_url' => $first['url'],
                'article_label' => $firstName,
                'quantity' => (int) $first['quantity'],
            ]);

            foreach ($data['items'] as $row) {
                $rowNameRaw = is_string($row['name'] ?? null) ? trim($row['name']) : '';
                $rowName = $rowNameRaw !== ''
                    ? $rowNameRaw
                    : AssistedPurchaseUrlLabel::fromUrl($row['url']);
                $mid = $row['merchant_id'] ?? null;
                AssistedPurchaseItem::query()->create([
                    'assisted_purchase_id' => $parent->id,
                    'merchant_id' => $mid !== null && $mid !== '' ? (int) $mid : null,
                    'url' => $row['url'],
                    'name' => $rowName,
                    'options' => $row['options'] ?? null,
                    'quantity' => (int) $row['quantity'],
                ]);
            }

            return $parent->load('items');
        });

        return response()->json([
            'message' => 'Demande d’achat assisté envoyée.',
            'purchase' => $purchase,
        ]);
    }

    /**
     * Aperçu HTML du mail de devis (sans enregistrement), pour l’admin.
     */
    public function quotePreview(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        $data = $this->validateQuotePayload($request, $assisted_purchase);
        $currency = $this->applicationQuoteCurrency();

        $assisted_purchase->load('items');
        $itemsById = $assisted_purchase->items->keyBy('id');

        $linesTotal = 0.0;
        foreach ($data['items'] as $row) {
            $item = $itemsById->get((int) $row['id']);
            if (! $item) {
                continue;
            }
            $item->unit_price = (float) $row['unit_price'];
            $linesTotal += (float) $row['unit_price'] * (int) $item->quantity;
        }

        $serviceFee = (float) $data['service_fee'];
        $bankFeePct = array_key_exists('bank_fee_percentage', $data) && $data['bank_fee_percentage'] !== null
            ? (float) $data['bank_fee_percentage']
            : 3.0;
        $bankFeeAmount = ($linesTotal + $serviceFee) * ($bankFeePct / 100.0);
        $totalAmount = $linesTotal + $serviceFee + $bankFeeAmount;

        $rawNote = $data['payment_methods_note'] ?? null;
        $paymentMethodsNote = is_string($rawNote) && trim($rawNote) !== '' ? trim($rawNote) : null;

        $assisted_purchase->forceFill([
            'service_fee' => $serviceFee,
            'bank_fee_percentage' => $bankFeePct,
            'payment_methods_note' => $paymentMethodsNote,
            'total_amount' => $totalAmount,
            'quote_amount' => $totalAmount,
            'quote_currency' => $currency,
        ]);

        try {
            $html = (new AssistedPurchaseQuoteMail($assisted_purchase))->render();
        } finally {
            $assisted_purchase->refresh();
            $assisted_purchase->load('items');
        }

        return response()->json(['html' => $html]);
    }

    public function quote(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        $data = $this->validateQuotePayload($request, $assisted_purchase);
        $currency = $this->applicationQuoteCurrency();

        $bankFeePct = array_key_exists('bank_fee_percentage', $data) && $data['bank_fee_percentage'] !== null
            ? (float) $data['bank_fee_percentage']
            : 3.0;
        $rawNote = $data['payment_methods_note'] ?? null;
        $paymentMethodsNote = is_string($rawNote) && trim($rawNote) !== '' ? trim($rawNote) : null;

        DB::transaction(function () use ($request, $assisted_purchase, $data, $currency, $bankFeePct, $paymentMethodsNote) {
            $itemsById = $assisted_purchase->items->keyBy('id');

            $linesTotal = 0.0;
            foreach ($data['items'] as $row) {
                $item = $itemsById->get((int) $row['id']);
                if (! $item) {
                    continue;
                }
                $unit = (float) $row['unit_price'];
                $item->update(['unit_price' => $unit]);
                $linesTotal += $unit * (int) $item->quantity;
            }

            $serviceFee = (float) $data['service_fee'];
            $bankFeeAmount = ($linesTotal + $serviceFee) * ($bankFeePct / 100.0);
            $totalAmount = $linesTotal + $serviceFee + $bankFeeAmount;

            $assisted_purchase->update([
                'operator_id' => $request->user()->id,
                'quoted_at' => now(),
                'status' => AssistedPurchaseStatus::AWAITING_PAYMENT,
                'service_fee' => $serviceFee,
                'bank_fee_percentage' => $bankFeePct,
                'payment_methods_note' => $paymentMethodsNote,
                'total_amount' => $totalAmount,
                'quote_amount' => $totalAmount,
                'quote_currency' => $currency,
            ]);
        });

        $assisted_purchase->refresh()->load(['items', 'user']);
        $client = $assisted_purchase->user;
        $dashboardUrl = rtrim((string) env('FRONTEND_URL', config('app.url')), '/').'/dashboard';

        if ($client?->email) {
            try {
                /** @var User $client */
                $client->notify(new QuoteReadyNotification($assisted_purchase));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($client) {
            $amount = number_format((float) ($assisted_purchase->total_amount ?? $assisted_purchase->quote_amount), 2, ',', ' ')
                .' '.$assisted_purchase->quote_currency;
            NotificationController::notify(
                $client->id,
                'assisted_purchase',
                'Devis disponible — achat assisté',
                "Un devis de {$amount} a été établi pour votre demande. Consultez votre espace pour la suite.",
                ['assisted_purchase_id' => $assisted_purchase->id],
                $dashboardUrl,
                ['in_app']
            );
        }

        return response()->json(['message' => 'Devis enregistré.']);
    }

    /**
     * Après paiement : l’agent indique que la commande fournisseur est passée et saisit le suivi marchand.
     */
    public function markAsOrdered(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        if ($assisted_purchase->status !== AssistedPurchaseStatus::PAID) {
            throw ValidationException::withMessages([
                'status' => ['Seules les demandes au statut « Paiement validé » peuvent être marquées comme commandées au fournisseur.'],
            ]);
        }

        $data = $request->validate([
            'supplier_tracking_number' => ['nullable', 'string', 'max:255'],
        ]);

        $trackingRaw = $data['supplier_tracking_number'] ?? null;
        $tracking = is_string($trackingRaw) ? trim($trackingRaw) : null;
        if ($tracking === '') {
            $tracking = null;
        }

        $assisted_purchase->update([
            'operator_id' => $request->user()->id,
            'status' => AssistedPurchaseStatus::ORDERED,
            'supplier_tracking_number' => $tracking,
            'purchased_at' => now(),
        ]);

        $assisted_purchase->refresh()->load(['user']);
        $client = $assisted_purchase->user;
        $dashboardUrl = rtrim((string) env('FRONTEND_URL', config('app.url')), '/').'/dashboard';

        if ($client?->email) {
            try {
                /** @var User $client */
                $client->notify(new AssistedPurchaseOrderedNotification($assisted_purchase));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($client) {
            NotificationController::notify(
                $client->id,
                'assisted_purchase',
                'Articles commandés chez le fournisseur',
                'Vos articles ont été achetés et sont en route vers notre entrepôt européen. Consultez votre espace pour le suivi.',
                ['assisted_purchase_id' => $assisted_purchase->id],
                $dashboardUrl,
                ['in_app']
            );
        }

        return response()->json([
            'message' => 'Commande fournisseur enregistrée. Le client a été notifié.',
            'purchase' => $assisted_purchase->load([
                'user.profile.city',
                'user.profile.state',
                'user.profile.country',
                'operator',
                'items.merchant',
            ]),
        ]);
    }

    /**
     * Devise des devis : toujours celle des paramètres généraux (`settings.currency`).
     */
    protected function applicationQuoteCurrency(): string
    {
        $c = Setting::getValue('currency');

        return ($c !== null && trim($c) !== '') ? trim($c) : 'EUR';
    }

    /**
     * @return array{items: array<int, array{id: int, unit_price: float}>, service_fee: numeric-string|float|int, bank_fee_percentage?: float|null, payment_methods_note?: string|null}
     */
    protected function validateQuotePayload(Request $request, AssistedPurchase $assisted_purchase): array
    {
        $assisted_purchase->loadMissing('items');

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => [
                'required',
                'integer',
                Rule::exists('assisted_purchase_items', 'id')->where(
                    'assisted_purchase_id',
                    $assisted_purchase->id
                ),
            ],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'service_fee' => ['required', 'numeric', 'min:0'],
            'bank_fee_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_methods_note' => ['nullable', 'string', 'max:10000'],
        ]);

        $itemIds = $assisted_purchase->items->modelKeys();
        $payloadIds = collect($data['items'])->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        sort($itemIds);

        if ($payloadIds !== $itemIds) {
            throw ValidationException::withMessages([
                'items' => ['Le devis doit inclure une ligne de prix pour chaque article de la demande.'],
            ]);
        }

        return $data;
    }

    protected function authorizeStaff(Request $request): void
    {
        abort_unless(
            $request->user()->can('manage_assisted_purchases'),
            403
        );
    }

    protected function authorizeView(Request $request, AssistedPurchase $assisted_purchase): void
    {
        $user = $request->user();
        if ($assisted_purchase->user_id === $user->id) {
            return;
        }

        abort_unless(
            $user->hasAnyRole(['super_admin', 'agency_admin', 'operator']),
            403
        );

        $assisted_purchase->loadMissing('user');

        if ($user->canAccessAllAgencies()) {
            return;
        }

        abort_unless(
            (int) $assisted_purchase->user?->agency_id === (int) $user->agency_id,
            403
        );
    }
}

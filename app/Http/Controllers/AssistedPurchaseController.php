<?php

namespace App\Http\Controllers;

use App\Enums\AssistedPurchaseStatus;
use App\Enums\PickupStatus;
use App\Enums\ShipmentStatus;
use App\Events\AssistedPurchaseStatusChanged;
use App\Jobs\ScrapeProductJob;
use App\Mail\AssistedPurchaseQuoteMail;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Models\AssistedPurchaseItem;
use App\Models\AssistedPurchasePayment;
use App\Models\Notification;
use App\Models\Pickup;
use App\Models\Profile;
use App\Models\QuoteSnapshot;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Notifications\AssistedPurchaseOrderedNotification;
use App\Notifications\QuoteReadyNotification;
use App\Services\NotificationDispatcher;
use App\Services\QuoteCalculationService;
use App\Services\QuoteFollowUpService;
use App\Services\Scraping\ProductScraperService;
use App\Services\Twilio\TwilioGateway;
use App\Support\AssistedPurchaseUrlLabel;
use App\Support\FrontendPortalUrl;
use App\Support\QuoteSnapshotDataNormalizer;
use App\Support\ShipmentDocumentSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            'tab' => ['nullable', 'string', Rule::in(['active', 'history'])],
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
        } elseif (($validated['tab'] ?? 'active') === 'active') {
            $q->whereNotIn('status', [
                AssistedPurchaseStatus::CONVERTED_TO_SHIPMENT->value,
                AssistedPurchaseStatus::CANCELLED->value,
            ]);
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
            'payments.recordedBy',
            'convertedShipment',
        ]);

        $latestSnapshot = QuoteSnapshot::where('assisted_purchase_id', $assisted_purchase->id)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();

        $snapshotHistory = QuoteSnapshot::where('assisted_purchase_id', $assisted_purchase->id)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'version', 'sent_at', 'total_primary', 'primary_currency', 'revision_reason', 'created_at'])
            ->map(fn (QuoteSnapshot $s) => [
                'id' => $s->id,
                'version' => $s->version,
                'sent_at' => $s->sent_at?->toIso8601String(),
                'total_primary' => (string) $s->total_primary,
                'primary_currency' => $s->primary_currency,
                'revision_reason' => $s->revision_reason,
                'created_at' => $s->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $data = $assisted_purchase->toArray();
        if ($latestSnapshot) {
            $data['latest_snapshot'] = [
                'snapshot_data' => $latestSnapshot->snapshot_data,
                'estimated_delivery' => $latestSnapshot->estimated_delivery,
                'staff_message' => $latestSnapshot->staff_message,
                'total_primary' => $latestSnapshot->total_primary,
                'primary_currency' => $latestSnapshot->primary_currency,
            ];
        }
        $data['quote_snapshot_history'] = $snapshotHistory;
        $data['dossier_timeline'] = $this->buildDossierTimeline($assisted_purchase);

        return response()->json([
            'purchase' => $data,
        ]);
    }

    private function assertStaffCanSubmitQuote(AssistedPurchase $purchase): void
    {
        $status = $purchase->status;
        if (! $status instanceof AssistedPurchaseStatus) {
            throw ValidationException::withMessages([
                'status' => ['Statut de demande invalide.'],
            ]);
        }

        $allowed = [
            AssistedPurchaseStatus::PENDING_QUOTE,
            AssistedPurchaseStatus::QUOTED,
            AssistedPurchaseStatus::AWAITING_PAYMENT,
        ];

        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ['Impossible d’envoyer ou de modifier le devis pour ce statut.'],
            ]);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $auth = $request->user();
        $isStaff = $auth->hasAnyRole(['super_admin', 'agency_admin', 'operator']);

        $rules = [
            'notes' => ['nullable', 'string', 'max:10000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.url' => ['nullable', 'url', 'max:2000'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.options' => ['nullable', 'string', 'max:5000'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.merchant_id' => ['nullable', 'integer', Rule::exists('merchants', 'id')],
        ];

        if ($isStaff) {
            $rules['user_id'] = ['required', 'integer', Rule::exists('users', 'id')];
        } else {
            $rules['user_id'] = ['prohibited'];
        }

        $data = $request->validate($rules);

        $clientId = $isStaff ? (int) $data['user_id'] : (int) $auth->id;

        if ($isStaff) {
            $clientUser = User::query()->find($clientId);
            if (! $clientUser || ! $clientUser->hasRole('client')) {
                throw ValidationException::withMessages([
                    'user_id' => ['Sélectionnez un compte client avec accès portail valide.'],
                ]);
            }
            if (! $auth->canAccessAllAgencies()) {
                if ((int) ($clientUser->agency_id ?? 0) !== (int) ($auth->agency_id ?? 0)) {
                    throw ValidationException::withMessages([
                        'user_id' => ['Ce client n’appartient pas à votre agence.'],
                    ]);
                }
            }
        }

        $first = $data['items'][0];
        $firstNameRaw = is_string($first['name'] ?? null) ? trim($first['name']) : '';
        $firstName = $firstNameRaw !== ''
            ? $firstNameRaw
            : AssistedPurchaseUrlLabel::fromUrl($first['url']);

        $purchase = DB::transaction(function () use ($data, $first, $firstName, $clientId) {
            $parent = AssistedPurchase::query()->create([
                'user_id' => $clientId,
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

        $this->autoExtractItems($purchase);

        return response()->json([
            'message' => 'Demande d’achat assisté envoyée.',
            'purchase' => $purchase,
        ]);
    }

    /**
     * Aperçu HTML du mail de devis (sans enregistrement), pour l’admin.
     */
    /**
     * Auto-extract product info from URLs for each item that lacks a proper name.
     */
    private function autoExtractItems(AssistedPurchase $purchase): void
    {
        $scraper = app(ProductScraperService::class);

        foreach ($purchase->items as $item) {
            $url = trim((string) ($item->url ?? ''));
            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            if ($item->name && mb_strlen($item->name) > 20) {
                continue;
            }

            try {
                $result = $scraper->scrape($url);
                if ($result->success && $result->name) {
                    $item->update(['name' => $result->name]);
                }
            } catch (\Throwable $e) {
                Log::warning('Auto-extract failed', [
                    'item_id' => $item->id,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $purchase->refresh();
        $firstItem = $purchase->items->first();
        if ($firstItem && $firstItem->name && $firstItem->name !== $purchase->article_label) {
            $purchase->update(['article_label' => $firstItem->name]);
        }
    }

    public function quotePreview(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        $data = $this->validateQuotePayload($request, $assisted_purchase);

        $dynamicLines = $request->validate([
            'lines' => ['sometimes', 'array'],
            'lines.*.internal_code' => ['required_with:lines', 'string', 'max:50'],
            'lines.*.name' => ['required_with:lines', 'string', 'max:255'],
            'lines.*.type' => ['required_with:lines', 'in:percentage,fixed_amount,manual'],
            'lines.*.calculation_base' => ['nullable', 'string'],
            'lines.*.value' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.is_visible_to_client' => ['boolean'],
            'estimated_delivery' => ['nullable', 'string', 'max:100'],
            'staff_message' => ['nullable', 'string', 'max:5000'],
        ]);

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
            if (isset($row['quantity'])) {
                $item->quantity = (int) $row['quantity'];
            }
            $linesTotal += (float) $row['unit_price'] * (int) $item->quantity;
        }

        $rawNote = $data['payment_methods_note'] ?? null;
        $paymentMethodsNote = is_string($rawNote) && trim($rawNote) !== '' ? trim($rawNote) : null;

        $hasDynamicLines = ! empty($dynamicLines['lines']);

        if ($hasDynamicLines) {
            $calcService = app(QuoteCalculationService::class);

            $articles = [];
            foreach ($data['items'] as $row) {
                $item = $itemsById->get((int) $row['id']);
                if ($item) {
                    $articles[] = [
                        'id' => $item->id,
                        'name' => $item->name ?? $item->display_label,
                        'url' => $item->url,
                        'unit_price' => (float) $row['unit_price'],
                        'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : (int) $item->quantity,
                    ];
                }
            }

            $calculationResult = $calcService->calculate($articles, $dynamicLines['lines']);
            $totalAmount = $calculationResult['total_primary'];

            $assisted_purchase->forceFill([
                'service_fee' => 0,
                'bank_fee_percentage' => 0,
                'payment_methods_note' => $paymentMethodsNote,
                'total_amount' => $totalAmount,
                'quote_amount' => $totalAmount,
                'quote_currency' => $currency,
            ]);

            $snapshotData = $calcService->buildSnapshot(
                $articles,
                $dynamicLines['lines'],
                $calculationResult,
                $dynamicLines['estimated_delivery'] ?? null,
                $dynamicLines['staff_message'] ?? null,
                false,
                null,
            );

            $mail = new AssistedPurchaseQuoteMail($assisted_purchase);
            $mail->snapshotData = QuoteSnapshotDataNormalizer::toArray($snapshotData);
            $mail->estimatedDelivery = $dynamicLines['estimated_delivery'] ?? null;
            $mail->staffMessage = $dynamicLines['staff_message'] ?? null;
            $mail->totalFormatted = $this->formatMoneyForPreview($totalAmount, $currency);

            $mail->refreshTemplateIntroHtml();

            $html = $mail->render();
        } else {
            $serviceFee = (float) $data['service_fee'];
            $bankFeePct = array_key_exists('bank_fee_percentage', $data) && $data['bank_fee_percentage'] !== null
                ? (float) $data['bank_fee_percentage']
                : 3.0;
            $bankFeeAmount = ($linesTotal + $serviceFee) * ($bankFeePct / 100.0);
            $totalAmount = $linesTotal + $serviceFee + $bankFeeAmount;

            $assisted_purchase->forceFill([
                'service_fee' => $serviceFee,
                'bank_fee_percentage' => $bankFeePct,
                'payment_methods_note' => $paymentMethodsNote,
                'total_amount' => $totalAmount,
                'quote_amount' => $totalAmount,
                'quote_currency' => $currency,
            ]);

            $html = (new AssistedPurchaseQuoteMail($assisted_purchase))->render();
        }

        $assisted_purchase->refresh();
        $assisted_purchase->load('items');

        return response()->json(['html' => $html]);
    }

    private function formatMoneyForPreview(float $amount, string $currency): string
    {
        $doc = ShipmentDocumentSettings::merged();
        $symbol = trim((string) ($doc['currency_symbol'] ?? ''));
        if ($symbol === '') {
            $symbol = match (strtoupper($currency)) {
                'EUR' => '€',
                'USD' => '$',
                'GBP' => '£',
                default => $currency,
            };
        }
        $decimals = max(0, min(6, (int) ($doc['decimals'] ?? 2)));
        $num = number_format($amount, $decimals, ',', ' ');
        $suffix = ((string) (Setting::getValue('symbol_position', 'prefix') ?: 'prefix')) === 'suffix';

        return $suffix ? $num."\u{a0}".$symbol : $symbol.$num;
    }

    public function quote(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);
        $this->assertStaffCanSubmitQuote($assisted_purchase);

        $data = $this->validateQuotePayload($request, $assisted_purchase);
        $currency = $this->applicationQuoteCurrency();

        $bankFeePct = array_key_exists('bank_fee_percentage', $data) && $data['bank_fee_percentage'] !== null
            ? (float) $data['bank_fee_percentage']
            : 3.0;
        $rawNote = $data['payment_methods_note'] ?? null;
        $paymentMethodsNote = is_string($rawNote) && trim($rawNote) !== '' ? trim($rawNote) : null;

        $statusBeforeQuote = AssistedPurchaseStatus::tryFromString($assisted_purchase->status);

        DB::transaction(function () use ($request, $assisted_purchase, $data, $currency, $bankFeePct, $paymentMethodsNote) {
            $itemsById = $assisted_purchase->items->keyBy('id');

            $linesTotal = 0.0;
            foreach ($data['items'] as $row) {
                $item = $itemsById->get((int) $row['id']);
                if (! $item) {
                    continue;
                }
                $unit = (float) $row['unit_price'];
                $qty = isset($row['quantity']) ? (int) $row['quantity'] : (int) $item->quantity;
                $item->update(['unit_price' => $unit, 'quantity' => $qty]);
                $linesTotal += $unit * $qty;
            }

            $serviceFee = (float) $data['service_fee'];
            $bankFeeAmount = ($linesTotal + $serviceFee) * ($bankFeePct / 100.0);
            $totalAmount = $linesTotal + $serviceFee + $bankFeeAmount;

            $estW = $data['estimated_weight_kg'] ?? null;
            $assisted_purchase->update([
                'operator_id' => $request->user()->id,
                'quoted_at' => now(),
                'status' => AssistedPurchaseStatus::QUOTED,
                'service_fee' => $serviceFee,
                'bank_fee_percentage' => $bankFeePct,
                'payment_methods_note' => $paymentMethodsNote,
                'total_amount' => $totalAmount,
                'quote_amount' => $totalAmount,
                'quote_currency' => $currency,
                'estimated_weight_kg' => $estW !== null && $estW !== '' ? (float) $estW : null,
            ]);
        });

        $assisted_purchase->refresh()->load(['items', 'user']);
        $newQuoted = AssistedPurchaseStatus::QUOTED;
        $oldForEvent = $statusBeforeQuote ?? AssistedPurchaseStatus::PENDING_QUOTE;
        if ($oldForEvent !== $newQuoted) {
            AssistedPurchaseStatusChanged::dispatch(
                $assisted_purchase->fresh(['user']),
                $oldForEvent,
                $newQuoted,
                $request->user()
            );
        }

        $client = $assisted_purchase->user;
        $base = FrontendPortalUrl::base();
        $purchaseUrl = $base.'/purchase-orders/'.$assisted_purchase->id;

        $mailStatus = $this->sendQuoteReadyNotificationToClient($assisted_purchase);

        if ($client) {
            $amount = number_format((float) ($assisted_purchase->total_amount ?? $assisted_purchase->quote_amount), 2, ',', ' ')
                .' '.$assisted_purchase->quote_currency;
            NotificationController::notify(
                $client->id,
                'assisted_purchase',
                'Devis envoyé — achat assisté',
                "Un devis de {$amount} a été établi pour votre demande. Ouvrez votre devis pour consulter le détail et les modalités de paiement.",
                ['assisted_purchase_id' => $assisted_purchase->id],
                $purchaseUrl,
                ['in_app']
            );
        }

        return response()->json([
            'message' => 'Devis enregistré.',
            'mail_status' => $mailStatus,
        ]);
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
        $dashboardUrl = FrontendPortalUrl::base().'/dashboard';

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
     * Renvoie le même e-mail / notification de devis (après envoi initial).
     */
    public function resendQuote(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        if ($assisted_purchase->quoted_at === null) {
            throw ValidationException::withMessages([
                'quote' => ['Aucun devis n’a encore été enregistré pour cette demande.'],
            ]);
        }

        if ($assisted_purchase->status === AssistedPurchaseStatus::PENDING_QUOTE) {
            throw ValidationException::withMessages([
                'status' => ['Le devis n’a pas été envoyé : la demande est encore en chiffrage.'],
            ]);
        }

        if ($assisted_purchase->status === AssistedPurchaseStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => ['Impossible de renvoyer un devis pour une demande annulée.'],
            ]);
        }

        $assisted_purchase->load(['items', 'user']);
        $client = $assisted_purchase->user;
        $base = FrontendPortalUrl::base();
        $purchaseUrl = $base.'/purchase-orders/'.$assisted_purchase->id;

        $mailStatus = $this->sendQuoteReadyNotificationToClient($assisted_purchase);

        if ($client) {
            $amount = number_format((float) ($assisted_purchase->total_amount ?? $assisted_purchase->quote_amount), 2, ',', ' ')
                .' '.$assisted_purchase->quote_currency;
            NotificationController::notify(
                $client->id,
                'assisted_purchase',
                'Devis (rappel) — achat assisté',
                "Nous vous renvoyons le devis de {$amount} pour votre demande. Consultez le détail dans votre espace.",
                ['assisted_purchase_id' => $assisted_purchase->id],
                $purchaseUrl,
                ['in_app']
            );
        }

        return response()->json([
            'message' => 'Devis renvoyé au client (e-mail et notification).',
            'mail_status' => $mailStatus,
        ]);
    }

    /**
     * Enregistre un encaissement (acompte ou solde). Passe en « Paiement validé » lorsque le cumul couvre le total du devis.
     */
    public function markPaid(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        if ($assisted_purchase->status === AssistedPurchaseStatus::PAID) {
            throw ValidationException::withMessages([
                'status' => ['Cette demande est déjà marquée comme entièrement payée.'],
            ]);
        }

        if (! in_array($assisted_purchase->status, [
            AssistedPurchaseStatus::QUOTED,
            AssistedPurchaseStatus::AWAITING_PAYMENT,
            AssistedPurchaseStatus::ORDERED,
            AssistedPurchaseStatus::ARRIVED_AT_HUB,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['Les encaissements ne peuvent être enregistrés que sur un dossier actif avec devis (hors annulation / conversion).'],
            ]);
        }

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $total = (float) ($assisted_purchase->total_amount ?? $assisted_purchase->quote_amount ?? 0);
        if ($total <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Montant total du devis invalide ou non défini.'],
            ]);
        }

        return DB::transaction(function () use ($request, $assisted_purchase, $data, $total) {
            $purchase = AssistedPurchase::query()->whereKey($assisted_purchase->id)->lockForUpdate()->firstOrFail();

            $already = (float) AssistedPurchasePayment::where('assisted_purchase_id', $purchase->id)->sum('amount');
            $remaining = round(max(0, $total - $already), 2);

            if ($remaining <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Aucun solde restant à enregistrer sur ce devis.'],
                ]);
            }

            $rawAmount = $data['amount'] ?? null;
            $inputAmount = $rawAmount !== null && $rawAmount !== ''
                ? round((float) $rawAmount, 2)
                : $remaining;

            if ($inputAmount <= 0) {
                throw ValidationException::withMessages(['amount' => ['Montant invalide.']]);
            }

            if ($inputAmount - $remaining > 0.02) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'Le montant dépasse le solde restant ('.number_format($remaining, 2, ',', ' ').' '.trim((string) ($purchase->quote_currency ?: '')).').',
                    ],
                ]);
            }

            AssistedPurchasePayment::create([
                'assisted_purchase_id' => $purchase->id,
                'amount' => $inputAmount,
                'currency' => $purchase->quote_currency,
                'note' => isset($data['note']) && is_string($data['note']) && trim($data['note']) !== '' ? trim($data['note']) : null,
                'recorded_by' => $request->user()->id,
            ]);

            $newSum = round($already + $inputAmount, 2);
            $fullyPaid = $newSum + 0.005 >= $total;
            $oldStatus = $purchase->status;

            if ($fullyPaid) {
                $purchase->update([
                    'status' => AssistedPurchaseStatus::PAID,
                    'paid_at' => $purchase->paid_at ?? now(),
                ]);
            }

            $purchase->refresh()->load(['user', 'payments.recordedBy']);

            if ($fullyPaid && $oldStatus !== AssistedPurchaseStatus::PAID) {
                AssistedPurchaseStatusChanged::dispatch(
                    $purchase->fresh(['user']),
                    $oldStatus,
                    AssistedPurchaseStatus::PAID,
                    $request->user()
                );
            }

            $client = $purchase->user;
            $base = FrontendPortalUrl::base();
            $purchaseUrl = $base.'/purchase-orders/'.$purchase->id;

            if ($fullyPaid && $client) {
                NotificationController::notify(
                    $client->id,
                    'assisted_purchase',
                    'Paiement validé',
                    'Votre paiement pour la demande d’achat assisté n° '.$purchase->id.' a été validé par notre équipe.',
                    ['assisted_purchase_id' => $purchase->id],
                    $purchaseUrl,
                    ['in_app']
                );
            }

            $remainingAfter = max(0, round($total - $newSum, 2));
            $message = $fullyPaid
                ? 'Encaissement enregistré. Le dossier est passé en « Paiement validé ».'
                : 'Encaissement enregistré. Solde restant : '.number_format($remainingAfter, 2, ',', ' ').' '.trim((string) ($purchase->quote_currency ?: '')).'.';

            return response()->json([
                'message' => $message,
                'total_paid' => $newSum,
                'remaining' => $remainingAfter,
                'purchase' => $purchase->load([
                    'user.profile.city',
                    'user.profile.state',
                    'user.profile.country',
                    'operator',
                    'items.merchant',
                    'payments.recordedBy',
                ]),
            ]);
        });
    }

    /**
     * Passe le dossier de « Devis envoyé » (quoted) à « En attente de paiement » pour que le client puisse régler.
     */
    public function publishPaymentRequest(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        if ($assisted_purchase->status !== AssistedPurchaseStatus::QUOTED) {
            throw ValidationException::withMessages([
                'status' => ['Le dossier doit être au statut « Devis envoyé » avant d’ouvrir la phase paiement.'],
            ]);
        }

        $current = AssistedPurchaseStatus::QUOTED;
        $target = AssistedPurchaseStatus::AWAITING_PAYMENT;
        if (! $current->canTransitionTo($target)) {
            throw ValidationException::withMessages(['status' => ['Transition non autorisée.']]);
        }

        $old = $assisted_purchase->status;
        $assisted_purchase->update([
            'status' => $target,
            'quoted_at' => $assisted_purchase->quoted_at ?? now(),
        ]);

        AssistedPurchaseStatusChanged::dispatch(
            $assisted_purchase->fresh(['user']),
            $old,
            $target,
            $request->user()
        );

        return response()->json([
            'message' => 'Demande de paiement activée pour le client.',
            'purchase' => $assisted_purchase->fresh(['items', 'user', 'operator']),
        ]);
    }

    /**
     * Rejette la preuve de paiement avec motif (retour au statut devis envoyé).
     */
    public function rejectPaymentProof(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        if ($assisted_purchase->status !== AssistedPurchaseStatus::AWAITING_PAYMENT) {
            throw ValidationException::withMessages([
                'status' => ['Le rejet ne s’applique qu’aux dossiers en attente de paiement.'],
            ]);
        }

        $current = AssistedPurchaseStatus::AWAITING_PAYMENT;
        $target = AssistedPurchaseStatus::QUOTED;
        if (! $current->canTransitionTo($target)) {
            throw ValidationException::withMessages(['status' => ['Transition non autorisée.']]);
        }

        $old = $assisted_purchase->status;
        $reasonBlock = "\n\n[Rejet preuve paiement — ".now()->toDateTimeString()."]\n".trim($data['rejection_reason']);
        $notes = trim((string) ($assisted_purchase->notes ?? '')).$reasonBlock;

        if ($assisted_purchase->payment_proof_path) {
            Storage::disk('local')->delete($assisted_purchase->payment_proof_path);
        }

        $assisted_purchase->update([
            'status' => $target,
            'payment_proof_path' => null,
            'notes' => $notes,
        ]);

        AssistedPurchaseStatusChanged::dispatch(
            $assisted_purchase->fresh(['user']),
            $old,
            $target,
            $request->user()
        );

        $assisted_purchase->loadMissing('user');
        if ($assisted_purchase->user) {
            NotificationController::notify(
                $assisted_purchase->user->id,
                'assisted_purchase',
                'Preuve de paiement non acceptée',
                'Votre preuve de paiement n’a pas été acceptée. Motif : '.Str::limit(trim($data['rejection_reason']), 200),
                ['assisted_purchase_id' => $assisted_purchase->id],
                FrontendPortalUrl::base().'/purchase-orders/'.$assisted_purchase->id,
                ['in_app']
            );
        }

        return response()->json([
            'message' => 'Preuve rejetée. Le dossier est repassé en « Devis envoyé ».',
            'purchase' => $assisted_purchase->fresh(['items', 'user', 'operator']),
        ]);
    }

    /**
     * Le client indique avoir réglé le devis (notification à l’équipe pour validation manuelle).
     */
    public function clientPaymentAck(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $auth = $request->user();
        abort_unless((int) $assisted_purchase->user_id === (int) $auth->id, 403);

        if ($assisted_purchase->status !== AssistedPurchaseStatus::AWAITING_PAYMENT) {
            throw ValidationException::withMessages([
                'status' => ['Vous ne pouvez signaler un paiement que lorsque le devis est en attente de règlement.'],
            ]);
        }

        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:2000'],
            'payment_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        if ($request->hasFile('payment_proof')) {
            $file = $request->file('payment_proof');
            $path = $file->store('payment-proofs', 'local');
            $assisted_purchase->update(['payment_proof_path' => $path]);
        }

        $assisted_purchase->loadMissing('user');
        $client = $assisted_purchase->user;

        $extra = isset($data['message']) && is_string($data['message']) && trim($data['message']) !== ''
            ? ' Message : '.trim($data['message'])
            : '';

        $proofNote = $request->hasFile('payment_proof') ? ' (preuve de paiement jointe)' : '';

        $this->notifyStaffAssistedPurchaseEvent(
            $assisted_purchase,
            'Paiement signalé par le client',
            'Le client '.trim((string) ($client?->name ?? '')).' indique avoir effectué le paiement pour la demande n° '.$assisted_purchase->id.'.'.$extra.$proofNote
        );

        return response()->json([
            'message' => 'Merci. Notre équipe a été prévenue et validera votre paiement sous peu.',
            'payment_proof_url' => $assisted_purchase->payment_proof_url,
        ]);
    }

    /**
     * Télécharger la preuve de paiement (client propriétaire ou staff).
     */
    public function downloadPaymentProof(Request $request, AssistedPurchase $assisted_purchase)
    {
        $auth = $request->user();
        $isOwner = (int) $assisted_purchase->user_id === (int) $auth->id;
        $isStaff = $auth->hasAnyRole(['super_admin', 'agency_admin', 'operator']);
        abort_unless($isOwner || $isStaff, 403);

        $path = $assisted_purchase->payment_proof_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            abort(404, 'Aucune preuve de paiement trouvée.');
        }

        return Storage::disk('local')->response($path);
    }

    protected function notifyStaffAssistedPurchaseEvent(AssistedPurchase $assisted_purchase, string $title, string $body): void
    {
        $assisted_purchase->loadMissing('user');
        $client = $assisted_purchase->user;
        if (! $client instanceof User) {
            return;
        }

        $base = FrontendPortalUrl::base();
        $actionUrl = $base.'/purchase-orders/'.$assisted_purchase->id.'/chiffrage';

        $recipients = User::query()
            ->permission('manage_assisted_purchases')
            ->where(function ($w) use ($client) {
                $w->where('can_view_all_agencies', true);
                if ($client->agency_id !== null) {
                    $w->orWhere('agency_id', (int) $client->agency_id);
                }
            })
            ->where('id', '!=', $client->id)
            ->pluck('id')
            ->unique();

        foreach ($recipients as $staffId) {
            NotificationController::notify(
                (int) $staffId,
                'assisted_purchase',
                $title,
                $body,
                ['assisted_purchase_id' => $assisted_purchase->id],
                $actionUrl,
                ['in_app']
            );
        }
    }

    /**
     * §13 PRD — Devise de référence des devis.
     * Priorité : default_quote_currency > settings.currency (paramètres généraux).
     */
    protected function applicationQuoteCurrency(): string
    {
        $explicit = Setting::getValue('default_quote_currency');
        if ($explicit !== null && trim($explicit) !== '') {
            return trim($explicit);
        }

        $c = Setting::getValue('currency');

        return ($c !== null && trim($c) !== '') ? trim($c) : '';
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
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'service_fee' => ['required', 'numeric', 'min:0'],
            'bank_fee_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_methods_note' => ['nullable', 'string', 'max:10000'],
            'estimated_weight_kg' => ['nullable', 'numeric', 'min:0.001', 'max:99999'],
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

    /**
     * Envoie l’e-mail « devis prêt » au client et indique si la boîte réelle peut le recevoir.
     *
     * @return array{level: string, message: string}
     */
    protected function sendQuoteReadyNotificationToClient(AssistedPurchase $assisted_purchase): array
    {
        $assisted_purchase->loadMissing('user');
        $client = $assisted_purchase->user;
        if (! $client instanceof User) {
            return [
                'level' => 'error',
                'message' => 'Aucun client associé : impossible d’envoyer l’e-mail.',
            ];
        }

        $email = strtolower(trim((string) ($client->email ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'level' => 'error',
                'message' => 'Le client n’a pas d’adresse e-mail valide : l’e-mail de devis n’a pas été envoyé.',
            ];
        }

        $driver = (string) config('mail.default', 'log');
        $nonInboxDrivers = ['log', 'array'];

        try {
            $client->notify(new QuoteReadyNotification($assisted_purchase));
        } catch (\Throwable $e) {
            report($e);

            return [
                'level' => 'error',
                'message' => 'L’e-mail n’a pas pu être envoyé : '.$e->getMessage(),
            ];
        }

        $this->sendQuoteSmsToClient($assisted_purchase, $client);

        if (in_array($driver, $nonInboxDrivers, true)) {
            return [
                'level' => 'warning',
                'message' => 'Le serveur est en mode « journal » pour les e-mails (MAIL_MAILER=log ou équivalent) : rien n’est envoyé sur Internet. Configurez le SMTP dans Paramètres de l’application (hôte SMTP + expéditeur) ou définissez MAIL_MAILER=smtp et les variables MAIL_* dans le fichier .env, puis testez l’envoi depuis les paramètres.',
            ];
        }

        return ['level' => 'ok', 'message' => ''];
    }

    protected function sendQuoteSmsToClient(AssistedPurchase $purchase, User $client): void
    {
        if (! TwilioGateway::smsEnabled()) {
            return;
        }

        $phone = $client->phone ?? $client->profile?->phone ?? null;
        if (! $phone) {
            return;
        }

        $total = number_format((float) ($purchase->total_amount ?? 0), 2, '.', ' ');
        $currency = $purchase->quote_currency ?? 'USD';
        $ref = 'MRP-AA-'.str_pad((string) $purchase->id, 4, '0', STR_PAD_LEFT);
        $expiresAt = $purchase->quote_expires_at
            ? $purchase->quote_expires_at->format('d/m')
            : '';

        $baseUrl = config('app.frontend_url', 'https://monrespro.cd');
        $shortLink = $baseUrl.'/d/'.str_replace('MRP-AA-', 'AA', $ref);

        $message = "Monrespro: Votre devis {$ref} est pret. "
            ."Total: {$total} {$currency}."
            .($expiresAt ? " Valable jusqu'au {$expiresAt}." : '')
            ." Consultez: {$shortLink}";

        try {
            TwilioGateway::sendSms($phone, $message);
        } catch (\Throwable $e) {
            Log::warning("SMS devis failed for AP#{$purchase->id}: {$e->getMessage()}");
        }
    }

    /**
     * Convertit un achat assisté au statut « Colis reçu à l'entrepôt » en expédition logistique.
     * Logique métier : L'agence est l'expéditeur, le client est le destinataire.
     * Le client doit avoir un profil complet (adresse, téléphone, pays).
     */
    public function convertToShipment(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        $request->validate([
            'create_home_delivery' => ['nullable', 'boolean'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_time_slot' => ['nullable', 'string', 'in:morning,afternoon'],
            'delivery_instructions' => ['nullable', 'string', 'max:500'],
            'agency_id' => ['nullable', 'integer', Rule::exists('agencies', 'id')],
            'recipient_country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
            'recipient_city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')],
            'recipient_address' => ['nullable', 'string', 'max:500'],
            'recipient_phone' => ['nullable', 'string', 'max:30'],
            'notify_client' => ['nullable', 'boolean'],
            'check_only' => ['nullable', 'boolean'],
        ]);

        if ($assisted_purchase->status !== AssistedPurchaseStatus::ARRIVED_AT_HUB) {
            throw ValidationException::withMessages([
                'status' => ['Seuls les achats au statut « Colis reçu à l\'entrepôt » peuvent être convertis en expédition.'],
            ]);
        }

        if ($assisted_purchase->converted_shipment_id !== null) {
            throw ValidationException::withMessages([
                'converted' => ['Cet achat a déjà été converti en expédition (Shipment #'.$assisted_purchase->converted_shipment_id.').'],
            ]);
        }

        $assisted_purchase->load(['items', 'user']);
        $client = $assisted_purchase->user;

        if (! $client) {
            throw ValidationException::withMessages([
                'client' => ['Aucun client associé à cet achat assisté.'],
            ]);
        }

        $agency = $this->resolveAgencyForAssistedPurchaseConversion($request, $client);

        $client->loadMissing('profile');
        $clientProfile = $client->profile;

        if (! $clientProfile) {
            $clientProfile = Profile::create([
                'first_name' => $client->name ?? 'Client',
                'last_name' => '',
                'email' => $client->email,
                'is_client' => true,
                'is_active' => true,
            ]);
            $client->update(['profile_id' => $clientProfile->id]);
        }

        $hasOverrides = $request->filled('recipient_country_id')
            || $request->filled('recipient_city_id')
            || $request->filled('recipient_address')
            || $request->filled('recipient_phone');

        if ($hasOverrides) {
            $updates = [];
            if ($request->filled('recipient_country_id')) {
                $updates['country_id'] = $request->input('recipient_country_id');
            }
            if ($request->filled('recipient_city_id')) {
                $updates['city_id'] = $request->input('recipient_city_id');
            }
            if ($request->filled('recipient_address')) {
                $updates['address'] = $request->input('recipient_address');
            }
            if ($request->filled('recipient_phone')) {
                $updates['phone'] = $request->input('recipient_phone');
            }
            $clientProfile->update($updates);
            $clientProfile->refresh();
        }

        $freshClient = $client->fresh(['profile']);
        $clientProfileCheck = $this->validateClientProfileComplete($freshClient);

        if ($request->boolean('check_only')) {
            if ($clientProfileCheck['valid']) {
                return response()->json(['profile_complete' => true]);
            }

            return response()->json([
                'profile_complete' => false,
                'missing_fields' => $clientProfileCheck['missing_fields'] ?? [],
                'message' => $clientProfileCheck['message'],
                'client_profile' => [
                    'phone' => $clientProfile->phone ?? $client->phone ?? '',
                    'country_id' => $clientProfile->country_id,
                    'city_id' => $clientProfile->city_id,
                    'address' => $clientProfile->address ?? '',
                ],
            ]);
        }

        if (! $clientProfileCheck['valid']) {
            if ($request->boolean('notify_client')) {
                $this->notifyClientToCompleteProfile($client, $assisted_purchase);
            }

            return response()->json([
                'profile_complete' => false,
                'missing_fields' => $clientProfileCheck['missing_fields'] ?? [],
                'message' => $clientProfileCheck['message'],
                'client_profile' => [
                    'phone' => $clientProfile->phone ?? $client->phone ?? '',
                    'country_id' => $clientProfile->country_id,
                    'city_id' => $clientProfile->city_id,
                    'address' => $clientProfile->address ?? '',
                ],
                'notification_sent' => $request->boolean('notify_client'),
            ], 200);
        }

        $clientProfile = $freshClient->profile;
        $shipment = DB::transaction(function () use ($request, $assisted_purchase, $agency, $clientProfile) {
            // Agency is the sender (Monrespro ships the goods)
            $agencyProfile = $this->ensureAgencyProfile($agency);

            // Client is the recipient
            $senderProfileId = $agencyProfile->id;
            $recipientProfileId = $clientProfile->id;

            // Origin = agency country, Destination = client country
            $originCountryId = $agencyProfile->country_id;
            $destCountryId = $clientProfile->country_id;

            $shipment = Shipment::query()->create([
                'sender_profile_id' => $senderProfileId,
                'recipient_profile_id' => $recipientProfileId,
                'creator_user_id' => $request->user()->id,
                'agency_id' => $agency->id,
                'origin_country_id' => $originCountryId,
                'dest_country_id' => $destCountryId,
                'status' => ShipmentStatus::ReceivedAtHub,
                'assisted_purchase_id' => $assisted_purchase->id,
                'declared_value' => $assisted_purchase->total_amount,
                'declared_currency' => $assisted_purchase->quote_currency,
                'currency' => $assisted_purchase->quote_currency ?? 'EUR',
            ]);

            // Create shipment log for initial status
            $shipment->logs()->create([
                'user_id' => $request->user()->id,
                'status' => ShipmentStatus::ReceivedAtHub,
                'title' => 'Expédition créée depuis achat assisté',
                'description' => 'Conversion automatique de l\'achat assisté #'.$assisted_purchase->id.'. Expéditeur: '.$agency->name.', Destinataire: '.$clientProfile->full_name,
                'ip_address' => $request->ip(),
            ]);

            foreach ($assisted_purchase->items as $item) {
                $description = $item->display_label ?? $item->name ?? 'Article';
                if ($item->options) {
                    $description .= ' — '.$item->options;
                }

                ShipmentItem::query()->create([
                    'shipment_id' => $shipment->id,
                    'description' => $description,
                    'quantity' => $item->quantity ?? 1,
                    'value' => $item->unit_price ? (float) $item->unit_price * (int) ($item->quantity ?? 1) : 0,
                    'weight_kg' => 0,
                    'length_cm' => 0,
                    'width_cm' => 0,
                    'height_cm' => 0,
                    'origin_country_id' => $originCountryId,
                ]);
            }

            $assisted_purchase->update([
                'status' => AssistedPurchaseStatus::CONVERTED_TO_SHIPMENT,
                'converted_shipment_id' => $shipment->id,
            ]);

            return $shipment;
        });

        $base = FrontendPortalUrl::base();

        if ($client) {
            NotificationController::notify(
                $client->id,
                'shipment',
                'Expédition créée depuis votre achat assisté',
                'Votre achat assisté #'.$assisted_purchase->id.' a été converti en dossier d\'expédition #'.$shipment->id.'. Le fret sera calculé après pesée.',
                ['shipment_id' => $shipment->id, 'assisted_purchase_id' => $assisted_purchase->id],
                $base.'/shipments/'.$shipment->id,
                ['in_app']
            );
        }

        // §8.3 PRD — Création auto pickup si livraison à domicile demandée
        $pickupId = null;
        if ($request->boolean('create_home_delivery') && $client) {
            $pickup = Pickup::create([
                'shipment_id' => $shipment->id,
                'agency_id' => $shipment->agency_id,
                'type' => 'delivery',
                'status' => PickupStatus::Draft,
                'contact_name' => $client->name ?? $client->profile?->full_name ?? '',
                'contact_phone' => $client->phone ?? $client->profile?->phone ?? '',
                'address' => $request->input('delivery_address', $client->profile?->address ?? ''),
                'time_slot' => $request->input('delivery_time_slot'),
                'special_instructions' => $request->input('delivery_instructions'),
            ]);
            $pickupId = $pickup->id;
        }

        return response()->json([
            'message' => 'Expédition #'.$shipment->id.' créée avec succès.'
                .($pickupId ? ' Livraison à domicile programmée (#'.$pickupId.').' : ''),
            'shipment_id' => $shipment->id,
            'pickup_id' => $pickupId,
        ]);
    }

    /**
     * Validate that client profile is complete for shipment.
     * Returns ['valid' => bool, 'message' => string, 'missing_fields' => string[]]
     */
    protected function validateClientProfileComplete(User $client): array
    {
        $profile = $client->profile;

        if (! $profile) {
            return [
                'valid' => false,
                'message' => 'Le client n\'a pas de profil.',
                'missing_fields' => ['phone', 'country_id', 'address'],
            ];
        }

        $missing = [];
        $missingFields = [];

        if (empty($profile->phone) && empty($client->phone)) {
            $missing[] = 'numéro de téléphone';
            $missingFields[] = 'phone';
        }

        if (empty($profile->country_id)) {
            $missing[] = 'pays de destination';
            $missingFields[] = 'country_id';
        }

        if (empty($profile->city_id) && empty($profile->address)) {
            $missing[] = 'ville ou adresse complète';
            $missingFields[] = 'address';
        }

        if (count($missing) > 0) {
            return [
                'valid' => false,
                'message' => 'Informations manquantes : '.implode(', ', $missing).'.',
                'missing_fields' => $missingFields,
            ];
        }

        return ['valid' => true, 'message' => '', 'missing_fields' => []];
    }

    /**
     * Send notification to client to complete their profile.
     */
    protected function notifyClientToCompleteProfile(User $client, AssistedPurchase $purchase): void
    {
        $base = FrontendPortalUrl::base();
        $profileUrl = $base.'/profile';

        NotificationController::notify(
            $client->id,
            'assisted_purchase',
            'Complétez votre profil pour la livraison',
            'Votre achat assisté #'.$purchase->id.' est prêt à être expédié. Veuillez compléter votre adresse de livraison (pays, ville, téléphone) dans votre profil.',
            ['assisted_purchase_id' => $purchase->id],
            $profileUrl,
            ['in_app', 'email']
        );
    }

    /**
     * Chronologie métier du dossier (événements datés) pour l’interface staff.
     *
     * @return list<array{at: string, event: string, label: string, meta?: string|null}>
     */
    protected function buildDossierTimeline(AssistedPurchase $ap): array
    {
        $rows = [];

        if ($ap->created_at) {
            $rows[] = [
                'at' => $ap->created_at->toIso8601String(),
                'event' => 'created',
                'label' => 'Demande créée',
            ];
        }

        if ($ap->quoted_at) {
            $qv = $ap->quote_version;
            $meta = is_numeric($qv) && (int) $qv > 0 ? 'Version '.(int) $qv : null;
            $rows[] = [
                'at' => $ap->quoted_at->toIso8601String(),
                'event' => 'quoted',
                'label' => 'Devis publié / envoyé',
                'meta' => $meta,
            ];
        }

        foreach ($ap->payments ?? [] as $pay) {
            if (! $pay->created_at) {
                continue;
            }
            $rows[] = [
                'at' => $pay->created_at->toIso8601String(),
                'event' => 'payment',
                'label' => 'Encaissement enregistré',
                'meta' => trim((string) ($pay->amount ?? '')).' '.trim((string) ($pay->currency ?? '')),
            ];
        }

        if ($ap->paid_at) {
            $rows[] = [
                'at' => $ap->paid_at->toIso8601String(),
                'event' => 'paid',
                'label' => 'Statut « payé » atteint',
            ];
        }

        if ($ap->purchased_at) {
            $rows[] = [
                'at' => $ap->purchased_at->toIso8601String(),
                'event' => 'ordered',
                'label' => 'Commande fournisseur enregistrée',
            ];
        }

        if ($ap->hub_received_weight_kg !== null && (float) $ap->hub_received_weight_kg > 0) {
            $rows[] = [
                'at' => ($ap->updated_at ?? $ap->created_at ?? now())->toIso8601String(),
                'event' => 'hub',
                'label' => 'Colis réceptionné à l\'entrepôt',
                'meta' => (string) $ap->hub_received_weight_kg.' kg',
            ];
        }

        if ($ap->converted_shipment_id && $ap->relationLoaded('convertedShipment') && $ap->convertedShipment?->created_at) {
            $rows[] = [
                'at' => $ap->convertedShipment->created_at->toIso8601String(),
                'event' => 'converted',
                'label' => 'Converti en expédition logistique',
                'meta' => '#'.$ap->converted_shipment_id,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

        return array_reverse($rows);
    }

    /**
     * Agence expéditrice (profil expéditeur Monrespro) pour la conversion en expédition.
     * Ordre : `agency_id` (corps, si autorisé), client.user, client.profile, opérateur, puis repli contrôlé.
     */
    protected function resolveAgencyForAssistedPurchaseConversion(Request $request, User $client): Agency
    {
        $staff = $request->user();
        $client->loadMissing('profile');
        $staff->loadMissing('profile');

        $candidateIds = [];
        if ($request->filled('agency_id')) {
            $candidateIds[] = (int) $request->input('agency_id');
        }
        if ($client->agency_id) {
            $candidateIds[] = (int) $client->agency_id;
        }
        if ($client->profile?->agency_id) {
            $candidateIds[] = (int) $client->profile->agency_id;
        }
        if ($staff->agency_id) {
            $candidateIds[] = (int) $staff->agency_id;
        }
        if ($staff->profile?->agency_id) {
            $candidateIds[] = (int) $staff->profile->agency_id;
        }

        $candidateIds = array_values(array_unique(array_filter($candidateIds)));

        foreach ($candidateIds as $agencyId) {
            $agency = Agency::with(['country', 'city', 'state'])->where('is_active', true)->find($agencyId);
            if ($agency && $this->staffMayUseAgencyForAssistedPurchaseConversion($staff, $agency, $client)) {
                return $agency;
            }
        }

        if ($staff->canAccessAllAgencies()) {
            $fallback = Agency::with(['country', 'city', 'state'])->where('is_active', true)->orderBy('id')->first();
            if ($fallback) {
                return $fallback;
            }
        }

        $staffAgencyId = (int) ($staff->agency_id ?? $staff->profile?->agency_id ?? 0);
        if ($staffAgencyId > 0) {
            $agency = Agency::with(['country', 'city', 'state'])->where('is_active', true)->find($staffAgencyId);
            if ($agency) {
                return $agency;
            }
        }

        throw ValidationException::withMessages([
            'agency' => ['Aucune agence trouvée pour cette conversion. Indiquez `agency_id` (agence active) ou rattachez le client / votre compte à une agence.'],
        ]);
    }

    protected function staffMayUseAgencyForAssistedPurchaseConversion(User $staff, Agency $agency, User $client): bool
    {
        if ($staff->canAccessAllAgencies()) {
            return true;
        }

        $staffAgencyId = (int) ($staff->agency_id ?? $staff->profile?->agency_id ?? 0);
        if ($staffAgencyId > 0 && (int) $agency->id === $staffAgencyId) {
            return true;
        }

        $clientAgencyId = (int) ($client->agency_id ?? $client->profile?->agency_id ?? 0);

        return $clientAgencyId > 0 && (int) $agency->id === $clientAgencyId;
    }

    /**
     * Ensure agency has a profile for use as sender.
     * Creates one from agency data if missing.
     */
    protected function ensureAgencyProfile(Agency $agency): Profile
    {
        // Search for existing agency profile by email
        $existingProfile = Profile::query()
            ->where('email', $agency->contact_email)
            ->where('is_staff', true)
            ->first();

        if ($existingProfile) {
            return $existingProfile;
        }

        // Create a new profile for the agency
        $profile = Profile::query()->create([
            'first_name' => $agency->name,
            'last_name' => '',
            'email' => $agency->contact_email ?? 'contact@'.Str::slug($agency->code).'.local',
            'phone' => $agency->contact_phone,
            'phone_secondary' => $agency->contact_phone_secondary,
            'address' => $agency->address,
            'city_id' => $agency->city_id,
            'state_id' => $agency->state_id,
            'country_id' => $agency->country_id,
            'agency_id' => $agency->id,
            'type' => 'agency',
            'is_active' => true,
            'is_client' => false,
            'is_staff' => true,
        ]);

        return $profile;
    }

    /**
     * Extract first name from full name.
     */
    protected function extractFirstName(?string $fullName): string
    {
        if (! $fullName) {
            return 'Client';
        }
        $parts = explode(' ', trim($fullName), 2);

        return $parts[0] ?? 'Client';
    }

    /**
     * Extract last name from full name.
     */
    protected function extractLastName(?string $fullName): ?string
    {
        if (! $fullName) {
            return null;
        }
        $parts = explode(' ', trim($fullName), 2);

        return $parts[1] ?? null;
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

    public function updateStatus(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(array_map(fn (AssistedPurchaseStatus $s) => $s->value, AssistedPurchaseStatus::cases()))],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $current = AssistedPurchaseStatus::tryFromString($assisted_purchase->status);
        $target = AssistedPurchaseStatus::tryFromString($data['status']);

        if (! $current || ! $target) {
            throw ValidationException::withMessages([
                'status' => ['Statut invalide.'],
            ]);
        }

        if (! $current->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => ["Transition non autorisee de {$current->value} vers {$target->value}."],
            ]);
        }

        $hubPhotoPath = null;
        $hubWeight = null;

        if ($current === AssistedPurchaseStatus::ORDERED && $target === AssistedPurchaseStatus::ARRIVED_AT_HUB) {
            $hub = $request->validate([
                'actual_weight_kg' => ['required', 'numeric', 'min:0.001', 'max:99999'],
                'hub_photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            ]);
            $hubWeight = (float) $hub['actual_weight_kg'];
            $hubPhotoPath = $request->file('hub_photo')->store('assisted-purchase-hub', 'public');
        }

        $old = $current;
        $assisted_purchase->status = $target;

        if ($target === AssistedPurchaseStatus::AWAITING_PAYMENT) {
            $assisted_purchase->quoted_at = now();
        } elseif ($target === AssistedPurchaseStatus::PAID) {
            $assisted_purchase->paid_at = now();
        } elseif ($target === AssistedPurchaseStatus::ORDERED) {
            $assisted_purchase->purchased_at = now();
        } elseif ($target === AssistedPurchaseStatus::ARRIVED_AT_HUB && $hubWeight !== null) {
            $assisted_purchase->hub_received_weight_kg = $hubWeight;
            $assisted_purchase->hub_received_photo_path = $hubPhotoPath;
        }

        $assisted_purchase->save();

        if ($target === AssistedPurchaseStatus::ARRIVED_AT_HUB && $hubWeight !== null) {
            $this->notifyAssistedPurchaseWeightDiscrepancyIfNeeded($assisted_purchase->fresh(['user']), $hubWeight, $request->user());
        }

        AssistedPurchaseStatusChanged::dispatch(
            $assisted_purchase->fresh(['user']),
            $old,
            $target,
            $request->user()
        );

        return response()->json([
            'message' => 'Statut mis a jour.',
            'purchase' => $assisted_purchase->fresh(['items', 'user', 'operator']),
        ]);
    }

    /**
     * §5 PRD — Alerte si écart > 15 % entre poids estimé (devis) et poids réel au hub.
     */
    protected function notifyAssistedPurchaseWeightDiscrepancyIfNeeded(AssistedPurchase $purchase, float $actualWeightKg, User $staffUser): void
    {
        $declared = (float) ($purchase->estimated_weight_kg ?? 0);
        if ($declared <= 0 || $actualWeightKg <= 0) {
            return;
        }

        $discrepancyPct = abs($actualWeightKg - $declared) / $declared * 100;
        if ($discrepancyPct < 15) {
            return;
        }

        $purchase->loadMissing('user');
        $label = $purchase->article_label ?? ('#'.$purchase->id);

        if ($purchase->user) {
            try {
                NotificationDispatcher::dispatch(
                    user: $purchase->user,
                    eventKey: 'weight_discrepancy',
                    variables: [
                        'reference' => $label,
                        'declared_kg' => $declared,
                        'actual_kg' => $actualWeightKg,
                        'discrepancy_pct' => round($discrepancyPct, 1),
                    ],
                    actionUrl: '/purchase-orders/'.$purchase->id,
                );
            } catch (\Throwable) {
            }
        }

        $agencyId = $staffUser->agency_id;
        if ($agencyId) {
            $managers = User::query()
                ->where('agency_id', $agencyId)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['agency_admin', 'super_admin', 'operator']))
                ->get();

            foreach ($managers as $manager) {
                Notification::query()->create([
                    'user_id' => $manager->id,
                    'type' => 'weight_discrepancy',
                    'channel' => 'system',
                    'title' => "Écart poids > 15 % — achat assisté #{$purchase->id}",
                    'body' => sprintf(
                        'Poids estimé : %.2f kg / Poids réel hub : %.2f kg (écart : %.1f%%)',
                        $declared,
                        $actualWeightKg,
                        $discrepancyPct
                    ),
                    'data' => [
                        'assisted_purchase_id' => $purchase->id,
                        'declared_kg' => $declared,
                        'actual_kg' => $actualWeightKg,
                        'discrepancy_pct' => round($discrepancyPct, 1),
                    ],
                ]);
            }
        }
    }

    public function extractProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2000'],
        ]);

        $cacheKey = 'product_extract_'.md5($data['url'].'_'.$request->user()->id.'_'.now()->timestamp);

        // Exécution synchrone : l’extraction ne doit pas dépendre d’un worker (`queue:work`).
        // Sinon, avec QUEUE_CONNECTION=database/redis sans worker, le cache reste vide
        // et l’UI affiche « Extraction indisponible » après le polling.
        ScrapeProductJob::dispatchSync($data['url'], $cacheKey);

        return response()->json([
            'cache_key' => $cacheKey,
            'message' => 'Extraction en cours...',
        ]);
    }

    public function extractProductResult(string $cacheKey): JsonResponse
    {
        $result = Cache::get($cacheKey);

        if ($result === null) {
            return response()->json(['status' => 'pending']);
        }

        return response()->json([
            'status' => 'done',
            'product' => $result,
        ]);
    }

    /**
     * Send quote using the dynamic line engine (PRD v2).
     * Accepts structured lines instead of flat service_fee + bank_fee.
     */
    public function quoteDynamic(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);
        $this->assertStaffCanSubmitQuote($assisted_purchase);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:assisted_purchase_items,id'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['sometimes', 'integer', 'min:1'],
            'items.*.availability_status' => ['required', 'string', 'in:exact,available_alternative,unavailable,not_checked'],
            'items.*.alternative_note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array'],
            'lines.*.internal_code' => ['required', 'string', 'max:50'],
            'lines.*.name' => ['required', 'string', 'max:255'],
            'lines.*.type' => ['required', 'in:percentage,fixed_amount,manual'],
            'lines.*.calculation_base' => ['nullable', 'string'],
            'lines.*.value' => ['required', 'numeric', 'min:0'],
            'lines.*.is_visible_to_client' => ['boolean'],
            'is_urgent' => ['boolean'],
            'urgency_surcharge_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'estimated_delivery' => ['nullable', 'string', 'max:100'],
            'staff_message' => ['nullable', 'string', 'max:5000'],
            'revision_reason' => ['nullable', 'string', 'max:500'],
            'estimated_weight_kg' => ['nullable', 'numeric', 'min:0.001', 'max:99999'],
            'payment_methods_note' => ['nullable', 'string', 'max:10000'],
        ]);

        $alternativeWithoutNote = collect($data['items'])
            ->filter(fn ($item) => $item['availability_status'] === 'available_alternative' && empty($item['alternative_note']));
        if ($alternativeWithoutNote->isNotEmpty()) {
            return response()->json([
                'message' => 'Une note explicative est requise pour les articles en alternative.',
            ], 422);
        }

        $calcService = app(QuoteCalculationService::class);
        $followUpService = app(QuoteFollowUpService::class);

        $articles = [];
        $assisted_purchase->loadMissing('items');
        $itemsById = $assisted_purchase->items->keyBy('id');

        foreach ($data['items'] as $row) {
            $item = $itemsById->get((int) $row['id']);
            if ($item) {
                $updateData = ['unit_price' => (float) $row['unit_price']];
                if (isset($row['quantity'])) {
                    $updateData['quantity'] = (int) $row['quantity'];
                }
                $item->update($updateData);
                $articles[] = [
                    'id' => $item->id,
                    'name' => $item->name ?? $item->display_label,
                    'url' => $item->url,
                    'unit_price' => (float) $row['unit_price'],
                    'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : (int) $item->quantity,
                    'availability_status' => $row['availability_status'],
                    'alternative_note' => $row['alternative_note'] ?? null,
                ];
            }
        }

        $calculationResult = $calcService->calculate($articles, $data['lines']);

        $isUrgent = $data['is_urgent'] ?? false;
        $urgencySurcharge = $data['urgency_surcharge_percent'] ?? null;

        $snapshotData = $calcService->buildSnapshot(
            $articles,
            $data['lines'],
            $calculationResult,
            $data['estimated_delivery'] ?? null,
            $data['staff_message'] ?? null,
            $isUrgent,
            $urgencySurcharge,
        );

        try {
            $snapshot = $followUpService->sendQuote(
                $assisted_purchase,
                $snapshotData,
                $articles,
                $calculationResult,
                $request->user()->id,
                $isUrgent,
                $urgencySurcharge,
                $data['estimated_delivery'] ?? null,
                $data['staff_message'] ?? null,
                $data['revision_reason'] ?? null,
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $extraUpdates = ['operator_id' => $request->user()->id];
        $quoteCurrency = $calculationResult['primary_currency'] ?? $this->applicationQuoteCurrency();
        if ($quoteCurrency !== '') {
            $extraUpdates['quote_currency'] = $quoteCurrency;
        }
        if (isset($data['estimated_weight_kg'])) {
            $extraUpdates['estimated_weight_kg'] = (float) $data['estimated_weight_kg'];
        }
        $rawNote = $data['payment_methods_note'] ?? null;
        if (is_string($rawNote) && trim($rawNote) !== '') {
            $extraUpdates['payment_methods_note'] = trim($rawNote);
        }
        $assisted_purchase->update($extraUpdates);

        $assisted_purchase->refresh()->load(['items', 'user']);
        $mailStatus = $this->sendQuoteReadyNotificationToClient($assisted_purchase);

        $client = $assisted_purchase->user;
        if ($client) {
            $base = FrontendPortalUrl::base();
            $purchaseUrl = $base.'/purchase-orders/'.$assisted_purchase->id;
            $amount = number_format((float) $calculationResult['total_primary'], 2, ',', ' ')
                .' '.$calculationResult['primary_currency'];
            NotificationController::notify(
                $client->id,
                'assisted_purchase',
                'Devis envoyé — achat assisté',
                "Un devis de {$amount} a été établi pour votre demande.",
                ['assisted_purchase_id' => $assisted_purchase->id],
                $purchaseUrl,
                ['in_app']
            );
        }

        return response()->json([
            'message' => 'Devis enregistré et envoyé.',
            'snapshot_id' => $snapshot->id,
            'total' => $calculationResult['total_primary'],
            'mail_status' => $mailStatus,
        ]);
    }

    /**
     * Create a new revision of an existing quote.
     */
    public function createRevision(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $followUpService = app(QuoteFollowUpService::class);

        try {
            $newVersion = $followUpService->createRevision($assisted_purchase, $data['reason']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Version {$newVersion} créée.",
            'version' => $newVersion,
        ]);
    }

    /**
     * Send a clarification request to the client.
     */
    public function sendClarification(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        $data = $request->validate([
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', 'in:email,sms'],
        ]);

        $followUpService = app(QuoteFollowUpService::class);
        $followUpService->sendClarification($assisted_purchase, $data['message'], $data['channels']);

        return response()->json(['message' => 'Demande de clarification envoyée.']);
    }

    /**
     * Mark an item as out of stock after the order was placed.
     * Staff provides resolution options for the client.
     */
    public function reportItemUnavailable(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        if (! in_array($assisted_purchase->status, [
            AssistedPurchaseStatus::PAID,
            AssistedPurchaseStatus::ORDERED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['Cette action est uniquement disponible pour les dossiers au statut Payé ou Commandé.'],
            ]);
        }

        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:assisted_purchase_items,id'],
            'resolution' => ['required', 'string', 'in:wait_restock,propose_alternative,partial_refund,full_refund'],
            'restock_date' => ['nullable', 'date', 'after:today'],
            'alternative_description' => ['nullable', 'string', 'max:1000'],
            'staff_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $item = AssistedPurchaseItem::where('id', $data['item_id'])
            ->where('assisted_purchase_id', $assisted_purchase->id)
            ->firstOrFail();

        $resolution = $data['resolution'];
        $updateData = [
            'availability_status' => 'unavailable_after_order',
            'unavailability_resolution' => $resolution,
            'unavailability_reported_at' => now(),
            'unavailability_reported_by' => $request->user()->id,
        ];

        if ($resolution === 'wait_restock' && isset($data['restock_date'])) {
            $updateData['restock_estimated_date'] = $data['restock_date'];
        }
        if ($resolution === 'propose_alternative' && isset($data['alternative_description'])) {
            $updateData['alternative_description'] = $data['alternative_description'];
        }

        $options = $item->options ?? [];
        $options['unavailability'] = array_filter([
            'resolution' => $resolution,
            'restock_date' => $data['restock_date'] ?? null,
            'alternative' => $data['alternative_description'] ?? null,
            'staff_note' => $data['staff_note'] ?? null,
            'reported_at' => now()->toIso8601String(),
            'reported_by' => $request->user()->id,
        ]);
        $item->update(['options' => $options]);

        $client = $assisted_purchase->user;
        if ($client) {
            $itemLabel = $item->name ?? $item->display_label ?? "Article #{$item->id}";
            $resolutionLabels = [
                'wait_restock' => 'Attente de réapprovisionnement',
                'propose_alternative' => 'Alternative proposée',
                'partial_refund' => 'Remboursement partiel',
                'full_refund' => 'Remboursement intégral',
            ];

            $base = FrontendPortalUrl::base();
            $purchaseUrl = $base.'/purchase-orders/'.$assisted_purchase->id;

            NotificationController::notify(
                $client->id,
                'assisted_purchase',
                "Article indisponible : {$itemLabel}",
                "L'article \"{$itemLabel}\" de votre commande n'est plus disponible. "
                    .'Résolution : '.($resolutionLabels[$resolution] ?? $resolution).'. '
                    .'Consultez votre espace pour plus de détails.',
                [
                    'assisted_purchase_id' => $assisted_purchase->id,
                    'item_id' => $item->id,
                    'resolution' => $resolution,
                ],
                $purchaseUrl,
                ['in_app']
            );

            if ($client->email) {
                $this->sendItemUnavailableEmail($assisted_purchase, $item, $client, $data);
            }
        }

        if ($resolution === 'full_refund') {
            $assisted_purchase->update([
                'status' => AssistedPurchaseStatus::CANCELLED,
                'cancellation_reason' => 'full_refund_item_unavailable',
            ]);
        }

        $assisted_purchase->refresh()->load(['items.merchant', 'user.profile']);

        return response()->json([
            'message' => 'Indisponibilité signalée. Le client a été notifié.',
            'purchase' => $assisted_purchase,
        ]);
    }

    /**
     * Handle a supplier price change after the quote was accepted.
     * Creates a new quote revision requiring client re-acceptance.
     */
    public function reportPriceChange(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        if (! in_array($assisted_purchase->status, [
            AssistedPurchaseStatus::AWAITING_PAYMENT,
            AssistedPurchaseStatus::PAID,
            AssistedPurchaseStatus::ORDERED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['La révision de prix est uniquement disponible pour les dossiers actifs après envoi de devis.'],
            ]);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:assisted_purchase_items,id'],
            'items.*.new_price' => ['required', 'numeric', 'min:0'],
        ]);

        $assisted_purchase->loadMissing('items');
        $itemsById = $assisted_purchase->items->keyBy('id');

        foreach ($data['items'] as $row) {
            $item = $itemsById->get((int) $row['id']);
            if ($item && $item->assisted_purchase_id === $assisted_purchase->id) {
                $item->update(['unit_price' => (float) $row['new_price']]);
            }
        }

        $assisted_purchase->update([
            'status' => AssistedPurchaseStatus::QUOTED,
            'quote_expires_at' => null,
            'reminder_count' => 0,
        ]);

        $followUpService = app(QuoteFollowUpService::class);
        try {
            $newVersion = $followUpService->createRevision($assisted_purchase, $data['reason']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $client = $assisted_purchase->user;
        if ($client) {
            $base = FrontendPortalUrl::base();
            $purchaseUrl = $base.'/purchase-orders/'.$assisted_purchase->id;

            NotificationController::notify(
                $client->id,
                'assisted_purchase',
                'Devis révisé — changement de prix fournisseur',
                "Le prix d'un ou plusieurs articles a changé chez le fournisseur. "
                    ."Un nouveau devis (v{$newVersion}) vous a été envoyé. "
                    .'Votre acceptation est requise.',
                [
                    'assisted_purchase_id' => $assisted_purchase->id,
                    'version' => $newVersion,
                    'reason' => 'price_change',
                ],
                $purchaseUrl,
                ['in_app']
            );
        }

        return response()->json([
            'message' => "Révision v{$newVersion} créée suite au changement de prix. Le client doit re-accepter.",
            'version' => $newVersion,
        ]);
    }

    private function sendItemUnavailableEmail(
        AssistedPurchase $purchase,
        AssistedPurchaseItem $item,
        User $client,
        array $data
    ): void {
        $itemLabel = $item->name ?? $item->display_label ?? "Article #{$item->id}";
        $resolution = $data['resolution'];
        $ref = 'MRP-AA-'.str_pad((string) $purchase->id, 4, '0', STR_PAD_LEFT);

        $resolutionMessages = [
            'wait_restock' => 'Nous attendons le réapprovisionnement.'
                .(isset($data['restock_date']) ? " Date estimée : {$data['restock_date']}." : ''),
            'propose_alternative' => 'Nous vous proposons une alternative : '
                .($data['alternative_description'] ?? 'voir détails dans votre espace.'),
            'partial_refund' => 'L\'article sera retiré de votre commande et le montant correspondant vous sera remboursé.',
            'full_refund' => 'Votre commande est annulée et un remboursement intégral sera effectué.',
        ];

        $body = "Bonjour {$client->name},\n\n"
            ."Concernant votre commande {$ref}, l'article \"{$itemLabel}\" n'est malheureusement plus disponible.\n\n"
            .($resolutionMessages[$resolution] ?? '')
            ."\n\n"
            .($data['staff_note'] ?? '')
            ."\n\nConnectez-vous sur votre espace Monrespro pour plus de détails.\n\n"
            ."L'équipe Monrespro";

        $subject = "Monrespro — Article indisponible — {$ref}";

        try {
            Mail::raw($body, function ($m) use ($client, $subject) {
                $m->to($client->email)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning("Email item unavailable failed for AP#{$purchase->id}: {$e->getMessage()}");
        }
    }
}

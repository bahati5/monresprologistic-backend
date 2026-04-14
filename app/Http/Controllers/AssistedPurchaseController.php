<?php

namespace App\Http\Controllers;

use App\Enums\AssistedPurchaseStatus;
use App\Enums\ShipmentStatus;
use App\Mail\AssistedPurchaseQuoteMail;
use App\Models\AssistedPurchase;
use App\Models\AssistedPurchaseItem;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Notifications\AssistedPurchaseOrderedNotification;
use App\Notifications\QuoteReadyNotification;
use App\Support\AssistedPurchaseUrlLabel;
use App\Support\FrontendPortalUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        ]);

        return response()->json([
            'purchase' => $assisted_purchase,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $auth = $request->user();
        $isStaff = $auth->hasAnyRole(['super_admin', 'agency_admin', 'operator']);

        $rules = [
            'notes' => ['nullable', 'string', 'max:10000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.url' => ['required', 'url', 'max:2000'],
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

        $purchase = DB::transaction(function () use ($request, $data, $first, $firstName, $clientId) {
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
        $base = FrontendPortalUrl::base();
        $purchaseUrl = $base.'/purchase-orders/'.$assisted_purchase->id;

        $mailStatus = $this->sendQuoteReadyNotificationToClient($assisted_purchase);

        if ($client) {
            $amount = number_format((float) ($assisted_purchase->total_amount ?? $assisted_purchase->quote_amount), 2, ',', ' ')
                .' '.$assisted_purchase->quote_currency;
            NotificationController::notify(
                $client->id,
                'assisted_purchase',
                'Devis disponible — achat assisté',
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
     * Après réception du paiement (hors passerelle en ligne) : passage au statut « Paiement validé ».
     */
    public function markPaid(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        if ($assisted_purchase->status !== AssistedPurchaseStatus::AWAITING_PAYMENT) {
            throw ValidationException::withMessages([
                'status' => ['Le paiement ne peut être validé que pour une demande au statut « Devis disponible ».'],
            ]);
        }

        $assisted_purchase->update([
            'status' => AssistedPurchaseStatus::PAID,
            'paid_at' => now(),
        ]);

        $assisted_purchase->refresh()->load(['user']);
        $client = $assisted_purchase->user;
        $base = FrontendPortalUrl::base();
        $purchaseUrl = $base.'/purchase-orders/'.$assisted_purchase->id;

        if ($client) {
            NotificationController::notify(
                $client->id,
                'assisted_purchase',
                'Paiement validé',
                'Votre paiement pour la demande d’achat assisté n° '.$assisted_purchase->id.' a été validé par notre équipe.',
                ['assisted_purchase_id' => $assisted_purchase->id],
                $purchaseUrl,
                ['in_app']
            );
        }

        return response()->json([
            'message' => 'Paiement enregistré comme reçu.',
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

        if (in_array($driver, $nonInboxDrivers, true)) {
            return [
                'level' => 'warning',
                'message' => 'Le serveur est en mode « journal » pour les e-mails (MAIL_MAILER=log ou équivalent) : rien n’est envoyé sur Internet. Configurez le SMTP dans Paramètres de l’application (hôte SMTP + expéditeur) ou définissez MAIL_MAILER=smtp et les variables MAIL_* dans le fichier .env, puis testez l’envoi depuis les paramètres.',
            ];
        }

        return ['level' => 'ok', 'message' => ''];
    }

    /**
     * Convertit un achat assisté (arrivé au hub ou commandé) en expédition logistique.
     */
    public function convertToShipment(Request $request, AssistedPurchase $assisted_purchase): JsonResponse
    {
        $this->authorizeView($request, $assisted_purchase);
        $this->authorizeStaff($request);

        if (! in_array($assisted_purchase->status, [AssistedPurchaseStatus::ARRIVED_AT_HUB, AssistedPurchaseStatus::ORDERED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Seuls les achats au statut « Colis reçu à l\'entrepôt » ou « Acheté chez le fournisseur » peuvent être convertis en expédition.'],
            ]);
        }

        if ($assisted_purchase->converted_shipment_id !== null) {
            throw ValidationException::withMessages([
                'converted' => ['Cet achat a déjà été converti en expédition (Shipment #' . $assisted_purchase->converted_shipment_id . ').'],
            ]);
        }

        $assisted_purchase->load(['items', 'user']);
        $client = $assisted_purchase->user;

        $shipment = DB::transaction(function () use ($request, $assisted_purchase, $client) {
            $description = 'Achat assisté #' . $assisted_purchase->id;
            if ($assisted_purchase->article_label) {
                $description .= ' — ' . $assisted_purchase->article_label;
            }

            $shipment = Shipment::query()->create([
                'sender_profile_id' => $client?->profile_id,
                'creator_user_id' => $request->user()->id,
                'agency_id' => $client?->agency_id,
                'status' => ShipmentStatus::ReceivedAtHub,
                'assisted_purchase_id' => $assisted_purchase->id,
                'declared_value' => $assisted_purchase->total_amount,
                'declared_currency' => $assisted_purchase->quote_currency,
            ]);

            foreach ($assisted_purchase->items as $item) {
                ShipmentItem::query()->create([
                    'shipment_id' => $shipment->id,
                    'description' => ($item->display_label ?? $item->name ?? 'Article') . ($item->options ? ' — ' . $item->options : ''),
                    'quantity' => $item->quantity ?? 1,
                    'value' => $item->unit_price ? (float) $item->unit_price * (int) ($item->quantity ?? 1) : null,
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
                'Votre achat assisté #' . $assisted_purchase->id . ' a été converti en dossier d\'expédition #' . $shipment->id . '. Le fret sera calculé après pesée.',
                ['shipment_id' => $shipment->id, 'assisted_purchase_id' => $assisted_purchase->id],
                $base . '/shipments/' . $shipment->id,
                ['in_app']
            );
        }

        return response()->json([
            'message' => 'Expédition #' . $shipment->id . ' créée avec succès.',
            'shipment_id' => $shipment->id,
        ]);
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

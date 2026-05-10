<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Events\ShipmentStatusChanged;
use App\Models\AddressBook;
use App\Models\Agency;
use App\Models\ArticleCategory;
use App\Models\Country;
use App\Models\PackagingType;
use App\Models\Profile;
use App\Models\Setting;
use App\Models\ShipLine;
use App\Models\ShipLineRate;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShipmentLog;
use App\Models\ShipmentPayment;
use App\Models\ShippingMode;
use App\Models\TransportCompany;
use App\Models\User;
use App\Services\PricingEngine;
use App\Services\NotificationDispatcher;
use App\Services\ShipmentWorkflowService;
use App\Support\ShipmentDocumentSettings;
use App\Support\ShipmentInvoiceNumberGenerator;
use App\Support\ShipmentRowPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShipmentController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $user = $request->user();
        $sort = (string) $request->input('sort', 'created_desc');
        $q = Shipment::query()->with([
            'senderProfile',
            'senderProfile.country',
            'senderProfile.city',
            'senderProfile.state',
            'recipientProfile',
            'recipientProfile.country',
            'recipientProfile.city',
            'recipientProfile.state',
            'agency',
            'originCountry',
            'destCountry',
            'assignedDriver',
            'regroupement',
        ]);
        if ($sort === 'created_asc') {
            $q->orderBy('created_at');
        } else {
            $q->orderByDesc('created_at');
        }
        $this->scopeShipmentsForUser($q, $user);

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $q->where(function ($w) use ($term) {
                $w->where('public_tracking', 'like', $term)
                    ->orWhereHas('senderProfile', fn ($p) => $p->whereRaw("CONCAT(first_name, ' ', last_name) like ?", [$term]))
                    ->orWhereHas('recipientProfile', fn ($p) => $p->whereRaw("CONCAT(first_name, ' ', last_name) like ?", [$term]));
            });
        }

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        if (! $user->hasRole('client')) {
            $tab = (string) $request->input('status_tab', 'all');
            if ($tab !== 'all') {
                $map = [
                    'preparation' => ['draft', 'pending_drop_off', 'received_at_hub'],
                    'transit' => ['ready_for_dispatch', 'in_transit'],
                    'customs' => ['in_transit'],
                    'delivered' => ['arrived_at_destination', 'delivered'],
                ];
                $codes = $map[$tab] ?? null;
                if ($codes !== null) {
                    $q->whereIn('status', $codes);
                }
            }
        }

        if ($request->has('export') && $request->get('export') === 'csv') {
            return $this->exportCsv($q);
        }

        $sheetShipment = null;
        if (! $user->hasRole('client') && $request->filled('sheet')) {
            $sheet = Shipment::query()
                ->with([
                    'senderProfile',
                    'senderProfile.country',
                    'senderProfile.city',
                    'senderProfile.state',
                    'recipientProfile',
                    'recipientProfile.country',
                    'recipientProfile.city',
                    'recipientProfile.state',
                    'items',
                    'logs.user',
                    'agency',
                    'originCountry',
                    'destCountry',
                    'assignedDriver',
                ])
                ->whereKey((int) $request->input('sheet'))
                ->first();
            if ($sheet && $user->can('view', $sheet)) {
                $workflow = app(ShipmentWorkflowService::class);
                $sheetShipment = [
                    'shipment' => $this->buildShipmentListRow($sheet),
                    'workflow_steps' => $workflow->buildStepsForShipment($sheet),
                    'available_transitions' => $workflow->getAvailableTransitions($sheet)->values()->all(),
                ];
            }
        }

        $drivers = ! $user->hasRole('client') ? $this->getDriversForUser($user) : collect();

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 100));
        $paginator = $q->paginate($perPage)->withQueryString();
        $items = collect($paginator->items());
        $paginator->setCollection(
            $items->map(fn (Shipment $s) => $this->buildShipmentListRow($s))
        );

        return response()->json([
            'shipments' => $paginator,
            'filters' => [
                'search' => $request->input('search'),
                'status_tab' => $request->input('status_tab', 'all'),
                'sort' => $sort,
            ],
            'sheetShipment' => $sheetShipment,
            'drivers' => $drivers,
            'isClientView' => $user->hasRole('client'),
        ]);
    }

    protected function exportCsv($query): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="expeditions-'.now()->format('Y-m-d').'.csv"',
        ];

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Suivi', 'Expéditeur', 'Destinataire', 'Statut', 'Agence', 'Poids (kg)', 'Prix', 'Date'], ';');
            $query->with(['senderProfile', 'recipientProfile', 'agency'])->chunk(200, function ($shipments) use ($handle) {
                foreach ($shipments as $s) {
                    $st = $s->status;
                    $statusName = $st instanceof ShipmentStatus ? $st->label() : '';
                    fputcsv($handle, [
                        $s->public_tracking ?? '#'.$s->id,
                        $s->senderProfile?->full_name ?? '',
                        $s->recipientProfile?->full_name ?? '',
                        $statusName,
                        $s->agency?->name ?? '',
                        $s->weight_kg ?? '',
                        $s->calculated_price ?? '',
                        $s->created_at?->format('Y-m-d H:i') ?? '',
                    ], ';');
                }
            });
            fclose($handle);
        }, 'expeditions.csv', $headers);
    }

    /**
     * Chauffeurs pour assignation (sans permission manage_drivers).
     */
    public function assignableDrivers(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('edit_shipments') || $user->can('assign_drivers'), 403);

        return response()->json([
            'drivers' => $this->getDriversForUser($user)->values()->all(),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->authorize('create', Shipment::class);

        $user = $request->user();
        $usersQuery = User::query()->orderBy('name')->limit(200);
        if (! $user->canAccessAllAgencies()) {
            $usersQuery->where('agency_id', $user->agency_id);
        }

        $agencies = $user->canAccessAllAgencies()
            ? Agency::where('is_active', true)->get(['id', 'name', 'code'])
            : Agency::where('id', $user->agency_id)->get(['id', 'name', 'code']);

        $drivers = $this->getDriversForUser($user);

        $trackingPrefix = Setting::getValue('tracking_prefix', 'MRP');
        $trackingNumberDigits = max(4, min(32, (int) (Setting::getValue('tracking_number_length', '8') ?: 8)));
        $volumetricDivisor = max(1.0, (float) (Setting::getValue('volumetric_divisor', '5000') ?: 5000));

        return response()->json([
            'users' => $usersQuery->get(['id', 'name', 'email']),
            'shippingModes' => ShippingMode::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'delivery_options', 'volumetric_divisor', 'default_pricing_type']),
            // Tous les emballages / transporteurs (comme l’écran Paramètres) : le filtre « actif » seulement en UI évite une liste vide si tout est désactivé par erreur.
            'packagingTypes' => PackagingType::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'is_billable', 'unit_price', 'is_active']),
            'transportCompanies' => TransportCompany::query()
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
            'shipLines' => ShipLine::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'countries' => Country::query()
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'code', 'iso2', 'emoji']),
            'articleCategories' => ArticleCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'agencies' => $agencies,
            'drivers' => $drivers,
            'trackingPrefix' => $trackingPrefix,
            'trackingNumberDigits' => $trackingNumberDigits,
            'volumetricDivisor' => $volumetricDivisor,
            'billableWeightRule' => $this->normalizedBillableWeightRule(),
            'defaultAgencyId' => $user->agency_id,
            'defaultInsurancePct' => (string) (Setting::getValue('default_insurance_pct', '0') ?? '0'),
            'defaultCustomsDutyPct' => (string) (Setting::getValue('default_customs_duty_pct', '0') ?? '0'),
            'defaultTaxPct' => (string) (Setting::getValue('default_tax_pct', '0') ?? '0'),
        ]);
    }

    public function previewQuote(Request $request): JsonResponse
    {
        $this->authorize('create', Shipment::class);

        $data = $request->validate([
            'sender_profile_id' => ['required', 'exists:profiles,id'],
            'recipient_profile_id' => ['required', 'exists:profiles,id'],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'shipping_mode_id' => ['nullable', 'exists:shipping_modes,id'],
            'delivery_time_label' => ['nullable', 'string', 'max:500'],
            'packaging_type_id' => ['nullable', 'exists:packaging_types,id'],
            'transport_company_id' => ['nullable', 'exists:transport_companies,id'],
            'ship_line_id' => ['nullable', 'exists:ship_lines,id'],
            'origin_country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'dest_country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'ship_line_rate_id' => ['nullable', 'integer', 'exists:ship_line_rates,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'items.*.length_cm' => ['nullable', 'numeric', 'min:0'],
            'items.*.width_cm' => ['nullable', 'numeric', 'min:0'],
            'items.*.height_cm' => ['nullable', 'numeric', 'min:0'],
            'items.*.value' => ['nullable', 'numeric', 'min:0'],
            'items.*.origin_country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:article_categories,id'],
            'items.*.delivery_time_label' => ['nullable', 'string', 'max:500'],
            'manual_fee' => ['nullable', 'numeric', 'min:0'],
            'service_options' => ['nullable', 'array'],
            'service_options.manual_fee_label' => ['nullable', 'string', 'max:255'],
            'service_options.delivery_time_label' => ['nullable', 'string', 'max:500'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'declared_currency' => ['nullable', 'string', 'max:8'],
            'insurance_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'customs_duty_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'manual_pricing' => ['nullable', 'boolean'],
            'manual_price_per_kg' => ['nullable', 'numeric', 'min:0'],
            'fixed_fees' => ['nullable', 'numeric', 'min:0'],
            'service_flow' => ['nullable', 'string', 'in:standard_a,standard_b,standard_c'],
        ]);

        $user = $request->user();
        $agencyId = $this->resolveWizardAgencyId($data, $user);
        $payload = array_merge($data, ['agency_id' => $agencyId]);
        $pricing = $this->computeWizardPricing($payload, $user, $agencyId);
        $snap = $pricing['pricing_snapshot'];
        $currencyCode = (string) (Setting::getValue('currency', 'EUR') ?: 'EUR');

        return response()->json([
            'base_quote' => $snap['base_quote'],
            'packaging_fee' => $snap['packaging_fee'],
            'manual_fee' => $snap['manual_fee'],
            'calculated_price' => $pricing['final_total'],
            'currency' => $currencyCode,
            'pricing_snapshot' => $snap,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Shipment::class);

        $data = $request->validate([
            'sender_profile_id' => ['required', 'exists:profiles,id'],
            'recipient_profile_id' => ['required', 'exists:profiles,id'],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'assigned_driver_id' => ['nullable', 'exists:users,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'items.*.length_cm' => ['nullable', 'numeric', 'min:0'],
            'items.*.width_cm' => ['nullable', 'numeric', 'min:0'],
            'items.*.height_cm' => ['nullable', 'numeric', 'min:0'],
            'items.*.value' => ['nullable', 'numeric', 'min:0'],
            'items.*.origin_country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:article_categories,id'],
            'items.*.delivery_time_label' => ['nullable', 'string', 'max:500'],
            'shipping_mode_id' => ['nullable', 'exists:shipping_modes,id'],
            'delivery_time_label' => ['nullable', 'string', 'max:500'],
            'packaging_type_id' => ['nullable', 'exists:packaging_types,id'],
            'transport_company_id' => ['nullable', 'exists:transport_companies,id'],
            'ship_line_id' => ['nullable', 'exists:ship_lines,id'],
            'origin_country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'dest_country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'ship_line_rate_id' => ['nullable', 'integer', 'exists:ship_line_rates,id'],
            'service_options' => ['nullable', 'array'],
            'service_options.manual_fee_label' => ['nullable', 'string', 'max:255'],
            'service_options.delivery_time_label' => ['nullable', 'string', 'max:500'],
            'manual_fee' => ['nullable', 'numeric', 'min:0'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'declared_currency' => ['nullable', 'string', 'max:8'],
            'insurance_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'customs_duty_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'manual_pricing' => ['nullable', 'boolean'],
            'manual_price_per_kg' => ['nullable', 'numeric', 'min:0'],
            'fixed_fees' => ['nullable', 'numeric', 'min:0'],
            'legal_declaration_accepted' => ['required', 'accepted'],
            'service_flow' => ['nullable', 'string', 'in:standard_a,standard_b,standard_c'],
        ]);

        $user = $request->user();

        if (empty($data['legal_declaration_accepted'])) {
            throw ValidationException::withMessages([
                'legal_declaration_accepted' => ['L\'acceptation des conditions est requise pour valider l\'expédition.'],
            ]);
        }

        // Comptoir : tout rôle staff (hors client). Les anciens rôles admin/employee n'existent pas dans RolesAndPermissionsSeeder.
        $isStaffCreation = ! $user->hasRole('client');
        $agencyId = $this->resolveWizardAgencyId($data, $user);

        $initialStatus = $isStaffCreation ? ShipmentStatus::ReceivedAtHub : ShipmentStatus::PendingDropOff;

        if (! isset($data['items']) || ! is_array($data['items'])) {
            $data['items'] = [];
        }

        $shipment = DB::transaction(function () use ($data, $user, $agencyId, $isStaffCreation, $initialStatus) {
            $pricing = $this->computeWizardPricing($data, $user, $agencyId);
            $serviceOptions = $pricing['service_options'];
            $pricingSnapshot = $pricing['pricing_snapshot'];
            $finalTotal = $pricing['final_total'];
            $realWeight = $pricing['real_weight'];
            $volWeight = $pricing['vol_weight'];
            $declaredValue = $pricing['declared_value_effective'];

            $senderProfileId = $data['sender_profile_id'] ?? null;
            $recipientProfileId = $data['recipient_profile_id'] ?? null;

            $senderProfile = $senderProfileId ? Profile::find($senderProfileId) : null;
            $recipientProfile = $recipientProfileId ? Profile::find($recipientProfileId) : null;

            if (! $senderProfile) {
                throw ValidationException::withMessages(['sender_profile_id' => 'Expéditeur requis.']);
            }
            if (! $recipientProfile) {
                throw ValidationException::withMessages(['recipient_profile_id' => 'Destinataire requis.']);
            }

            // Check address book — staff may create for any pair; auto-add to address book
            if ($senderProfile && $recipientProfile) {
                $senderUser = $senderProfile->user;
                $inBook = AddressBook::query()
                    ->where('owner_profile_id', $senderProfile->id)
                    ->where('contact_profile_id', $recipientProfile->id)
                    ->exists();

                if ($isStaffCreation) {
                    // Staff: auto-add recipient to sender's address book if missing
                    if (! $inBook) {
                        AddressBook::create([
                            'owner_profile_id' => $senderProfile->id,
                            'contact_profile_id' => $recipientProfile->id,
                        ]);
                    }
                } elseif ($senderUser) {
                    if (! $inBook) {
                        throw ValidationException::withMessages([
                            'recipient_profile_id' => 'Ce destinataire n\'appartient pas au carnet de l\'expéditeur.',
                        ]);
                    }
                } elseif ((int) ($recipientProfile->agency_id ?? 0) !== (int) ($senderProfile->agency_id ?? 0)) {
                    throw ValidationException::withMessages([
                        'recipient_profile_id' => 'Ce destinataire n\'appartient pas à l\'agence du client.',
                    ]);
                }
            }

            $originC = isset($data['origin_country_id']) && $data['origin_country_id'] !== '' && $data['origin_country_id'] !== null
                ? (int) $data['origin_country_id'] : null;
            $destC = isset($data['dest_country_id']) && $data['dest_country_id'] !== '' && $data['dest_country_id'] !== null
                ? (int) $data['dest_country_id'] : null;

            $shipment = Shipment::query()->create([
                'sender_profile_id' => $senderProfile?->id,
                'recipient_profile_id' => $recipientProfile?->id,
                'creator_user_id' => $user->id,
                'agency_id' => $agencyId,
                'origin_country_id' => $originC && $originC > 0 ? $originC : null,
                'dest_country_id' => $destC && $destC > 0 ? $destC : null,
                'status' => $initialStatus,
                'service_flow' => $data['service_flow'] ?? null,
                'assigned_driver_id' => $data['assigned_driver_id'] ?? null,
                'weight_kg' => $realWeight ?: null,
                'volumetric_weight_kg' => $volWeight ?: null,
                'declared_value' => $declaredValue ?: null,
                'declared_currency' => $data['declared_currency'] ?? 'USD',
                'service_options' => $serviceOptions,
                'pricing_snapshot' => $pricingSnapshot,
                'calculated_price' => $finalTotal,
                'currency' => 'USD',
            ]);

            foreach ($data['items'] as $item) {
                $originCountryId = isset($item['origin_country_id']) && $item['origin_country_id'] !== '' && $item['origin_country_id'] !== null
                    ? (int) $item['origin_country_id'] : null;
                if ($originCountryId === 0) {
                    $originCountryId = null;
                }
                $itemDt = isset($item['delivery_time_label']) ? trim((string) $item['delivery_time_label']) : '';
                $desc = trim((string) ($item['description'] ?? ''));
                if ($desc === '') {
                    continue;
                }
                $qty = (int) ($item['quantity'] ?? 0);
                if ($qty < 1) {
                    $qty = 1;
                }
                ShipmentItem::query()->create([
                    'shipment_id' => $shipment->id,
                    'description' => $desc,
                    'quantity' => $qty,
                    'weight_kg' => $item['weight_kg'] ?? null,
                    'length_cm' => $item['length_cm'] ?? null,
                    'width_cm' => $item['width_cm'] ?? null,
                    'height_cm' => $item['height_cm'] ?? null,
                    'value' => $item['value'] ?? null,
                    'origin_country_id' => $originCountryId,
                    'category_id' => $item['category_id'] ?? null,
                    'delivery_time_label' => $itemDt !== '' ? $itemDt : null,
                ]);
            }

            ShipmentInvoiceNumberGenerator::assignToShipment($shipment);

            $shipment->logs()->create([
                'user_id' => $user->id,
                'status' => $initialStatus,
                'title' => $isStaffCreation ? 'Expédition enregistrée au hub' : 'Expédition créée (brouillon)',
                'ip_address' => request()->ip(),
            ]);

            return $shipment;
        });

        return response()->json(['message' => 'Expédition créée.', 'id' => $shipment->id], 201);
    }

    public function update(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('update', $shipment);

        $isDraftRequest = (bool) ($request->input('is_draft') ?? false);

        $itemsRules = $isDraftRequest
            ? ['nullable', 'array']
            : ['required', 'array', 'min:1'];
        $itemDescriptionRules = $isDraftRequest
            ? ['nullable', 'string', 'max:255']
            : ['required', 'string', 'max:255'];
        $itemQuantityRules = $isDraftRequest
            ? ['nullable', 'integer', 'min:1']
            : ['required', 'integer', 'min:1'];

        $data = $request->validate([
            'sender_profile_id' => [$isDraftRequest ? 'nullable' : 'required', 'exists:profiles,id'],
            'recipient_profile_id' => [$isDraftRequest ? 'nullable' : 'required', 'exists:profiles,id'],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'assigned_driver_id' => ['nullable', 'exists:users,id'],
            'items' => $itemsRules,
            'items.*.description' => $itemDescriptionRules,
            'items.*.quantity' => $itemQuantityRules,
            'items.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'items.*.length_cm' => ['nullable', 'numeric', 'min:0'],
            'items.*.width_cm' => ['nullable', 'numeric', 'min:0'],
            'items.*.height_cm' => ['nullable', 'numeric', 'min:0'],
            'items.*.value' => ['nullable', 'numeric', 'min:0'],
            'items.*.origin_country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:article_categories,id'],
            'items.*.delivery_time_label' => ['nullable', 'string', 'max:500'],
            'shipping_mode_id' => ['nullable', 'exists:shipping_modes,id'],
            'delivery_time_label' => ['nullable', 'string', 'max:500'],
            'packaging_type_id' => ['nullable', 'exists:packaging_types,id'],
            'transport_company_id' => ['nullable', 'exists:transport_companies,id'],
            'ship_line_id' => ['nullable', 'exists:ship_lines,id'],
            'origin_country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'dest_country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'ship_line_rate_id' => ['nullable', 'integer', 'exists:ship_line_rates,id'],
            'service_options' => ['nullable', 'array'],
            'service_options.manual_fee_label' => ['nullable', 'string', 'max:255'],
            'service_options.delivery_time_label' => ['nullable', 'string', 'max:500'],
            'manual_fee' => ['nullable', 'numeric', 'min:0'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'declared_currency' => ['nullable', 'string', 'max:8'],
            'insurance_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'customs_duty_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'manual_pricing' => ['nullable', 'boolean'],
            'manual_price_per_kg' => ['nullable', 'numeric', 'min:0'],
            'fixed_fees' => ['nullable', 'numeric', 'min:0'],
            'legal_declaration_accepted' => ['sometimes', 'accepted'],
            'is_draft' => ['nullable', 'boolean'],
            'service_flow' => ['nullable', 'string', 'in:standard_a,standard_b,standard_c'],
        ]);

        $user = $request->user();
        $isDraft = (bool) ($data['is_draft'] ?? false);

        if (! $isDraft && empty($data['legal_declaration_accepted']) && $shipment->status === ShipmentStatus::Draft) {
            throw ValidationException::withMessages([
                'legal_declaration_accepted' => ['L\'acceptation des conditions est requise pour valider l\'expédition.'],
            ]);
        }

        $agencyId = $this->resolveWizardAgencyId($data, $user);

        if (array_key_exists('items', $data) && $data['items'] !== null && ! is_array($data['items'])) {
            $data['items'] = [];
        }
        if ($isDraft && array_key_exists('items', $data) && $data['items'] === null) {
            $data['items'] = [];
        }

        $shipment = DB::transaction(function () use ($data, $user, $agencyId, $shipment, $isDraft) {
            $pricing = $this->computeWizardPricing($data, $user, $agencyId);
            $serviceOptions = $pricing['service_options'];
            $pricingSnapshot = $pricing['pricing_snapshot'];
            $finalTotal = $pricing['final_total'];
            $realWeight = $pricing['real_weight'];
            $volWeight = $pricing['vol_weight'];
            $declaredValue = $pricing['declared_value_effective'];

            $originC = isset($data['origin_country_id']) && $data['origin_country_id'] !== '' && $data['origin_country_id'] !== null
                ? (int) $data['origin_country_id'] : null;
            $destC = isset($data['dest_country_id']) && $data['dest_country_id'] !== '' && $data['dest_country_id'] !== null
                ? (int) $data['dest_country_id'] : null;

            $updateData = [
                'sender_profile_id' => $data['sender_profile_id'] ?? $shipment->sender_profile_id,
                'recipient_profile_id' => $data['recipient_profile_id'] ?? $shipment->recipient_profile_id,
                'agency_id' => $agencyId,
                'origin_country_id' => $originC && $originC > 0 ? $originC : null,
                'dest_country_id' => $destC && $destC > 0 ? $destC : null,
                'service_flow' => $data['service_flow'] ?? $shipment->service_flow,
                'assigned_driver_id' => $data['assigned_driver_id'] ?? $shipment->assigned_driver_id,
                'weight_kg' => $realWeight ?: null,
                'volumetric_weight_kg' => $volWeight ?: null,
                'declared_value' => $declaredValue ?: null,
                'declared_currency' => $data['declared_currency'] ?? $shipment->declared_currency ?? 'USD',
                'service_options' => $serviceOptions,
                'pricing_snapshot' => $pricingSnapshot,
                'calculated_price' => $finalTotal,
            ];

            if (! $isDraft && $shipment->status === ShipmentStatus::Draft) {
                if (! ($updateData['sender_profile_id'] ?? null)) {
                    throw ValidationException::withMessages(['sender_profile_id' => 'Expéditeur requis.']);
                }
                if (! ($updateData['recipient_profile_id'] ?? null)) {
                    throw ValidationException::withMessages(['recipient_profile_id' => 'Destinataire requis.']);
                }
                $isStaff = ! $user->hasRole('client');
                $updateData['status'] = $isStaff ? ShipmentStatus::ReceivedAtHub : ShipmentStatus::PendingDropOff;
            }

            $shipment->update($updateData);

            // Refresh items
            if (array_key_exists('items', $data)) {
                $shipment->items()->delete();
                foreach ($data['items'] as $item) {
                    $originCountryId = isset($item['origin_country_id']) && $item['origin_country_id'] !== '' && $item['origin_country_id'] !== null
                        ? (int) $item['origin_country_id'] : null;
                    if ($originCountryId === 0) {
                        $originCountryId = null;
                    }
                    $itemDt = isset($item['delivery_time_label']) ? trim((string) $item['delivery_time_label']) : '';
                    $desc = trim((string) ($item['description'] ?? ''));
                    if ($desc === '') {
                        if (! $isDraft) {
                            continue;
                        }
                        $desc = 'Brouillon (à compléter)';
                    }
                    $qty = (int) ($item['quantity'] ?? 0);
                    if ($qty < 1) {
                        $qty = 1;
                    }
                    ShipmentItem::query()->create([
                        'shipment_id' => $shipment->id,
                        'description' => $desc,
                        'quantity' => $qty,
                        'weight_kg' => $item['weight_kg'] ?? null,
                        'length_cm' => $item['length_cm'] ?? null,
                        'width_cm' => $item['width_cm'] ?? null,
                        'height_cm' => $item['height_cm'] ?? null,
                        'value' => $item['value'] ?? null,
                        'origin_country_id' => $originCountryId,
                        'category_id' => $item['category_id'] ?? null,
                        'delivery_time_label' => $itemDt !== '' ? $itemDt : null,
                    ]);
                }
            }

            $shipment->logs()->create([
                'user_id' => $user->id,
                'status' => $shipment->status,
                'title' => 'Expédition mise à jour',
                'ip_address' => request()->ip(),
            ]);

            return $shipment;
        });

        return response()->json(['message' => 'Expédition mise à jour.', 'id' => $shipment->id]);
    }

    /**
     * §21.9 — Duplique une expédition en brouillon (même expéditeur / destinataire / lignes, nouveau suivi).
     */
    public function duplicate(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('view', $shipment);
        $this->authorize('create', Shipment::class);

        $user = $request->user();
        if ($user->hasRole('client')) {
            return response()->json(['message' => 'Action réservée au personnel.'], 403);
        }

        $shipment->load(['items']);

        if ($shipment->items->isEmpty()) {
            return response()->json(['message' => 'Impossible de dupliquer une expédition sans lignes de colis.'], 422);
        }

        $so = (array) ($shipment->service_options ?? []);
        $snap = is_array($shipment->pricing_snapshot) ? $shipment->pricing_snapshot : [];

        $items = $shipment->items->map(fn (ShipmentItem $it) => [
            'description' => (string) $it->description,
            'quantity' => max(1, (int) ($it->quantity ?? 1)),
            'weight_kg' => $it->weight_kg,
            'length_cm' => $it->length_cm,
            'width_cm' => $it->width_cm,
            'height_cm' => $it->height_cm,
            'value' => $it->value,
            'origin_country_id' => $it->origin_country_id,
            'category_id' => $it->category_id,
            'delivery_time_label' => $it->delivery_time_label,
        ])->all();

        $data = [
            'sender_profile_id' => $shipment->sender_profile_id,
            'recipient_profile_id' => $shipment->recipient_profile_id,
            'agency_id' => $shipment->agency_id,
            'origin_country_id' => $shipment->origin_country_id,
            'dest_country_id' => $shipment->dest_country_id,
            'shipping_mode_id' => isset($so['shipping_mode_id']) ? (int) $so['shipping_mode_id'] : null,
            'delivery_time_label' => isset($so['delivery_time_label']) ? trim((string) $so['delivery_time_label']) : null,
            'packaging_type_id' => isset($so['packaging_type_id']) ? (int) $so['packaging_type_id'] : null,
            'transport_company_id' => isset($so['transport_company_id']) ? (int) $so['transport_company_id'] : null,
            'ship_line_id' => isset($so['ship_line_id']) ? (int) $so['ship_line_id'] : null,
            'ship_line_rate_id' => isset($so['ship_line_rate_id']) ? (int) $so['ship_line_rate_id'] : null,
            'service_options' => $so,
            'items' => $items,
            'declared_value' => $shipment->declared_value,
            'declared_currency' => $shipment->declared_currency ?? 'USD',
            'insurance_pct' => (float) ($snap['insurance_pct'] ?? $snap['insurance_percentage'] ?? 0),
            'customs_duty_pct' => (float) ($snap['customs_duty_pct'] ?? $snap['customs_duty_percentage'] ?? 0),
            'tax_pct' => (float) ($snap['tax_pct'] ?? $snap['tax_percentage'] ?? 0),
            'discount_pct' => (float) ($snap['discount_pct'] ?? $snap['discount_percentage'] ?? 0),
            'manual_pricing' => (bool) ($so['manual_pricing'] ?? $snap['manual_pricing'] ?? false),
            'manual_price_per_kg' => (float) ($so['manual_price_per_kg'] ?? $snap['manual_price_per_kg'] ?? $snap['price_per_kg'] ?? 0),
            'fixed_fees' => (float) ($snap['fixed_fees'] ?? 0),
            'manual_fee' => (float) ($so['manual_fee'] ?? $snap['manual_fee'] ?? 0),
        ];

        $agencyId = $this->resolveWizardAgencyId($data, $user);

        $newShipment = DB::transaction(function () use ($data, $user, $agencyId, $shipment, $request) {
            $pricing = $this->computeWizardPricing($data, $user, $agencyId);
            $serviceOptions = $pricing['service_options'];
            $pricingSnapshot = $pricing['pricing_snapshot'];
            $finalTotal = $pricing['final_total'];
            $realWeight = $pricing['real_weight'];
            $volWeight = $pricing['vol_weight'];
            $declaredValue = $pricing['declared_value_effective'];

            $senderProfile = Profile::findOrFail($data['sender_profile_id']);
            $recipientProfile = Profile::findOrFail($data['recipient_profile_id']);

            $senderUser = $senderProfile->user;
            if ($senderUser) {
                $inBook = AddressBook::query()
                    ->where('owner_profile_id', $senderProfile->id)
                    ->where('contact_profile_id', $recipientProfile->id)
                    ->exists();
                if (! $inBook) {
                    throw ValidationException::withMessages([
                        'recipient_profile_id' => 'Ce destinataire n\'appartient pas au carnet de l\'expéditeur.',
                    ]);
                }
            } elseif ((int) ($recipientProfile->agency_id ?? 0) !== (int) ($senderProfile->agency_id ?? 0)) {
                throw ValidationException::withMessages([
                    'recipient_profile_id' => 'Ce destinataire n\'appartient pas à l\'agence du client.',
                ]);
            }

            $originC = isset($data['origin_country_id']) && $data['origin_country_id'] !== '' && $data['origin_country_id'] !== null
                ? (int) $data['origin_country_id'] : null;
            $destC = isset($data['dest_country_id']) && $data['dest_country_id'] !== '' && $data['dest_country_id'] !== null
                ? (int) $data['dest_country_id'] : null;

            $copy = Shipment::query()->create([
                'sender_profile_id' => $senderProfile->id,
                'recipient_profile_id' => $recipientProfile->id,
                'creator_user_id' => $shipment->creator_user_id,
                'agency_id' => $agencyId,
                'origin_country_id' => $originC && $originC > 0 ? $originC : null,
                'dest_country_id' => $destC && $destC > 0 ? $destC : null,
                'status' => ShipmentStatus::Draft,
                'assigned_driver_id' => null,
                'regroupement_id' => null,
                'master_shipment_id' => null,
                'pre_alert_id' => null,
                'assisted_purchase_id' => null,
                'service_flow' => $shipment->service_flow,
                'weight_kg' => $realWeight ?: null,
                'volumetric_weight_kg' => $volWeight ?: null,
                'declared_value' => $declaredValue ?: null,
                'company_coverage_amount' => $shipment->company_coverage_amount,
                'declared_currency' => $data['declared_currency'] ?? 'USD',
                'service_options' => $serviceOptions,
                'pricing_snapshot' => $pricingSnapshot,
                'calculated_price' => $finalTotal,
                'currency' => $shipment->currency ?? 'USD',
                'signed_form_path' => null,
                'delivery_signature' => null,
                'delivery_notes' => null,
                'current_hub_id' => null,
            ]);

            foreach ($data['items'] as $item) {
                $originCountryId = isset($item['origin_country_id']) && $item['origin_country_id'] !== '' && $item['origin_country_id'] !== null
                    ? (int) $item['origin_country_id'] : null;
                if ($originCountryId === 0) {
                    $originCountryId = null;
                }
                $itemDt = isset($item['delivery_time_label']) ? trim((string) $item['delivery_time_label']) : '';
                ShipmentItem::query()->create([
                    'shipment_id' => $copy->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'weight_kg' => $item['weight_kg'] ?? null,
                    'length_cm' => $item['length_cm'] ?? null,
                    'width_cm' => $item['width_cm'] ?? null,
                    'height_cm' => $item['height_cm'] ?? null,
                    'value' => $item['value'] ?? null,
                    'origin_country_id' => $originCountryId,
                    'category_id' => $item['category_id'] ?? null,
                    'delivery_time_label' => $itemDt !== '' ? $itemDt : null,
                ]);
            }

            ShipmentInvoiceNumberGenerator::assignToShipment($copy);

            $copy->logs()->create([
                'user_id' => $user->id,
                'status' => ShipmentStatus::Draft,
                'title' => 'Expédition dupliquée',
                'description' => sprintf(
                    'Copie depuis l’expédition #%d (%s).',
                    $shipment->id,
                    (string) ($shipment->public_tracking ?? '')
                ),
                'ip_address' => $request->ip(),
            ]);

            return $copy;
        });

        return response()->json([
            'message' => 'Expédition dupliquée en brouillon.',
            'id' => $newShipment->id,
            'public_tracking' => $newShipment->public_tracking,
        ], 201);
    }

    public function assignDriver(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('update', $shipment);

        $data = $request->validate([
            'assigned_driver_id' => ['nullable', 'exists:users,id'],
        ]);

        $driverId = $data['assigned_driver_id'] ?? null;
        if ($driverId !== null && ! $this->shipmentStatusAllowsDriverAssignment($shipment->status)) {
            throw ValidationException::withMessages([
                'assigned_driver_id' => [
                    'L’assignation de chauffeur n’est possible qu’en phase ramassage (en attente de dépôt) ou livraison (en transit ou arrivé à destination).',
                ],
            ]);
        }

        $shipment->update([
            'assigned_driver_id' => $driverId,
        ]);

        $shipment->logs()->create([
            'user_id' => $request->user()->id,
            'status' => $shipment->status,
            'title' => 'Chauffeur assigné / modifié',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Chauffeur mis à jour.']);
    }

    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('view', $shipment);

        $shipment->load([
            'senderProfile',
            'senderProfile.country',
            'senderProfile.city',
            'senderProfile.state',
            'recipientProfile',
            'recipientProfile.country',
            'recipientProfile.city',
            'recipientProfile.state',
            'items.originCountry',
            'items.category',
            'agency',
            'originCountry',
            'destCountry',
            'regroupement.shipments.senderProfile.country',
            'regroupement.shipments.senderProfile.city',
            'regroupement.shipments.recipientProfile.country',
            'regroupement.shipments.recipientProfile.city',
            'regroupement.shipments.originCountry',
            'regroupement.shipments.destCountry',
            'logs.user',
            'currentHub',
            'assignedDriver',
            'creator',
            'preAlert.media',
            'payments.recordedBy',
        ]);
        $workflow = app(ShipmentWorkflowService::class);
        $workflowSteps = $workflow->buildStepsForShipment($shipment);
        $availableTransitions = $workflow->getAvailableTransitions($shipment);
        $drivers = $this->getDriversForUser($request->user());

        $doc = ShipmentDocumentSettings::merged();
        $invoice = $shipment->invoices()->first();

        return response()->json([
            'shipment' => $this->buildShipmentListRow($shipment),
            'workflow_steps' => $workflowSteps,
            'available_transitions' => $availableTransitions,
            'drivers' => $drivers,
            'doc_settings' => [
                'site_name' => $doc['site_name'] ?? 'Monrespro',
                'site_email' => $doc['site_email'] ?? '',
                'phone' => $doc['phone'] ?? '',
                'address' => $doc['address'] ?? '',
                'city' => $doc['city'] ?? '',
                'country' => $doc['country'] ?? '',
                'nit' => $doc['nit'] ?? '',
                'currency' => $doc['currency'] ?? 'EUR',
                'currency_symbol' => $doc['currency_symbol'] ?? '€',
                'decimals' => (int) (Setting::getValue('decimals', '2') ?: 2),
                'symbol_position' => (Setting::getValue('symbol_position', 'prefix') ?: 'prefix') === 'suffix' ? 'suffix' : 'prefix',
                'weight_unit' => $doc['weight_unit'] ?? 'kg',
                'transport_company' => $doc['transport_company'] ?? '',
                'shipping_mode_label' => $doc['shipping_mode_label'] ?? '',
                'invoice_terms' => $doc['invoice_terms'] ?? '',
                'signing_company' => $doc['signing_company'] ?? '',
                'signing_customer' => $doc['signing_customer'] ?? '',
                'logo_url' => $doc['logo_url'] ?? null,
            ],
            'invoice_data' => $invoice ? [
                'invoice_number' => $invoice->invoice_number,
                'due_at' => $invoice->due_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function accept(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('update', $shipment);

        $data = $request->validate([
            'assigned_driver_id' => ['nullable', 'exists:users,id'],
            'calculated_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            /** Poids mesuré au hub — comparé au poids déclaré (§6 PRD, écart max 10 % sans confirmation). */
            'hub_measured_weight_kg' => ['nullable', 'numeric', 'min:0'],
            /** Si l’écart dépasse 10 %, le personnel doit confirmer explicitement. */
            'confirm_weight_variance' => ['sometimes', 'boolean'],
        ]);

        $declaredKg = (float) ($shipment->weight_kg ?? 0);
        $measured = isset($data['hub_measured_weight_kg']) ? (float) $data['hub_measured_weight_kg'] : null;
        if ($declaredKg > 0 && $measured !== null && $measured > 0) {
            $rel = abs($measured - $declaredKg) / $declaredKg;
            if ($rel > 0.10 && ! $request->boolean('confirm_weight_variance')) {
                $pct = round($rel * 100, 1);
                throw ValidationException::withMessages([
                    'hub_measured_weight_kg' => [
                        "Écart de {$pct} % avec le poids déclaré ({$declaredKg} kg). Corrigez la saisie ou confirmez l’acceptation (confirm_weight_variance : true).",
                    ],
                ]);
            }
        }

        $oldStatus = $shipment->status ?? ShipmentStatus::Draft;
        $newStatus = ShipmentStatus::ReceivedAtHub;

        $shipment->update(array_filter([
            'assigned_driver_id' => $data['assigned_driver_id'] ?? null,
            'calculated_price' => $data['calculated_price'] ?? $shipment->calculated_price,
            'status' => $newStatus,
        ]));

        $shipment->logs()->create([
            'user_id' => $request->user()->id,
            'status' => $newStatus,
            'title' => 'Expédition réceptionnée / validée au hub',
            'description' => $data['notes'] ?? null,
            'ip_address' => $request->ip(),
        ]);

        if ($oldStatus !== $newStatus) {
            event(new ShipmentStatusChanged(
                shipment: $shipment->fresh(),
                oldStatus: $oldStatus,
                newStatus: $newStatus,
                changedBy: $request->user(),
            ));
        }

        return response()->json(['message' => 'Expédition acceptée.']);
    }

    public function updateStatus(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('update', $shipment);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::enum(ShipmentStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'failure_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $newStatus = ShipmentStatus::from($data['status']);

        if ($newStatus === ShipmentStatus::DeliveryFailed) {
            $reason = trim((string) ($data['failure_reason'] ?? ''));
            if ($reason === '') {
                throw ValidationException::withMessages([
                    'failure_reason' => ['Un motif d’échec de livraison est obligatoire.'],
                ]);
            }
        }

        if ($newStatus === ShipmentStatus::ReadyForDispatch) {
            $paidStatuses = ['paid', 'partially_paid'];
            if (! in_array($shipment->payment_status, $paidStatuses, true)) {
                throw ValidationException::withMessages([
                    'status' => ['Impossible de passer en « Prêt à l\'expédition » sans paiement validé.'],
                ]);
            }
        }

        $current = $shipment->status ?? ShipmentStatus::Draft;
        if (! $current->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => 'Transition de statut non autorisée.',
            ]);
        }

        $oldStatus = $shipment->status ?? ShipmentStatus::Draft;
        $shipment->update(['status' => $newStatus]);

        $logDescription = $data['notes'] ?? null;
        if ($newStatus === ShipmentStatus::DeliveryFailed && ! empty($data['failure_reason'])) {
            $logDescription = trim(($logDescription ? $logDescription."\n\n" : '').'Motif : '.$data['failure_reason']);
        }

        $shipment->logs()->create([
            'user_id' => $request->user()->id,
            'status' => $newStatus,
            'title' => 'Changement de statut : '.$newStatus->label(),
            'description' => $logDescription,
            'ip_address' => $request->ip(),
        ]);

        if ($oldStatus !== $newStatus) {
            event(new ShipmentStatusChanged(
                shipment: $shipment->fresh(),
                oldStatus: $oldStatus,
                newStatus: $newStatus,
                changedBy: $request->user(),
            ));
        }

        return response()->json(['message' => 'Statut mis à jour.']);
    }

    public function deliver(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('update', $shipment);

        $rules = [
            'delivery_signature' => ['nullable', 'string', 'max:50000'],
            'delivery_notes' => ['nullable', 'string', 'max:2000'],
            'delivery_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];

        if ($request->user()->hasRole('driver')) {
            $rules['delivery_photo'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'];
        }

        $data = $request->validate($rules);

        $proofPath = $shipment->delivery_proof_path;
        if ($request->hasFile('delivery_photo')) {
            if ($proofPath) {
                Storage::disk('public')->delete($proofPath);
            }
            $proofPath = $request->file('delivery_photo')->store('delivery-proofs', 'public');
        }

        $oldStatus = $shipment->status ?? ShipmentStatus::Draft;
        $newStatus = ShipmentStatus::Delivered;

        $shipment->update([
            'status' => $newStatus,
            'delivery_signature' => $data['delivery_signature'] ?? null,
            'delivery_notes' => $data['delivery_notes'] ?? null,
            'delivery_proof_path' => $proofPath,
        ]);

        $shipment->logs()->create([
            'user_id' => $request->user()->id,
            'status' => $newStatus,
            'title' => 'Livraison effectuée',
            'description' => $data['delivery_notes'] ?? null,
            'meta' => $data['delivery_signature'] ? ['has_signature' => true] : null,
            'ip_address' => $request->ip(),
        ]);

        if ($oldStatus !== $newStatus) {
            event(new ShipmentStatusChanged(
                shipment: $shipment->fresh(),
                oldStatus: $oldStatus,
                newStatus: $newStatus,
                changedBy: $request->user(),
            ));
        }

        return response()->json(['message' => 'Livraison enregistrée.']);
    }

    public function archiveSignedForm(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('update', $shipment);

        $data = $request->validate([
            'signed_form' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $file = $data['signed_form'];
        $oldPath = $shipment->signed_form_path;
        $storedPath = $file->store('shipment-signed-forms', 'public');

        $shipment->update([
            'signed_form_path' => $storedPath,
        ]);

        if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $storedPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $shipment->logs()->create([
            'user_id' => $request->user()->id,
            'status' => $shipment->status,
            'title' => 'Formulaire signé archivé',
            'description' => 'Fichier : '.(string) $file->getClientOriginalName(),
            'ip_address' => $request->ip(),
        ]);

        $owner = $shipment->creator;
        if ($owner) {
            NotificationDispatcher::dispatch(
                user: $owner,
                eventKey: 'shipment.signed_form_archived',
                variables: [
                    'tracking' => $shipment->public_tracking ?? '',
                    'client_nom' => $owner->name ?? '',
                ],
                actionUrl: "/shipments/{$shipment->id}",
            );
        }

        return response()->json([
            'message' => 'Formulaire signé archivé.',
            'signed_form_url' => ShipmentDocumentSettings::publicStorageWebPath($storedPath),
        ]);
    }

    public function recordPayment(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('update', $shipment);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $totalDue = (float) ($shipment->calculated_price ?? 0);
        $alreadyPaid = (float) ($shipment->amount_paid ?? 0);
        $newAmount = (float) $data['amount'];
        $newTotal = $alreadyPaid + $newAmount;

        $paymentStatus = 'partial';
        $paidAt = null;
        if ($newTotal >= $totalDue && $totalDue > 0) {
            $paymentStatus = 'paid';
            $paidAt = now();
        }

        $methodLabels = [
            'cash' => 'Espèces',
            'mobile_money' => 'Mobile Money',
            'bank_transfer' => 'Virement bancaire',
            'card' => 'Carte bancaire',
            'other' => 'Autre',
        ];

        $label = $methodLabels[$data['payment_method']] ?? $data['payment_method'];

        DB::transaction(function () use ($request, $shipment, $data, $newTotal, $paymentStatus, $paidAt, $newAmount, $label) {
            $shipment->update([
                'amount_paid' => $newTotal,
                'payment_status' => $paymentStatus,
                'paid_at' => $paidAt,
            ]);

            ShipmentPayment::query()->create([
                'shipment_id' => $shipment->id,
                'amount' => $newAmount,
                'payment_method' => $data['payment_method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by_user_id' => $request->user()->id,
            ]);

            if ($paymentStatus === 'paid') {
                $cur = $shipment->status ?? ShipmentStatus::Draft;
                if (in_array($cur, [ShipmentStatus::Draft, ShipmentStatus::ReceivedAtHub], true)
                    && $cur->canTransitionTo(ShipmentStatus::ReadyForDispatch)) {
                    $next = ShipmentStatus::ReadyForDispatch;
                    $shipment->update(['status' => $next]);
                    $shipment->logs()->create([
                        'user_id' => $request->user()->id,
                        'status' => $next,
                        'title' => 'Paiement complet — prêt à l’expédition',
                        'ip_address' => $request->ip(),
                    ]);
                    event(new ShipmentStatusChanged(
                        shipment: $shipment->fresh(),
                        oldStatus: $cur,
                        newStatus: $next,
                        changedBy: $request->user(),
                    ));
                }
            }

            $shipment->refresh();

            $shipment->logs()->create([
                'user_id' => $request->user()->id,
                'status' => $shipment->status,
                'title' => 'Paiement enregistré : '.number_format($newAmount, 2).' '.($shipment->currency ?? 'USD').' ('.$label.')',
                'description' => $data['notes'] ?? null,
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json(['message' => 'Paiement enregistré avec succès.']);
    }

    public function updateInvoiceOptions(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('update', $shipment);

        $request->validate([
            'company_coverage_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $raw = $request->input('company_coverage_amount');
        $shipment->update([
            'company_coverage_amount' => $raw === null || $raw === '' ? null : round((float) $raw, 2),
        ]);

        $shipment->load([
            'senderProfile',
            'senderProfile.country',
            'senderProfile.city',
            'senderProfile.state',
            'recipientProfile',
            'recipientProfile.country',
            'recipientProfile.city',
            'recipientProfile.state',
            'items.originCountry',
            'items.category',
            'agency',
            'originCountry',
            'destCountry',
            'payments.recordedBy',
        ]);

        return response()->json([
            'message' => 'Options de facture enregistrées.',
            'shipment' => $this->buildShipmentListRow($shipment),
        ]);
    }

    protected function estimatedDelivery(Shipment $s): ?Carbon
    {
        $opts = $s->service_options ?? [];
        $days = max(1, (int) ($opts['estimated_delivery_days'] ?? 14));

        return $s->created_at?->copy()->addDays($days);
    }

    /**
     * @param  mixed  $value
     */
    protected function localeLabel($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $t = trim($value);

            return $t !== '' ? $t : null;
        }
        if (is_array($value)) {
            $pick = $value['fr'] ?? $value['en'] ?? reset($value);

            return $this->localeLabel($pick);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildShipmentListRow(Shipment $s): array
    {
        $data = $s->toArray();
        unset($data['logs']);

        $st = $s->status ?? ShipmentStatus::Draft;
        $workflowSvc = app(ShipmentWorkflowService::class);
        $data['status'] = [
            'code' => $st->value,
            'name' => $st->label(),
            'color_hex' => $workflowSvc->colorHexForStatus($st),
        ];

        if ($s->relationLoaded('logs')) {
            $data['logs'] = $s->logs->sortBy('created_at')->values()->map(function (ShipmentLog $log) {
                $ls = $log->status;

                return [
                    'id' => $log->id,
                    'title' => $log->title,
                    'description' => $log->description,
                    'status' => $ls instanceof ShipmentStatus ? [
                        'code' => $ls->value,
                        'name' => $ls->label(),
                    ] : null,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'changed_at' => $log->created_at?->toIso8601String(),
                    'changed_by_name' => $log->user?->name,
                    'user_name' => $log->user?->name,
                ];
            })->all();
        }

        $opts = $s->service_options ?? [];
        $rp = $s->recipientProfile;
        $sp = $s->senderProfile;
        $corridor = ShipmentRowPresenter::corridor($s);
        $data['corridor'] = array_merge($corridor, ['origin_name' => null]);
        $data['route_display'] = ShipmentRowPresenter::routeDisplay($corridor);
        $data['estimated_delivery_at'] = $this->estimatedDelivery($s)?->toIso8601String();

        // Champs attendus par le SPA (alias sur le modèle colonnes / relations)
        $data['tracking_number'] = $s->public_tracking;
        $data['sender_name'] = $sp?->full_name;
        $data['recipient_name'] = $rp?->full_name;
        $data['recipient_city'] = $this->localeLabel($rp?->city?->name);
        $smId = (int) ($opts['shipping_mode_id'] ?? 0);
        if ($smId > 0) {
            $mode = ShippingMode::query()->find($smId);
            $data['shipping_mode'] = $mode?->name;
        } else {
            $data['shipping_mode'] = null;
        }
        $dtLabel = isset($opts['delivery_time_label']) ? trim((string) $opts['delivery_time_label']) : '';
        $data['delivery_time'] = $dtLabel !== '' ? $dtLabel : null;
        $data['transport_company'] = isset($opts['transport_company_name']) ? (string) $opts['transport_company_name'] : null;
        $data['ship_line'] = isset($opts['ship_line_name']) ? (string) $opts['ship_line_name'] : null;
        $total = $s->calculated_price !== null ? (float) $s->calculated_price : null;
        $data['total'] = $total;

        $snap = is_array($s->pricing_snapshot) ? $s->pricing_snapshot : [];
        $data['subtotal'] = isset($snap['subtotal']) ? (float) $snap['subtotal'] : null;
        $data['tax_total'] = isset($snap['tax_amount']) ? (float) $snap['tax_amount'] : null;
        if ($total === null && isset($snap['total'])) {
            $total = (float) $snap['total'];
            $data['total'] = $total;
        }
        $paid = (float) ($s->amount_paid ?? 0);
        $data['balance_due'] = $total !== null ? round(max(0, $total - $paid), 2) : null;

        $data['company_coverage_amount'] = $s->company_coverage_amount !== null
            ? round((float) $s->company_coverage_amount, 2)
            : null;
        $data['signed_form_path'] = $s->signed_form_path;
        $data['signed_form_url'] = $s->signed_form_path
            ? ShipmentDocumentSettings::publicStorageWebPath((string) $s->signed_form_path)
            : null;
        $data['has_signed_form'] = ! empty($s->signed_form_path);

        $ad = $s->assignedDriver;
        $data['driver'] = $ad ? [
            'id' => $ad->id,
            'name' => $ad->name,
            'phone' => $ad->phone,
        ] : null;

        if ($sp) {
            $data['sender_email'] = $sp->email;
            $data['sender_phone'] = $sp->phone;
            $data['sender_phone_secondary'] = $sp->phone_secondary;
            $data['sender_address'] = $sp->address;
            $data['sender_landmark'] = $sp->landmark;
            $data['sender_zip_code'] = $sp->zip_code;
            $data['sender_city'] = $this->localeLabel($sp->city?->name);
            $data['sender_state'] = $this->localeLabel($sp->state?->name);
            $data['sender_country'] = $this->localeLabel($sp->country?->name);
        }

        if ($rp) {
            $data['recipient_email'] = $rp->email;
            $data['recipient_phone'] = $rp->phone;
            $data['recipient_phone_secondary'] = $rp->phone_secondary;
            $data['recipient_address'] = $rp->address;
            $data['recipient_landmark'] = $rp->landmark;
            $data['recipient_zip_code'] = $rp->zip_code;
            $data['recipient_state'] = $this->localeLabel($rp->state?->name);
            $data['recipient_country'] = $this->localeLabel($rp->country?->name);
        }

        if ($s->relationLoaded('payments')) {
            $data['payments'] = $s->payments->map(function (ShipmentPayment $p) use ($s) {
                return [
                    'id' => $p->id,
                    'amount' => (float) $p->amount,
                    'currency' => $s->currency,
                    'method' => $p->payment_method,
                    'reference' => $p->reference,
                    'note' => $p->notes,
                    'created_at' => $p->created_at?->toIso8601String(),
                    'recorded_by' => $p->recordedBy?->name,
                ];
            })->values()->all();
        } else {
            $data['payments'] = [];
        }

        $isClient = auth()->check() && auth()->user()->hasRole('client');
        if (! $isClient && $s->relationLoaded('regroupement') && $s->regroupement) {
            $rg = $s->regroupement;
            $rst = $rg->status ?? ShipmentStatus::Draft;
            $workflowSvc = app(ShipmentWorkflowService::class);
            $summaries = [];
            if ($rg->relationLoaded('shipments')) {
                $summaries = $rg->shipments
                    ->map(fn (Shipment $sh) => ShipmentRowPresenter::summaryForRegroupement($sh))
                    ->values()
                    ->all();
            }
            $data['regroupement'] = [
                'id' => $rg->id,
                'batch_number' => $rg->batch_number,
                'status' => [
                    'code' => $rst->value,
                    'name' => $rst->label(),
                    'color_hex' => $workflowSvc->colorHexForStatus($rst),
                ],
                'shipments_in_lot' => $summaries,
                'lot_route' => ShipmentRowPresenter::aggregateLotRoute($summaries),
                'same_lot_count' => $rg->relationLoaded('shipments') ? $rg->shipments->count() : null,
            ];
        }

        return $data;
    }

    protected function getDriversForUser($user): Collection
    {
        $q = User::query()->role('driver')->orderBy('name')->limit(100);
        if (! $user->canAccessAllAgencies()) {
            $q->where('agency_id', $user->agency_id);
        }

        return $q->get(['id', 'name', 'email']);
    }

    /**
     * Frais d'emballage : prix unitaire (paramètre type) × somme des quantités d'articles.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{fee: float, label: string|null, quantity: int, unit_price: float}
     */
    protected function computePackagingFeeFromItems(?int $packagingTypeId, array $items): array
    {
        $qty = (int) collect($items)->sum(fn ($i) => (int) ($i['quantity'] ?? 1));

        if ($packagingTypeId === null || $packagingTypeId <= 0) {
            return ['fee' => 0.0, 'label' => null, 'quantity' => $qty, 'unit_price' => 0.0];
        }

        $pt = PackagingType::query()->find($packagingTypeId);
        if (! $pt) {
            return ['fee' => 0.0, 'label' => null, 'quantity' => $qty, 'unit_price' => 0.0];
        }

        $unit = (float) ($pt->unit_price ?? 0);

        if (! $pt->is_billable) {
            return ['fee' => 0.0, 'label' => $pt->name, 'quantity' => $qty, 'unit_price' => 0.0];
        }

        if ($unit <= 0) {
            return ['fee' => 0.0, 'label' => $pt->name, 'quantity' => $qty, 'unit_price' => 0.0];
        }

        return [
            'fee' => round($unit * max(0, $qty), 2),
            'label' => $pt->name,
            'quantity' => max(0, $qty),
            'unit_price' => $unit,
        ];
    }

    /**
     * Base pour les pourcentages assurance / douane : « valeur déclarée » si renseignée, sinon somme (valeur × qté) des lignes articles.
     *
     * @param  array<string, mixed>  $data
     */
    protected function effectiveDeclaredValue(array $data): float
    {
        $fromItems = collect($data['items'] ?? [])->sum(fn ($i) => (float) ($i['value'] ?? 0) * (int) ($i['quantity'] ?? 1));
        if (! array_key_exists('declared_value', $data) || $data['declared_value'] === null || $data['declared_value'] === '') {
            return (float) $fromItems;
        }

        return (float) $data['declared_value'];
    }

    /**
     * Tarification complète (assistant + prévisualisation JSON).
     *
     * @param  array<string, mixed>  $data
     * @return array{
     *     service_options: array,
     *     pricing_snapshot: array<string, mixed>,
     *     final_total: float,
     *     real_weight: float,
     *     vol_weight: float,
     *     declared_value_effective: float
     * }
     */
    /**
     * Agence pour tarification / persistance : jamais 0 (MySQL refuse la FK), utiliser null si aucune agence.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveWizardAgencyId(array $data, User $user): ?int
    {
        $agencyId = (int) ($data['agency_id'] ?? $user->agency_id ?? 0);
        if (! $user->canAccessAllAgencies()) {
            $agencyId = (int) ($user->agency_id ?? 0);
        } elseif (! empty($data['agency_id'])) {
            $ok = Agency::query()->where('is_active', true)->whereKey((int) $data['agency_id'])->exists();
            if (! $ok) {
                $agencyId = (int) ($user->agency_id ?? 0);
            }
        }

        return $agencyId > 0 ? $agencyId : null;
    }

    protected function computeWizardPricing(array $data, User $user, ?int $agencyId): array
    {
        $soIncoming = (array) ($data['service_options'] ?? []);
        if (trim((string) ($data['delivery_time_label'] ?? '')) === '' && isset($soIncoming['delivery_time_label'])) {
            $tl = trim((string) $soIncoming['delivery_time_label']);
            if ($tl !== '') {
                $data['delivery_time_label'] = $tl;
            }
        }

        $itemLabels = collect($data['items'] ?? [])
            ->map(fn ($i) => trim((string) ($i['delivery_time_label'] ?? '')))
            ->filter(fn ($s) => $s !== '');
        if ($itemLabels->isNotEmpty()) {
            $uniq = $itemLabels->unique()->values();
            $merged = $uniq->count() === 1 ? (string) $uniq->first() : $uniq->implode(' · ');
            $data['delivery_time_label'] = $merged;
        }

        $quotePayload = array_merge($data, ['agency_id' => $agencyId]);
        $slrIdEarly = isset($data['ship_line_rate_id']) ? (int) $data['ship_line_rate_id'] : 0;
        if ($slrIdEarly > 0) {
            $earlyRate = ShipLineRate::query()->find($slrIdEarly);
            if ($earlyRate && $earlyRate->is_active) {
                if (empty($data['shipping_mode_id']) || $data['shipping_mode_id'] === '' || $data['shipping_mode_id'] === null) {
                    $data['shipping_mode_id'] = $earlyRate->shipping_mode_id;
                    $quotePayload['shipping_mode_id'] = $earlyRate->shipping_mode_id;
                }
                $curLabel = trim((string) ($data['delivery_time_label'] ?? ''));
                if ($curLabel === '') {
                    $ov = $earlyRate->delivery_label_override;
                    if (is_string($ov) && trim($ov) !== '') {
                        $data['delivery_time_label'] = trim($ov);
                        $quotePayload['delivery_time_label'] = trim($ov);
                    }
                }
            }
        }
        $divisor = $this->resolveVolumetricDivisorForWizard($data);

        $realWeight = collect($data['items'] ?? [])->sum(fn ($i) => (float) ($i['weight_kg'] ?? 0) * (int) ($i['quantity'] ?? 1));
        $volWeight = collect($data['items'] ?? [])->sum(function ($i) use ($divisor) {
            $l = (float) ($i['length_cm'] ?? 0);
            $w = (float) ($i['width_cm'] ?? 0);
            $h = (float) ($i['height_cm'] ?? 0);
            $qty = (int) ($i['quantity'] ?? 1);
            if ($l <= 0 || $w <= 0 || $h <= 0) {
                return 0;
            }

            return ($l * $w * $h / $divisor) * $qty;
        });

        $billableRule = $this->normalizedBillableWeightRule();
        $billableWeight = $this->billableWeightFromRule($realWeight, $volWeight, $billableRule);
        $volumeM3 = collect($data['items'] ?? [])->sum(function ($i) {
            $l = (float) ($i['length_cm'] ?? 0) / 100;
            $w = (float) ($i['width_cm'] ?? 0) / 100;
            $h = (float) ($i['height_cm'] ?? 0) / 100;
            $qty = (int) ($i['quantity'] ?? 1);
            if ($l <= 0 || $w <= 0 || $h <= 0) {
                return 0;
            }

            return $l * $w * $h * $qty;
        });
        $declaredValue = $this->effectiveDeclaredValue($data);
        $insurancePct = (float) ($data['insurance_pct'] ?? 0);
        $customsDutyPct = (float) ($data['customs_duty_pct'] ?? 0);
        $taxPct = (float) ($data['tax_pct'] ?? 0);
        $discountPct = (float) ($data['discount_pct'] ?? 0);
        $manualPricing = (bool) ($data['manual_pricing'] ?? false);
        $manualPricePerKg = (float) ($data['manual_price_per_kg'] ?? 0);
        $fixedFees = (float) ($data['fixed_fees'] ?? 0);
        $manualFee = (float) ($data['manual_fee'] ?? 0);

        $optsEarly = (array) ($data['service_options'] ?? []);
        $packagingTypeIdStore = isset($optsEarly['packaging_type_id']) ? (int) $optsEarly['packaging_type_id'] : null;
        if ($packagingTypeIdStore === 0) {
            $packagingTypeIdStore = null;
        }
        if ($packagingTypeIdStore === null && isset($data['packaging_type_id']) && $data['packaging_type_id'] !== '' && $data['packaging_type_id'] !== null) {
            $pid = (int) $data['packaging_type_id'];
            $packagingTypeIdStore = $pid === 0 ? null : $pid;
        }
        $packagingBreakdown = $this->computePackagingFeeFromItems($packagingTypeIdStore, $data['items'] ?? []);
        $packagingFee = $packagingBreakdown['fee'];

        if ($manualPricing && $manualPricePerKg > 0) {
            $baseQuote = $billableWeight * $manualPricePerKg;
        } else {
            $baseQuote = $this->quoteFromValidatedPayload($quotePayload, $user, $divisor, $realWeight, $volWeight, $billableWeight, $volumeM3);
        }

        $insuranceAmount = $declaredValue > 0 ? round($declaredValue * $insurancePct / 100, 2) : 0;
        $customsDutyAmount = $declaredValue > 0 ? round($declaredValue * $customsDutyPct / 100, 2) : 0;
        $subtotal = $baseQuote + $insuranceAmount + $customsDutyAmount + $fixedFees + $manualFee + $packagingFee;
        $taxAmount = round($subtotal * $taxPct / 100, 2);
        $totalBeforeDiscount = $subtotal + $taxAmount;
        $discountAmount = round($totalBeforeDiscount * $discountPct / 100, 2);
        $finalTotal = round($totalBeforeDiscount - $discountAmount, 2);

        $manualFeeLabel = isset($optsEarly['manual_fee_label']) ? trim((string) $optsEarly['manual_fee_label']) : '';

        $serviceOptions = array_merge($optsEarly, [
            'manual_fee' => $manualFee,
            'base_quote' => $baseQuote,
            'manual_pricing' => $manualPricing,
            'manual_price_per_kg' => $manualPricePerKg,
            'insurance_pct' => $insurancePct,
            'customs_duty_pct' => $customsDutyPct,
            'tax_pct' => $taxPct,
            'discount_pct' => $discountPct,
            'fixed_fees' => $fixedFees,
            'packaging_fee' => round($packagingFee, 2),
            'packaging_label' => $packagingBreakdown['label'],
            'packaging_quantity' => $packagingBreakdown['quantity'],
            'packaging_unit_price' => round($packagingBreakdown['unit_price'], 4),
            'manual_fee_label' => $manualFeeLabel,
        ]);

        $smid = isset($data['shipping_mode_id']) && $data['shipping_mode_id'] !== '' && $data['shipping_mode_id'] !== null ? (int) $data['shipping_mode_id'] : null;
        if ($smid !== null && $smid > 0) {
            $serviceOptions['shipping_mode_id'] = $smid;
        }
        $dtLabelMerged = trim((string) ($data['delivery_time_label'] ?? ''));
        if ($dtLabelMerged === '' && isset($optsEarly['delivery_time_label'])) {
            $dtLabelMerged = trim((string) $optsEarly['delivery_time_label']);
        }
        if ($dtLabelMerged !== '') {
            $serviceOptions['delivery_time_label'] = $dtLabelMerged;
        }
        $pkid = $packagingTypeIdStore;
        if ($pkid !== null && $pkid > 0) {
            $serviceOptions['packaging_type_id'] = $pkid;
        }

        $tcid = isset($data['transport_company_id']) && $data['transport_company_id'] !== '' && $data['transport_company_id'] !== null
            ? (int) $data['transport_company_id'] : 0;
        if ($tcid > 0) {
            $tc = TransportCompany::query()->where('is_active', true)->find($tcid);
            if ($tc) {
                $serviceOptions['transport_company_id'] = $tcid;
                $serviceOptions['transport_company_name'] = $tc->name;
            }
        }

        $slid = isset($data['ship_line_id']) && $data['ship_line_id'] !== '' && $data['ship_line_id'] !== null
            ? (int) $data['ship_line_id'] : 0;
        if ($slid > 0) {
            $sl = ShipLine::query()->where('is_active', true)->find($slid);
            if ($sl) {
                $serviceOptions['ship_line_id'] = $slid;
                $serviceOptions['ship_line_name'] = $sl->name;
            }
        }

        $ocid = isset($data['origin_country_id']) && $data['origin_country_id'] !== '' && $data['origin_country_id'] !== null
            ? (int) $data['origin_country_id'] : 0;
        if ($ocid > 0) {
            $serviceOptions['origin_country_id'] = $ocid;
        }
        $dcid = isset($data['dest_country_id']) && $data['dest_country_id'] !== '' && $data['dest_country_id'] !== null
            ? (int) $data['dest_country_id'] : 0;
        if ($dcid > 0) {
            $serviceOptions['dest_country_id'] = $dcid;
        }
        $slrid = isset($data['ship_line_rate_id']) && $data['ship_line_rate_id'] !== '' && $data['ship_line_rate_id'] !== null
            ? (int) $data['ship_line_rate_id'] : 0;
        if ($slrid > 0) {
            $serviceOptions['ship_line_rate_id'] = $slrid;
            $rateRow = ShipLineRate::query()->find($slrid);
            if ($rateRow && $rateRow->ship_line_id > 0 && ($slid <= 0)) {
                $sln = ShipLine::query()->where('is_active', true)->find($rateRow->ship_line_id);
                if ($sln) {
                    $serviceOptions['ship_line_id'] = (int) $rateRow->ship_line_id;
                    $serviceOptions['ship_line_name'] = $sln->name;
                }
            }
        }

        if (! empty($data['legal_declaration_accepted'])) {
            $serviceOptions['legal_declaration_accepted'] = true;
            $serviceOptions['legal_declaration_accepted_at'] = now()->toIso8601String();
        }

        $pricingSnapshot = [
            'real_weight_kg' => round($realWeight, 3),
            'volumetric_weight_kg' => round($volWeight, 3),
            'billable_weight_rule' => $billableRule,
            'billable_weight_kg' => round($billableWeight, 3),
            'base_quote' => round($baseQuote, 2),
            'insurance_pct' => $insurancePct,
            'customs_duty_pct' => $customsDutyPct,
            'tax_pct' => $taxPct,
            'discount_pct' => $discountPct,
            'insurance_amount' => $insuranceAmount,
            'customs_duty_amount' => $customsDutyAmount,
            'fixed_fees' => $fixedFees,
            'manual_fee' => $manualFee,
            'manual_fee_label' => $manualFeeLabel,
            'packaging_fee' => round($packagingFee, 2),
            'packaging_label' => $packagingBreakdown['label'],
            'packaging_quantity' => $packagingBreakdown['quantity'],
            'packaging_unit_price' => round($packagingBreakdown['unit_price'], 4),
            'subtotal' => round($subtotal, 2),
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total' => $finalTotal,
            'price_per_kg' => $manualPricing && $manualPricePerKg > 0 ? round($manualPricePerKg, 4) : 0.0,
            'declared_value_effective' => round($declaredValue, 2),
        ];

        return [
            'service_options' => $serviceOptions,
            'pricing_snapshot' => $pricingSnapshot,
            'final_total' => $finalTotal,
            'real_weight' => $realWeight,
            'vol_weight' => $volWeight,
            'declared_value_effective' => $declaredValue,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function quoteFromValidatedPayload(
        array $data,
        User $user,
        float $divisor,
        float $realWeight,
        float $volWeight,
        float $billableWeightKg,
        float $volumeM3,
    ): float {
        $rawAgency = $data['agency_id'] ?? $user->agency_id ?? null;
        $engineAgencyId = ($rawAgency !== null && $rawAgency !== '' && (int) $rawAgency > 0)
            ? (int) $rawAgency
            : null;

        $fromLineRate = $this->tryShipLineRateBaseQuote($data, $billableWeightKg, $volumeM3);
        if ($fromLineRate !== null) {
            return $fromLineRate;
        }

        $engineQuote = PricingEngine::forContext(
            agencyId: $engineAgencyId,
        )->quote([
            'real_weight_kg' => max($realWeight, 0.001),
            'volumetric_weight_kg' => max($volWeight, 0.001),
            'declared_value' => collect($data['items'] ?? [])->sum(fn ($i) => (float) ($i['value'] ?? 0)),
        ]);

        $fromEngine = $engineQuote !== null ? (float) $engineQuote : null;
        if ($fromEngine !== null && $fromEngine > 0) {
            return $fromEngine;
        }

        // Legacy ShippingRateResolver removed — fallback to engine result
        return (float) ($fromEngine ?? 0);
    }

    /**
     * Poids facturable (kg) pour les tarifs au kilo, selon le paramètre d'application « billable_weight_rule ».
     *
     * Si le cubage volumétrique est absent (vol. = 0), on retombe sur le poids réel pour éviter une base vide.
     */
    protected function billableWeightFromRule(float $realWeight, float $volWeight, string $rule): float
    {
        $real = max(0.0, $realWeight);
        $vol = max(0.0, $volWeight);

        return match ($rule) {
            'min' => $vol <= 0.0 ? $real : min($real, $vol),
            'real' => $real,
            'volumetric' => $vol <= 0.0 ? $real : $vol,
            default => max($real, $vol),
        };
    }

    /**
     * Règle : max | min | real | volumetric (paramètre billable_weight_rule).
     */
    protected function normalizedBillableWeightRule(): string
    {
        $raw = trim((string) (Setting::getValue('billable_weight_rule', '') ?? ''));

        if ($raw !== '' && in_array($raw, ['max', 'min', 'real', 'volumetric'], true)) {
            return $raw;
        }

        return 'max';
    }

    protected function volumetricDivisorValue(): float
    {
        return max(1.0, (float) (Setting::getValue('volumetric_divisor', '5000') ?: 5000));
    }

    /**
     * Repli : tarif ligne (rate) → mode → réglage global.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveVolumetricDivisorForWizard(array $data): float
    {
        $global = $this->volumetricDivisorValue();
        $slrId = isset($data['ship_line_rate_id']) ? (int) $data['ship_line_rate_id'] : 0;
        if ($slrId > 0) {
            $slr = ShipLineRate::query()->with('shippingMode')->find($slrId);
            if ($slr && $slr->is_active) {
                $mode = $slr->shippingMode;
                if ($mode && $mode->volumetric_divisor) {
                    return max(1.0, (float) $mode->volumetric_divisor);
                }
            }
        }
        $mid = isset($data['shipping_mode_id']) ? (int) $data['shipping_mode_id'] : 0;
        if ($mid > 0) {
            $mode = ShippingMode::query()->find($mid);
            if ($mode && $mode->volumetric_divisor) {
                return max(1.0, (float) $mode->volumetric_divisor);
            }
        }

        return $global;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function tryShipLineRateBaseQuote(array $data, float $billableWeightKg, float $volumeM3): ?float
    {
        $slrId = isset($data['ship_line_rate_id']) ? (int) $data['ship_line_rate_id'] : 0;
        if ($slrId <= 0) {
            return null;
        }
        $originId = isset($data['origin_country_id']) ? (int) $data['origin_country_id'] : 0;
        $destId = isset($data['dest_country_id']) ? (int) $data['dest_country_id'] : 0;
        if ($originId <= 0 || $destId <= 0) {
            throw ValidationException::withMessages([
                'origin_country_id' => ['Pays de départ et d’arrivée requis avec un tarif ligne.'],
                'dest_country_id' => ['Pays de départ et d’arrivée requis avec un tarif ligne.'],
            ]);
        }
        $slr = ShipLineRate::query()
            ->where('is_active', true)
            ->whereKey($slrId)
            ->with(['shipLine.countryScopes', 'shippingMode'])
            ->first();
        if ($slr === null) {
            return null;
        }
        $line = $slr->shipLine;
        if ($line === null || ! $line->is_active) {
            return null;
        }
        $scopes = $line->relationLoaded('countryScopes') ? $line->countryScopes : $line->countryScopes()->get();
        $origins = $scopes->where('scope', 'origin')->pluck('country_id')->map(fn ($x) => (int) $x)->all();
        $dests = $scopes->where('scope', 'destination')->pluck('country_id')->map(fn ($x) => (int) $x)->all();
        if ($origins === [] || $dests === []) {
            throw ValidationException::withMessages([
                'ship_line_rate_id' => ['Ligne d’expédition incomplète (pays manquants).'],
            ]);
        }
        if (! in_array($originId, $origins, true) || ! in_array($destId, $dests, true)) {
            throw ValidationException::withMessages([
                'ship_line_rate_id' => ['Ce tarif ne correspond pas à la paire pays départ / arrivée.'],
            ]);
        }
        $smid = isset($data['shipping_mode_id']) ? (int) $data['shipping_mode_id'] : 0;
        if ($smid > 0 && $smid !== (int) $slr->shipping_mode_id) {
            throw ValidationException::withMessages([
                'shipping_mode_id' => ['Le mode d’expédition ne correspond pas au tarif ligne sélectionné.'],
            ]);
        }

        return $slr->computeBaseQuote($billableWeightKg, $volumeM3);
    }

    private function shipmentStatusAllowsDriverAssignment(ShipmentStatus $status): bool
    {
        return match ($status) {
            ShipmentStatus::PendingDropOff => true,
            ShipmentStatus::InTransit,
            ShipmentStatus::ArrivedAtDestination => true,
            default => false,
        };
    }
}

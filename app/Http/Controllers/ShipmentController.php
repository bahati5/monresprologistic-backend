<?php

namespace App\Http\Controllers;

use App\Models\ArticleCategory;
use App\Models\Country;
use App\Models\DeliveryTime;
use App\Models\Office;
use App\Models\PackagingType;
use App\Models\Recipient;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMode;
use App\Models\ShipLine;
use App\Models\Status;
use App\Models\TransportCompany;
use App\Models\User;
use App\Services\PricingEngine;
use App\Services\ShippingRateResolver;
use App\Services\ShipmentWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShipmentController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $user = $request->user();
        $sort = (string) $request->input('sort', 'created_desc');
        $q = Shipment::query()->with([
            'status',
            'sender',
            'recipient',
            'senderClient',
            'deliveryRecipient',
            'agency',
            'serviceType',
            'assignedDriver',
        ]);
        if ($sort === 'created_asc') {
            $q->orderBy('created_at');
        } else {
            $q->orderByDesc('created_at');
        }
        $this->scopeShipmentsFor($q, $user);

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $q->where(function ($w) use ($term) {
                $w->where('public_tracking', 'like', $term)
                    ->orWhereHas('sender', fn ($u) => $u->where('name', 'like', $term))
                    ->orWhereHas('recipient', fn ($u) => $u->where('name', 'like', $term));
            });
        }

        if ($request->filled('status_id')) {
            $q->where('status_id', (int) $request->input('status_id'));
        }

        if (! $user->hasRole('client')) {
            $tab = (string) $request->input('status_tab', 'all');
            if ($tab !== 'all') {
                $map = [
                    'preparation' => ['created', 'accepted', 'in_preparation'],
                    'transit' => ['collected', 'in_transit'],
                    'customs' => ['in_customs'],
                    'delivered' => ['arrived', 'out_for_delivery', 'delivered'],
                ];
                $codes = $map[$tab] ?? null;
                if ($codes !== null) {
                    $ids = Status::query()->whereIn('code', $codes)->pluck('id');
                    $q->whereIn('status_id', $ids);
                }
            }
        }

        if ($request->has('export') && $request->get('export') === 'csv') {
            return $this->exportCsv($q);
        }

        $sheetShipment = null;
        if (! $user->hasRole('client') && $request->filled('sheet')) {
            $sheet = Shipment::query()
                ->with(['status', 'sender', 'recipient', 'items', 'logs.user', 'agency', 'assignedDriver', 'serviceType'])
                ->whereKey((int) $request->input('sheet'))
                ->first();
            if ($sheet && $user->can('view', $sheet)) {
                $workflow = app(ShipmentWorkflowService::class);
                $officesById = $this->prefetchOfficesForShipment($sheet);
                $sheetShipment = [
                    'shipment' => $this->buildShipmentListRow($sheet, $officesById, null),
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
        $officeIds = $items
            ->map(fn (Shipment $s) => (int) (($s->service_options ?? [])['office_id'] ?? 0))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();
        $officesById = $officeIds->isEmpty()
            ? collect()
            : Office::query()->whereIn('id', $officeIds)->get()->keyBy('id');
        $recipientMatchMap = $this->bulkResolveRecipientsForShipments($items);

        $paginator->setCollection(
            $items->map(fn (Shipment $s) => $this->buildShipmentListRow($s, $officesById, $recipientMatchMap))
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
            $query->with(['status', 'sender', 'recipient', 'agency'])->chunk(200, function ($shipments) use ($handle) {
                foreach ($shipments as $s) {
                    $statusName = $s->status?->name;
                    if (is_array($statusName)) {
                        $statusName = $statusName['fr'] ?? $statusName['en'] ?? reset($statusName) ?? $s->status?->code ?? '';
                    }
                    fputcsv($handle, [
                        $s->public_tracking ?? '#'.$s->id,
                        $s->sender?->name ?? '',
                        $s->recipient?->name ?? '',
                        $statusName ?? '',
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
            ? \App\Models\Agency::where('is_active', true)->get(['id', 'name', 'code'])
            : \App\Models\Agency::where('id', $user->agency_id)->get(['id', 'name', 'code']);

        $drivers = $this->getDriversForUser($user);

        $trackingPrefix = \App\Models\Setting::getValue('tracking_prefix', 'MRP');
        $trackingNumberDigits = max(4, min(32, (int) (\App\Models\Setting::getValue('tracking_number_length', '8') ?: 8)));
        $volumetricDivisor = max(1.0, (float) (\App\Models\Setting::getValue('volumetric_divisor', '5000') ?: 5000));

        return response()->json([
            'users' => $usersQuery->get(['id', 'name', 'email']),
            'serviceTypes' => ServiceType::query()->where('is_active', true)->get(),
            'shippingModes' => ShippingMode::query()
                ->where('is_active', true)
                ->with(['deliveryTimes' => fn ($q) => $q->where('is_active', true)->select(['id', 'label', 'shipping_mode_id'])])
                ->orderBy('sort_order')
                ->get(['id', 'name']),
            'packagingTypes' => PackagingType::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'is_billable', 'unit_price']),
            'transportCompanies' => TransportCompany::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'offices' => Office::query()->where('is_active', true)
                ->where(function ($q) use ($user) {
                    $q->where('agency_id', $user->agency_id)->orWhereNull('agency_id');
                })
                ->orderBy('name')->get(['id', 'name', 'type']),
            'shipLines' => ShipLine::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'countries' => Country::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'code', 'iso2']),
            'articleCategories' => ArticleCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'agencies' => $agencies,
            'drivers' => $drivers,
            'trackingPrefix' => $trackingPrefix,
            'trackingNumberDigits' => $trackingNumberDigits,
            'volumetricDivisor' => $volumetricDivisor,
            'defaultAgencyId' => $user->agency_id,
            'defaultInsurancePct' => (string) (\App\Models\Setting::getValue('default_insurance_pct', '0') ?? '0'),
            'defaultCustomsDutyPct' => (string) (\App\Models\Setting::getValue('default_customs_duty_pct', '0') ?? '0'),
            'defaultTaxPct' => (string) (\App\Models\Setting::getValue('default_tax_pct', '0') ?? '0'),
        ]);
    }

    public function previewQuote(Request $request): JsonResponse
    {
        $this->authorize('create', Shipment::class);

        $data = $request->validate([
            'sender_client_id' => ['required', 'exists:crm_clients,id'],
            'recipient_id' => ['required', 'exists:recipients,id'],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'shipping_mode_id' => [
                'nullable',
                Rule::requiredIf(fn () => $request->filled('delivery_time_id')),
                'exists:shipping_modes,id',
            ],
            'delivery_time_id' => [
                'nullable',
                'integer',
                Rule::exists('delivery_times', 'id')->where(function ($q) use ($request) {
                    $mid = $request->input('shipping_mode_id');
                    if ($mid === null || $mid === '') {
                        return;
                    }
                    $q->where('shipping_mode_id', (int) $mid);
                }),
            ],
            'office_id' => ['nullable', 'exists:offices,id'],
            'packaging_type_id' => ['nullable', 'exists:packaging_types,id'],
            'transport_company_id' => ['nullable', 'exists:transport_companies,id'],
            'ship_line_id' => ['nullable', 'exists:ship_lines,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'items.*.length_cm' => ['nullable', 'numeric', 'min:0'],
            'items.*.width_cm' => ['nullable', 'numeric', 'min:0'],
            'items.*.height_cm' => ['nullable', 'numeric', 'min:0'],
            'items.*.value' => ['nullable', 'numeric', 'min:0'],
            'items.*.origin_country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'manual_fee' => ['nullable', 'numeric', 'min:0'],
            'service_options' => ['nullable', 'array'],
            'service_options.manual_fee_label' => ['nullable', 'string', 'max:255'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'declared_currency' => ['nullable', 'string', 'max:8'],
            'insurance_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'customs_duty_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'manual_pricing' => ['nullable', 'boolean'],
            'manual_price_per_kg' => ['nullable', 'numeric', 'min:0'],
            'fixed_fees' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = $request->user();
        $agencyId = (int) ($data['agency_id'] ?? $user->agency_id);
        if (! $user->canAccessAllAgencies()) {
            $agencyId = (int) $user->agency_id;
        }
        $payload = array_merge($data, ['agency_id' => $agencyId]);
        $pricing = $this->computeWizardPricing($payload, $user, $agencyId);
        $snap = $pricing['pricing_snapshot'];
        $currencyCode = (string) (\App\Models\Setting::getValue('currency', 'EUR') ?: 'EUR');

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
            'sender_client_id' => ['required', 'exists:crm_clients,id'],
            'recipient_id' => ['required', 'exists:recipients,id'],
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
            'shipping_mode_id' => [
                'nullable',
                Rule::requiredIf(fn () => $request->filled('delivery_time_id')),
                'exists:shipping_modes,id',
            ],
            'delivery_time_id' => [
                'nullable',
                'integer',
                Rule::exists('delivery_times', 'id')->where(function ($q) use ($request) {
                    $mid = $request->input('shipping_mode_id');
                    if ($mid === null || $mid === '') {
                        return;
                    }
                    $q->where('shipping_mode_id', (int) $mid);
                }),
            ],
            'office_id' => ['nullable', 'exists:offices,id'],
            'packaging_type_id' => ['nullable', 'exists:packaging_types,id'],
            'transport_company_id' => ['nullable', 'exists:transport_companies,id'],
            'ship_line_id' => ['nullable', 'exists:ship_lines,id'],
            'service_options' => ['nullable', 'array'],
            'service_options.manual_fee_label' => ['nullable', 'string', 'max:255'],
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
        ]);

        $user = $request->user();
        $isStaffCreation = $user->hasAnyRole(['admin', 'super_admin', 'employee']);
        $agencyId = (int) ($data['agency_id'] ?? $user->agency_id);
        if (! $user->canAccessAllAgencies()) {
            $agencyId = (int) $user->agency_id;
        } elseif (! empty($data['agency_id'])) {
            $ok = \App\Models\Agency::query()->where('is_active', true)->whereKey((int) $data['agency_id'])->exists();
            if (! $ok) {
                $agencyId = (int) $user->agency_id;
            }
        }

        // Staff (comptoir) → directement en préparation. Client (portail) → créée, à valider plus tard.
        $defaultStatusCode = $isStaffCreation ? 'in_preparation' : 'created';
        $defaultStatus = Status::query()->where('code', $defaultStatusCode)->first()
            ?? Status::query()->where('code', 'created')->first();
        $resolvedStatusId = $defaultStatus?->id;
        if ($resolvedStatusId === null) {
            $resolvedStatusId = Status::query()->where('is_active', true)->orderBy('sort_order')->value('id');
        }

        $shipment = DB::transaction(function () use ($data, $user, $resolvedStatusId, $agencyId, $isStaffCreation) {
            $pricing = $this->computeWizardPricing($data, $user, $agencyId);
            $serviceOptions = $pricing['service_options'];
            $pricingSnapshot = $pricing['pricing_snapshot'];
            $finalTotal = $pricing['final_total'];
            $realWeight = $pricing['real_weight'];
            $volWeight = $pricing['vol_weight'];
            $declaredValue = $pricing['declared_value_effective'];

            $crm = \App\Models\CrmClient::query()->findOrFail($data['sender_client_id']);
            $recipientRecord = \App\Models\Recipient::findOrFail($data['recipient_id']);

            if ($recipientRecord->crm_client_id !== $crm->id) {
                if ((int) $recipientRecord->user_id !== (int) ($crm->user_id ?? 0)) {
                    throw ValidationException::withMessages([
                        'recipient_id' => 'Ce destinataire n’appartient pas à l’expéditeur sélectionné.',
                    ]);
                }
            }

            $senderUserId = $crm->user_id;

            $shipment = Shipment::query()->create([
                'sender_client_id' => $crm->id,
                'sender_id' => $senderUserId,
                'recipient_id' => $senderUserId,
                'delivery_recipient_id' => $recipientRecord->id,
                'agency_id' => $agencyId,
                'status_id' => $resolvedStatusId,
                'service_type_id' => null,
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
                ShipmentItem::query()->create([
                    'shipment_id' => $shipment->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'weight_kg' => $item['weight_kg'] ?? null,
                    'length_cm' => $item['length_cm'] ?? null,
                    'width_cm' => $item['width_cm'] ?? null,
                    'height_cm' => $item['height_cm'] ?? null,
                    'value' => $item['value'] ?? null,
                    'origin_country_id' => $originCountryId,
                ]);
            }

            $shipment->logs()->create([
                'user_id' => $user->id,
                'status_id' => $resolvedStatusId,
                'title' => $isStaffCreation ? 'Expédition créée (en préparation)' : 'Expédition créée',
                'ip_address' => request()->ip(),
            ]);

            return $shipment;
        });

        return response()->json(['message' => 'Expédition créée.', 'id' => $shipment->id], 201);
    }

    public function assignDriver(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('update', $shipment);

        $data = $request->validate([
            'assigned_driver_id' => ['nullable', 'exists:users,id'],
        ]);

        $shipment->update([
            'assigned_driver_id' => $data['assigned_driver_id'] ?? null,
        ]);

        $shipment->logs()->create([
            'user_id' => $request->user()->id,
            'status_id' => $shipment->status_id,
            'title' => 'Chauffeur assigné / modifié',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Chauffeur mis à jour.']);
    }

    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('view', $shipment);

        $shipment->load([
            'status',
            'sender',
            'senderClient',
            'recipient',
            'deliveryRecipient',
            'items.originCountry',
            'logs.user',
            'currentHub',
            'serviceType',
            'assignedDriver',
            'preAlert.media',
        ]);
        $workflow = app(ShipmentWorkflowService::class);
        $workflowSteps = $workflow->buildStepsForShipment($shipment);
        $availableTransitions = $workflow->getAvailableTransitions($shipment);
        $drivers = $this->getDriversForUser($request->user());
        $officesById = $this->prefetchOfficesForShipment($shipment);

        $doc = \App\Support\ShipmentDocumentSettings::merged();
        $invoice = $shipment->invoices()->first();

        return response()->json([
            'shipment' => $this->buildShipmentListRow($shipment, $officesById, null),
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
                'decimals' => (int) (\App\Models\Setting::getValue('decimals', '2') ?: 2),
                'symbol_position' => (\App\Models\Setting::getValue('symbol_position', 'prefix') ?: 'prefix') === 'suffix' ? 'suffix' : 'prefix',
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

    public function acceptance(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('view', $shipment);

        $shipment->load(['status', 'sender', 'recipient', 'items', 'logs.user', 'currentHub', 'serviceType', 'assignedDriver']);
        $drivers = $this->getDriversForUser($request->user());

        return response()->json([
            'shipment' => $shipment,
            'drivers' => $drivers,
        ]);
    }

    public function accept(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('update', $shipment);

        $data = $request->validate([
            'assigned_driver_id' => ['nullable', 'exists:users,id'],
            'calculated_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $acceptedStatus = Status::query()->where('code', 'accepted')->first();
        if (! $acceptedStatus) {
            return back()->withErrors(['status' => 'Statut "acceptée" introuvable.']);
        }

        $shipment->update(array_filter([
            'assigned_driver_id' => $data['assigned_driver_id'] ?? null,
            'calculated_price' => $data['calculated_price'] ?? $shipment->calculated_price,
            'status_id' => $acceptedStatus->id,
        ]));

        $shipment->logs()->create([
            'user_id' => $request->user()->id,
            'status_id' => $acceptedStatus->id,
            'title' => 'Expédition acceptée',
            'description' => $data['notes'] ?? null,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Expédition acceptée.']);
    }

    public function updateStatus(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('update', $shipment);

        $data = $request->validate([
            'status_id' => ['required', 'exists:statuses,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $newStatus = Status::findOrFail($data['status_id']);
        $shipment->update(['status_id' => $newStatus->id]);

        $shipment->logs()->create([
            'user_id' => $request->user()->id,
            'status_id' => $newStatus->id,
            'title' => 'Changement de statut : '.($newStatus->name['fr'] ?? $newStatus->code),
            'description' => $data['notes'] ?? null,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Statut mis à jour.']);
    }

    public function deliver(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('update', $shipment);

        $data = $request->validate([
            'delivery_signature' => ['nullable', 'string', 'max:50000'],
            'delivery_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $deliveredStatus = Status::query()->where('code', 'delivered')->first();
        if (! $deliveredStatus) {
            return back()->withErrors(['status' => 'Statut "livrée" introuvable.']);
        }

        $shipment->update([
            'status_id' => $deliveredStatus->id,
            'delivery_signature' => $data['delivery_signature'] ?? null,
            'delivery_notes' => $data['delivery_notes'] ?? null,
        ]);

        $shipment->logs()->create([
            'user_id' => $request->user()->id,
            'status_id' => $deliveredStatus->id,
            'title' => 'Livraison effectuée',
            'description' => $data['delivery_notes'] ?? null,
            'meta' => $data['delivery_signature'] ? ['has_signature' => true] : null,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Livraison enregistrée.']);
    }

    public function recordPayment(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorize('update', $shipment);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'string', 'in:cash,mobile_money,bank_transfer,card,other'],
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

        $shipment->update([
            'amount_paid' => $newTotal,
            'payment_status' => $paymentStatus,
            'paid_at' => $paidAt,
        ]);

        $methodLabels = [
            'cash' => 'Espèces',
            'mobile_money' => 'Mobile Money',
            'bank_transfer' => 'Virement bancaire',
            'card' => 'Carte bancaire',
            'other' => 'Autre',
        ];

        $shipment->logs()->create([
            'user_id' => $request->user()->id,
            'status_id' => $shipment->status_id,
            'title' => 'Paiement enregistré : '.number_format($newAmount, 2).' '.($shipment->currency ?? 'USD').' ('.$methodLabels[$data['payment_method']].')',
            'description' => $data['notes'] ?? null,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Paiement enregistré avec succès.']);
    }

    protected function estimatedDelivery(Shipment $s): ?Carbon
    {
        $opts = $s->service_options ?? [];
        $days = max(1, (int) ($opts['estimated_delivery_days'] ?? 14));

        return $s->created_at?->copy()->addDays($days);
    }

    /**
     * @param  Collection<int, \App\Models\Office>  $officesById
     * @param  Collection<string, \App\Models\Recipient>|null  $recipientMatchMap clé "{sender_id}|{email}" ou "c{sender_client_id}|{email}"
     * @return array<string, mixed>
     */
    protected function buildShipmentListRow(Shipment $s, Collection $officesById, ?Collection $recipientMatchMap): array
    {
        $data = $s->toArray();
        $opts = $s->service_options ?? [];
        $officeId = isset($opts['office_id']) ? (int) $opts['office_id'] : null;
        $office = $officeId ? $officesById->get($officeId) : null;
        $recipientRow = null;
        if ($recipientMatchMap !== null && $s->relationLoaded('recipient') && $s->recipient?->email) {
            $email = $s->recipient->email;
            if ($s->sender_id) {
                $recipientRow = $recipientMatchMap->get($s->sender_id.'|'.$email);
            }
            if ($recipientRow === null && $s->sender_client_id) {
                $recipientRow = $recipientMatchMap->get('c'.$s->sender_client_id.'|'.$email);
            }
        }
        $recipientRow ??= $this->resolveDestinationRecipient($s);
        $data['corridor'] = [
            'origin_country' => $office?->country,
            'origin_city' => $office?->city,
            'origin_name' => $office?->name,
            'dest_country' => $recipientRow?->country,
            'dest_city' => $recipientRow?->city,
        ];
        $data['estimated_delivery_at'] = $this->estimatedDelivery($s)?->toIso8601String();

        // Champs attendus par le SPA (alias sur le modèle colonnes / relations)
        $data['tracking_number'] = $s->public_tracking;
        $data['sender_name'] = $s->sender?->name
            ?? $s->senderClient?->display_name
            ?? trim(($s->senderClient?->first_name ?? '').' '.($s->senderClient?->last_name ?? ''))
            ?: null;
        $data['recipient_name'] = $s->deliveryRecipient?->name ?? $s->recipient?->name;
        $data['recipient_city'] = $s->deliveryRecipient?->city ?? null;
        $smId = (int) ($opts['shipping_mode_id'] ?? 0);
        if ($smId > 0) {
            $mode = ShippingMode::query()->find($smId);
            $data['shipping_mode'] = $mode?->name;
        } else {
            $data['shipping_mode'] = $s->serviceType?->name;
        }
        $dtId = (int) ($opts['delivery_time_id'] ?? 0);
        if ($dtId > 0) {
            $dt = DeliveryTime::query()->find($dtId);
            $label = $dt?->label;
            if (is_array($label)) {
                $data['delivery_time'] = (string) ($label['fr'] ?? $label['en'] ?? reset($label) ?? '');
            } else {
                $data['delivery_time'] = $label ? (string) $label : null;
            }
        } else {
            $data['delivery_time'] = null;
        }
        $data['transport_company'] = isset($opts['transport_company_name']) ? (string) $opts['transport_company_name'] : null;
        $data['ship_line'] = isset($opts['ship_line_name']) ? (string) $opts['ship_line_name'] : null;
        $data['total'] = $s->calculated_price !== null ? (float) $s->calculated_price : null;

        return $data;
    }

    protected function resolveDestinationRecipient(Shipment $s): ?Recipient
    {
        if ($s->delivery_recipient_id) {
            return Recipient::query()->find($s->delivery_recipient_id);
        }

        $ru = $s->recipient;
        if (! $ru?->email) {
            return null;
        }

        if ($s->sender_client_id) {
            $byCrm = Recipient::query()
                ->where('crm_client_id', $s->sender_client_id)
                ->where('is_active', true)
                ->where(function ($q) use ($ru) {
                    $q->where('email', $ru->email);
                    if ($ru->name) {
                        $q->orWhere('name', $ru->name);
                    }
                })
                ->orderBy('id')
                ->first();
            if ($byCrm) {
                return $byCrm;
            }
        }

        if ($s->sender_id) {
            $bySender = Recipient::query()
                ->where('user_id', $s->sender_id)
                ->where('is_active', true)
                ->where(function ($q) use ($ru) {
                    $q->where('email', $ru->email);
                    if ($ru->name) {
                        $q->orWhere('name', $ru->name);
                    }
                })
                ->orderBy('id')
                ->first();

            if ($bySender) {
                return $bySender;
            }
        }

        return Recipient::query()
            ->where('is_active', true)
            ->where('email', $ru->email)
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     * @return Collection<string, Recipient>
     */
    protected function bulkResolveRecipientsForShipments(Collection $shipments): Collection
    {
        $map = collect();
        if ($shipments->isEmpty()) {
            return $map;
        }

        $senderIds = $shipments->pluck('sender_id')->unique()->filter()->values();
        $senderClientIds = $shipments->pluck('sender_client_id')->unique()->filter()->values();
        $emails = $shipments->map(fn (Shipment $s) => $s->recipient?->email)->filter()->unique()->values();
        if ($emails->isEmpty() || ($senderIds->isEmpty() && $senderClientIds->isEmpty())) {
            return $map;
        }

        $candidates = Recipient::query()
            ->whereIn('email', $emails)
            ->where('is_active', true)
            ->where(function ($q) use ($senderIds, $senderClientIds) {
                $added = false;
                if ($senderIds->isNotEmpty()) {
                    $q->whereIn('user_id', $senderIds);
                    $added = true;
                }
                if ($senderClientIds->isNotEmpty()) {
                    if ($added) {
                        $q->orWhereIn('crm_client_id', $senderClientIds);
                    } else {
                        $q->whereIn('crm_client_id', $senderClientIds);
                    }
                }
            })
            ->orderBy('id')
            ->get();

        foreach ($candidates as $r) {
            if ($r->user_id) {
                $key = $r->user_id.'|'.$r->email;
                if (! $map->has($key)) {
                    $map->put($key, $r);
                }
            }
            if ($r->crm_client_id) {
                $key = 'c'.$r->crm_client_id.'|'.$r->email;
                if (! $map->has($key)) {
                    $map->put($key, $r);
                }
            }
        }

        return $map;
    }

    /**
     * @return Collection<int, Office>
     */
    protected function prefetchOfficesForShipment(Shipment $s): Collection
    {
        $officeId = (int) (($s->service_options ?? [])['office_id'] ?? 0);
        if ($officeId <= 0) {
            return collect();
        }

        return Office::query()->where('id', $officeId)->get()->keyBy('id');
    }

    protected function getDriversForUser($user): \Illuminate\Support\Collection
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
    protected function computeWizardPricing(array $data, User $user, int $agencyId): array
    {
        $quotePayload = array_merge($data, ['agency_id' => $agencyId]);
        $divisor = $this->volumetricDivisorValue();

        $realWeight = collect($data['items'])->sum(fn ($i) => (float) ($i['weight_kg'] ?? 0) * (int) ($i['quantity'] ?? 1));
        $volWeight = collect($data['items'])->sum(function ($i) use ($divisor) {
            $l = (float) ($i['length_cm'] ?? 0);
            $w = (float) ($i['width_cm'] ?? 0);
            $h = (float) ($i['height_cm'] ?? 0);
            $qty = (int) ($i['quantity'] ?? 1);
            if ($l <= 0 || $w <= 0 || $h <= 0) {
                return 0;
            }

            return ($l * $w * $h / $divisor) * $qty;
        });

        $billableWeight = max($realWeight, $volWeight);
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
            $baseQuote = $this->quoteFromValidatedPayload($quotePayload, $user);
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

        $oid = isset($data['office_id']) && $data['office_id'] !== '' && $data['office_id'] !== null ? (int) $data['office_id'] : null;
        if ($oid !== null && $oid > 0) {
            $serviceOptions['office_id'] = $oid;
        }
        $smid = isset($data['shipping_mode_id']) && $data['shipping_mode_id'] !== '' && $data['shipping_mode_id'] !== null ? (int) $data['shipping_mode_id'] : null;
        if ($smid !== null && $smid > 0) {
            $serviceOptions['shipping_mode_id'] = $smid;
        }
        $dtid = isset($data['delivery_time_id']) && $data['delivery_time_id'] !== '' && $data['delivery_time_id'] !== null ? (int) $data['delivery_time_id'] : null;
        if ($dtid !== null && $dtid > 0) {
            $serviceOptions['delivery_time_id'] = $dtid;
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

        if (! empty($data['legal_declaration_accepted'])) {
            $serviceOptions['legal_declaration_accepted'] = true;
            $serviceOptions['legal_declaration_accepted_at'] = now()->toIso8601String();
        }

        $pricingSnapshot = [
            'real_weight_kg' => round($realWeight, 3),
            'volumetric_weight_kg' => round($volWeight, 3),
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
    protected function quoteFromValidatedPayload(array $data, User $user): float
    {
        $divisor = $this->volumetricDivisorValue();
        $agencyId = (int) ($data['agency_id'] ?? $user->agency_id);
        if (! $user->canAccessAllAgencies()) {
            $agencyId = (int) $user->agency_id;
        }

        $realWeight = collect($data['items'])->sum(fn ($i) => (float) ($i['weight_kg'] ?? 0) * (int) ($i['quantity'] ?? 1));
        $volWeight = collect($data['items'])->sum(function ($i) use ($divisor) {
            $l = (float) ($i['length_cm'] ?? 0);
            $w = (float) ($i['width_cm'] ?? 0);
            $h = (float) ($i['height_cm'] ?? 0);
            $qty = (int) ($i['quantity'] ?? 1);
            if ($l <= 0 || $w <= 0 || $h <= 0) {
                return 0;
            }

            return ($l * $w * $h / $divisor) * $qty;
        });

        $volumeM3 = collect($data['items'])->sum(function ($i) {
            $l = (float) ($i['length_cm'] ?? 0) / 100;
            $w = (float) ($i['width_cm'] ?? 0) / 100;
            $h = (float) ($i['height_cm'] ?? 0) / 100;
            $qty = (int) ($i['quantity'] ?? 1);
            if ($l <= 0 || $w <= 0 || $h <= 0) {
                return 0;
            }

            return $l * $w * $h * $qty;
        });

        $billableWeightKg = max($realWeight, $volWeight);

        $engineQuote = PricingEngine::forContext(
            agencyId: $agencyId,
            serviceTypeId: isset($data['service_type_id']) && $data['service_type_id'] !== '' && $data['service_type_id'] !== null
                ? (int) $data['service_type_id']
                : null
        )->quote([
            'real_weight_kg' => max($realWeight, 0.001),
            'volumetric_weight_kg' => max($volWeight, 0.001),
            'declared_value' => collect($data['items'])->sum(fn ($i) => (float) ($i['value'] ?? 0)),
        ]);

        $fromEngine = $engineQuote !== null ? (float) $engineQuote : null;
        if ($fromEngine !== null && $fromEngine > 0) {
            return $fromEngine;
        }

        $opts = (array) ($data['service_options'] ?? []);
        $shippingModeId = null;
        if (isset($data['shipping_mode_id']) && $data['shipping_mode_id'] !== '' && $data['shipping_mode_id'] !== null) {
            $shippingModeId = (int) $data['shipping_mode_id'];
        } elseif (isset($opts['shipping_mode_id']) && $opts['shipping_mode_id'] !== '' && $opts['shipping_mode_id'] !== null) {
            $shippingModeId = (int) $opts['shipping_mode_id'];
        }
        if ($shippingModeId === 0) {
            $shippingModeId = null;
        }

        $officeId = null;
        if (isset($data['office_id']) && $data['office_id'] !== '' && $data['office_id'] !== null) {
            $officeId = (int) $data['office_id'];
        } elseif (isset($opts['office_id']) && $opts['office_id'] !== '' && $opts['office_id'] !== null) {
            $officeId = (int) $opts['office_id'];
        }
        if ($officeId === 0) {
            $officeId = null;
        }

        $recipientId = isset($data['recipient_id']) ? (int) $data['recipient_id'] : null;
        $originCountryId = $this->resolveOriginCountryIdFromOffice($officeId);
        $destCountryId = $this->resolveDestinationCountryIdFromRecipient($recipientId);

        $resolver = app(ShippingRateResolver::class);
        $resolved = $resolver->resolve($agencyId, $originCountryId, $destCountryId, $shippingModeId);
        $rate = $resolved['rate'];

        if ($rate === null) {
            return (float) ($fromEngine ?? 0);
        }

        return $resolver->computeBaseQuote($rate, $billableWeightKg, $volumeM3);
    }

    protected function resolveOriginCountryIdFromOffice(?int $officeId): ?int
    {
        if ($officeId === null || $officeId <= 0) {
            return null;
        }

        $office = Office::query()->find($officeId);
        if ($office === null) {
            return null;
        }

        $raw = trim((string) ($office->country ?? ''));
        if ($raw === '') {
            return null;
        }

        return $this->lookupCountryIdByLabel($raw);
    }

    protected function resolveDestinationCountryIdFromRecipient(?int $recipientId): ?int
    {
        if ($recipientId === null || $recipientId <= 0) {
            return null;
        }

        $recipient = Recipient::query()->find($recipientId);
        if ($recipient === null) {
            return null;
        }

        if ($recipient->country_id) {
            return (int) $recipient->country_id;
        }

        $raw = trim((string) ($recipient->country ?? ''));

        return $raw !== '' ? $this->lookupCountryIdByLabel($raw) : null;
    }

    protected function lookupCountryIdByLabel(string $label): ?int
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }

        $upper = mb_strtoupper($label);
        if (strlen($label) === 2) {
            $id = Country::query()->where('iso2', $upper)->value('id');

            return $id ? (int) $id : null;
        }
        if (strlen($label) === 3) {
            $id = Country::query()
                ->where(function ($q) use ($upper) {
                    $q->where('iso3', $upper)->orWhere('code', $upper);
                })
                ->value('id');

            return $id ? (int) $id : null;
        }

        $lower = mb_strtolower($label);

        return Country::query()
            ->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(name) = ?', [$lower])
                    ->orWhereRaw('LOWER(COALESCE(native, \'\')) = ?', [$lower]);
            })
            ->value('id');
    }

    protected function volumetricDivisorValue(): float
    {
        return max(1.0, (float) (\App\Models\Setting::getValue('volumetric_divisor', '5000') ?: 5000));
    }
}

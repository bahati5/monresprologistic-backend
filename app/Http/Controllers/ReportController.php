<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Consolidation;
use App\Models\CrmClient;
use App\Models\Invoice;
use App\Models\PaymentProof;
use App\Models\Pickup;
use App\Models\PreAlert;
use App\Models\Shipment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    /**
     * Vue synthèse pour le hub rapports (données réelles, période paramétrable).
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $section = (string) $request->input('section', 'shipments');
        $period = (string) $request->input('period', 'month');
        [$from, $to] = $this->resolveReportPeriod($period);
        [$pFrom, $pTo] = $this->previousReportPeriod($from, $to);

        $payload = match ($section) {
            'shipments' => $this->buildShipmentsSummary($user, $from, $to, $pFrom, $pTo),
            'finance' => $this->buildFinanceSummary($user, $from, $to, $pFrom, $pTo),
            'pickups' => $this->buildPickupsSummary($user, $from, $to, $pFrom, $pTo),
            'clients' => $this->buildClientsSummary($user, $from, $to, $pFrom, $pTo),
            default => ['kpis' => [], 'stats' => []],
        };

        return response()->json($payload);
    }

    public function shipments(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Shipment::query()->with(['status', 'sender', 'recipient', 'agency']);
        $this->scopeShipmentsFor($query, $user);

        $this->applyFilters($query, $request, ['agency_id', 'status_id']);
        $this->applyDateRange($query, $request);
        if ($request->filled('client_id')) {
            $cid = (int) $request->input('client_id');
            $query->where(function ($q) use ($cid) {
                $q->where('sender_id', $cid)->orWhere('sender_client_id', $cid);
            });
        }
        $this->applyUserFilter($query, $request, 'driver_id', 'driver_id');

        $shipments = $query->latest()->paginate(50)->withQueryString();

        $totals = [
            'count' => (clone $query)->count(),
            'total_weight' => (clone $query)->sum('weight_kg'),
            'total_value' => (clone $query)->sum('calculated_price'),
        ];

        return response()->json([
            'shipments' => $shipments,
            'totals' => $totals,
            'filters' => $request->only(['agency_id', 'status_id', 'client_id', 'driver_id', 'employee_id', 'date_from', 'date_to']),
            'agencies' => $this->getAgencies($user),
            'statuses' => \App\Models\Status::orderBy('sort_order')->get(['id', 'code', 'name', 'color_hex']),
        ]);
    }

    public function pickups(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Pickup::query()->with(['status', 'client', 'agency', 'driver']);
        $this->scopeByAgency($query, $user);

        $this->applyFilters($query, $request, ['agency_id', 'status_id']);
        $this->applyDateRange($query, $request);
        if ($request->filled('driver_id')) {
            $query->where('assigned_driver_id', $request->input('driver_id'));
        }

        $pickups = $query->latest()->paginate(50)->withQueryString();

        $totals = [
            'count' => (clone $query)->count(),
            'completed' => (clone $query)->whereNotNull('completed_at')->count(),
        ];

        return response()->json([
            'pickups' => $pickups,
            'totals' => $totals,
            'filters' => $request->only(['agency_id', 'status_id', 'driver_id', 'date_from', 'date_to']),
            'agencies' => $this->getAgencies($user),
            'statuses' => \App\Models\Status::orderBy('sort_order')->get(['id', 'code', 'name', 'color_hex']),
        ]);
    }

    public function consolidations(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Consolidation::query()->with(['status', 'user', 'agency']);
        $this->scopeByAgency($query, $user);

        $this->applyFilters($query, $request, ['agency_id', 'status_id']);
        $this->applyDateRange($query, $request);

        $consolidations = $query->latest()->paginate(50)->withQueryString();

        $totals = [
            'count' => (clone $query)->count(),
            'total_weight' => (clone $query)->sum('total_weight_kg'),
        ];

        return response()->json([
            'consolidations' => $consolidations,
            'totals' => $totals,
            'filters' => $request->only(['agency_id', 'status_id', 'date_from', 'date_to']),
            'agencies' => $this->getAgencies($user),
            'statuses' => \App\Models\Status::orderBy('sort_order')->get(['id', 'code', 'name', 'color_hex']),
        ]);
    }

    public function packages(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = PreAlert::query()->with(['user', 'status', 'locker']);

        if (! $user->canAccessAllAgencies()) {
            $query->whereHas('user', fn ($q) => $q->where('agency_id', $user->agency_id));
        }

        $this->applyDateRange($query, $request);
        $this->applyFilters($query, $request, ['status_id']);

        $packages = $query->latest()->paginate(50)->withQueryString();

        $totals = [
            'count' => (clone $query)->count(),
        ];

        return response()->json([
            'packages' => $packages,
            'totals' => $totals,
            'filters' => $request->only(['agency_id', 'status_id', 'date_from', 'date_to']),
            'agencies' => $this->getAgencies($user),
            'statuses' => \App\Models\Status::orderBy('sort_order')->get(['id', 'code', 'name', 'color_hex']),
        ]);
    }

    public function finance(Request $request): JsonResponse
    {
        $user = $request->user();

        $invoiceQuery = Invoice::query()->with(['user', 'shipment']);
        if (! $user->canAccessAllAgencies()) {
            $invoiceQuery->whereHas('shipment', fn ($q) => $q->where('agency_id', $user->agency_id));
        }
        $this->applyDateRange($invoiceQuery, $request);

        $paymentQuery = PaymentProof::query()->with(['invoice.user'])->where('status', 'approved');
        if (! $user->canAccessAllAgencies()) {
            $paymentQuery->whereHas('invoice.shipment', fn ($q) => $q->where('agency_id', $user->agency_id));
        }
        $this->applyDateRange($paymentQuery, $request);

        $invoices = $invoiceQuery->latest()->paginate(50, ['*'], 'invoices_page')->withQueryString();

        $totals = [
            'total_invoiced' => (clone $invoiceQuery)->sum('amount'),
            'total_paid' => (clone $paymentQuery)->sum('amount'),
            'total_pending' => (clone $invoiceQuery)->where('status', 'pending')->sum('amount'),
            'invoices_count' => (clone $invoiceQuery)->count(),
            'payments_count' => (clone $paymentQuery)->count(),
        ];

        return response()->json([
            'invoices' => $invoices,
            'totals' => $totals,
            'filters' => $request->only(['agency_id', 'date_from', 'date_to']),
            'agencies' => $this->getAgencies($user),
        ]);
    }

    public function exportShipments(Request $request): StreamedResponse
    {
        $user = $request->user();
        $query = Shipment::query()->with(['status', 'sender', 'recipient', 'agency']);
        $this->scopeShipmentsFor($query, $user);
        $this->applyFilters($query, $request, ['agency_id', 'status_id']);
        $this->applyDateRange($query, $request);

        return $this->streamCsv('rapport-expeditions.csv', $query, function ($s) {
            return [
                $s->public_tracking,
                $s->sender?->name ?? '',
                $s->recipient?->name ?? '',
                $s->status?->code ?? '',
                $s->agency?->name ?? '',
                $s->weight_kg,
                $s->calculated_price,
                $s->currency,
                $s->created_at?->format('Y-m-d H:i'),
            ];
        }, ['Tracking', 'Expéditeur', 'Destinataire', 'Statut', 'Agence', 'Poids (kg)', 'Prix', 'Devise', 'Date']);
    }

    public function exportPickups(Request $request): StreamedResponse
    {
        $user = $request->user();
        $query = Pickup::query()->with(['status', 'client', 'agency', 'driver']);
        $this->scopeByAgency($query, $user);
        $this->applyFilters($query, $request, ['agency_id', 'status_id']);
        $this->applyDateRange($query, $request);

        return $this->streamCsv('rapport-pickups.csv', $query, function ($p) {
            return [
                $p->id,
                $p->client?->name ?? '',
                $p->driver?->name ?? '',
                $p->status?->code ?? '',
                $p->agency?->name ?? '',
                $p->address_text ?? '',
                $p->completed_at?->format('Y-m-d H:i') ?? '',
                $p->created_at?->format('Y-m-d H:i'),
            ];
        }, ['ID', 'Client', 'Chauffeur', 'Statut', 'Agence', 'Adresse', 'Complété le', 'Date']);
    }

    public function exportFinance(Request $request): StreamedResponse
    {
        $user = $request->user();
        $invoiceQuery = Invoice::query()->with(['user', 'shipment']);
        if (! $user->canAccessAllAgencies()) {
            $invoiceQuery->whereHas('shipment', fn ($q) => $q->where('agency_id', $user->agency_id));
        }
        $this->applyDateRange($invoiceQuery, $request);

        return $this->streamCsv('rapport-finance.csv', $invoiceQuery, function ($inv) {
            return [
                $inv->invoice_number,
                $inv->user?->name ?? '',
                $inv->amount,
                $inv->currency,
                $inv->status,
                $inv->due_at?->format('Y-m-d') ?? '',
                $inv->paid_at?->format('Y-m-d') ?? '',
                $inv->created_at?->format('Y-m-d H:i'),
            ];
        }, ['N° Facture', 'Client', 'Montant', 'Devise', 'Statut', 'Échéance', 'Payé le', 'Date']);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveReportPeriod(string $period): array
    {
        $to = now()->endOfDay();
        $from = match ($period) {
            'week' => now()->copy()->startOfWeek(),
            'quarter' => now()->copy()->startOfQuarter(),
            'year' => now()->copy()->startOfYear(),
            default => now()->copy()->startOfMonth(),
        };

        return [$from, $to];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function previousReportPeriod(Carbon $from, Carbon $to): array
    {
        $days = max(1, (int) $from->diffInDays($to) + 1);
        $pTo = $from->copy()->subDay()->endOfDay();
        $pFrom = $pTo->copy()->subDays($days - 1)->startOfDay();

        return [$pFrom, $pTo];
    }

    private function reportTrend(float|int $current, float|int $previous): ?float
    {
        $c = (float) $current;
        $p = (float) $previous;
        if ($p <= 0) {
            return $c > 0 ? 100.0 : null;
        }

        return round((($c - $p) / $p) * 100, 1);
    }

    /**
     * @return array{kpis: list<array{label: string, value: float|int|string, trend?: float|null}>, stats: array<string, mixed>}
     */
    private function buildShipmentsSummary(User $user, Carbon $from, Carbon $to, Carbon $pFrom, Carbon $pTo): array
    {
        $q = Shipment::query();
        $this->scopeShipmentsFor($q, $user);
        $cur = (clone $q)->whereBetween('created_at', [$from, $to])->count();
        $prev = (clone $q)->whereBetween('created_at', [$pFrom, $pTo])->count();
        $weightCur = (clone $q)->whereBetween('created_at', [$from, $to])->sum('weight_kg');
        $valueCur = (clone $q)->whereBetween('created_at', [$from, $to])->sum('calculated_price');

        return [
            'kpis' => [
                ['label' => 'Expeditions', 'value' => $cur, 'trend' => $this->reportTrend($cur, $prev)],
                ['label' => 'Poids total (kg)', 'value' => round((float) $weightCur, 2)],
                ['label' => 'Valeur declaree', 'value' => round((float) $valueCur, 2)],
                ['label' => 'Periode precedente', 'value' => $prev],
            ],
            'stats' => [
                'total_shipments' => $cur,
                'shipments_trend' => $this->reportTrend($cur, $prev),
                'total_weight' => (float) $weightCur,
            ],
        ];
    }

    /**
     * @return array{kpis: list<array<string, mixed>>, stats: array<string, mixed>}
     */
    private function buildFinanceSummary(User $user, Carbon $from, Carbon $to, Carbon $pFrom, Carbon $pTo): array
    {
        $inv = Invoice::query();
        if (! $user->canAccessAllAgencies()) {
            $inv->whereHas('shipment', fn ($s) => $s->where('agency_id', $user->agency_id));
        }

        $paidCur = (float) (clone $inv)->where('status', 'paid')->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');
        $paidPrev = (float) (clone $inv)->where('status', 'paid')->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$pFrom, $pTo])
            ->sum('amount');
        $pending = (float) (clone $inv)->whereIn('status', ['open', 'partial', 'pending'])->sum('amount');
        $invoicedCur = (float) (clone $inv)->whereBetween('created_at', [$from, $to])->sum('amount');

        return [
            'kpis' => [
                ['label' => 'Encaisse (periode)', 'value' => round($paidCur, 2), 'trend' => $this->reportTrend($paidCur, $paidPrev)],
                ['label' => 'Facture emise (periode)', 'value' => round($invoicedCur, 2)],
                ['label' => 'Creances ouvertes', 'value' => round($pending, 2)],
                ['label' => 'Encaisse periode prec.', 'value' => round($paidPrev, 2)],
            ],
            'stats' => [
                'total_revenue' => round($paidCur, 2),
                'revenue_trend' => $this->reportTrend($paidCur, $paidPrev),
                'active_clients' => null,
            ],
        ];
    }

    /**
     * @return array{kpis: list<array<string, mixed>>, stats: array<string, mixed>}
     */
    private function buildPickupsSummary(User $user, Carbon $from, Carbon $to, Carbon $pFrom, Carbon $pTo): array
    {
        $q = Pickup::query();
        $this->scopeByAgency($q, $user);
        $cur = (clone $q)->whereBetween('created_at', [$from, $to])->count();
        $prev = (clone $q)->whereBetween('created_at', [$pFrom, $pTo])->count();
        $done = (clone $q)->whereBetween('created_at', [$from, $to])->whereNotNull('completed_at')->count();

        return [
            'kpis' => [
                ['label' => 'Ramassages', 'value' => $cur, 'trend' => $this->reportTrend($cur, $prev)],
                ['label' => 'Termines', 'value' => $done],
                ['label' => 'Periode precedente', 'value' => $prev],
            ],
            'stats' => [
                'total_pickups' => $cur,
                'delivery_rate' => $cur > 0 ? round(100 * $done / $cur, 1) : null,
            ],
        ];
    }

    /**
     * @return array{kpis: list<array<string, mixed>>, stats: array<string, mixed>}
     */
    private function buildClientsSummary(User $user, Carbon $from, Carbon $to, Carbon $pFrom, Carbon $pTo): array
    {
        $q = CrmClient::query();
        if (! $user->canAccessAllAgencies()) {
            $q->where('agency_id', $user->agency_id);
        }

        $total = (clone $q)->count();
        $newCur = (clone $q)->whereBetween('created_at', [$from, $to])->count();
        $newPrev = (clone $q)->whereBetween('created_at', [$pFrom, $pTo])->count();
        $active = (clone $q)->where('is_active', true)->count();

        return [
            'kpis' => [
                ['label' => 'Clients actifs', 'value' => $active],
                ['label' => 'Nouveaux (periode)', 'value' => $newCur, 'trend' => $this->reportTrend($newCur, $newPrev)],
                ['label' => 'Total clients', 'value' => $total],
            ],
            'stats' => [
                'active_clients' => $active,
                'clients_trend' => $this->reportTrend($newCur, $newPrev),
            ],
        ];
    }

    // --- Helpers ---

    private function applyFilters($query, Request $request, array $columns): void
    {
        foreach ($columns as $col) {
            if ($request->filled($col)) {
                $query->where($col, $request->input($col));
            }
        }
    }

    private function applyDateRange($query, Request $request, string $column = 'created_at'): void
    {
        if ($request->filled('date_from')) {
            $query->whereDate($column, '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate($column, '<=', $request->input('date_to'));
        }
    }

    private function applyUserFilter($query, Request $request, string $column, string $paramName): void
    {
        if ($request->filled($paramName)) {
            $query->where($column, $request->input($paramName));
        }
    }

    private function getAgencies(User $user): \Illuminate\Support\Collection
    {
        if ($user->canAccessAllAgencies()) {
            return Agency::where('is_active', true)->get(['id', 'name']);
        }

        return Agency::where('id', $user->agency_id)->get(['id', 'name']);
    }

    private function streamCsv(string $filename, $query, callable $mapper, array $headers): StreamedResponse
    {
        return response()->streamDownload(function () use ($query, $mapper, $headers) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers, ';');

            $query->latest()->chunk(500, function ($rows) use ($handle, $mapper) {
                foreach ($rows as $row) {
                    fputcsv($handle, $mapper($row), ';');
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}

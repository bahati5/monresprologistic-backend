<?php

namespace App\Console\Commands;

use App\Enums\ShipmentStatus;
use App\Models\AssistedPurchase;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MonthlyReportCommand extends Command
{
    protected $signature = 'report:monthly {--month= : YYYY-MM format, defaults to previous month} {--dry-run : Show stats without sending}';

    protected $description = 'Generate and email the monthly operations report';

    public function handle(): int
    {
        $month = $this->option('month')
            ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
            : now()->subMonth()->startOfMonth();

        $end = $month->copy()->endOfMonth();

        $this->info("Generating report for {$month->format('F Y')}...");

        try {
            $stats = $this->buildStats($month, $end);

            if ($this->option('dry-run')) {
                $this->table(['Métrique', 'Valeur'], array_map(
                    fn ($k, $v) => [$k, is_array($v) ? json_encode($v) : $v],
                    array_keys($stats),
                    array_values($stats),
                ));
                return self::SUCCESS;
            }

            $pdf = Pdf::loadView('pdf.monthly-report', compact('stats'));
            $pdfContent = $pdf->output();

            if (empty($pdfContent)) {
                throw new \RuntimeException('PDF généré est vide.');
            }

        } catch (\Throwable $e) {
            $this->error("Failed to generate report: {$e->getMessage()}");
            Log::error("MonthlyReportCommand failed: {$e->getMessage()}", ['trace' => $e->getTraceAsString()]);

            // §21.12 — Alerter le super_admin en cas d'échec
            $this->notifyFailure($month->format('F Y'), $e->getMessage());

            return self::FAILURE;
        }

        $recipients = User::role(['super_admin', 'agency_admin'])
            ->whereNotNull('email')
            ->pluck('email')
            ->toArray();

        if (empty($recipients)) {
            $this->warn('No admin recipients found.');
            return self::SUCCESS;
        }

        $filename = "rapport-mensuel-{$month->format('Y-m')}.pdf";
        $sent = 0;
        $failed = 0;

        foreach ($recipients as $email) {
            try {
                Mail::raw(
                    "Bonjour,\n\nVeuillez trouver ci-joint le rapport mensuel pour {$stats['period']}.\n\nCordialement,\nMonrespro Logistic",
                    function ($message) use ($email, $filename, $pdfContent, $stats) {
                        $message->to($email)
                            ->subject("Rapport mensuel — {$stats['period']}")
                            ->attachData($pdfContent, $filename, ['mime' => 'application/pdf']);
                    }
                );
                $sent++;
            } catch (\Throwable $e) {
                $this->warn("Failed to send to {$email}: {$e->getMessage()}");
                Log::warning("MonthlyReport send failed to {$email}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Report sent to {$sent} recipient(s). Failed: {$failed}.");

        return self::SUCCESS;
    }

    private function buildStats(Carbon $month, Carbon $end): array
    {
        $prevMonth = $month->copy()->subMonth()->startOfMonth();
        $prevEnd = $prevMonth->copy()->endOfMonth();

        $shipmentsTotal = Shipment::query()->excludingDrafts()->whereBetween('created_at', [$month, $end])->count();
        $shipmentsPrev = Shipment::query()->excludingDrafts()->whereBetween('created_at', [$prevMonth, $prevEnd])->count();
        $shipmentsDelivered = Shipment::query()->excludingDrafts()->whereBetween('created_at', [$month, $end])->where('status', ShipmentStatus::Delivered)->count();

        $assistedTotal = AssistedPurchase::whereBetween('created_at', [$month, $end])->count();
        $assistedPrev = AssistedPurchase::whereBetween('created_at', [$prevMonth, $prevEnd])->count();

        $revenue = (float) Shipment::query()->excludingDrafts()->whereBetween('created_at', [$month, $end])->sum('calculated_price');
        $revenuePrev = (float) Shipment::query()->excludingDrafts()->whereBetween('created_at', [$prevMonth, $prevEnd])->sum('calculated_price');

        $refundsTotal = Refund::whereBetween('created_at', [$month, $end])->sum('amount');
        $refundsCount = Refund::whereBetween('created_at', [$month, $end])->count();

        $topClients = Shipment::query()
            ->excludingDrafts()
            ->selectRaw('creator_user_id, COUNT(*) as count, SUM(calculated_price) as revenue')
            ->whereBetween('created_at', [$month, $end])
            ->groupBy('creator_user_id')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'name' => User::find($r->creator_user_id)?->name ?? 'Inconnu',
                'count' => $r->count,
                'revenue' => round((float) $r->revenue, 2),
            ]);

        return [
            'period' => $month->format('F Y'),
            'generated_at' => now()->format('d/m/Y H:i'),
            'shipments_total' => $shipmentsTotal,
            'shipments_prev' => $shipmentsPrev,
            'shipments_change' => $shipmentsPrev > 0
                ? round(($shipmentsTotal - $shipmentsPrev) / $shipmentsPrev * 100, 1)
                : null,
            'shipments_delivered' => $shipmentsDelivered,
            'assisted_purchases' => $assistedTotal,
            'assisted_prev' => $assistedPrev,
            'revenue' => round($revenue, 2),
            'revenue_prev' => round($revenuePrev, 2),
            'revenue_change' => $revenuePrev > 0
                ? round(($revenue - $revenuePrev) / $revenuePrev * 100, 1)
                : null,
            'refunds_total' => round((float) $refundsTotal, 2),
            'refunds_count' => $refundsCount,
            'top_clients' => $topClients,
        ];
    }

    private function notifyFailure(string $period, string $error): void
    {
        try {
            $superAdmins = User::role('super_admin')->whereNotNull('email')->pluck('email');
            foreach ($superAdmins as $email) {
                Mail::raw(
                    "ALERTE : La génération du rapport mensuel pour {$period} a échoué.\n\nErreur : {$error}",
                    function ($msg) use ($email, $period) {
                        $msg->to($email)->subject("[ERREUR] Rapport mensuel {$period} — Échec de génération");
                    }
                );
            }
        } catch (\Throwable) {
            // Silencieux si l'email d'alerte échoue aussi
        }
    }
}

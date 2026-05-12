<?php

namespace App\Http\Controllers;

use App\Models\SavTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SavAnalyticsController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = $request->input('period', 'month');

        $start = match ($period) {
            'week' => now()->startOfWeek(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };

        $base = SavTicket::query()
            ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id));

        $periodBase = (clone $base)->where('created_at', '>=', $start);

        $totalCreated = (clone $periodBase)->count();
        $resolved = (clone $periodBase)->whereNotNull('resolved_at')->count();

        $slaRespected = (clone $periodBase)
            ->whereNotNull('first_response_at')
            ->whereNotNull('sla_deadline_at')
            ->whereRaw('first_response_at <= sla_deadline_at')
            ->count();
        $slaTotal = (clone $periodBase)->whereNotNull('first_response_at')->count();
        $slaRate = $slaTotal > 0 ? round(100 * $slaRespected / $slaTotal) : 0;

        $avgResolutionHours = (clone $periodBase)
            ->whereNotNull('resolved_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours')
            ->value('avg_hours') ?? 0;

        $byCategory = (clone $periodBase)
            ->select('category', DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->get();

        $byChannel = (clone $periodBase)
            ->select('channel', DB::raw('COUNT(*) as count'))
            ->groupBy('channel')
            ->get();

        $byPriority = (clone $periodBase)
            ->select('priority', DB::raw('COUNT(*) as count'))
            ->groupBy('priority')
            ->get();

        $byAgent = (clone $periodBase)
            ->whereNotNull('assigned_to')
            ->join('users', 'users.id', '=', 'sav_tickets.assigned_to')
            ->select(
                'users.name as agent_name',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN sav_tickets.resolved_at IS NOT NULL THEN 1 ELSE 0 END) as resolved'),
                DB::raw('AVG(CASE WHEN sav_tickets.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, sav_tickets.created_at, sav_tickets.resolved_at) END) as avg_hours'),
            )
            ->groupBy('users.id', 'users.name')
            ->get();

        $slaPriorityBreakdown = [];
        foreach (['urgent', 'normal', 'low'] as $prio) {
            $prioBase = (clone $periodBase)->where('priority', $prio);
            $prioSlaOk = (clone $prioBase)->whereNotNull('first_response_at')->whereNotNull('sla_deadline_at')->whereRaw('first_response_at <= sla_deadline_at')->count();
            $prioSlaTotal = (clone $prioBase)->whereNotNull('first_response_at')->count();
            $slaPriorityBreakdown[] = [
                'priority' => $prio,
                'target' => match ($prio) { 'urgent' => '< 2h', 'normal' => '< 4h', default => '< 24h' },
                'respected' => $prioSlaOk,
                'total' => $prioSlaTotal,
                'rate' => $prioSlaTotal > 0 ? round(100 * $prioSlaOk / $prioSlaTotal) : 0,
            ];
        }

        return response()->json([
            'kpis' => [
                'total_created' => $totalCreated,
                'resolved' => $resolved,
                'resolved_rate' => $totalCreated > 0 ? round(100 * $resolved / $totalCreated) : 0,
                'sla_rate' => $slaRate,
                'avg_resolution_hours' => round($avgResolutionHours, 1),
            ],
            'by_category' => $byCategory,
            'by_channel' => $byChannel,
            'by_priority' => $byPriority,
            'by_agent' => $byAgent,
            'sla_by_priority' => $slaPriorityBreakdown,
        ]);
    }
}

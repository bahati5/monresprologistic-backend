<?php

namespace App\Services;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Illuminate\Support\Collection;

class ShipmentWorkflowService
{
    /**
     * Étapes affichées : pré-alerte = en attente dépôt → hub → … ; comptoir = brouillon → hub → … (sans étape pré-alerte).
     *
     * @return list<ShipmentStatus>
     */
    public function displayFlowFor(Shipment $shipment): array
    {
        if ($shipment->pre_alert_id) {
            return [
                ShipmentStatus::PendingDropOff,
                ShipmentStatus::ReceivedAtHub,
                ShipmentStatus::ReadyForDispatch,
                ShipmentStatus::InTransit,
                ShipmentStatus::ArrivedAtDestination,
                ShipmentStatus::Delivered,
            ];
        }

        return [
            ShipmentStatus::Draft,
            ShipmentStatus::ReceivedAtHub,
            ShipmentStatus::ReadyForDispatch,
            ShipmentStatus::InTransit,
            ShipmentStatus::ArrivedAtDestination,
            ShipmentStatus::Delivered,
        ];
    }

    /**
     * Étapes pour le suivi visuel (ordre métier unifié).
     *
     * @return array<int, array{code: string, label: string, color?: string, completed: bool, current: bool, date?: string|null}>
     */
    public function buildStepsForShipment(Shipment $shipment): array
    {
        $logs = $shipment->logs()->orderBy('created_at')->get();
        $current = $shipment->status ?? ShipmentStatus::Draft;

        $flow = $this->displayFlowFor($shipment);
        $flowValues = array_map(fn (ShipmentStatus $s) => $s->value, $flow);
        if (! in_array($current->value, $flowValues, true) && $current !== ShipmentStatus::Cancelled) {
            $flow = ShipmentStatus::orderedFlow();
        }

        $completedByLog = [];
        foreach ($logs as $l) {
            if ($l->status instanceof ShipmentStatus) {
                $completedByLog[$l->status->value] = $l->created_at;
            }
        }

        $orderValues = array_map(fn (ShipmentStatus $s) => $s->value, $flow);
        $curRank = array_search($current->value, $orderValues, true);

        $steps = [];
        foreach ($flow as $step) {
            $code = $step->value;
            $stepRank = array_search($code, $orderValues, true);
            $completed = $curRank !== false && $stepRank !== false && $stepRank < $curRank;
            $isCurrent = $current->value === $code;
            $date = $completedByLog[$code] ?? null;

            $steps[] = [
                'code' => $code,
                'label' => $step->label(),
                'color' => $this->colorFor($step),
                'completed' => $completed,
                'current' => $isCurrent,
                'date' => $date ? $date->format('Y-m-d H:i:s') : null,
            ];
        }

        if ($current === ShipmentStatus::Cancelled) {
            $steps[] = [
                'code' => ShipmentStatus::Cancelled->value,
                'label' => ShipmentStatus::Cancelled->label(),
                'color' => '#ef4444',
                'completed' => true,
                'current' => true,
                'date' => $logs->last()?->created_at?->format('Y-m-d H:i:s'),
            ];
        }

        return $steps;
    }

    public function colorHexForStatus(ShipmentStatus $s): string
    {
        return $this->colorFor($s);
    }

    private function colorFor(ShipmentStatus $s): string
    {
        return match ($s) {
            ShipmentStatus::Draft => '#64748b',
            ShipmentStatus::PendingDropOff => '#0ea5e9',
            ShipmentStatus::ReceivedAtHub => '#14b8a6',
            ShipmentStatus::ReadyForDispatch => '#f59e0b',
            ShipmentStatus::InTransit => '#8b5cf6',
            ShipmentStatus::ArrivedAtDestination => '#06b6d4',
            ShipmentStatus::Delivered => '#10b981',
            ShipmentStatus::Cancelled => '#ef4444',
        };
    }

    /**
     * @return Collection<int, array{code: string, label: string}>
     */
    public function getAvailableTransitions(Shipment $shipment): Collection
    {
        $current = $shipment->status ?? ShipmentStatus::Draft;
        $next = collect($current->allowedNext());

        // Flux comptoir : pas de passage par « en attente de dépôt ».
        if ($current === ShipmentStatus::Draft && ! $shipment->pre_alert_id) {
            $next = $next->reject(fn (ShipmentStatus $s) => $s === ShipmentStatus::PendingDropOff)->values();
        }

        return $next->map(fn (ShipmentStatus $s) => [
            'code' => $s->value,
            'label' => $s->label(),
        ]);
    }
}

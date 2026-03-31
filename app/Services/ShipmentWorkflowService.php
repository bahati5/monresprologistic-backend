<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\Status;
use Illuminate\Support\Collection;

class ShipmentWorkflowService
{
    /** @var array<string, string> Map status code to label */
    protected array $stepLabels = [
        'created' => 'Créée',
        'accepted' => 'Acceptée',
        'in_preparation' => 'En préparation',
        'collected' => 'Collectée',
        'in_transit' => 'En transit',
        'in_customs' => 'En douane',
        'arrived' => 'Arrivée',
        'out_for_delivery' => 'En livraison',
        'delivered' => 'Livrée',
        'cancelled' => 'Annulée',
    ];

    /**
     * Build workflow steps for display based on shipment logs and current status.
     *
     * @return array<int, array{code: string, label: string, color?: string, completed: bool, current: bool, date?: string}>
     */
    public function buildStepsForShipment(Shipment $shipment): array
    {
        $logs = $shipment->logs()->with('status')->orderBy('created_at')->get();
        $currentStatus = $shipment->status;

        $orderedCodes = ['created', 'accepted', 'in_preparation', 'collected', 'in_transit', 'in_customs', 'arrived', 'out_for_delivery', 'delivered'];
        $completedByLog = [];
        foreach ($logs as $l) {
            if ($l->status_id && $l->status) {
                $completedByLog[$l->status->code] = $l->created_at;
            }
        }

        $steps = [];
        foreach ($orderedCodes as $code) {
            $status = Status::query()->where('code', $code)->first();
            if (! $status) {
                continue;
            }
            $completed = isset($completedByLog[$code]) || ($currentStatus && $this->isAfterOrEqual($code, $currentStatus->code, $orderedCodes));
            $current = $currentStatus && $currentStatus->code === $code;
            $date = $completedByLog[$code] ?? null;

            $steps[] = [
                'code' => $code,
                'label' => is_array($status->name) ? ($status->name['fr'] ?? $status->name['en'] ?? reset($status->name) ?? $code) : (string) $status->name,
                'color' => $status->color_hex ?? null,
                'completed' => $completed,
                'current' => $current,
                'date' => $date ? (is_string($date) ? $date : $date->format('Y-m-d H:i:s')) : null,
            ];
        }

        if ($currentStatus && $currentStatus->code === 'cancelled') {
            $steps[] = [
                'code' => 'cancelled',
                'label' => $this->stepLabels['cancelled'] ?? 'Annulée',
                'color' => $currentStatus->color_hex ?? '#ef4444',
                'completed' => true,
                'current' => true,
                'date' => $logs->last()?->created_at?->format('Y-m-d H:i:s'),
            ];
        }

        return array_values($steps);
    }

    protected function isAfterOrEqual(string $code, string $currentCode, array $ordered): bool
    {
        $idx = array_search($code, $ordered, true);
        $curIdx = array_search($currentCode, $ordered, true);

        return $idx !== false && $curIdx !== false && $curIdx > $idx;
    }

    /** @var array<string, string[]> Fallback transitions when status_transitions table is empty */
    protected array $fallbackTransitions = [
        'created' => ['accepted', 'cancelled'],
        'accepted' => ['in_preparation', 'cancelled'],
        'in_preparation' => ['collected', 'cancelled'],
        'collected' => ['in_transit'],
        'in_transit' => ['in_customs', 'arrived'],
        'in_customs' => ['arrived'],
        'arrived' => ['out_for_delivery'],
        'out_for_delivery' => ['delivered'],
    ];

    /**
     * Get statuses that can be transitioned to from the current shipment status.
     *
     * @return \Illuminate\Support\Collection<int, Status>
     */
    public function getAvailableTransitions(Shipment $shipment): \Illuminate\Support\Collection
    {
        $current = $shipment->status_id;
        $currentCode = $shipment->status?->code;

        $ids = \DB::table('status_transitions')
            ->where('from_status_id', $current)
            ->pluck('to_status_id');

        if ($ids->isEmpty() && $currentCode && isset($this->fallbackTransitions[$currentCode])) {
            return Status::query()
                ->whereIn('code', $this->fallbackTransitions[$currentCode])
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        }

        if ($ids->isEmpty()) {
            if (! $current) {
                return Status::query()->whereIn('code', ['accepted', 'cancelled'])->orderBy('sort_order')->get();
            }

            return collect();
        }

        return Status::query()->whereIn('id', $ids)->where('is_active', true)->orderBy('sort_order')->get();
    }
}

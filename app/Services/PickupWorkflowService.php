<?php

namespace App\Services;

use App\Models\Pickup;
use App\Models\Status;
use Illuminate\Support\Collection;

class PickupWorkflowService
{
    protected array $stepCodes = ['created', 'pickup_accepted', 'pickup_driver_assigned', 'pickup_en_route', 'pickup_collected', 'pickup_at_hub'];

    public function buildStepsForPickup(Pickup $pickup): array
    {
        $current = $pickup->status;
        $steps = [];
        foreach ($this->stepCodes as $code) {
            $status = Status::query()->where('code', $code)->first();
            if (! $status) {
                continue;
            }
            $label = is_array($status->name) ? ($status->name['fr'] ?? $status->name['en'] ?? $code) : (string) $status->name;
            $currentCode = $current?->code;
            $idx = array_search($code, $this->stepCodes, true);
            $curIdx = $currentCode ? array_search($currentCode, $this->stepCodes, true) : -1;
            $completed = $curIdx !== false && $idx !== false && $curIdx > $idx;
            $currentStep = $currentCode === $code;

            $steps[] = [
                'code' => $code,
                'label' => $label,
                'color' => $status->color_hex ?? null,
                'completed' => $completed,
                'current' => $currentStep,
                'date' => null,
            ];
        }

        return $steps;
    }

    public function getAvailableTransitions(Pickup $pickup): Collection
    {
        $current = $pickup->status_id;
        if (! $current) {
            return Status::query()->where('code', 'pickup_accepted')->orderBy('sort_order')->get();
        }

        $ids = \DB::table('status_transitions')
            ->where('from_status_id', $current)
            ->pluck('to_status_id');

        if ($ids->isEmpty()) {
            $currentCode = $pickup->status?->code;
            $idx = array_search($currentCode, $this->stepCodes, true);
            $nextCodes = $idx !== false && $idx < count($this->stepCodes) - 1
                ? [$this->stepCodes[$idx + 1]]
                : [];

            return Status::query()
                ->whereIn('code', $nextCodes)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        }

        return Status::query()->whereIn('id', $ids)->where('is_active', true)->orderBy('sort_order')->get();
    }
}

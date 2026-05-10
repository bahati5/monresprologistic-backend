<?php

namespace App\Jobs;

use App\Services\Integrations\FreshsalesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncToFreshsalesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $action,
        public readonly array $data,
    ) {}

    public function handle(FreshsalesService $freshsales): void
    {
        if (! $freshsales->isEnabled()) {
            return;
        }

        match ($this->action) {
            'upsert_contact' => $freshsales->createOrUpdateContact($this->data),
            'create_contact' => $freshsales->createOrUpdateContact($this->data),
            'create_deal' => $freshsales->createDeal($this->data),
            'update_deal' => $freshsales->updateDeal($this->data['deal_id'] ?? 0, $this->data),
            'close_deal_won' => $freshsales->closeDealWon($this->data),
            'create_ticket' => $freshsales->createTicket($this->data),
            'close_ticket' => $freshsales->closeTicket($this->data),
            default => null,
        };
    }
}

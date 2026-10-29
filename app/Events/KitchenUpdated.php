<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Disiarkan lewat Reverb di LAN outlet ke layar Rasentra Kitchen. Bila WebSocket gagal,
 * layar mengambil pembaruan lewat polling GET /api/v1/kitchen/{station}.
 */
class KitchenUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $outletId,
        public string $station,
        public string $ticketId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("outlet.{$this->outletId}.kitchen.{$this->station}")];
    }
}

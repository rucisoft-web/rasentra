<?php

namespace App\Jobs;

use App\Domain\Sync\OutboxDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchOutbox implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 60;

    public function handle(OutboxDispatcher $dispatcher): void
    {
        $dispatcher->dispatch();
    }
}

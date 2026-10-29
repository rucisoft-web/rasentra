<?php

use App\Domain\Integration\Sap\SapPostingService;
use App\Domain\Integration\Xml\NmexmlImporter;
use App\Jobs\DispatchOutbox;
use App\Models\InboxEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Server outlet: kirim outbox ke Rasentra Central setiap 5 detik.
Schedule::job(new DispatchOutbox)->everyFiveSeconds()->when(fn () => config('rasentra.profile') === 'outlet');

// Rasentra Central: posting penjualan yang sudah diterima ke SAP B1.
Schedule::command('rasentra:post-sap')->everyMinute()->withoutOverlapping()->when(fn () => config('rasentra.profile') === 'central');

Artisan::command('rasentra:post-sap {--limit=200}', function (SapPostingService $sap) {
    $events = InboxEvent::where('event_type', 'order.finalized')->where('status', 'accepted')->orderBy('id')->limit((int) $this->option('limit'))->get();
    foreach ($events as $event) {
        $result = $sap->postSale($event->payload);
        if ($result['status'] === 'posted') {
            $event->update(['status' => 'processed']);
        }
        $this->line("{$event->entity_id}: {$result['status']}");
    }
})->purpose('Posting penjualan dari inbox ke SAP Business One');

Artisan::command('rasentra:import-xml {file} {kind : MSI atau MSR}', function (NmexmlImporter $importer) {
    $file = $this->argument('file');
    $kind = strtoupper($this->argument('kind'));
    if (! in_array($kind, ['MSI', 'MSR'], true) || ! is_readable($file)) {
        $this->error('File tidak terbaca atau jenis harus MSI/MSR.');

        return 1;
    }
    $batch = $importer->import(file_get_contents($file), basename($file), $kind);
    $this->table(['kunci', 'nilai'], collect($batch->summary)->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->values());
})->purpose('Impor file NMEXML MSI/MSR ke staging');

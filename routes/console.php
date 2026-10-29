<?php

use App\Domain\Core\SalesProjector;
use App\Domain\Integration\Sap\SapPostingService;
use App\Domain\Integration\Xml\NmexmlImporter;
use App\Jobs\DispatchOutbox;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Rasentra Lokal (server outlet): kirim outbox ke Rasentra Central setiap 5 detik.
Schedule::job(new DispatchOutbox)->everyFiveSeconds()->when(fn () => config('rasentra.profile') === 'lokal');

// Rasentra Central: posting penjualan yang sudah diterima ke SAP B1.
Schedule::command('rasentra:post-sap')->everyMinute()->withoutOverlapping()->when(fn () => config('rasentra.profile') === 'central');

Artisan::command('rasentra:post-sap {--limit=200}', function (SapPostingService $sap) {
    foreach ($sap->postInbox((int) $this->option('limit')) as $status => $count) {
        $this->line("{$status}: {$count}");
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

Artisan::command('rasentra:rebuild-sales', function (SalesProjector $projector) {
    $this->info('Proyeksi penjualan dibangun ulang dari '.$projector->rebuild().' event inbox.');
})->purpose('Bangun ulang tabel laporan penjualan Rasentra Core dari inbox');

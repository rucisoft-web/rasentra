<?php

use App\Domain\Core\SalesProjector;
use App\Domain\Core\StockProjector;
use App\Domain\Integration\Sap\SapHealth;
use App\Domain\Integration\Sap\SapPostingService;
use App\Domain\Integration\Xml\NmexmlImporter;
use App\Domain\Lokal\Backup;
use App\Domain\Sync\ChangePuller;
use App\Domain\Sync\DayCloser;
use App\Domain\Sync\DayReconciler;
use App\Domain\Sync\HeartbeatSender;
use App\Jobs\DispatchOutbox;
use App\Models\DailyReconciliation;
use App\Models\Outlet;
use App\Support\SystemState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

$lokal = fn () => config('rasentra.profile') === 'lokal';
$central = fn () => config('rasentra.profile') === 'central';

// Rasentra Lokal (server outlet): kirim outbox ke Rasentra Central setiap 5 detik, tarik publikasi
// harga/promo/resep/master setiap 30 detik, heartbeat setiap menit, backup pukul 02.00 dan
// tutup hari kemarin pukul 04.00.
Schedule::job(new DispatchOutbox)->everyFiveSeconds()->when($lokal);
Schedule::command('rasentra:pull')->everyThirtySeconds()->withoutOverlapping()->when($lokal);
Schedule::command('rasentra:heartbeat')->everyMinute()->when($lokal);
Schedule::command('rasentra:close-day')->dailyAt('04:00')->timezone(config('rasentra.display_timezone'))->when($lokal);
Schedule::command('rasentra:backup')->dailyAt('02:00')->timezone(config('rasentra.display_timezone'))->when($lokal);

// Rasentra Central: posting penjualan yang sudah diterima ke SAP B1 dan cek koneksi tiap instance.
Schedule::command('rasentra:post-sap')->everyMinute()->withoutOverlapping()->when($central);
Schedule::command('rasentra:sap-check')->everyFifteenMinutes()->when($central);
Schedule::command('rasentra:reconcile')->hourly()->when($central);

Artisan::command('rasentra:post-sap {--limit=200}', function (SapPostingService $sap) {
    $summary = $sap->postInbox((int) $this->option('limit'));
    SystemState::ran('post-sap', $summary);
    foreach ($summary as $status => $count) {
        $this->line("{$status}: {$count}");
    }
})->purpose('Posting penjualan dari inbox ke SAP Business One');

Artisan::command('rasentra:sap-check', function (SapHealth $health) {
    $rows = collect($health->checkAll())->map(fn ($r, $db) => [$db, $r['ok'] ? 'OK' : 'GAGAL', $r['mode'] ?? '-', $r['ms'].' ms', $r['error'] ?? '']);
    SystemState::ran('sap-check', ['ok' => $rows->where(1, 'OK')->count(), 'failed' => $rows->where(1, 'GAGAL')->count()]);
    $this->table(['instance', 'status', 'mode', 'waktu', 'error'], $rows->values());

    return $rows->contains(1, 'GAGAL') ? 1 : 0;
})->purpose('Cek koneksi Service Layer setiap instance SAP B1');

Artisan::command('rasentra:reconcile {date? : tanggal bisnis Y-m-d, kosong = semua yang belum cocok}', function (DayReconciler $reconciler) {
    $rows = DailyReconciliation::when($this->argument('date'), fn ($q, $d) => $q->whereDate('business_date', $d), fn ($q) => $q->where('status', '!=', 'matched'))->get();
    $rows->each(fn ($r) => $reconciler->check($r->outlet_id, $r->business_date->toDateString()));
    SystemState::ran('reconcile', ['checked' => $rows->count()]);
    $this->info("{$rows->count()} rekonsiliasi diperiksa ulang.");
})->purpose('Periksa ulang rekonsiliasi tutup hari terhadap penjualan yang sudah diterima');

Artisan::command('rasentra:pull', function (ChangePuller $puller) {
    $result = $puller->pull();
    $this->line("diterapkan: {$result['applied']}, cursor: {$result['cursor']}".(isset($result['error']) ? ", error: {$result['error']}" : ''));

    return isset($result['error']) ? 1 : 0;
})->purpose('Rasentra Lokal: tarik dan terapkan publikasi dari Rasentra Central');

Artisan::command('rasentra:heartbeat', function (HeartbeatSender $sender) {
    $result = $sender->send();
    $this->line($result['ok'] ? "OK, selisih jam {$result['clock_skew_seconds']} detik" : "GAGAL: {$result['error']}");

    return $result['ok'] ? 0 : 1;
})->purpose('Rasentra Lokal: kirim kondisi server outlet ke Rasentra Central');

Artisan::command('rasentra:bootstrap-lokal', function (ChangePuller $puller) {
    $result = $puller->bootstrap();
    $this->table(['tahap', 'diterapkan', 'cursor', 'error'], collect($result)->map(fn ($r, $k) => [$k, $r['applied'], $r['cursor'], $r['error'] ?? ''])->values());

    return isset($result['snapshot']['error']) || isset($result['pull']['error']) ? 1 : 0;
})->purpose('Rasentra Lokal baru: ambil snapshot master, harga, promo dan resep dari Rasentra Central');

Artisan::command('rasentra:close-day {date? : tanggal bisnis Y-m-d, kosong = kemarin menurut zona waktu outlet} {--outlet=}', function (DayCloser $closer) {
    $code = $this->option('outlet') ?: config('rasentra.outlet_code');
    $outlet = Outlet::where('code', $code)->first();
    if ($outlet === null) {
        $this->error('Outlet tidak ditemukan. Isi RASENTRA_OUTLET_CODE atau --outlet.');

        return 1;
    }
    $date = $this->argument('date') ?: Carbon::now($outlet->timezone)->subDay()->toDateString();
    $payload = $closer->close($outlet, $date)->payload;
    $this->info("Tutup hari {$outlet->code} {$date}: {$payload['orders']} struk, bersih {$payload['net_amount']}, selisih kas {$payload['cash_difference']}, shift masih buka {$payload['open_shifts']}.");
})->purpose('Rasentra Lokal: tutup hari bisnis dan kirim ringkasannya ke Rasentra Central');

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

Artisan::command('rasentra:rebuild-sales', function (SalesProjector $projector, StockProjector $stock) {
    $this->info('Proyeksi penjualan dibangun ulang dari '.$projector->rebuild().' event inbox.');
    $this->info('Proyeksi penyesuaian stok dibangun ulang dari '.$stock->rebuild().' event inbox.');
})->purpose('Bangun ulang tabel laporan penjualan dan stok Rasentra Core dari inbox');

Artisan::command('rasentra:backup', function (Backup $backup) {
    $result = $backup->run();
    $this->info("Backup {$result['file']} ({$result['bytes']} byte) di storage/app/backups.");
})->purpose('Rasentra Lokal: cadangkan database outlet');

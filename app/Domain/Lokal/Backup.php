<?php

namespace App\Domain\Lokal;

use App\Support\SystemState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Rasentra Lokal: cadangan harian database outlet ke storage/app/backups (14 terakhir disimpan).
 * MySQL memakai mysqldump --single-transaction sehingga kasir tetap bisa bertransaksi.
 */
final class Backup
{
    public const KEEP = 14;

    /** @return array{file:string, bytes:int, at:string} */
    public function run(): array
    {
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        $connection = DB::connection();
        $stamp = now()->format('Ymd-His');

        $file = match ($connection->getDriverName()) {
            'sqlite' => $this->sqlite($connection->getDatabaseName(), "{$dir}/rasentra-{$stamp}.sqlite"),
            'mysql', 'mariadb' => $this->mysql($connection->getConfig(), "{$dir}/rasentra-{$stamp}.sql.gz"),
            default => throw new RuntimeException('Driver database ini belum didukung untuk backup.'),
        };

        collect(File::files($dir))->sortByDesc(fn ($f) => $f->getMTime())->slice(self::KEEP)->each(fn ($f) => File::delete($f->getPathname()));
        $result = ['file' => basename($file), 'bytes' => filesize($file), 'at' => now()->toIso8601ZuluString()];
        SystemState::ran('backup', $result);

        return $result;
    }

    /** @return list<array{file:string, bytes:int, at:int}> */
    public function list(): array
    {
        $dir = storage_path('app/backups');

        return File::isDirectory($dir)
            ? collect(File::files($dir))->sortByDesc(fn ($f) => $f->getMTime())
                ->map(fn ($f) => ['file' => $f->getFilename(), 'bytes' => $f->getSize(), 'at' => $f->getMTime()])->values()->all()
            : [];
    }

    private function sqlite(string $database, string $target): string
    {
        if ($database === ':memory:' || ! is_file($database)) {
            throw new RuntimeException('Database SQLite tidak berupa berkas.');
        }
        // VACUUM INTO menghasilkan salinan yang konsisten walau ada transaksi berjalan.
        DB::statement('VACUUM INTO ?', [$target]);

        return $target;
    }

    private function mysql(array $config, string $target): string
    {
        $result = Process::env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])->timeout(600)->run(sprintf(
            'mysqldump --single-transaction --quick --routines -h %s -P %s -u %s %s | gzip > %s',
            escapeshellarg((string) $config['host']), escapeshellarg((string) ($config['port'] ?? 3306)),
            escapeshellarg((string) $config['username']), escapeshellarg((string) $config['database']), escapeshellarg($target),
        ));
        if (! $result->successful() || ! is_file($target) || filesize($target) < 100) {
            @unlink($target);
            throw new RuntimeException('mysqldump gagal: '.trim($result->errorOutput()));
        }

        return $target;
    }
}

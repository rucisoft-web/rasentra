<?php

namespace App\Domain\Sync;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rasentra Lokal: menerapkan publikasi dari Rasentra Central ke database outlet. Idempoten
 * (upsert per id), sehingga publikasi yang sama boleh diterima berkali-kali, dan snapshot
 * boleh diterapkan di atas data yang sudah ada.
 */
final class PublicationApplier
{
    /** Anak yang diganti utuh bersama induknya: kunci payload => [tabel, kolom foreign key]. */
    private const CHILDREN = [
        'price_version' => ['items' => ['price_items', 'price_version_id']],
        'recipe_version' => ['lines' => ['recipe_lines', 'recipe_version_id']],
    ];

    private array $columns = [];

    public function __construct(private readonly ?string $connection = null) {}

    public function on(?string $connection): self
    {
        return new self($connection);
    }

    public function db(): Connection
    {
        return DB::connection($this->connection);
    }

    /** @param array{entity_type:string, entity_key:string, payload:array} $change */
    public function apply(array $change, int $outletId): void
    {
        $type = $change['entity_type'];
        $table = Publications::TABLES[$type] ?? null;
        if ($table === null) {
            return; // jenis baru dari Central yang belum dikenal versi Lokal ini: dilewati, cursor tetap maju
        }
        $payload = $change['payload'];

        $this->db()->transaction(function () use ($type, $table, $payload, $outletId) {
            foreach ($payload['_parents'] ?? [] as $parentTable => $rows) {
                foreach ($rows as $row) {
                    $this->upsert($parentTable, $row);
                }
            }
            $this->upsert($table, $payload);

            foreach (self::CHILDREN[$type] ?? [] as $key => [$childTable, $foreignKey]) {
                $this->db()->table($childTable)->where($foreignKey, $payload['id'])->delete();
                foreach ($payload[$key] ?? [] as $child) {
                    $child[$foreignKey] = $payload['id'];
                    $this->db()->table($childTable)->insert($this->clean($childTable, $child));
                }
            }

            if ($type === 'promotion_version') {
                // Target outlet ini sendiri; ActivePromotions membaca snapshot target.
                $this->db()->table('promotion_targets')->updateOrInsert(
                    ['promotion_version_id' => $payload['id'], 'outlet_id' => $outletId],
                    ['ack_status' => 'applied', 'acked_at' => now()],
                );
            }
        });
    }

    public function cursor(): int
    {
        return (int) json_decode((string) $this->db()->table('system_states')->where('key', 'sync:cursor')->value('value'), true);
    }

    public function setCursor(int $cursor): void
    {
        $this->db()->table('system_states')->updateOrInsert(['key' => 'sync:cursor'], ['value' => json_encode($cursor), 'updated_at' => now()]);
    }

    private function upsert(string $table, array $row): void
    {
        $row = $this->clean($table, $row);
        $this->db()->table($table)->updateOrInsert(['id' => $row['id']], array_diff_key($row, ['id' => true]));
    }

    /** Buang kolom yang tidak ada di tabel ini, ubah array ke JSON dan waktu ISO ke format database. */
    private function clean(string $table, array $row): array
    {
        $columns = $this->columns[$table] ??= array_flip(Schema::connection($this->connection)->getColumnListing($table));
        $out = [];
        foreach (array_intersect_key($row, $columns) as $key => $value) {
            $out[$key] = match (true) {
                is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value) === 1 => Carbon::parse($value)->utc()->format('Y-m-d H:i:s'),
                default => $value,
            };
        }

        return $out;
    }
}

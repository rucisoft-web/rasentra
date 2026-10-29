<?php

namespace App\Domain\Sync;

use App\Support\SystemState;
use Throwable;

/**
 * Rasentra Lokal: menarik publikasi (harga, promo, resep, cooking fee, master) dari Rasentra
 * Central sejak cursor terakhir, menerapkannya, lalu mengirim ack beserta cursor baru.
 * Bila satu publikasi gagal diterapkan, cursor berhenti tepat sebelumnya dan dicoba lagi
 * pada putaran berikutnya.
 */
final class ChangePuller
{
    public function __construct(
        private readonly CentralClient $central,
        private readonly PublicationApplier $applier,
    ) {}

    /** @return array{applied:int, cursor:int, error?:string} */
    public function pull(int $maxPages = 20): array
    {
        $cursor = $this->applier->cursor();
        $applied = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->central->request()->get('changes', ['cursor' => $cursor])->throw()->json();
            [$acks, $error, $reached] = $this->applyAll($response['changes'] ?? [], (int) $response['outlet_id']);
            $applied += count(array_filter($acks, fn ($a) => $a['status'] === 'applied'));
            $cursor = $error === null ? max($cursor, (int) $response['next_cursor']) : $reached;
            $this->applier->setCursor($cursor);
            $this->ack($acks, $cursor);

            if ($error !== null) {
                return $this->done($applied, $cursor, $error);
            }
            if (! ($response['has_more'] ?? false)) {
                break;
            }
        }

        return $this->done($applied, $cursor);
    }

    /**
     * Pemasangan Rasentra Lokal baru: terapkan snapshot lengkap, lalu lanjutkan dari cursor snapshot.
     *
     * @return array{snapshot:array, pull?:array}
     */
    public function bootstrap(): array
    {
        $response = $this->central->request(120)->get('snapshot')->throw()->json();
        [$acks, $error] = $this->applyAll($response['changes'], (int) $response['outlet_id']);
        if ($error !== null) {
            return ['snapshot' => $this->done(count($acks) - 1, $this->applier->cursor(), $error)];
        }
        $this->applier->setCursor((int) $response['cursor']);
        $this->ack(array_values(array_filter($acks, fn ($a) => $a['entity_type'] === 'promotion_version')), (int) $response['cursor']);

        return ['snapshot' => $this->done(count($acks), (int) $response['cursor']), 'pull' => $this->pull()];
    }

    /** @return array{0:list<array>, 1:?string, 2:int} ack per publikasi, error pertama, cursor terakhir yang berhasil */
    private function applyAll(array $changes, int $outletId): array
    {
        $acks = [];
        $reached = $this->applier->cursor();
        foreach ($changes as $change) {
            try {
                $this->applier->apply($change, $outletId);
            } catch (Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 1000);
                $acks[] = ['entity_type' => $change['entity_type'], 'entity_key' => (string) $change['entity_key'], 'status' => 'failed', 'error' => $error];

                return [$acks, "{$change['entity_type']} {$change['entity_key']}: {$error}", $reached];
            }
            $acks[] = ['entity_type' => $change['entity_type'], 'entity_key' => (string) $change['entity_key'], 'status' => 'applied'];
            $reached = max($reached, (int) ($change['id'] ?? 0));
        }

        return [$acks, null, $reached];
    }

    private function ack(array $acks, int $cursor): void
    {
        try {
            $this->central->request()->post('ack', ['acks' => $acks, 'cursor' => $cursor])->throw();
        } catch (Throwable) {
            // Ack ikut terkirim lewat heartbeat (applied_cursor); publikasi tidak perlu ditarik ulang.
        }
    }

    private function done(int $applied, int $cursor, ?string $error = null): array
    {
        SystemState::put('sync:last_pull', ['at' => now()->toIso8601ZuluString(), 'applied' => $applied, 'cursor' => $cursor, 'error' => $error]);

        return ['applied' => $applied, 'cursor' => $cursor] + ($error ? ['error' => $error] : []);
    }
}

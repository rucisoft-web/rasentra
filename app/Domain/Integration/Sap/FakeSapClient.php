<?php

namespace App\Domain\Integration\Sap;

/**
 * SAP tiruan untuk pengembangan dan uji. Dapat disetel agar timeout setelah "commit"
 * untuk menguji pemulihan tanpa dokumen ganda (skenario U15).
 */
final class FakeSapClient implements SapClient
{
    public array $documents = [];

    public array $failNext = [];

    public bool $timeoutAfterCommitOnce = false;

    private int $seq = 1000;

    public function findBySourceRef(string $object, string $sourceRef): ?array
    {
        return $this->documents[$object][$sourceRef] ?? null;
    }

    public function create(string $object, array $payload): array
    {
        if (($this->failNext[$object] ?? 0) > 0) {
            $this->failNext[$object]--;
            throw new \RuntimeException("SAP menolak {$object}");
        }
        $doc = ['DocEntry' => ++$this->seq, 'DocNum' => $this->seq + 50000, 'DocTotal' => $payload['DocTotal'] ?? null];
        $this->documents[$object][$payload['U_POS_SRC']] = $doc;

        if ($this->timeoutAfterCommitOnce) {
            $this->timeoutAfterCommitOnce = false;
            throw new SapTimeout('Timeout setelah SAP menyimpan dokumen');
        }

        return $doc;
    }
}

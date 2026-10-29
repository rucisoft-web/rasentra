<?php

namespace App\Domain\Integration\Sap;

interface SapClient
{
    /** Cari dokumen yang sudah ada berdasarkan referensi sumber POS (mis. UDF U_POS_SRC). */
    public function findBySourceRef(string $object, string $sourceRef): ?array;

    /** @return array{DocEntry:int, DocNum:int} */
    public function create(string $object, array $payload): array;
}

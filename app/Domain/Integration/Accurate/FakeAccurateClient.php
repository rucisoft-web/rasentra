<?php

namespace App\Domain\Integration\Accurate;

use App\Models\Crm\AccurateConnection;

/** Accurate tiruan untuk uji dan demo: data diisi lewat konstruktor. */
final class FakeAccurateClient implements AccurateClient
{
    public function __construct(public array $customers = [], public array $invoices = []) {}

    public function customers(AccurateConnection $conn, int $page): array
    {
        return ['rows' => $page === 1 ? $this->customers : [], 'page_count' => 1];
    }

    public function invoices(AccurateConnection $conn, string $from, string $to, int $page): array
    {
        $rows = array_values(array_filter($this->invoices, fn ($i) => $i['date'] >= $from && $i['date'] <= $to));

        return ['rows' => $page === 1 ? $rows : [], 'page_count' => 1];
    }

    public function ping(AccurateConnection $conn): string
    {
        return 'Accurate Demo';
    }
}

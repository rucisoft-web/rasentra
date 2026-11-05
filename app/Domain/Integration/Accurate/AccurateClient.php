<?php

namespace App\Domain\Integration\Accurate;

use App\Models\Crm\AccurateConnection;

/**
 * Akses baca ke Accurate Online untuk Rasentra CRM. Baris dikembalikan sudah dinormalkan:
 * pelanggan ['id', 'customer_no', 'name', 'phone', 'email', 'city'] dan faktur penjualan
 * ['id', 'number', 'date' (Y-m-d), 'amount', 'customer_no'].
 */
interface AccurateClient
{
    /** @return array{rows: list<array>, page_count: int} */
    public function customers(AccurateConnection $conn, int $page): array;

    /** @return array{rows: list<array>, page_count: int} */
    public function invoices(AccurateConnection $conn, string $from, string $to, int $page): array;

    /** Uji koneksi; mengembalikan nama database Accurate. */
    public function ping(AccurateConnection $conn): string;
}

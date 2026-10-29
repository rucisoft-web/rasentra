<?php

namespace App\Support;

use RuntimeException;

/**
 * Pelanggaran aturan bisnis yang ditampilkan ke petugas. Kode error stabil agar
 * layar Rasentra POS dapat menampilkan pesan dan tindakan yang tepat.
 */
class DomainException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $code, string $message, array $context = []): self
    {
        return new self($code, $message, 409, $context);
    }

    public static function needsApproval(string $code, string $message, array $context = []): self
    {
        return new self($code, $message, 403, $context + ['approval_required' => true]);
    }
}

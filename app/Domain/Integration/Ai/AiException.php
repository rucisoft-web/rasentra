<?php

namespace App\Domain\Integration\Ai;

use App\Support\DomainException;

final class AiException extends DomainException
{
    public static function because(string $message, int $status = 502): self
    {
        return new self('ai_failed', 'Asisten AI: '.$message, $status);
    }
}

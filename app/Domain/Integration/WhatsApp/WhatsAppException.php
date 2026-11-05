<?php

namespace App\Domain\Integration\WhatsApp;

use App\Support\DomainException;

class WhatsAppException extends DomainException
{
    public static function from(string $message, int $status = 502): self
    {
        return new self('whatsapp_error', 'WhatsApp menolak: '.$message, $status);
    }
}

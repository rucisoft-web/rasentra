<?php

namespace App\Domain\Integration\Ai;

/** Model bahasa untuk Rasentra CRM. Jawaban selalu JSON yang sesuai skema. */
interface AiClient
{
    public function configured(): bool;

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $schema  JSON Schema objek jawaban
     * @return array<string, mixed>
     */
    public function json(string $system, array $messages, array $schema): array;
}

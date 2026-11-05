<?php

namespace App\Domain\Integration\Ai;

/** Pengganti Claude untuk uji dan server demo: mengembalikan jawaban yang disiapkan. */
final class FakeAiClient implements AiClient
{
    /** @var list<array{system: string, messages: array, schema: array}> */
    public array $calls = [];

    /** @param  list<array<string, mixed>>  $responses */
    public function __construct(public array $responses = []) {}

    public function configured(): bool
    {
        return true;
    }

    public function json(string $system, array $messages, array $schema): array
    {
        $this->calls[] = compact('system', 'messages', 'schema');
        if ($this->responses === []) {
            throw AiException::because('tidak ada jawaban uji tersisa');
        }

        return array_shift($this->responses);
    }
}

<?php

namespace App\Domain\Integration\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * Claude API (SDK resmi Anthropic untuk PHP). Jawaban dibatasi structured outputs
 * (JSON Schema) agar bisa langsung disimpan. Bila model utama menolak permintaan,
 * server Anthropic otomatis meneruskan ke model cadangan (fallbacks "default").
 */
final class ClaudeAiClient implements AiClient
{
    private ?Client $client = null;

    public function configured(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    public function json(string $system, array $messages, array $schema): array
    {
        if (! $this->configured()) {
            throw AiException::because('ANTHROPIC_API_KEY belum diisi di server Central.', 422);
        }
        $this->client ??= new Client(apiKey: config('services.anthropic.key'));

        try {
            $response = $this->client->beta->messages->create(
                model: config('services.anthropic.model'),
                maxTokens: 8000,
                // Prompt sistem tetap sama di setiap panggilan sehingga bisa di-cache.
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                messages: $messages,
                outputConfig: [
                    'effort' => config('services.anthropic.effort'),
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException) {
            throw AiException::because('API key Anthropic ditolak. Periksa ANTHROPIC_API_KEY.');
        } catch (RateLimitException) {
            throw AiException::because('batas pemakaian Claude API tercapai, coba lagi sebentar.', 429);
        } catch (APIStatusException $e) {
            throw AiException::because('Claude API mengembalikan error '.$e->getCode().'.');
        } catch (APIConnectionException) {
            throw AiException::because('tidak dapat terhubung ke Claude API.');
        } catch (APIException $e) {
            throw AiException::because($e->getMessage());
        }

        if ($response->stopReason === 'refusal') {
            throw AiException::because('permintaan ditolak oleh model, serahkan ke CS.', 422);
        }
        if ($response->stopReason === 'max_tokens') {
            throw AiException::because('jawaban terpotong.');
        }
        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        throw AiException::because('jawaban tidak berformat JSON.');
    }
}

<?php

namespace App\Domain\Integration\WhatsApp;

use App\Models\Crm\WaChannel;

/** WhatsApp tiruan untuk uji dan demo: mencatat pesan yang "dikirim". */
final class FakeWhatsAppClient implements WhatsAppClient
{
    /** @var list<array> */
    public array $sent = [];

    /** @var array<string, string> nomor yang akan gagal => pesan error */
    public array $failFor = [];

    public function __construct(public array $templateList = []) {}

    public function sendText(WaChannel $channel, string $to, string $body): string
    {
        return $this->record(['to' => $to, 'type' => 'text', 'body' => $body]);
    }

    public function sendTemplate(WaChannel $channel, string $to, string $name, string $language, array $bodyParams = [], ?array $header = null): string
    {
        return $this->record(['to' => $to, 'type' => 'template', 'name' => $name, 'params' => $bodyParams, 'header' => $header]);
    }

    public function templates(WaChannel $channel): array
    {
        return $this->templateList;
    }

    private function record(array $msg): string
    {
        if (isset($this->failFor[$msg['to']])) {
            throw WhatsAppException::from($this->failFor[$msg['to']], 422);
        }
        $this->sent[] = $msg;

        return 'wamid.FAKE'.count($this->sent);
    }
}

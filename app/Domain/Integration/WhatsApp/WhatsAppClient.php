<?php

namespace App\Domain\Integration\WhatsApp;

use App\Models\Crm\WaChannel;

/** WhatsApp Business Cloud API (Meta). Mengembalikan id pesan WhatsApp (wamid). */
interface WhatsAppClient
{
    public function sendText(WaChannel $channel, string $to, string $body): string;

    /**
     * @param  list<string>  $bodyParams  isi {{1}}, {{2}}, ...
     * @param  array{type: string, link: string}|null  $header  media header (image/video/document)
     */
    public function sendTemplate(WaChannel $channel, string $to, string $name, string $language, array $bodyParams = [], ?array $header = null): string;

    /** @return list<array{name: string, language: string, category: string, status: string, components: array}> */
    public function templates(WaChannel $channel): array;
}

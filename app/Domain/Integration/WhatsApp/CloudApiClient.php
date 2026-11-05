<?php

namespace App\Domain\Integration\WhatsApp;

use App\Models\Crm\WaChannel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Klien Graph API untuk WhatsApp Business Cloud API. Versi Graph diatur di services.whatsapp.graph_version. */
final class CloudApiClient implements WhatsAppClient
{
    public function sendText(WaChannel $channel, string $to, string $body): string
    {
        return $this->send($channel, [
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $body, 'preview_url' => true],
        ]);
    }

    public function sendTemplate(WaChannel $channel, string $to, string $name, string $language, array $bodyParams = [], ?array $header = null): string
    {
        $components = [];
        if ($header !== null) {
            $type = strtolower($header['type']);
            $components[] = ['type' => 'header', 'parameters' => [['type' => $type, $type => ['link' => $header['link']]]]];
        }
        if ($bodyParams) {
            $components[] = ['type' => 'body', 'parameters' => array_map(fn ($t) => ['type' => 'text', 'text' => (string) $t], array_values($bodyParams))];
        }

        return $this->send($channel, [
            'to' => $to,
            'type' => 'template',
            'template' => ['name' => $name, 'language' => ['code' => $language]] + ($components ? ['components' => $components] : []),
        ]);
    }

    public function templates(WaChannel $channel): array
    {
        $out = [];
        $url = $this->url($channel->waba_id.'/message_templates');
        $query = ['fields' => 'name,language,status,category,components', 'limit' => 100];
        for ($page = 0; $url && $page < 20; $page++) {
            $res = Http::withToken($channel->access_token)->timeout(30)->get($url, $query);
            $this->assertOk($res);
            foreach ($res->json('data') ?? [] as $t) {
                $out[] = [
                    'name' => (string) $t['name'],
                    'language' => (string) $t['language'],
                    'category' => (string) ($t['category'] ?? ''),
                    'status' => (string) ($t['status'] ?? ''),
                    'components' => $t['components'] ?? [],
                ];
            }
            $url = $res->json('paging.next');
            $query = [];
        }

        return $out;
    }

    private function send(WaChannel $channel, array $payload): string
    {
        $res = Http::withToken($channel->access_token)->timeout(20)->acceptJson()
            ->post($this->url($channel->phone_number_id.'/messages'), ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual'] + $payload);
        $this->assertOk($res);
        $id = $res->json('messages.0.id');
        if (! $id) {
            throw WhatsAppException::from('respons tanpa id pesan');
        }

        return (string) $id;
    }

    private function assertOk(Response $res): void
    {
        if ($res->successful()) {
            return;
        }
        $err = $res->json('error') ?? [];
        $detail = $err['error_data']['details'] ?? $err['message'] ?? ('HTTP '.$res->status());
        throw WhatsAppException::from(trim(($err['code'] ?? '').' '.$detail), $res->status() >= 500 ? 502 : 422);
    }

    private function url(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.whatsapp.graph_version', 'v23.0').'/'.$path;
    }
}

<?php

namespace App\Domain\Crm;

use App\Domain\Integration\WhatsApp\WhatsAppClient;
use App\Domain\Integration\WhatsApp\WhatsAppException;
use App\Models\Crm\CampaignRecipient;
use App\Models\Crm\Community;
use App\Models\Crm\Conversation;
use App\Models\Crm\Customer;
use App\Models\Crm\Message;
use App\Models\Crm\WaChannel;
use App\Models\Crm\WaTemplate;
use App\Support\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pengiriman dan penerimaan WhatsApp untuk Rasentra CRM. Aturan Meta yang dijaga di sini:
 * pesan bebas (teks) hanya boleh dalam 24 jam sejak pesan terakhir pelanggan; di luar itu
 * harus memakai template yang sudah disetujui. Kata kunci STOP/BERHENTI mencatat opt-out,
 * START/YA mencatat opt-in, dan kata kunci komunitas memasukkan pelanggan ke komunitasnya.
 */
final class Messenger
{
    public const OPT_OUT = ['STOP', 'BERHENTI', 'UNSUBSCRIBE', 'STOP PROMO'];

    public const OPT_IN = ['START', 'YA', 'MAU', 'SUBSCRIBE', 'DAFTAR'];

    private const STATUS_RANK = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

    public function __construct(private readonly WhatsAppClient $client, private readonly CustomerDirectory $directory) {}

    public function conversation(Customer $customer, WaChannel $channel): Conversation
    {
        return Conversation::firstOrCreate(['crm_customer_id' => $customer->id, 'crm_wa_channel_id' => $channel->id]);
    }

    public static function windowOpen(Conversation $c): bool
    {
        return $c->last_inbound_at !== null && $c->last_inbound_at->gt(now()->subHours(24));
    }

    public function sendText(Conversation $c, string $body, ?int $userId = null): Message
    {
        if (! self::windowOpen($c)) {
            throw new DomainException('wa_window_closed', 'Lebih dari 24 jam sejak pesan terakhir pelanggan. Gunakan template yang sudah disetujui Meta.', 422);
        }
        $c->loadMissing('customer', 'channel');

        return $this->deliver($c, 'text', $body, $userId, null, fn () => $this->client->sendText($c->channel, $c->customer->phone, $body));
    }

    /** @param list<string> $params */
    public function sendTemplate(Customer $customer, WaTemplate $template, array $params = [], ?string $mediaUrl = null, ?int $userId = null, ?int $campaignId = null): Message
    {
        $channel = $template->channel;
        if ($template->status !== 'APPROVED') {
            throw new DomainException('wa_template_not_approved', "Template {$template->name} belum disetujui Meta.", 422);
        }
        $header = $template->header_type && in_array($template->header_type, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && $mediaUrl
            ? ['type' => $template->header_type, 'link' => $mediaUrl] : null;
        $body = self::render($template->body, $params);
        $c = $this->conversation($customer, $channel);

        return $this->deliver($c, 'template', $body, $userId, $campaignId, fn () => $this->client->sendTemplate($channel, $customer->phone, $template->name, $template->language, $params, $header), $mediaUrl);
    }

    /** Ganti {{1}}, {{2}} dengan parameter untuk pratinjau dan riwayat chat. */
    public static function render(string $body, array $params): string
    {
        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => $params[(int) $m[1] - 1] ?? $m[0], $body);
    }

    public function syncTemplates(WaChannel $channel): int
    {
        $rows = $this->client->templates($channel);
        foreach ($rows as $t) {
            $body = '';
            $header = null;
            foreach ($t['components'] as $comp) {
                if (($comp['type'] ?? '') === 'BODY') {
                    $body = (string) ($comp['text'] ?? '');
                }
                if (($comp['type'] ?? '') === 'HEADER') {
                    $header = strtoupper((string) ($comp['format'] ?? 'TEXT'));
                }
            }
            preg_match_all('/\{\{(\d+)\}\}/', $body, $m);
            WaTemplate::updateOrCreate(
                ['crm_wa_channel_id' => $channel->id, 'name' => $t['name'], 'language' => $t['language']],
                ['category' => $t['category'], 'status' => $t['status'], 'header_type' => $header, 'body' => $body,
                    'body_params' => $m[1] ? max(array_map('intval', $m[1])) : 0, 'components' => $t['components']],
            );
        }
        $channel->update(['templates_synced_at' => now()]);

        return count($rows);
    }

    /** Proses satu payload webhook Meta (pesan masuk dan status kiriman). */
    public function handleWebhook(array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $channel = WaChannel::where('phone_number_id', $value['metadata']['phone_number_id'] ?? '')->first();
                if (! $channel) {
                    continue;
                }
                $names = collect($value['contacts'] ?? [])->mapWithKeys(fn ($c) => [$c['wa_id'] ?? '' => $c['profile']['name'] ?? null]);
                foreach ($value['messages'] ?? [] as $msg) {
                    $this->inbound($channel, $msg, $names[$msg['from'] ?? ''] ?? null);
                }
                foreach ($value['statuses'] ?? [] as $status) {
                    $this->status($status);
                }
            }
        }
    }

    private function inbound(WaChannel $channel, array $msg, ?string $profileName): void
    {
        $phone = Phone::normalize($msg['from'] ?? null);
        if ($phone === null || Message::where('wa_message_id', $msg['id'] ?? '')->exists()) {
            return;
        }
        $type = (string) ($msg['type'] ?? 'text');
        $text = match ($type) {
            'text' => $msg['text']['body'] ?? '',
            'button' => $msg['button']['text'] ?? '',
            'interactive' => $msg['interactive']['button_reply']['title'] ?? $msg['interactive']['list_reply']['title'] ?? '',
            'image', 'video', 'document' => $msg[$type]['caption'] ?? '['.$type.']',
            'location' => '[lokasi] '.($msg['location']['name'] ?? ''),
            default => '['.$type.']',
        };
        $at = isset($msg['timestamp']) ? Carbon::createFromTimestamp((int) $msg['timestamp']) : now();

        [$customer] = $this->directory->upsert($phone, ['name' => $profileName], $channel->crm_branch_id, 'whatsapp');
        $c = $this->conversation($customer, $channel);
        DB::transaction(function () use ($c, $msg, $type, $text, $at) {
            Message::create([
                'crm_conversation_id' => $c->id, 'direction' => 'in', 'type' => $type, 'body' => $text,
                'media_id' => $msg[$type]['id'] ?? null, 'wa_message_id' => $msg['id'] ?? null, 'status' => 'received', 'status_at' => $at,
            ]);
            $c->update([
                'status' => 'open', 'last_message_at' => $at, 'last_inbound_at' => $at,
                'unread_count' => $c->unread_count + 1, 'last_preview' => mb_substr($text, 0, 160),
            ]);
        });
        $this->keywords($c->fresh(['customer', 'channel']), trim($text));
    }

    private function keywords(Conversation $c, string $text): void
    {
        $word = mb_strtoupper(preg_replace('/\s+/', ' ', $text));
        $customer = $c->customer;
        if (in_array($word, self::OPT_OUT, true)) {
            $customer->update(['wa_status' => 'opted_out', 'wa_status_at' => now()]);
            $this->autoReply($c, 'Baik, Anda tidak akan menerima pesan promosi lagi dari kami. Kirim START kapan saja untuk berlangganan kembali.');

            return;
        }
        if (in_array($word, self::OPT_IN, true)) {
            $customer->update(['wa_status' => 'opted_in', 'wa_status_at' => now()]);
            $this->autoReply($c, 'Terima kasih! Anda akan menerima info promo dan menu terbaru. Kirim STOP untuk berhenti.');

            return;
        }
        $community = $word !== '' ? Community::whereRaw('UPPER(join_keyword) = ?', [$word])->first() : null;
        if ($community) {
            $community->members()->syncWithoutDetaching([$customer->id => ['source' => 'keyword', 'joined_at' => now()]]);
            if ($customer->wa_status !== 'opted_in') {
                $customer->update(['wa_status' => 'opted_in', 'wa_status_at' => now()]);
            }
            $this->autoReply($c, "Selamat bergabung di komunitas {$community->name}! Info dan promo khusus anggota akan kami kirim lewat WhatsApp ini. Kirim STOP untuk berhenti.");
            app(WorkflowEngine::class)->fire('joined_community', $customer->fresh(), ['community_id' => $community->id]);

            return;
        }
        if ($text !== '') {
            app(WorkflowEngine::class)->fire('keyword', $customer, ['text' => $text]);
        }
    }

    private function autoReply(Conversation $c, string $body): void
    {
        try {
            $this->sendText($c, $body);
        } catch (WhatsAppException) {
            // Balasan otomatis gagal tidak boleh menggagalkan webhook; pesan tercatat failed.
        }
    }

    private function status(array $s): void
    {
        $message = Message::where('wa_message_id', $s['id'] ?? '')->first();
        $new = (string) ($s['status'] ?? '');
        if (! $message || ! isset(self::STATUS_RANK[$new])) {
            return;
        }
        // Webhook bisa datang tidak berurutan: status tidak boleh mundur (read → delivered).
        if ((self::STATUS_RANK[$message->status] ?? -1) >= self::STATUS_RANK[$new] && $new !== 'failed') {
            return;
        }
        $error = $new === 'failed' ? mb_substr(trim(($s['errors'][0]['code'] ?? '').' '.($s['errors'][0]['title'] ?? $s['errors'][0]['message'] ?? '')), 0, 500) : null;
        $message->update(['status' => $new, 'error' => $error, 'status_at' => isset($s['timestamp']) ? Carbon::createFromTimestamp((int) $s['timestamp']) : now()]);
        if ($message->crm_campaign_id) {
            CampaignRecipient::where('crm_message_id', $message->id)->update(['status' => $new, 'error' => $error, 'updated_at' => now()]);
        }
    }

    private function deliver(Conversation $c, string $type, string $body, ?int $userId, ?int $campaignId, \Closure $send, ?string $mediaUrl = null): Message
    {
        $message = Message::create([
            'crm_conversation_id' => $c->id, 'direction' => 'out', 'type' => $type, 'body' => $body,
            'media_id' => $mediaUrl ? mb_substr($mediaUrl, 0, 120) : null,
            'status' => 'queued', 'sent_by' => $userId, 'crm_campaign_id' => $campaignId,
        ]);
        try {
            $wamid = $send();
            $message->update(['wa_message_id' => $wamid, 'status' => 'sent', 'status_at' => now()]);
        } catch (WhatsAppException $e) {
            $message->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'status_at' => now()]);
            throw $e;
        } finally {
            $c->update(['last_message_at' => now(), 'last_preview' => mb_substr($body, 0, 160)]);
        }

        return $message;
    }
}

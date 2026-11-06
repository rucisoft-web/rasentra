<?php

namespace App\Domain\Crm;

use App\Domain\Integration\WhatsApp\WhatsAppException;
use App\Models\Crm\Campaign;
use App\Models\Crm\CampaignRecipient;
use App\Models\Crm\Customer;
use App\Models\Crm\Message;
use App\Models\Crm\Segment;
use App\Support\DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Kirim iklan/broadcast WhatsApp ke anggota komunitas dan/atau segmen. Hanya pelanggan yang
 * opt-in yang menerima (aturan template marketing Meta); opt-out dicek ulang tepat sebelum
 * kirim. Pengiriman dicicil tiap menit sesuai batas send_per_minute nomor pengirim.
 */
final class CampaignService
{
    public function __construct(private readonly Messenger $messenger) {}

    public function audience(Campaign $campaign): Builder
    {
        $a = $campaign->audience ?? [];
        $q = Customer::query()->where('wa_status', 'opted_in');
        $communityIds = array_filter(array_map('intval', $a['community_ids'] ?? []));
        $segment = ! empty($a['segment_id']) ? Segment::find($a['segment_id']) : null;

        return $q->where(function ($w) use ($communityIds, $segment) {
            if ($communityIds) {
                $w->orWhereHas('communities', fn ($c) => $c->whereIn('crm_communities.id', $communityIds));
            }
            if ($segment) {
                $w->orWhereIn('id', SegmentQuery::apply(Customer::query(), $segment->rules)->select('id'));
            }
            if (! $communityIds && ! $segment) {
                $w->whereRaw('1 = 0');
            }
        });
    }

    /** Jadwalkan atau mulai kirim sekarang. */
    public function launch(Campaign $campaign): Campaign
    {
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            throw new DomainException('campaign_state', 'Kampanye ini sudah berjalan atau selesai.', 422);
        }
        if ($campaign->template->status !== 'APPROVED') {
            throw new DomainException('wa_template_not_approved', 'Template belum disetujui Meta.', 422);
        }
        if ($this->audience($campaign)->count() === 0) {
            throw new DomainException('campaign_empty', 'Tidak ada penerima opt-in di komunitas/segmen yang dipilih.', 422);
        }
        if ($campaign->scheduled_at && $campaign->scheduled_at->isFuture()) {
            $campaign->update(['status' => 'scheduled']);

            return $campaign;
        }

        return $this->start($campaign);
    }

    public function cancel(Campaign $campaign): void
    {
        if (in_array($campaign->status, ['done', 'cancelled'], true)) {
            return;
        }
        DB::transaction(function () use ($campaign) {
            $campaign->recipients()->where('status', 'pending')->update(['status' => 'skipped', 'error' => 'Kampanye dibatalkan', 'updated_at' => now()]);
            $campaign->update(['status' => 'cancelled', 'finished_at' => now()]);
        });
    }

    /** Dipanggil scheduler tiap menit: mulai kampanye terjadwal dan cicil pengiriman. */
    public function dispatch(): array
    {
        $summary = ['started' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'finished' => 0];
        Campaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->get()
            ->each(function (Campaign $c) use (&$summary) {
                $this->start($c);
                $summary['started']++;
            });

        foreach (Campaign::with(['channel', 'template.channel'])->where('status', 'sending')->get() as $campaign) {
            $batch = $campaign->recipients()->with('customer')->where('status', 'pending')->orderBy('id')
                ->limit(max(1, $campaign->channel->send_per_minute))->get();
            foreach ($batch as $r) {
                $summary[$this->sendOne($campaign, $r)]++;
            }
            if (! $campaign->recipients()->where('status', 'pending')->exists()) {
                $campaign->update(['status' => 'done', 'finished_at' => now()]);
                $summary['finished']++;
            }
        }

        return $summary;
    }

    /** @return array<string, int> jumlah penerima per status */
    public static function stats(Campaign $campaign): array
    {
        $counts = $campaign->recipients()->select('status', DB::raw('COUNT(*) AS n'))->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        return array_merge(['pending' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'skipped' => 0], $counts);
    }

    /** Isi parameter template: {name}, {first_name}, {outlet} atau teks tetap. */
    public static function params(Campaign $campaign, Customer $customer): array
    {
        return array_map(function ($p) use ($customer) {
            $p = (string) $p;

            return strtr($p, [
                '{name}' => $customer->name,
                '{first_name}' => strtok($customer->name, ' ') ?: $customer->name,
                '{outlet}' => $customer->branches()->value('name') ?? '',
            ]);
        }, array_values($campaign->params ?? []));
    }

    private function start(Campaign $campaign): Campaign
    {
        DB::transaction(function () use ($campaign) {
            $now = now();
            $this->audience($campaign)->select('id')->chunkById(1000, function ($rows) use ($campaign, $now) {
                CampaignRecipient::insertOrIgnore($rows->map(fn ($c) => [
                    'crm_campaign_id' => $campaign->id, 'crm_customer_id' => $c->id, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });
            $campaign->update(['status' => 'sending', 'started_at' => $now, 'recipient_count' => $campaign->recipients()->count()]);
        });

        return $campaign;
    }

    private function sendOne(Campaign $campaign, CampaignRecipient $r): string
    {
        $customer = $r->customer;
        if ($customer->wa_status !== 'opted_in') {
            $r->update(['status' => 'skipped', 'error' => 'Pelanggan opt-out sebelum dikirim']);

            return 'skipped';
        }
        try {
            $msg = $this->messenger->sendTemplate($customer, $campaign->template, self::params($campaign, $customer), $campaign->media_url, $campaign->created_by, $campaign->id);
            $r->update(['status' => 'sent', 'crm_message_id' => $msg->id]);

            return 'sent';
        } catch (WhatsAppException $e) {
            $r->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'crm_message_id' => Message::where('crm_campaign_id', $campaign->id)->latest('id')->value('id')]);

            return 'failed';
        }
    }
}

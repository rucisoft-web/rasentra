<?php

namespace App\Domain\Crm;

use App\Models\Crm\Community;
use App\Models\Crm\Conversation;
use App\Models\Crm\Customer;
use App\Models\Crm\Segment;
use App\Models\Crm\WaChannel;
use App\Models\Crm\WaTemplate;
use App\Models\Crm\Workflow;
use App\Models\Crm\WorkflowRun;
use Illuminate\Support\Facades\Log;

/**
 * Menjalankan chat automation workflow Rasentra CRM. Pemicu kejadian (kata kunci, pelanggan
 * baru, gabung komunitas, belanja) dipanggil langsung dari Messenger/CustomerDirectory;
 * pemicu harian (ulang tahun, tidak belanja) dipindai sekali sehari oleh scheduler. Pesan
 * promosi hanya dikirim ke pelanggan yang tidak opt-out; template marketing butuh opt-in.
 */
final class WorkflowEngine
{
    /** Cegah rekursi: langkah workflow (mis. masuk komunitas) tidak memicu workflow lain di run yang sama. */
    private static int $depth = 0;

    public const DAILY_SCAN_HOUR = 9;

    public function __construct(private readonly Messenger $messenger) {}

    /** Pemicu kejadian. $context: text (keyword), community_id, amount, crm_branch_id. */
    public function fire(string $trigger, Customer $customer, array $context = []): int
    {
        if (self::$depth > 0) {
            return 0;
        }
        $started = 0;
        foreach (Workflow::where('is_active', true)->where('trigger', $trigger)->orderBy('id')->get() as $wf) {
            if ($this->matches($wf, $customer, $context) && $this->start($wf, $customer)) {
                $started++;
            }
        }

        return $started;
    }

    public function start(Workflow $wf, Customer $customer): ?WorkflowRun
    {
        if ($wf->cooldown_days > 0 && $wf->runs()->where('crm_customer_id', $customer->id)->where('created_at', '>=', now()->subDays($wf->cooldown_days))->exists()) {
            return null;
        }
        if ($wf->runs()->where('crm_customer_id', $customer->id)->whereIn('status', ['running', 'waiting'])->exists()) {
            return null;
        }
        $run = $wf->runs()->create(['crm_customer_id' => $customer->id, 'step' => 0, 'status' => 'running', 'log' => []]);
        $this->advance($run);

        return $run;
    }

    /** Jalankan langkah sampai selesai atau bertemu langkah tunggu. */
    public function advance(WorkflowRun $run): void
    {
        $run->loadMissing('workflow', 'customer');
        $steps = array_values($run->workflow->steps ?? []);
        $log = $run->log ?? [];
        self::$depth++;
        try {
            while ($run->step < count($steps)) {
                $step = $steps[$run->step];
                if (($step['type'] ?? '') === 'wait') {
                    $hours = max(1, (int) ($step['hours'] ?? 1));
                    if ($run->status !== 'waiting' || $run->resume_at === null) {
                        $log[] = [now()->toIso8601String(), "Tunggu {$hours} jam"];
                        $run->update(['status' => 'waiting', 'resume_at' => now()->addHours($hours), 'log' => $log]);

                        return;
                    }
                    if ($run->resume_at->isFuture()) {
                        return;
                    }
                    $run->step++;
                    $run->resume_at = null;
                    $run->status = 'running';

                    continue;
                }
                $log[] = [now()->toIso8601String(), $this->execute($step, $run->customer)];
                $run->step++;
                $run->log = $log;
                $run->save();
            }
            $run->update(['status' => 'done', 'resume_at' => null, 'log' => $log]);
        } catch (\Throwable $e) {
            $log[] = [now()->toIso8601String(), 'Gagal: '.$e->getMessage()];
            $run->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'log' => $log]);
            Log::warning('crm.workflow', ['run' => $run->id, 'error' => $e->getMessage()]);
        } finally {
            self::$depth--;
        }
    }

    /** Dipanggil scheduler tiap menit: lanjutkan run yang menunggu dan pindai pemicu harian. */
    public function tick(): array
    {
        $resumed = 0;
        WorkflowRun::where('status', 'waiting')->where('resume_at', '<=', now())->orderBy('id')->limit(500)->get()
            ->each(function (WorkflowRun $run) use (&$resumed) {
                $this->advance($run);
                $resumed++;
            });

        $started = 0;
        // Pemicu harian memakai tanggal lokal outlet dan baru jalan mulai pukul 09.00 agar pesan tidak masuk subuh.
        $local = now(config('rasentra.display_timezone'));
        if ($local->hour < self::DAILY_SCAN_HOUR) {
            return ['resumed' => $resumed, 'started' => 0];
        }
        $today = $local->toDateString();
        foreach (Workflow::where('is_active', true)->whereIn('trigger', ['birthday', 'inactive'])->get() as $wf) {
            if ($wf->last_scanned_on?->toDateString() === $today) {
                continue;
            }
            $wf->update(['last_scanned_on' => $today]);
            $q = Customer::query()->where('wa_status', '!=', 'opted_out');
            if ($wf->trigger === 'birthday') {
                $q->whereNotNull('birth_date')->whereMonth('birth_date', $local->month)->whereDay('birth_date', $local->day);
            } else {
                $days = max(1, (int) ($wf->trigger_config['days'] ?? 60));
                $q->whereDate('last_purchase_on', $local->copy()->subDays($days)->toDateString());
            }
            $this->segmentFilter($wf, $q);
            $q->chunkById(500, function ($customers) use ($wf, &$started) {
                foreach ($customers as $c) {
                    $started += $this->start($wf, $c) ? 1 : 0;
                }
            });
        }

        return ['resumed' => $resumed, 'started' => $started];
    }

    private function matches(Workflow $wf, Customer $customer, array $ctx): bool
    {
        $cfg = $wf->trigger_config ?? [];
        $ok = match ($wf->trigger) {
            'keyword' => $this->keywordMatches((string) ($cfg['keywords'] ?? ''), (string) ($ctx['text'] ?? '')),
            'joined_community' => empty($cfg['community_id']) || (int) $cfg['community_id'] === (int) ($ctx['community_id'] ?? 0),
            'purchase' => (float) ($ctx['amount'] ?? 0) >= (float) ($cfg['min_amount'] ?? 0),
            'new_customer' => empty($cfg['crm_branch_id']) || (int) $cfg['crm_branch_id'] === (int) ($ctx['crm_branch_id'] ?? 0),
            default => true,
        };
        if (! $ok) {
            return false;
        }
        if (! empty($cfg['segment_id'])) {
            $q = Customer::whereKey($customer->id);
            $this->segmentFilter($wf, $q);

            return $q->exists();
        }

        return true;
    }

    private function segmentFilter(Workflow $wf, $q): void
    {
        $segment = ! empty($wf->trigger_config['segment_id']) ? Segment::find($wf->trigger_config['segment_id']) : null;
        if ($segment) {
            SegmentQuery::apply($q, $segment->rules);
        }
    }

    /** Kata kunci dipisah koma; cocok bila pesan sama persis atau mengandung kata tersebut. */
    private function keywordMatches(string $keywords, string $text): bool
    {
        $text = ' '.mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text)).' ';
        foreach (array_filter(array_map('trim', explode(',', mb_strtolower($keywords)))) as $kw) {
            if (str_contains($text, ' '.$kw.' ')) {
                return true;
            }
        }

        return false;
    }

    private function execute(array $step, Customer $customer): string
    {
        $customer->refresh();
        switch ($step['type'] ?? '') {
            case 'send_template':
                $template = WaTemplate::find($step['template_id'] ?? 0);
                if (! $template) {
                    return 'Lewati: template tidak ditemukan';
                }
                if ($customer->wa_status === 'opted_out' || ($template->category === 'MARKETING' && $customer->wa_status !== 'opted_in')) {
                    return "Lewati {$template->name}: pelanggan belum opt-in";
                }
                $params = array_map(fn ($p) => strtr((string) $p, [
                    '{name}' => $customer->name,
                    '{first_name}' => strtok($customer->name, ' ') ?: $customer->name,
                ]), array_values(array_filter((array) ($step['params'] ?? []), fn ($p) => $p !== null && $p !== '')));
                $this->messenger->sendTemplate($customer, $template, $params, $step['media_url'] ?? null);

                return "Template {$template->name} terkirim";
            case 'send_text':
                $conversation = Conversation::where('crm_customer_id', $customer->id)->orderByDesc('last_inbound_at')->first();
                if (! $conversation || ! Messenger::windowOpen($conversation)) {
                    return 'Lewati pesan teks: di luar jendela 24 jam';
                }
                $body = strtr((string) ($step['body'] ?? ''), ['{name}' => $customer->name, '{first_name}' => strtok($customer->name, ' ') ?: $customer->name]);
                $this->messenger->sendText($conversation, $body);

                return 'Pesan teks terkirim';
            case 'add_tag':
                $customer->tags()->syncWithoutDetaching([(int) $step['tag_id']]);

                return 'Tag ditambahkan';
            case 'join_community':
                $community = Community::find($step['community_id'] ?? 0);
                $community?->members()->syncWithoutDetaching([$customer->id => ['source' => 'workflow', 'joined_at' => now()]]);

                return $community ? "Masuk komunitas {$community->name}" : 'Lewati: komunitas tidak ditemukan';
            case 'assign':
                $channel = WaChannel::where('is_default', true)->first() ?? WaChannel::first();
                if (! $channel) {
                    return 'Lewati: belum ada nomor WhatsApp';
                }
                $this->messenger->conversation($customer, $channel)->update(['assigned_to' => $step['user_id'] ?? null, 'status' => 'open']);

                return 'Percakapan diserahkan ke CS';
            default:
                return 'Lewati: langkah tidak dikenal';
        }
    }
}

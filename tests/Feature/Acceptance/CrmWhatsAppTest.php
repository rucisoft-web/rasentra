<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Crm\CampaignService;
use App\Domain\Integration\WhatsApp\FakeWhatsAppClient;
use App\Domain\Integration\WhatsApp\WhatsAppClient;
use App\Models\Crm\Branch;
use App\Models\Crm\Campaign;
use App\Models\Crm\Community;
use App\Models\Crm\Conversation;
use App\Models\Crm\Customer;
use App\Models\Crm\Message;
use App\Models\Crm\WaChannel;
use App\Models\Crm\WaTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/** Rasentra CRM tahap 2: WhatsApp Cloud API: webhook, inbox, komunitas dan kirim iklan. */
class CrmWhatsAppTest extends AcceptanceTestCase
{
    private User $crm;

    private FakeWhatsAppClient $wa;

    private WaChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.verify_token' => 'verif-123', 'services.whatsapp.app_secret' => 'rahasia-app']);
        $this->crm = User::create(['name' => 'CS', 'email' => 'cs@hq.test', 'password' => Hash::make('x'), 'role' => 'hq_crm', 'is_active' => true]);
        $this->wa = new FakeWhatsAppClient([
            ['name' => 'promo_akhir_pekan', 'language' => 'id', 'category' => 'MARKETING', 'status' => 'APPROVED', 'components' => [
                ['type' => 'HEADER', 'format' => 'IMAGE'],
                ['type' => 'BODY', 'text' => 'Halo {{1}}, wagyu diskon {{2}} khusus anggota komunitas! Balas STOP untuk berhenti.'],
            ]],
            ['name' => 'draf_belum_disetujui', 'language' => 'id', 'category' => 'MARKETING', 'status' => 'PENDING', 'components' => [['type' => 'BODY', 'text' => 'Hai']]],
        ]);
        $this->app->instance(WhatsAppClient::class, $this->wa);

        $this->actingAs($this->crm)->post(route('core.crm.whatsapp.store'), [
            'name' => 'WA Pusat', 'phone_number_id' => '1098765432', 'waba_id' => '2233445566', 'access_token' => 'EAAG-test',
            'crm_branch_id' => Branch::forOutlet($this->outlet->id)->id, 'send_per_minute' => 2,
        ])->assertSessionHas('ok');
        $this->channel = WaChannel::firstOrFail();
        $this->actingAs($this->crm)->post(route('core.crm.whatsapp.templates', $this->channel))->assertSessionHas('ok');
    }

    private function webhook(array $value): TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '2233445566', 'changes' => [['field' => 'messages', 'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1098765432']] + $value]]]]]);

        return $this->call('POST', '/api/crm/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-app'),
        ], $body);
    }

    private function inbound(string $from, string $text, string $id, string $name = 'Pelanggan'): void
    {
        $this->webhook([
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => $name]]],
            'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ])->assertOk();
    }

    public function test_webhook_verification_and_signature(): void
    {
        $this->get('/api/crm/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=verif-123&hub.challenge=987')->assertOk()->assertSee('987');
        $this->get('/api/crm/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=salah&hub.challenge=987')->assertForbidden();
        $this->call('POST', '/api/crm/whatsapp/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=palsu'], '{}')->assertStatus(401);
        $this->assertSame(1, WaTemplate::where('status', 'APPROVED')->count());
        $this->assertSame(2, WaTemplate::where('name', 'promo_akhir_pekan')->value('body_params'));
    }

    public function test_inbound_chat_creates_customer_and_cs_replies_within_24h_window(): void
    {
        $this->inbound('6281277778888', 'Halo, wagyu ready?', 'wamid.IN1', 'Dina');
        $this->inbound('6281277778888', 'Halo, wagyu ready?', 'wamid.IN1', 'Dina'); // kiriman ulang Meta tidak menggandakan

        $dina = Customer::where('phone', '6281277778888')->firstOrFail();
        $this->assertSame('Dina', $dina->name);
        $c = Conversation::firstOrFail();
        $this->assertSame(1, $c->messages()->count());
        $this->assertSame(1, $c->unread_count);

        $this->actingAs($this->crm)->get(route('core.crm.inbox.index'))->assertOk()->assertSee('Dina');
        $this->actingAs($this->crm)->get(route('core.crm.inbox.show', $c))->assertOk()->assertSee('wagyu ready');
        $this->actingAs($this->crm)->post(route('core.crm.inbox.reply', $c), ['body' => 'Ready kak, 350rb/kg'])->assertRedirect();
        $this->assertSame('6281277778888', $this->wa->sent[0]['to']);
        $out = Message::where('direction', 'out')->firstOrFail();
        $this->assertSame('sent', $out->status);

        // Status dari Meta: tidak boleh mundur (read lalu delivered tetap read).
        foreach (['delivered', 'read', 'delivered'] as $st) {
            $this->webhook(['statuses' => [['id' => $out->wa_message_id, 'status' => $st, 'timestamp' => (string) now()->timestamp, 'recipient_id' => '6281277778888']]])->assertOk();
        }
        $this->assertSame('read', $out->fresh()->status);

        // Lewat 24 jam: balasan teks ditolak, harus template.
        $this->travel(25)->hours();
        $this->actingAs($this->crm)->post(route('core.crm.inbox.reply', $c), ['body' => 'Masih ada?'])->assertSessionHas('error');
        $this->assertCount(1, $this->wa->sent);
    }

    public function test_keywords_join_community_and_opt_out(): void
    {
        $community = Community::create(['name' => 'Pecinta Steak Solo', 'join_keyword' => 'STEAKSOLO']);
        $this->inbound('6281300001111', 'steaksolo', 'wamid.K1', 'Rio');
        $rio = Customer::where('phone', '6281300001111')->firstOrFail();
        $this->assertTrue($community->members()->whereKey($rio->id)->exists());
        $this->assertSame('opted_in', $rio->wa_status);
        $this->assertStringContainsString('Selamat bergabung', $this->wa->sent[0]['body']);

        $this->inbound('6281300001111', 'STOP', 'wamid.K2');
        $this->assertSame('opted_out', $rio->fresh()->wa_status);
    }

    public function test_send_ad_to_community_members_who_opted_in(): void
    {
        $community = Community::create(['name' => 'Komunitas Steak']);
        $mk = fn (string $phone, string $name, string $wa) => Customer::create(['phone' => $phone, 'name' => $name, 'wa_status' => $wa]);
        $a = $mk('6281200000001', 'Ani Lestari', 'opted_in');
        $b = $mk('6281200000002', 'Bima Sakti', 'opted_in');
        $c = $mk('6281200000003', 'Citra Ayu', 'opted_in');
        $d = $mk('6281200000004', 'Doni Tolak', 'opted_out');
        $this->actingAs($this->crm)->post(route('core.crm.communities.members.store', $community), ['phone' => '0812-0000-0001'])->assertSessionHas('ok');
        foreach ([$b, $c, $d] as $x) {
            $community->members()->attach($x->id, ['source' => 'manual', 'joined_at' => now()]);
        }

        $template = WaTemplate::where('name', 'promo_akhir_pekan')->firstOrFail();
        $this->actingAs($this->crm)->post(route('core.crm.campaigns.store'), [
            'name' => 'Promo wagyu', 'crm_wa_template_id' => $template->id, 'community_ids' => [$community->id],
            'params' => ['{first_name}', '20%'], 'action' => 'launch',
        ])->assertSessionHasErrors('media'); // template header gambar wajib materi

        $this->wa->failFor['6281200000003'] = '131026 Message undeliverable';
        $this->actingAs($this->crm)->post(route('core.crm.campaigns.store'), [
            'name' => 'Promo wagyu', 'crm_wa_template_id' => $template->id, 'community_ids' => [$community->id],
            'params' => ['{first_name}', '20%'], 'media_url' => 'https://cdn.indoguna.test/wagyu.jpg', 'action' => 'launch',
        ])->assertRedirect()->assertSessionMissing('error');
        $campaign = Campaign::firstOrFail();
        $this->assertSame('sending', $campaign->status);
        $this->assertSame(3, $campaign->recipient_count, 'yang opt-out tidak ikut');

        $b->update(['wa_status' => 'opted_out']); // opt-out setelah kampanye dimulai
        $this->artisan('rasentra:crm-campaigns')->assertSuccessful(); // batas 2 per menit
        $this->artisan('rasentra:crm-campaigns')->assertSuccessful();
        $campaign->refresh();
        $this->assertSame('done', $campaign->status);

        $this->assertCount(1, $this->wa->sent);
        $this->assertSame(['Ani', '20%'], $this->wa->sent[0]['params']);
        $this->assertSame(['type' => 'IMAGE', 'link' => 'https://cdn.indoguna.test/wagyu.jpg'], $this->wa->sent[0]['header']);
        $stats = CampaignService::stats($campaign);
        $this->assertSame([1, 1, 1], [$stats['sent'], $stats['failed'], $stats['skipped']]);

        $this->actingAs($this->crm)->get(route('core.crm.campaigns.show', $campaign))->assertOk()->assertSee('Halo Budi, wagyu diskon 20%')->assertSee('Message undeliverable');
        $this->actingAs($this->crm)->get(route('core.crm.communities.show', $community))->assertOk()->assertSee('Promo wagyu');
        $this->actingAs($this->crm)->get(route('core.crm.campaigns.create', ['community' => $community->id]))->assertOk()->assertSee('promo_akhir_pekan')->assertDontSee('draf_belum_disetujui');
    }

    public function test_scheduled_campaign_waits_until_due(): void
    {
        $community = Community::create(['name' => 'Reseller']);
        $x = Customer::create(['phone' => '6281299990000', 'name' => 'Rina', 'wa_status' => 'opted_in']);
        $community->members()->attach($x->id, ['source' => 'manual']);
        $template = WaTemplate::where('name', 'promo_akhir_pekan')->firstOrFail();
        $this->actingAs($this->crm)->post(route('core.crm.campaigns.store'), [
            'name' => 'Besok', 'crm_wa_template_id' => $template->id, 'community_ids' => [$community->id], 'params' => ['{name}', '10%'],
            'media_url' => 'https://cdn.indoguna.test/a.jpg', 'scheduled_at' => now()->addDay()->format('Y-m-d H:i'), 'action' => 'launch',
        ]);
        $campaign = Campaign::firstOrFail();
        $this->assertSame('scheduled', $campaign->status);
        $this->artisan('rasentra:crm-campaigns');
        $this->assertCount(0, $this->wa->sent);
        $this->travel(25)->hours();
        $this->artisan('rasentra:crm-campaigns');
        $this->assertCount(1, $this->wa->sent);
        $this->assertSame('done', $campaign->fresh()->status);
    }
}

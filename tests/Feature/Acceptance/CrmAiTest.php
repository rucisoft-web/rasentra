<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Crm\AiAssistant;
use App\Domain\Integration\Ai\AiClient;
use App\Domain\Integration\Ai\FakeAiClient;
use App\Domain\Integration\WhatsApp\FakeWhatsAppClient;
use App\Domain\Integration\WhatsApp\WhatsAppClient;
use App\Models\Crm\Branch;
use App\Models\Crm\Conversation;
use App\Models\Crm\KbArticle;
use App\Models\Crm\Message;
use App\Models\Crm\WaChannel;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/** Rasentra CRM tahap 4: knowledge base, asisten chat AI dan identifikasi lead. */
class CrmAiTest extends AcceptanceTestCase
{
    private User $crm;

    private FakeWhatsAppClient $wa;

    private FakeAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.app_secret' => 'rahasia-app']);
        $this->crm = User::create(['name' => 'CS', 'email' => 'cs@hq.test', 'password' => Hash::make('x'), 'role' => 'hq_crm', 'is_active' => true]);
        $this->wa = new FakeWhatsAppClient([]);
        $this->app->instance(WhatsAppClient::class, $this->wa);
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
        $this->actingAs($this->crm)->post(route('core.crm.whatsapp.store'), [
            'name' => 'WA Pusat', 'phone_number_id' => '1098765432', 'waba_id' => '2233445566', 'access_token' => 'EAAG-test',
            'crm_branch_id' => Branch::forOutlet($this->outlet->id)->id, 'send_per_minute' => 60, 'ai_mode' => 'auto',
        ])->assertSessionHas('ok');
        $this->actingAs($this->crm)->post(route('core.crm.knowledge.store'), [
            'title' => 'Katering dan acara', 'keywords' => 'katering, pax, acara', 'is_active' => 1,
            'body' => 'Katering minimal 30 porsi, pesan paling lambat 3 hari sebelumnya. Penawaran dibuat tim penjualan.',
        ])->assertSessionHas('ok');
        KbArticle::create(['title' => 'Jam buka', 'keywords' => 'buka, tutup', 'body' => 'Buka setiap hari 10.00-21.00.', 'is_active' => true]);
    }

    private function answer(array $override = []): array
    {
        return $override + [
            'reply' => 'Katering kami melayani mulai 30 porsi, Kak. Boleh info tanggal dan jumlah tamunya?',
            'confidence' => 0.9, 'needs_human' => false, 'handoff_reason' => '',
            'lead_score' => 85, 'lead_stage' => 'hot', 'intent' => 'katering',
            'summary' => 'Butuh katering 50 pax untuk acara kantor.', 'interest' => 'Katering prasmanan',
            'estimated_value' => 7500000, 'next_action' => 'Kirim penawaran katering 50 pax',
        ];
    }

    private function inbound(string $text, string $id): void
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '2233445566', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1098765432'],
            'contacts' => [['wa_id' => '6281300001111', 'profile' => ['name' => 'Bu Ratna']]],
            'messages' => [['from' => '6281300001111', 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
        $this->call('POST', '/api/crm/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-app'),
        ], $body)->assertOk();
    }

    public function test_auto_mode_replies_from_knowledge_base_and_scores_hot_lead(): void
    {
        $this->ai->responses = [$this->answer()];
        $this->inbound('Halo, bisa katering 50 pax untuk acara kantor tgl 12?', 'wamid.A1');

        $this->assertCount(1, $this->ai->calls);
        $prompt = $this->ai->calls[0]['messages'][0]['content'];
        $this->assertStringContainsString('Katering minimal 30 porsi', $prompt, 'artikel relevan masuk ke prompt');
        $this->assertStringNotContainsString('Buka setiap hari', $prompt, 'artikel tidak relevan tidak ikut');
        $this->assertStringContainsString('Pelanggan: Halo, bisa katering', $prompt);
        $this->assertFalse($this->ai->calls[0]['schema']['additionalProperties']);

        $reply = Message::where('direction', 'out')->sole();
        $this->assertTrue($reply->by_ai);
        $this->assertStringStartsWith('Katering kami melayani', $this->wa->sent[0]['body']);

        $c = Conversation::sole();
        $this->assertSame(85, $c->lead_score);
        $this->assertSame('hot', $c->lead_stage);
        $this->assertTrue($c->customer->tags()->where('name', AiAssistant::HOT_TAG)->exists());

        $this->actingAs($this->crm)->get(route('core.crm.leads.index'))->assertOk()->assertSee('Bu Ratna')->assertSee('Kirim penawaran katering 50 pax');
        $this->actingAs($this->crm)->get(route('core.crm.inbox.show', $c))->assertOk()->assertSee('Asisten AI')->assertSee('Panas');
    }

    public function test_uncertain_or_sensitive_question_is_handed_to_cs_without_reply(): void
    {
        $this->ai->responses = [$this->answer(['needs_human' => true, 'handoff_reason' => 'Komplain pesanan', 'confidence' => 0.4,
            'reply' => 'Mohon maaf, CS kami segera membantu.', 'lead_score' => 20, 'intent' => 'komplain'])];
        $this->inbound('Daging pesanan saya kemarin bau, minta refund', 'wamid.B1');

        $this->assertSame([], $this->wa->sent, 'AI tidak membalas sendiri');
        $c = Conversation::sole();
        $this->assertTrue($c->ai_handoff);
        $this->assertSame('cold', $c->lead_stage);
        $this->assertSame('Mohon maaf, CS kami segera membantu.', $c->ai_suggestion);

        $this->actingAs($this->crm)->get(route('core.crm.inbox.index', ['filter' => 'handoff']))->assertOk()->assertSee('Perlu CS');
        // CS mengirim balasan (boleh dari saran AI): status serah-terima selesai dan AI berhenti membalas 12 jam.
        $this->actingAs($this->crm)->post(route('core.crm.inbox.reply', $c), ['body' => 'Mohon maaf Bu, kami ganti hari ini.'])->assertRedirect();
        $this->assertFalse($c->fresh()->ai_handoff);

        $this->ai->responses = [$this->answer()];
        $this->inbound('Baik, terima kasih', 'wamid.B2');
        $this->assertCount(1, $this->wa->sent, 'CS sedang menangani, AI hanya memberi saran');
    }

    public function test_suggest_mode_button_and_ai_disabled_without_key(): void
    {
        WaChannel::query()->update(['ai_mode' => 'suggest']);
        $this->ai->responses = [$this->answer(['lead_score' => 60, 'reply' => 'Boleh, Kak. Untuk tanggal berapa?'])];
        $this->inbound('mau tanya katering', 'wamid.C1');
        $this->assertSame([], $this->wa->sent, 'mode saran tidak mengirim');
        $c = Conversation::sole();
        $this->assertSame('warm', $c->lead_stage);
        $this->actingAs($this->crm)->get(route('core.crm.inbox.show', $c))->assertOk()->assertSee('Boleh, Kak. Untuk tanggal berapa?');

        $this->ai->responses = [$this->answer(['reply' => 'Saran kedua'])];
        $this->actingAs($this->crm)->post(route('core.crm.inbox.assist', $c))->assertSessionHas('ok');
        $this->assertSame('Saran kedua', $c->fresh()->ai_suggestion);

        // Lead yang belum dianalisis sejak pesan terakhir dinilai oleh tugas terjadwal.
        $c->update(['lead_scored_at' => now()->subHour()]);
        $this->ai->responses = [$this->answer(['lead_score' => 92])];
        Artisan::call('rasentra:crm-ai-leads');
        $this->assertSame(92, $c->fresh()->lead_score);

        WaChannel::query()->update(['ai_mode' => 'off']);
        $this->inbound('halo lagi', 'wamid.C2');
        $this->assertCount(3, $this->ai->calls, 'mode off tidak memanggil AI');
    }

    public function test_knowledge_base_crud_and_search_preview(): void
    {
        $this->actingAs($this->crm)->get(route('core.crm.knowledge.index', ['uji' => 'jam berapa outlet buka?']))
            ->assertOk()->assertSee('Jam buka')->assertSee('skor');
        $article = KbArticle::where('title', 'Jam buka')->firstOrFail();
        $this->actingAs($this->crm)->put(route('core.crm.knowledge.update', $article), ['title' => 'Jam buka outlet', 'body' => 'Buka 09.00-22.00.', 'is_active' => 0])
            ->assertRedirect(route('core.crm.knowledge.index'));
        $this->assertFalse($article->fresh()->is_active);
        $this->actingAs($this->crm)->delete(route('core.crm.knowledge.destroy', $article))->assertRedirect();
        $this->assertSame(1, KbArticle::count());
    }
}

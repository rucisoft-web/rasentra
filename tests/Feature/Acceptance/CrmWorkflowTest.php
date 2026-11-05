<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Crm\CustomerDirectory;
use App\Domain\Crm\WorkflowEngine;
use App\Domain\Integration\WhatsApp\FakeWhatsAppClient;
use App\Domain\Integration\WhatsApp\WhatsAppClient;
use App\Models\Crm\Branch;
use App\Models\Crm\Community;
use App\Models\Crm\Customer;
use App\Models\Crm\Tag;
use App\Models\Crm\WaChannel;
use App\Models\Crm\WaTemplate;
use App\Models\Crm\Workflow;
use App\Models\Crm\WorkflowRun;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/** Rasentra CRM tahap 3: chat automation workflow. */
class CrmWorkflowTest extends AcceptanceTestCase
{
    private User $crm;

    private FakeWhatsAppClient $wa;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.app_secret' => 'rahasia-app']);
        $this->crm = User::create(['name' => 'CS', 'email' => 'cs@hq.test', 'password' => Hash::make('x'), 'role' => 'hq_crm', 'is_active' => true]);
        $this->wa = new FakeWhatsAppClient([
            ['name' => 'ucapan_ultah', 'language' => 'id', 'category' => 'MARKETING', 'status' => 'APPROVED', 'components' => [
                ['type' => 'BODY', 'text' => 'Selamat ulang tahun {{1}}! Pakai kode {{2}} untuk diskon 20%.'],
            ]],
            ['name' => 'info_pesanan', 'language' => 'id', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [
                ['type' => 'BODY', 'text' => 'Terima kasih {{1}}, belanja Anda sudah kami terima.'],
            ]],
        ]);
        $this->app->instance(WhatsAppClient::class, $this->wa);
        $this->actingAs($this->crm)->post(route('core.crm.whatsapp.store'), [
            'name' => 'WA Pusat', 'phone_number_id' => '1098765432', 'waba_id' => '2233445566', 'access_token' => 'EAAG-test',
            'crm_branch_id' => Branch::forOutlet($this->outlet->id)->id, 'send_per_minute' => 60,
        ]);
        $this->actingAs($this->crm)->post(route('core.crm.whatsapp.templates', WaChannel::firstOrFail()));
    }

    private function inbound(string $from, string $text, string $id): TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '2233445566', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1098765432'],
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Rina Wijaya']]],
            'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);

        return $this->call('POST', '/api/crm/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-app'),
        ], $body)->assertOk();
    }

    private function activate(Workflow $wf): void
    {
        $this->actingAs($this->crm)->post(route('core.crm.workflows.toggle', $wf))->assertSessionHas('ok');
        $this->assertTrue($wf->fresh()->is_active);
    }

    public function test_keyword_workflow_built_from_form_replies_waits_then_continues(): void
    {
        $tag = Tag::firstOrCreate(['name' => 'Tanya menu']);
        $this->actingAs($this->crm)->get(route('core.crm.workflows.create'))->assertOk()->assertSee('Langkah 1');
        $this->actingAs($this->crm)->post(route('core.crm.workflows.store'), [
            'name' => 'Balas MENU', 'trigger' => 'keyword', 'keywords' => 'Menu, harga', 'cooldown_days' => 0,
            'steps' => [
                ['type' => 'send_text', 'body' => 'Halo {first_name}, ini menu kami.'],
                ['type' => 'add_tag', 'tag_id' => $tag->id],
                ['type' => 'wait', 'hours' => 2],
                ['type' => 'send_text', 'body' => 'Sudah pilih menunya?'],
                ['type' => ''],
            ],
        ])->assertRedirect();
        $wf = Workflow::where('name', 'Balas MENU')->firstOrFail();
        $this->assertFalse($wf->is_active, 'workflow baru disimpan nonaktif');
        $this->assertSame('menu, harga', $wf->trigger_config['keywords']);
        $this->assertCount(4, $wf->steps);

        $this->inbound('6281299990001', 'boleh minta menu?', 'wamid.K0');
        $this->assertSame(0, WorkflowRun::count(), 'workflow nonaktif tidak berjalan');

        $this->activate($wf);
        $this->inbound('6281299990001', 'Kak, minta MENU dong', 'wamid.K1');
        $this->inbound('6281299990002', 'menunya enak', 'wamid.K2'); // "menunya" bukan kata "menu"

        $run = WorkflowRun::sole();
        $customer = Customer::where('phone', '6281299990001')->firstOrFail();
        $this->assertSame($customer->id, $run->crm_customer_id);
        $this->assertSame('waiting', $run->status);
        $this->assertTrue($customer->tags()->whereKey($tag->id)->exists());
        $texts = collect($this->wa->sent)->where('type', 'text')->pluck('body');
        $this->assertContains('Halo Rina, ini menu kami.', $texts);
        $this->assertNotContains('Sudah pilih menunya?', $texts);

        // Pesan kata kunci kedua saat run masih menunggu tidak memulai run ganda.
        $this->inbound('6281299990001', 'menu', 'wamid.K3');
        $this->assertSame(1, WorkflowRun::count());

        $this->travel(3)->hours();
        $this->assertSame(['resumed' => 1, 'started' => 0], app(WorkflowEngine::class)->tick());
        $this->assertSame('done', $run->fresh()->status);
        $this->assertContains('Sudah pilih menunya?', collect($this->wa->sent)->where('type', 'text')->pluck('body'));

        $this->actingAs($this->crm)->get(route('core.crm.workflows.show', $wf))->assertOk()->assertSee('Rina Wijaya')->assertSee('Pesan teks terkirim');
        $this->actingAs($this->crm)->get(route('core.crm.workflows.index'))->assertOk()->assertSee('Balas MENU');
    }

    public function test_birthday_scan_respects_opt_in_and_runs_once_per_day(): void
    {
        $template = WaTemplate::where('name', 'ucapan_ultah')->firstOrFail();
        $wf = Workflow::create(['name' => 'Ultah', 'trigger' => 'birthday', 'trigger_config' => [], 'is_active' => true, 'cooldown_days' => 300,
            'steps' => [['type' => 'send_template', 'template_id' => $template->id, 'params' => ['{first_name}', 'ULTAH20']]]]);

        Date::setTestNow('2026-11-05 03:00:00'); // 10.00 WIB
        $dir = app(CustomerDirectory::class);
        [$optIn] = $dir->upsert('6281211110001', ['name' => 'Budi Santoso', 'birth_date' => '1990-11-05'], null, 'import');
        [$unknown] = $dir->upsert('6281211110002', ['name' => 'Sari', 'birth_date' => '1992-11-05'], null, 'import');
        [$optOut] = $dir->upsert('6281211110003', ['name' => 'Tono', 'birth_date' => '1985-11-05'], null, 'import');
        $dir->upsert('6281211110004', ['name' => 'Bukan hari ini', 'birth_date' => '1985-11-06'], null, 'import');
        $optIn->update(['wa_status' => 'opted_in']);
        $optOut->update(['wa_status' => 'opted_out']);

        $this->assertSame(2, app(WorkflowEngine::class)->tick()['started'], 'opt-out tidak dipindai');
        $this->assertSame(['resumed' => 0, 'started' => 0], app(WorkflowEngine::class)->tick(), 'hanya sekali sehari');

        $sent = collect($this->wa->sent)->where('type', 'template');
        $this->assertCount(1, $sent, 'template MARKETING hanya ke pelanggan opt-in');
        $this->assertSame(['Budi', 'ULTAH20'], $sent->first()['params']);
        $this->assertStringContainsString('belum opt-in', implode(' ', WorkflowRun::where('crm_customer_id', $unknown->id)->sole()->log[0]));
    }

    public function test_daily_scan_waits_until_morning_local_time(): void
    {
        Workflow::create(['name' => 'Win-back 60', 'trigger' => 'inactive', 'trigger_config' => ['days' => 60], 'is_active' => true,
            'steps' => [['type' => 'add_tag', 'tag_id' => Tag::firstOrCreate(['name' => 'Win-back'])->id]]]);
        Date::setTestNow('2026-11-05 00:30:00'); // 07.30 WIB
        $dir = app(CustomerDirectory::class);
        [$c] = $dir->upsert('6281233330001', ['name' => 'Lama Tak Datang'], null, 'import');
        $dir->recordPurchase($c, 'accurate', 'INV-1', '2026-09-06', '250000');

        $this->assertSame(0, app(WorkflowEngine::class)->tick()['started']);
        Date::setTestNow('2026-11-05 02:00:00'); // 09.00 WIB
        $this->assertSame(1, app(WorkflowEngine::class)->tick()['started']);
        $this->assertTrue($c->tags()->where('name', 'Win-back')->exists());
    }

    public function test_purchase_and_new_customer_triggers_with_cooldown(): void
    {
        $template = WaTemplate::where('name', 'info_pesanan')->firstOrFail();
        $branch = Branch::forOutlet($this->outlet->id);
        Workflow::create(['name' => 'Terima kasih', 'trigger' => 'purchase', 'trigger_config' => ['min_amount' => 100000], 'is_active' => true, 'cooldown_days' => 7,
            'steps' => [['type' => 'send_template', 'template_id' => $template->id, 'params' => ['{name}']]]]);
        $community = Community::create(['name' => 'Member Baru Uji']);
        Workflow::create(['name' => 'Member baru', 'trigger' => 'new_customer', 'trigger_config' => ['crm_branch_id' => $branch->id], 'is_active' => true,
            'steps' => [['type' => 'join_community', 'community_id' => $community->id]]]);

        $dir = app(CustomerDirectory::class);
        [$c] = $dir->upsert('6281244440001', ['name' => 'Andi'], $branch->id, 'pos');
        [$imported] = $dir->upsert('6281244440002', ['name' => 'Impor'], $branch->id, 'import');
        $this->assertTrue($community->members()->whereKey($c->id)->exists());
        $this->assertFalse($community->members()->whereKey($imported->id)->exists(), 'impor massal tidak memicu workflow');

        $today = now()->toDateString();
        $dir->recordPurchase($c, 'pos', 'ORD-1', $today, '50000');      // di bawah minimal
        $dir->recordPurchase($c, 'pos', 'ORD-2', $today, '150000');     // memicu (UTILITY: tidak butuh opt-in)
        $dir->recordPurchase($c, 'pos', 'ORD-3', $today, '200000');     // masa jeda 7 hari
        $dir->recordPurchase($c, 'accurate', 'INV-OLD', '2026-01-10', '900000'); // riwayat lama tidak memicu

        $sent = collect($this->wa->sent)->where('type', 'template')->where('name', 'info_pesanan');
        $this->assertCount(1, $sent);
        $this->assertSame(['Andi'], $sent->first()['params']);
    }

    public function test_deactivating_cancels_waiting_runs_and_validation_requires_fields(): void
    {
        $this->actingAs($this->crm)->post(route('core.crm.workflows.store'), [
            'name' => 'Salah', 'trigger' => 'keyword', 'keywords' => 'promo',
            'steps' => [['type' => 'send_text', 'body' => ''], ['type' => 'add_tag']],
        ])->assertSessionHasErrors(['steps.0.type', 'steps.1.type']);

        $wf = Workflow::create(['name' => 'Tunggu', 'trigger' => 'keyword', 'trigger_config' => ['keywords' => 'promo'], 'is_active' => true,
            'steps' => [['type' => 'wait', 'hours' => 24], ['type' => 'send_text', 'body' => 'Promo hari ini!']]]);
        $this->inbound('6281255550001', 'ada promo?', 'wamid.P1');
        $this->assertSame('waiting', WorkflowRun::sole()->status, (string) WorkflowRun::sole()->error);

        $this->actingAs($this->crm)->post(route('core.crm.workflows.toggle', $wf))->assertSessionHas('ok');
        $this->assertSame('cancelled', WorkflowRun::sole()->status);
        $this->travel(2)->days();
        app(WorkflowEngine::class)->tick();
        $this->assertNotContains('Promo hari ini!', collect($this->wa->sent)->pluck('body'));
    }
}

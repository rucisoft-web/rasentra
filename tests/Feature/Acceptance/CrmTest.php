<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Crm\SegmentQuery;
use App\Domain\Integration\Accurate\AccurateClient;
use App\Domain\Integration\Accurate\FakeAccurateClient;
use App\Domain\Sync\InboxReceiver;
use App\Models\BusinessUnit;
use App\Models\Crm\AccurateConnection;
use App\Models\Crm\Customer;
use App\Models\Crm\Import;
use App\Models\Crm\Segment;
use App\Models\Crm\Tag;
use App\Models\Device;
use App\Models\OutboxEvent;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

/** Rasentra CRM tahap 1: impor member per outlet, pelanggan dari POS dan Accurate, segmentasi. */
class CrmTest extends AcceptanceTestCase
{
    private User $crm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crm = User::create(['name' => 'CS Pusat', 'email' => 'crm@hq.test', 'password' => Hash::make('rahasia'), 'role' => 'hq_crm', 'is_active' => true]);
    }

    private function csv(string $content, string $name = 'member.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_access_is_limited_to_crm_and_admin_roles(): void
    {
        $this->actingAs($this->crm)->get(route('core.crm.customers.index'))->assertOk()->assertSee('Total pelanggan');
        $this->actingAs($this->crm)->get(route('core.prices.index'))->assertForbidden();
        $merch = User::create(['name' => 'Merch', 'email' => 'm@hq.test', 'password' => Hash::make('x'), 'role' => 'hq_merch', 'is_active' => true]);
        $this->actingAs($merch)->get(route('core.crm.customers.index'))->assertForbidden();
        $this->actingAs($this->crm)->get(route('core.dashboard'))->assertOk()->assertSee('Rasentra CRM');
    }

    public function test_import_members_per_outlet_merges_duplicates_across_outlets(): void
    {
        $other = Outlet::where('id', '!=', $this->outlet->id)->first()
            ?? Outlet::create(['business_unit_id' => $this->outlet->business_unit_id, 'region_id' => $this->outlet->region_id, 'code' => 'UJ2', 'name' => 'Outlet Dua']);
        $vip = Tag::create(['name' => 'VIP']);

        $first = "Kode Member;Nama;No HP;Tanggal Lahir;Kota\nM-1;Budi Santoso;0812-3456-7890;17/08/1985;Surakarta\nM-2;Sari;+62 813 1111 2222;;\nM-3;Rusak;12345;;\nM-4;Sari lagi;081311112222;;\n";
        $this->actingAs($this->crm)->post(route('core.crm.imports.store'), ['outlet_id' => $this->outlet->id, 'tag_id' => $vip->id, 'file' => $this->csv($first)])
            ->assertRedirect()->assertSessionHas('ok');
        $this->assertSame(2, Customer::count());
        $budi = Customer::where('phone', '6281234567890')->firstOrFail();
        $this->assertSame('Budi Santoso', $budi->name);
        $this->assertSame('1985-08-17', $budi->birth_date->toDateString());
        $this->assertTrue($budi->tags->contains($vip));
        $import = Import::latest('id')->first();
        $this->assertSame([2, 0, 2], [$import->created_count, $import->merged_count, $import->error_count]);

        // Outlet lain mengimpor nomor yang sama dalam format berbeda: tetap satu pelanggan, dua keanggotaan.
        $second = "nama,whatsapp,email\nBudi S.,6281234567890,budi@contoh.id\nAni,0857 0000 1111,\n";
        $this->actingAs($this->crm)->post(route('core.crm.imports.store'), ['outlet_id' => $other->id, 'file' => $this->csv($second)])->assertRedirect();
        $this->assertSame(3, Customer::count());
        $budi->refresh();
        $this->assertSame('Budi Santoso', $budi->name, 'nama lama tidak ditimpa');
        $this->assertSame('budi@contoh.id', $budi->email, 'data kosong dilengkapi');
        $this->assertEqualsCanonicalizing([$this->outlet->id, $other->id], $budi->memberships()->pluck('outlet_id')->all());
        $this->assertSame('M-1', $budi->memberships()->where('outlet_id', $this->outlet->id)->value('member_code'));

        $this->actingAs($this->crm)->get(route('core.crm.customers.index', ['outlet' => $other->id]))->assertOk()->assertSee('Budi Santoso')->assertDontSee('Sari');
        $this->actingAs($this->crm)->get(route('core.crm.customers.show', $budi))->assertOk()->assertSee('M-1');
        $this->actingAs($this->crm)->get(route('core.crm.imports.show', $import))->assertOk()->assertSee('Nomor HP tidak valid');

        $this->actingAs($this->crm)->post(route('core.crm.imports.store'), ['outlet_id' => $this->outlet->id, 'file' => $this->csv("Nama;Alamat\nA;B\n")])
            ->assertSessionHasErrors();
    }

    public function test_xlsx_member_file_is_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Nama</t></si><si><t>No HP</t></si><si><t>Dewi</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>81299998888</v></c></row></sheetData></worksheet>');
        $zip->close();
        $file = new UploadedFile($path, 'member.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->crm)->post(route('core.crm.imports.store'), ['outlet_id' => $this->outlet->id, 'file' => $file])->assertRedirect()->assertSessionHas('ok');
        $this->assertSame('Dewi', Customer::where('phone', '6281299998888')->value('name'));
    }

    public function test_pos_orders_with_customer_phone_build_purchase_history(): void
    {
        $order = $this->newOrder();
        $this->orders()->addSku($order, Product::where('barcode', '8991234500011')->first(), '2');
        $this->orders()->setCustomer($order, ['customer_name' => 'Rudi', 'customer_phone' => '0811 2233 4455']);
        $order = $this->payAndFinalize($order);
        $event = OutboxEvent::where('event_type', 'order.finalized')->firstOrFail();
        Device::create(['outlet_id' => $this->outlet->id, 'code' => $event->source_id, 'app' => 'outlet_server', 'name' => 'Server']);
        $wire = $event->only(['event_id', 'source_id', 'outlet_id', 'entity_type', 'entity_id', 'entity_version', 'event_type', 'payload', 'checksum']);
        app(InboxReceiver::class)->receive([$wire], $event->source_id);

        $rudi = Customer::where('phone', '6281122334455')->firstOrFail();
        $this->assertSame(1, $rudi->purchase_count);
        $this->assertEquals($order->net_amount, $rudi->total_spent);
        $this->assertTrue($rudi->memberships()->where('outlet_id', $this->outlet->id)->where('source', 'pos')->exists());

        $this->artisan('rasentra:rebuild-sales')->assertSuccessful();
        $this->assertSame(1, $rudi->fresh()->purchase_count, 'bangun ulang proyeksi tidak menggandakan riwayat');
        $this->assertSame('new', SegmentQuery::stageOf($rudi->fresh()));
    }

    public function test_accurate_sync_pulls_customers_and_invoices_idempotently(): void
    {
        $company = BusinessUnit::findOrFail($this->outlet->business_unit_id);
        $today = now()->toDateString();
        $fake = new FakeAccurateClient(
            customers: [
                ['id' => '1', 'customer_no' => 'C-001', 'name' => 'PT Katering Jaya', 'phone' => '0821-5555-6666', 'email' => 'order@katering.id', 'city' => 'Solo'],
                ['id' => '2', 'customer_no' => 'C-002', 'name' => 'Tanpa HP', 'phone' => null, 'email' => null, 'city' => null],
            ],
            invoices: [
                ['id' => '901', 'number' => 'SI.2026.11.0001', 'date' => $today, 'amount' => '7500000', 'customer_no' => 'C-001'],
                ['id' => '902', 'number' => 'SI.2026.11.0002', 'date' => $today, 'amount' => '100000', 'customer_no' => 'C-999'],
            ],
        );
        $this->app->instance(AccurateClient::class, $fake);

        $this->actingAs($this->crm)->get(route('core.crm.accurate.index'))->assertOk()->assertSee('Belum terhubung');
        $this->actingAs($this->crm)->post(route('core.crm.accurate.sync', $company))->assertSessionHas('error');
        $this->actingAs($this->crm)->put(route('core.crm.accurate.update', $company), [
            'api_token' => 'aat.demo', 'signature_secret' => 'rahasia', 'default_outlet_id' => $this->outlet->id, 'sync_customers' => 1, 'sync_invoices' => 1,
        ])->assertSessionHas('ok');
        $conn = AccurateConnection::firstOrFail();
        $this->assertNotSame('aat.demo', $conn->getRawOriginal('api_token'), 'token terenkripsi');

        $this->actingAs($this->crm)->post(route('core.crm.accurate.sync', $company))->assertSessionHas('ok');
        $this->actingAs($this->crm)->post(route('core.crm.accurate.sync', $company))->assertSessionHas('ok');

        $kj = Customer::where('phone', '6282155556666')->firstOrFail();
        $this->assertSame(1, $kj->purchase_count);
        $this->assertSame('7500000.00', (string) $kj->total_spent);
        $this->assertSame('C-001', $kj->memberships()->value('member_code'));
        $this->assertSame('ok', $conn->fresh()->last_status);
        $this->assertStringContainsString('1 faktur tanpa pelanggan', $conn->fresh()->last_message);

        $this->artisan('rasentra:crm-accurate-sync')->assertSuccessful();
        $this->assertSame(1, $kj->fresh()->purchase_count);
    }

    public function test_segments_filter_by_rules_and_feed_the_customer_list(): void
    {
        $mk = fn (string $phone, string $name, array $extra = []) => Customer::create(['phone' => $phone, 'name' => $name] + $extra);
        $mk('6281100000001', 'Andi Setia', ['purchase_count' => 8, 'total_spent' => '6000000', 'first_purchase_on' => now()->subYear(), 'last_purchase_on' => now()->subDays(5), 'wa_status' => 'opted_in']);
        $mk('6281100000002', 'Tono Lama', ['purchase_count' => 2, 'total_spent' => '300000', 'first_purchase_on' => now()->subYear(), 'last_purchase_on' => now()->subDays(200), 'wa_status' => 'opted_in']);
        $mk('6281100000003', 'Uli Ultah', ['birth_date' => now()->subYears(30)->startOfMonth()->addDays(1)]);

        $this->actingAs($this->crm)->post(route('core.crm.segments.store'), ['name' => 'Win-back', 'inactive_days' => 90, 'wa' => 'opted_in'])->assertRedirect();
        $segment = Segment::where('name', 'Win-back')->firstOrFail();
        $this->assertSame(['wa' => 'opted_in', 'inactive_days' => 90], $segment->rules);
        $this->assertSame(['Tono Lama'], SegmentQuery::apply(Customer::query(), $segment->rules)->pluck('name')->all());

        $this->assertSame(['Andi Setia'], SegmentQuery::apply(Customer::query(), ['lifecycle' => 'loyal'])->pluck('name')->all());
        $this->assertSame(['Andi Setia'], SegmentQuery::apply(Customer::query(), ['min_total_spent' => 5000000])->pluck('name')->all());
        $this->assertSame(['Uli Ultah'], SegmentQuery::apply(Customer::query(), ['birthday_month' => 'current'])->pluck('name')->all());

        $this->actingAs($this->crm)->get(route('core.crm.segments.index'))->assertOk()->assertSee('tidak belanja &gt; 90 hari', false);
        $this->actingAs($this->crm)->get(route('core.crm.customers.index', ['segment' => $segment->id]))->assertOk()->assertSee('Tono Lama')->assertDontSee('Andi Setia');
        $csv = $this->actingAs($this->crm)->get(route('core.crm.customers.export', ['segment' => $segment->id]))->assertOk()->streamedContent();
        $this->assertStringContainsString('6281100000002', $csv);
        $this->assertStringNotContainsString('6281100000001', $csv);

        $tidur = Customer::where('name', 'Tono Lama')->first();
        $this->actingAs($this->crm)->put(route('core.crm.customers.update', $tidur), ['name' => 'Tono Lama', 'wa_status' => 'opted_out'])->assertSessionHas('ok');
        $this->assertSame(0, SegmentQuery::apply(Customer::query(), $segment->rules)->count(), 'opt-out keluar dari segmen promosi');
    }
}

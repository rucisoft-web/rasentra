<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Integration\Sap\FakeSapClient;
use App\Domain\Integration\Sap\SapClient;
use App\Domain\Integration\Sap\SapClientFactory;
use App\Domain\Integration\Sap\SapPostingService;
use App\Models\Device;
use App\Models\InboxEvent;
use App\Models\IntegrationException;
use App\Models\OutboxEvent;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\SapDocument;
use App\Models\SapMapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SyncAndSapTest extends AcceptanceTestCase
{
    private function finalizedOrderEvent(): OutboxEvent
    {
        $order = $this->newOrder();
        $this->orders()->addSku($order, Product::where('barcode', '8991234500011')->first(), '2');
        $this->payAndFinalize($order);

        return OutboxEvent::where('event_type', 'order.finalized')->firstOrFail();
    }

    private function wire(OutboxEvent $e): array
    {
        return $e->only(['event_id', 'source_id', 'outlet_id', 'entity_type', 'entity_id', 'entity_version', 'event_type', 'payload', 'checksum']);
    }

    /** U14: event berulang tidak menggandakan; ack_ref sama. Checksum salah ditolak. */
    public function test_u14_repeated_events_are_idempotent(): void
    {
        config(['rasentra.source_id' => 'SRV-UJI']);
        Device::create(['outlet_id' => $this->outlet->id, 'code' => 'SRV-UJI', 'app' => 'outlet_server', 'name' => 'Server UJI', 'token_hash' => hash('sha256', 'rahasia-uji')]);
        $event = $this->finalizedOrderEvent();

        $first = $this->withToken('rahasia-uji')->postJson('/api/v1/sync/events', ['events' => [$this->wire($event)]])->assertOk()->json('results.0');
        $second = $this->withToken('rahasia-uji')->postJson('/api/v1/sync/events', ['events' => [$this->wire($event)]])->assertOk()->json('results.0');

        $this->assertSame('accepted', $first['result']);
        $this->assertSame('duplicate', $second['result']);
        $this->assertSame($first['ack_ref'], $second['ack_ref']);
        $this->assertSame(1, InboxEvent::count());

        $tampered = $this->wire($event);
        $tampered['event_id'] = (string) Str::uuid7();
        $tampered['payload']['net_amount'] = '1.00';
        $this->withToken('rahasia-uji')->postJson('/api/v1/sync/events', ['events' => [$tampered]])
            ->assertOk()->assertJsonPath('results.0.result', 'rejected');

        $this->flushHeaders()->postJson('/api/v1/sync/events', ['events' => []])->assertUnauthorized();
    }

    private function mapEverything(): void
    {
        foreach (Product::all() as $p) {
            SapMapping::create(['company_db' => 'sap_solo', 'entity_type' => 'product', 'entity_key' => (string) $p->id, 'sap_code' => 'IT'.$p->code]);
        }
        SapMapping::create(['company_db' => 'sap_solo', 'entity_type' => 'payment_method', 'entity_key' => 'cash', 'sap_code' => '1110004']);
    }

    /** U15: SAP menyimpan tetapi respons timeout; retry menemukan dokumen dan tidak membuat kedua. */
    public function test_u15_timeout_after_commit_does_not_duplicate(): void
    {
        $this->mapEverything();
        $fake = new FakeSapClient;
        $fake->timeoutAfterCommitOnce = true;
        $this->app->instance(SapClient::class, $fake);
        $payload = $this->finalizedOrderEvent()->payload;

        $this->assertSame('needs_review', app(SapPostingService::class)->postSale($payload)['status']);
        $this->assertSame('posted', app(SapPostingService::class)->postSale($payload)['status']);
        $this->assertCount(1, $fake->documents['Invoices']);
        $this->assertCount(1, $fake->documents['IncomingPayments']);
    }

    /** U16: invoice berhasil, pembayaran gagal; retry hanya pembayaran. */
    public function test_u16_partial_posting_retries_payment_only(): void
    {
        $this->mapEverything();
        $fake = new FakeSapClient;
        $fake->failNext = ['IncomingPayments' => 1];
        $this->app->instance(SapClient::class, $fake);
        $payload = $this->finalizedOrderEvent()->payload;

        $this->assertSame('partially_posted', app(SapPostingService::class)->postSale($payload)['status']);
        $invoiceEntry = SapDocument::where('sap_object', 'Invoices')->value('doc_entry');
        $this->assertSame('posted', app(SapPostingService::class)->postSale($payload)['status']);
        $this->assertSame($invoiceEntry, SapDocument::where('sap_object', 'Invoices')->value('doc_entry'));
        $this->assertCount(1, $fake->documents['Invoices']);
    }

    public function test_missing_mapping_goes_to_exception_queue(): void
    {
        $this->app->instance(SapClient::class, new FakeSapClient);
        $result = app(SapPostingService::class)->postSale($this->finalizedOrderEvent()->payload);
        $this->assertSame('failed', $result['status']);
        $this->assertDatabaseHas('integration_exceptions', ['kind' => 'missing_mapping']);
        $this->assertSame(0, SapDocument::count());
    }

    /** Indoguna Group: penjualan outlet Lombok diposting ke instance sap_lombok dengan mapping-nya sendiri. */
    public function test_each_company_posts_to_its_own_sap_instance(): void
    {
        $this->mapEverything(); // hanya sap_solo
        $fake = new FakeSapClient;
        $this->app->instance(SapClient::class, $fake);
        $lombok = Outlet::where('code', 'LBK')->firstOrFail();
        $product = Product::where('barcode', '8991234500011')->firstOrFail();
        $payload = [
            'id' => (string) Str::uuid7(), 'doc_no' => 'LBK-261029-0001', 'outlet_id' => $lombok->id, 'channel' => 'retail',
            'business_date' => '2026-10-29', 'gross_amount' => '90000.00', 'discount_amount' => '0.00', 'net_amount' => '90000.00',
            'lines' => [['line_no' => 1, 'line_type' => 'retail_product', 'product_id' => $product->id, 'pack_id' => null, 'quantity' => '2', 'uom' => 'PCS',
                'unit_price' => '45000.00', 'gross_amount' => '90000.00', 'discount_amount' => '0.00', 'net_amount' => '90000.00', 'discounts' => []]],
            'payments' => [['method' => 'cash', 'amount' => '90000.00', 'status' => 'succeeded', 'provider_ref' => null]],
        ];

        $result = app(SapPostingService::class)->postSale($payload);
        $this->assertSame('failed', $result['status'], 'Mapping sap_solo tidak berlaku untuk outlet Lombok');
        $this->assertStringContainsString('sap_lombok', IntegrationException::latest('id')->value('message'));

        SapMapping::create(['company_db' => 'sap_lombok', 'entity_type' => 'product', 'entity_key' => (string) $product->id, 'sap_code' => 'LB'.$product->code]);
        SapMapping::create(['company_db' => 'sap_lombok', 'entity_type' => 'payment_method', 'entity_key' => 'cash', 'sap_code' => '1110104']);
        SapMapping::create(['company_db' => 'sap_lombok', 'entity_type' => 'outlet', 'entity_key' => (string) $lombok->id, 'sap_code' => 'WH-LBK']);

        $this->assertSame('posted', app(SapPostingService::class)->postSale($payload)['status']);
        $this->assertSame(['sap_lombok'], SapDocument::distinct()->pluck('company_db')->all());
        $line = SapDocument::where('sap_object', 'Invoices')->firstOrFail()->request_payload['DocumentLines'][0];
        $this->assertSame(['LB'.$product->code, 'WH-LBK'], [$line['ItemCode'], $line['WarehouseCode']]);
    }

    public function test_sap_connection_settings_can_differ_per_instance(): void
    {
        config(['services.sap.url' => 'https://sap-solo:50000/b1s/v1', 'services.sap.username' => 'manager',
            'services.sap.companies.sap_lombok.url' => 'https://sap-lombok:50000/b1s/v1']);

        $solo = SapClientFactory::config('sap_solo');
        $lombok = SapClientFactory::config('sap_lombok');
        $this->assertSame(['https://sap-solo:50000/b1s/v1', 'sap_solo'], [$solo['url'], $solo['company_db']]);
        $this->assertSame(['https://sap-lombok:50000/b1s/v1', 'sap_lombok', 'manager'], [$lombok['url'], $lombok['company_db'], $lombok['username']]);
    }

    /** Lombok memakai WITA: lewat pukul 00.00 WITA tanggal bisnisnya sudah berganti, Solo belum. */
    public function test_business_date_follows_outlet_timezone(): void
    {
        $this->travelTo(Carbon::parse('2026-10-29 16:30:00', 'UTC'));
        $this->assertSame('2026-10-30', Outlet::businessDate(Outlet::where('code', 'LBK')->value('id')));
        $this->assertSame('2026-10-29', Outlet::businessDate($this->outlet->id));
    }
}

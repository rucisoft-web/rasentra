<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Integration\Sap\FakeSapClient;
use App\Domain\Integration\Sap\SapClient;
use App\Domain\Integration\Sap\SapPostingService;
use App\Models\Device;
use App\Models\InboxEvent;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\SapDocument;
use App\Models\SapMapping;

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
        config(['rasentra.source_id' => 'SRV-PJT']);
        Device::create(['outlet_id' => $this->outlet->id, 'code' => 'SRV-PJT', 'app' => 'outlet_server', 'name' => 'Server PJT', 'token_hash' => hash('sha256', 'rahasia-pjt')]);
        $event = $this->finalizedOrderEvent();

        $first = $this->withToken('rahasia-pjt')->postJson('/api/v1/sync/events', ['events' => [$this->wire($event)]])->assertOk()->json('results.0');
        $second = $this->withToken('rahasia-pjt')->postJson('/api/v1/sync/events', ['events' => [$this->wire($event)]])->assertOk()->json('results.0');

        $this->assertSame('accepted', $first['result']);
        $this->assertSame('duplicate', $second['result']);
        $this->assertSame($first['ack_ref'], $second['ack_ref']);
        $this->assertSame(1, InboxEvent::count());

        $tampered = $this->wire($event);
        $tampered['event_id'] = (string) \Illuminate\Support\Str::uuid7();
        $tampered['payload']['net_amount'] = '1.00';
        $this->withToken('rahasia-pjt')->postJson('/api/v1/sync/events', ['events' => [$tampered]])
            ->assertOk()->assertJsonPath('results.0.result', 'rejected');

        $this->flushHeaders()->postJson('/api/v1/sync/events', ['events' => []])->assertUnauthorized();
    }

    private function mapEverything(): void
    {
        foreach (Product::all() as $p) {
            SapMapping::create(['company_db' => 'PASARTANI', 'entity_type' => 'product', 'entity_key' => (string) $p->id, 'sap_code' => 'IT'.$p->code]);
        }
        SapMapping::create(['company_db' => 'PASARTANI', 'entity_type' => 'payment_method', 'entity_key' => 'cash', 'sap_code' => '1110004']);
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
}

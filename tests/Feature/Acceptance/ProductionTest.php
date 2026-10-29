<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Integration\Sap\FakeSapClient;
use App\Domain\Integration\Sap\SapClient;
use App\Domain\Integration\Sap\SapPostingService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Sync\InboxReceiver;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\SapDocument;
use App\Models\SapMapping;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\Decimal;

/** Rasentra Kitchen · Produksi: batch dari resep berstok dan potong & porsi, sampai Central dan SAP. */
class ProductionTest extends AcceptanceTestCase
{
    private RecipeVersion $sauce;

    protected function setUp(): void
    {
        parent::setUp();
        $product = Product::where('code', '0600601')->firstOrFail();
        $recipe = Recipe::create(['product_id' => $product->id, 'name' => 'Saus Lada Hitam']);
        $this->sauce = RecipeVersion::create(['recipe_id' => $recipe->id, 'version' => 1, 'yield_quantity' => 1, 'yield_uom' => 'KG', 'kind' => 'stocked_prep', 'status' => 'approved']);
        $this->sauce->lines()->create(['component_product_id' => $this->product('0500503')->id, 'quantity' => '0.100', 'uom_code' => 'KG']);
        $this->sauce->lines()->create(['component_product_id' => $this->product('0500502')->id, 'quantity' => '0.300', 'uom_code' => 'KG']);
        $this->actingAs(User::where('email', 'chef@uji.test')->firstOrFail());
    }

    private function product(string $code): Product
    {
        return Product::where('code', $code)->firstOrFail();
    }

    private function balance(string $code): string
    {
        return Decimal::qty(StockLedger::balance($this->outlet->id, $this->product($code)->id));
    }

    public function test_chef_produces_sauce_from_recipe_with_actual_yield(): void
    {
        $this->get('/kitchen')->assertOk()->assertSee('Produksi');
        $this->get('/kitchen/production')->assertOk()->assertSee('Dari resep');
        $data = $this->getJson('/kitchen/api/production')->assertOk();
        $this->assertSame('Saus Lada Hitam', $data->json('recipes.0.name'));
        $this->assertCount(2, $data->json('recipes.0.lines'));

        // Dua resep (2 kg), hasil 1,9 kg: susut 5% di atas toleransi 2% wajib alasan.
        $body = ['mode' => 'recipe', 'version_id' => $this->sauce->id, 'quantity' => 2, 'output' => 1.9,
            'inputs' => [['product_id' => $this->product('0500503')->id, 'quantity' => 0.25], ['product_id' => $this->product('0500502')->id, 'quantity' => 0.6]]];
        $this->postJson('/kitchen/api/production', $body)->assertStatus(422)->assertJsonPath('error', 'loss_reason_required');
        $res = $this->postJson('/kitchen/api/production', $body + ['loss_reason' => 'Susut masak'])->assertCreated();
        $this->assertMatchesRegularExpression('/^UJI-PR\d{6}-0001$/', $res->json('doc_no'));

        $this->assertSame('5.900000', $this->balance('0600601'));   // 4 + 1,9
        $this->assertSame('2.750000', $this->balance('0500503'));   // butter aktual 0,25 (standar 0,2)
        $this->assertSame('9.400000', $this->balance('0500502'));
        $batch = ProductionBatch::firstOrFail();
        $this->assertSame(['recipe', '2.000000', '0.100000', 'Susut masak'], [$batch->kind, (string) $batch->expected_quantity, (string) $batch->loss_quantity, $batch->loss_reason]);
        $this->assertSame('0.200000', $batch->inputs[0]['standard']);

        $doc = StockAdjustment::where('kind', 'production')->firstOrFail();
        $this->assertSame(2, $doc->lines->whereNull('to_state')->count());
        $this->assertSame('1.900000', (string) $doc->lines->firstWhere('to_state', 'available')->quantity);
        $this->assertSame(1, OutboxEvent::where('event_type', 'stock.adjusted')->count());

        $history = $this->getJson('/kitchen/api/production')->json('batches');
        $this->assertSame($doc->doc_no, $history[0]['doc_no']);
    }

    public function test_cutting_balances_input_against_portions_and_trim(): void
    {
        $raw = $this->product('0600602');
        $sauce = $this->product('0600601');
        $veg = $this->product('0500502');
        $outputs = [['product_id' => $raw->id, 'quantity' => 3]];

        $this->postJson('/kitchen/api/production', ['mode' => 'cutting', 'inputs' => [['product_id' => $veg->id, 'quantity' => 2]], 'outputs' => [['product_id' => $raw->id, 'quantity' => 2.5]]])
            ->assertStatus(422)->assertJsonPath('error', 'production_balance');
        $this->postJson('/kitchen/api/production', ['mode' => 'cutting', 'inputs' => [['product_id' => $veg->id, 'quantity' => 4]], 'outputs' => $outputs])
            ->assertStatus(422)->assertJsonPath('error', 'loss_reason_required');
        // Susut di bawah toleransi 2% tidak perlu alasan.
        $this->postJson('/kitchen/api/production', ['mode' => 'cutting', 'inputs' => [['product_id' => $veg->id, 'quantity' => 4]],
            'outputs' => [['product_id' => $raw->id, 'quantity' => 3.2], ['product_id' => $sauce->id, 'quantity' => 0.75]]])->assertCreated();
        $this->assertSame('0.050000', (string) ProductionBatch::firstOrFail()->loss_quantity);
        $this->assertSame('11.200000', $this->balance('0600602'));
    }

    public function test_production_reaches_central_report_and_posts_issue_and_receipt(): void
    {
        $this->postJson('/kitchen/api/production', ['mode' => 'recipe', 'version_id' => $this->sauce->id, 'quantity' => 1, 'output' => 1])->assertCreated();
        $events = OutboxEvent::where('event_type', 'stock.adjusted')->get();
        app(InboxReceiver::class)->receive($events->map(fn ($e) => $e->only(InboxReceiver::REQUIRED))->all(), $events->first()->source_id);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/core/reports/stock')->assertOk()->assertSee('Produksi')->assertSee('Hasil: Saus Lada Hitam (prep) 1');

        foreach (Product::all() as $p) {
            SapMapping::create(['company_db' => 'sap_solo', 'entity_type' => 'product', 'entity_key' => (string) $p->id, 'sap_code' => 'IT'.$p->code]);
        }
        $this->app->instance(SapClient::class, new FakeSapClient);
        $this->assertSame(['posted' => 1], app(SapPostingService::class)->postInbox());
        $this->assertCount(2, SapDocument::where('sap_object', 'InventoryGenExits')->firstOrFail()->request_payload['DocumentLines']);
        $receipt = SapDocument::where('sap_object', 'InventoryGenEntries')->firstOrFail()->request_payload;
        $this->assertSame('IT0600601', $receipt['DocumentLines'][0]['ItemCode']);
        $this->assertStringStartsWith('Produksi dapur', $receipt['Comments']);
    }

    public function test_recipe_draft_needs_another_person_to_approve(): void
    {
        $merch = User::factory()->create(['role' => 'hq_merch']);
        $admin = User::factory()->create(['role' => 'admin']);
        $recipe = $this->sauce->recipe;

        $this->actingAs($merch)->post(route('core.recipes.draft', $recipe))->assertRedirect();
        $draft = $recipe->versions()->where('status', 'draft')->firstOrFail();
        $this->put(route('core.recipes.update', $draft), ['yield_quantity' => 2, 'yield_uom' => 'KG', 'kind' => 'stocked_prep',
            'code' => ['0500503', '0500502'], 'qty' => ['0.150', '0.500'], 'uom' => ['KG', 'KG']])->assertRedirect();
        $this->get(route('core.recipes.show', [$recipe, 'v' => $draft->id]))->assertOk()->assertSee('Rekan lain yang harus menyetujui');

        $this->from(route('core.recipes.show', $recipe))->post(route('core.recipes.approve', $draft));
        $this->assertSame('draft', $draft->fresh()->status);

        $this->actingAs($admin)->post(route('core.recipes.approve', $draft))->assertRedirect();
        $this->assertSame(['approved', 'retired'], [$draft->fresh()->status, $this->sauce->fresh()->status]);

        // Produksi berikutnya memakai versi baru: 2 kg per resep.
        $this->actingAs(User::where('email', 'chef@uji.test')->firstOrFail());
        $this->assertSame($draft->id, $this->getJson('/kitchen/api/production')->json('recipes.0.version_id'));
        $this->assertSame('0.150000', $this->getJson('/kitchen/api/production')->json('recipes.0.lines.0.quantity'));
    }
}

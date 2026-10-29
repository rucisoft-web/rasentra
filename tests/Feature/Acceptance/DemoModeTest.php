<?php

namespace Tests\Feature\Acceptance;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mode demo: tamu masuk ke tiap aplikasi dari landing page dengan akun data contoh,
 * tetapi tidak bisa mengubah pengguna atau outlet.
 */
class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_is_hidden_unless_enabled(): void
    {
        config(['rasentra.demo.enabled' => false]);

        $this->get('/')->assertOk()->assertDontSee('Coba sebagai tamu');
        $this->get('/demo')->assertNotFound();
        $this->post('/demo/central')->assertNotFound();
        $this->artisan('rasentra:demo-reset', ['--force' => true])->assertFailed();
    }

    public function test_guest_enters_every_app_from_the_demo_page(): void
    {
        $this->seed(DatabaseSeeder::class);
        config(['rasentra.demo.enabled' => true, 'rasentra.outlet_code' => 'SLO']);

        $this->get('/')->assertOk()->assertSee('Coba sebagai tamu')->assertSee(route('demo'));
        $page = $this->get('/demo')->assertOk()->assertSee('Coba aplikasi Rasentra tanpa login');

        foreach (config('rasentra.demo.accounts') as $key => $account) {
            $page->assertSee(route('demo.enter', $key));
            $this->post("/demo/{$key}")->assertRedirect($account['home']);
            $this->assertAuthenticatedAs(User::where('email', $account['email'])->first());
            $this->get($account['home'])->assertOk()->assertSee('Mode tamu · Ganti aplikasi');
            $this->post(route('core.logout'));
        }

        $this->post('/demo/tidak-ada')->assertNotFound();
    }

    public function test_guest_cannot_change_users(): void
    {
        $this->seed(DatabaseSeeder::class);
        config(['rasentra.demo.enabled' => true]);
        $cashier = User::where('email', 'kasir@solo.test')->firstOrFail();

        $this->post('/demo/central');
        $this->from(route('core.users.edit', $cashier))
            ->put(route('core.users.update', $cashier), ['name' => 'Diubah tamu', 'role' => 'admin'])
            ->assertRedirect(route('core.users.edit', $cashier))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Mode tamu'));
        $this->assertSame('cashier', $cashier->fresh()->role);

        // Akun yang sama tanpa mode demo tetap bisa mengelola pengguna (penjagaan hanya untuk tamu).
        $this->post(route('core.logout'));
        $this->actingAs(User::where('email', 'admin@rasentra.test')->firstOrFail())
            ->get(route('core.users.edit', $cashier))->assertOk();
    }
}

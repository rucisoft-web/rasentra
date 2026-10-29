<?php

namespace Tests\Feature\Acceptance;

use Tests\TestCase;

/**
 * Panduan operasional per peran tampil sebagai halaman publik dari landing page, lengkap dengan gambar layar.
 */
class GuidePageTest extends TestCase
{
    public function test_landing_links_to_the_role_guide(): void
    {
        $this->get('/')->assertOk()->assertSee('Panduan Peran')->assertSee(route('panduan'));
    }

    public function test_guide_shows_every_role_with_screenshots_and_architecture(): void
    {
        $page = $this->get('/panduan')->assertOk()
            ->assertSee('Panduan Operasional Rasentra per Peran')
            ->assertSee('assets/guide/arsitektur-sistem.jpg')
            ->assertSee('href="#1-kasir-meatshop-grocery-rasentra-pos"', false)
            ->assertDontSee('[Screen capture');

        foreach (['Kasir restoran', 'Pelayan', 'Chef', 'Petugas gudang', 'Kepala outlet', 'Admin kantor pusat', 'Merchandising', 'Keuangan'] as $role) {
            $page->assertSee($role);
        }

        preg_match_all('#<img src="[^"]*/assets/guide/([^"]+)"#', $page->getContent(), $m);
        $this->assertGreaterThanOrEqual(57, count($m[1]));
        foreach ($m[1] as $file) {
            $this->assertFileExists(public_path('assets/guide/'.$file));
        }
    }
}

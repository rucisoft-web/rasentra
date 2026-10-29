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

    public function test_features_page_opens_with_architecture_and_lists_every_app(): void
    {
        $this->get('/')->assertOk()->assertSee('Fitur Aplikasi')->assertSee(route('fitur'));

        $page = $this->get('/fitur')->assertOk()
            ->assertSee('assets/guide/arsitektur-sistem.jpg')
            ->assertSee('Siapa memakai apa');

        $content = $page->getContent();
        $this->assertLessThan(strpos($content, 'Fitur per aplikasi'), strpos($content, 'arsitektur-sistem.jpg'));

        foreach (require resource_path('guide/fitur.php') as $app) {
            $page->assertSee($app['name'])->assertSee('id="'.$app['key'].'"', false);
        }

        preg_match_all('#<img src="[^"]*/assets/guide/([^"]+)"#', $content, $m);
        $this->assertGreaterThanOrEqual(15, count($m[1]));
        foreach ($m[1] as $file) {
            $this->assertFileExists(public_path('assets/guide/'.$file));
        }
    }
}

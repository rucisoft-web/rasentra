<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Panduan migrasi POS Rene/Raptor ke Rasentra dengan akuntansi tetap di Accurate (resources/guide/migrasi-pos.md). */
class MigrationGuideController extends Controller
{
    public function show(): View
    {
        $markdown = preg_replace('/\A# .*\n+/', '', file_get_contents(resource_path('guide/migrasi-pos.md')));
        $html = Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $html = str_replace(['<li>[ ] ', '<table>'], ['<li><input type="checkbox"> ', '<div class="tablewrap"><table class="t">'], $html);
        $html = str_replace('</table>', '</table></div>', $html);

        $toc = [];
        $html = preg_replace_callback('#<h2>(.*?)</h2>#', function ($m) use (&$toc) {
            $text = html_entity_decode(strip_tags($m[1]));
            $id = Str::slug($text);
            $toc[] = ['id' => $id, 'text' => $text];

            return '<h2 id="'.$id.'">'.$m[1].'</h2>';
        }, $html);

        return view('core.migration-guide', ['title' => 'Panduan migrasi POS ke Rasentra', 'html' => $html, 'toc' => $toc]);
    }
}

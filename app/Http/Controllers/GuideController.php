<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Panduan operasional per peran (resources/guide/panduan.md) dengan gambar layar di public/assets/guide.
 */
class GuideController extends Controller
{
    public function show(): View
    {
        $markdown = file_get_contents(resource_path('guide/panduan.md'));
        // Judul halaman ada di header; gambar memakai asset() agar tetap jalan bila aplikasi di subfolder.
        $markdown = preg_replace('/\A# .*\n+/', '', $markdown);
        $markdown = str_replace('](/assets/guide/', ']('.asset('assets/guide').'/', $markdown);
        $html = Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        // Gambar berdiri sendiri menjadi figure dengan keterangan dari alt; klik membuka ukuran penuh.
        $html = preg_replace(
            '#<p><img src="([^"]+)" alt="([^"]*)" /></p>#',
            '<figure><a href="$1" target="_blank" rel="noopener"><img src="$1" alt="$2" loading="lazy"></a><figcaption>$2</figcaption></figure>',
            $html,
        );

        $toc = [];
        $used = [];
        $html = preg_replace_callback('#<h([23])>(.*?)</h\1>#', function ($m) use (&$toc, &$used) {
            $text = html_entity_decode(strip_tags($m[2]));
            $id = Str::slug($text) ?: 'bagian';
            $id = isset($used[$id]) ? $id.'-'.(++$used[$id]) : $id;
            $used[$id] ??= 1;
            if ($m[1] === '2') {
                $toc[] = ['id' => $id, 'text' => $text, 'children' => []];
            } elseif ($toc !== []) {
                $toc[array_key_last($toc)]['children'][] = ['id' => $id, 'text' => $text];
            }

            return "<h{$m[1]} id=\"{$id}\">{$m[2]}</h{$m[1]}>";
        }, $html);

        return view('guide', ['html' => $html, 'toc' => $toc]);
    }
}

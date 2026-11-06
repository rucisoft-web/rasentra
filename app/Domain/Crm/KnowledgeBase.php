<?php

namespace App\Domain\Crm;

use App\Models\Crm\KbArticle;
use Illuminate\Support\Collection;

/**
 * Knowledge base untuk asisten AI: FAQ, menu, harga, jam buka, kebijakan. Artikel paling
 * relevan dipilih dengan pencocokan kata (judul dan kata kunci berbobot lebih), lalu
 * disertakan ke prompt sehingga AI hanya menjawab dari informasi resmi.
 */
final class KnowledgeBase
{
    private const STOPWORDS = ['yang', 'dan', 'atau', 'untuk', 'dengan', 'ada', 'apa', 'apakah', 'bisa', 'boleh', 'saya', 'kami', 'kak', 'min',
        'dong', 'ya', 'ini', 'itu', 'di', 'ke', 'dari', 'mau', 'tolong', 'halo', 'hai', 'pak', 'bu', 'gak', 'tidak', 'sudah', 'belum', 'nya'];

    /** @return Collection<int, KbArticle> */
    public function search(string $text, ?int $branchId = null, int $limit = 6): Collection
    {
        $words = $this->words($text);
        $articles = KbArticle::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('crm_branch_id')->when($branchId, fn ($q) => $q->orWhere('crm_branch_id', $branchId)))
            ->get();

        return $articles
            ->map(function (KbArticle $a) use ($words) {
                $title = ' '.implode(' ', $this->words($a->title.' '.$a->keywords)).' ';
                $body = ' '.implode(' ', $this->words($a->body)).' ';
                $score = 0;
                foreach ($words as $w) {
                    $score += 3 * substr_count($title, ' '.$w) + min(3, substr_count($body, ' '.$w));
                }
                $a->setAttribute('score', $score);

                return $a;
            })
            ->filter(fn ($a) => $a->score > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($parts, fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true))));
    }
}

<?php

namespace App\Http\Controllers\Core\Crm;

use App\Domain\Audit\Audit;
use App\Domain\Crm\KnowledgeBase;
use App\Domain\Integration\Ai\AiClient;
use App\Http\Controllers\Controller;
use App\Models\Crm\Branch;
use App\Models\Crm\KbArticle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Rasentra CRM: knowledge base yang menjadi satu-satunya sumber jawaban asisten AI. */
class KnowledgeController extends Controller
{
    public function index(Request $request, KnowledgeBase $kb, AiClient $ai): View
    {
        $test = trim((string) $request->query('uji'));

        return view('core.crm.knowledge.index', [
            'title' => 'Knowledge base AI',
            'articles' => KbArticle::with('branch:id,name', 'editor:id,name')->orderByDesc('is_active')->orderBy('title')->get(),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'test' => $test,
            'matches' => $test !== '' ? $kb->search($test) : null,
            'aiReady' => $ai->configured(),
            'model' => config('services.anthropic.model'),
        ]);
    }

    public function edit(KbArticle $article): View
    {
        return view('core.crm.knowledge.edit', [
            'title' => 'Ubah artikel',
            'article' => $article,
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $article = KbArticle::create($this->validated($request) + ['updated_by' => $request->user()->id]);
        Audit::record('crm.kb.created', $article, $request->user()->id, after: $article->only(['title']));

        return back()->with('ok', "Artikel \"{$article->title}\" ditambahkan.");
    }

    public function update(Request $request, KbArticle $article): RedirectResponse
    {
        $before = $article->only(['title', 'body', 'is_active']);
        $article->update($this->validated($request) + ['updated_by' => $request->user()->id]);
        Audit::record('crm.kb.updated', $article, $request->user()->id, $before, $article->only(['title', 'body', 'is_active']));

        return redirect()->route('core.crm.knowledge.index')->with('ok', 'Artikel disimpan.');
    }

    public function destroy(Request $request, KbArticle $article): RedirectResponse
    {
        Audit::record('crm.kb.deleted', $article, $request->user()->id, $article->only(['title', 'body']));
        $article->delete();

        return redirect()->route('core.crm.knowledge.index')->with('ok', 'Artikel dihapus.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:8000'],
            'keywords' => ['nullable', 'string', 'max:300'],
            'crm_branch_id' => ['nullable', 'exists:crm_branches,id'],
            'is_active' => ['boolean'],
        ]);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        return $data;
    }
}

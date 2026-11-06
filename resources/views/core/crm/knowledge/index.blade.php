@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
@if (! $aiReady)
  <div class="alert err">Claude API belum diaktifkan: isi <span class="mono">ANTHROPIC_API_KEY</span> di file .env server Central. Setelah itu pilih mode asisten AI per nomor di <a href="{{ route('core.crm.whatsapp.index') }}">Nomor & template</a>.</div>
@else
  <p class="muted">Asisten AI aktif dengan model <span class="mono">{{ $model }}</span>. AI hanya menjawab dari artikel aktif di bawah; pertanyaan di luar knowledge base diserahkan ke CS.</p>
@endif
<div class="grid g2">
  <form class="card" method="post" action="{{ route('core.crm.knowledge.store') }}">
    @csrf
    <h3>Artikel baru</h3>
    @include('core.crm.knowledge._fields', ['a' => null])
    <p class="actions"><button class="btn">Tambah artikel</button></p>
  </form>
  <div class="card">
    <h3>Uji pencarian</h3>
    <form class="bar" method="get"><label class="f" style="flex:1"><span>Contoh pesan pelanggan</span><input name="uji" value="{{ $test }}" placeholder="kak, outlet solo buka jam berapa?"></label><button class="btn alt">Cari</button></form>
    @if ($matches !== null)
      @forelse ($matches as $m)<p class="small"><b>{{ $m->title }}</b> <span class="muted">skor {{ $m->score }}</span></p>@empty<p class="muted small">Tidak ada artikel yang cocok: AI akan menyerahkan pertanyaan ini ke CS.</p>@endforelse
    @endif
    <p class="muted small">Tips: satu artikel untuk satu topik (menu, harga, jam buka, alamat, pengiriman, katering, reseller, promo berjalan). Perbarui saat harga atau promo berubah.</p>
  </div>
</div>
<div class="tablewrap" style="margin-top:14px">
<table class="t">
  <thead><tr><th>Judul</th><th>Isi</th><th>Cabang</th><th>Status</th><th>Diperbarui</th></tr></thead>
  <tbody>
  @forelse ($articles as $a)
    <tr>
      <td><a href="{{ route('core.crm.knowledge.edit', $a) }}"><b>{{ $a->title }}</b></a>@if($a->keywords)<div class="muted small">{{ $a->keywords }}</div>@endif</td>
      <td class="small">{{ \Illuminate\Support\Str::limit($a->body, 140) }}</td>
      <td class="small">{{ $a->branch?->name ?? 'Semua' }}</td>
      <td>@if($a->is_active)<span class="b ok">Aktif</span>@else<span class="b mute">Nonaktif</span>@endif</td>
      <td class="small">{{ Fmt::dt($a->updated_at) }}@if($a->editor) · {{ $a->editor->name }}@endif</td>
    </tr>
  @empty
    <tr><td colspan="5" class="muted">Belum ada artikel. Mulai dari pertanyaan yang paling sering masuk ke WhatsApp.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
@endsection

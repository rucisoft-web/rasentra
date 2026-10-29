@extends('lokal.layout')
@use('App\Support\Fmt')
@use('App\Http\Controllers\Core\CentralController', 'C')
@section('content')
<p class="muted">Harga, promo, resep dan data master diterbitkan dari Rasentra Core dan ditarik server ini setiap 30 detik. Yang sudah diterima tetap berlaku saat internet putus. Cursor publikasi server ini: <b>{{ $sync['cursor'] }}</b>@if($sync['central_cursor'] !== null), Central: <b>{{ $sync['central_cursor'] }}</b>@endif.</p>
<form method="post" action="{{ route('lokal.pull') }}" style="margin-bottom:14px">@csrf<button class="btn alt">Tarik publikasi sekarang</button></form>

<div class="grid g2">
  <div class="card">
    <h2>Versi harga</h2>
    <table class="t"><thead><tr><th>Kode</th><th>Berlaku</th><th>Cakupan</th><th class="n">Produk</th></tr></thead><tbody>
      @forelse ($prices as $p)
        <tr><td class="mono">{{ $p->code }}</td><td>{{ Fmt::dt($p->effective_from) }}@if($p->effective_from->isFuture()) <span class="b info">mendatang</span>@endif</td>
          <td>{{ $p->outlet_id ? 'outlet ini' : 'semua outlet' }}{{ $p->channel ? ' · '.$p->channel : '' }}</td><td class="n">{{ $p->items_count }}</td></tr>
      @empty
        <tr><td colspan="4" class="muted">Belum ada versi harga.</td></tr>
      @endforelse
    </tbody></table>
  </div>
  <div class="card">
    <h2>Cooking fee</h2>
    <table class="t"><thead><tr><th>Metode</th><th>Dasar</th><th class="n">Tarif</th><th class="n">Minimum</th></tr></thead><tbody>
      @forelse ($fees as $f)
        <tr><td>{{ $f->method ?: 'semua metode' }}</td><td>{{ $f->basis }}</td><td class="n">{{ Fmt::money($f->rate) }}</td><td class="n">{{ Fmt::money($f->minimum_fee) }}</td></tr>
      @empty
        <tr><td colspan="4" class="muted">Tidak ada cooking fee yang berlaku.</td></tr>
      @endforelse
    </tbody></table>
    <p class="muted small">{{ $recipes }} versi resep disetujui tersimpan di server ini.</p>
  </div>
</div>

<h2>Promo yang berlaku dan mendatang</h2>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Kode</th><th>Nama</th><th>Jenis</th><th>Kanal</th><th>Mulai</th><th>Selesai</th><th>Biaya</th></tr></thead>
  <tbody>
  @forelse ($promos as $v)
    <tr>
      <td class="mono">{{ $v->promotion?->code }} <span class="muted">v{{ $v->version }}</span></td>
      <td>{{ $v->promotion?->name }}</td>
      <td>{{ $v->type }} · {{ $v->scope }}</td>
      <td>{{ $v->channels ? implode(', ', $v->channels) : 'semua' }}</td>
      <td>{{ Fmt::dt($v->starts_at) }}@if($v->starts_at->isFuture()) <span class="b info">mendatang</span>@else <span class="b ok">aktif</span>@endif</td>
      <td>{{ $v->ends_at ? Fmt::dt($v->ends_at) : 'tanpa batas' }}</td>
      <td>{{ $v->funded_by }}</td>
    </tr>
  @empty
    <tr><td colspan="7" class="muted">Tidak ada promo untuk outlet ini.</td></tr>
  @endforelse
  </tbody>
</table>
</div>

<h2>Riwayat tarik publikasi</h2>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Waktu</th><th class="n">Diterapkan</th><th>Rincian</th><th class="n">Cursor</th><th>Error</th></tr></thead>
  <tbody>
  @forelse ($log as $l)
    <tr>
      <td>{{ Fmt::dt($l['at']) }} <span class="muted small">{{ C::ago($l['at']) }}</span></td>
      <td class="n">{{ $l['applied'] }}</td>
      <td class="small">{{ collect($l['types'] ?? [])->map(fn ($n, $t) => str_replace('_', ' ', $t)." $n")->implode(', ') ?: '-' }}</td>
      <td class="n">{{ $l['cursor'] }}</td>
      <td class="small" style="color:var(--red)">{{ $l['error'] ?? '' }}</td>
    </tr>
  @empty
    <tr><td colspan="5" class="muted">Belum ada publikasi yang ditarik.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
@endsection

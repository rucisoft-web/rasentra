@extends('core.layout')
@use('App\Support\Fmt')
@use('App\Domain\Crm\Phone')
@use('App\Domain\Crm\SegmentQuery')
@section('content')
<div class="grid g4">
  <div class="card kpi"><div class="label">Total pelanggan</div><div class="value">{{ number_format($stats['total'], 0, ',', '.') }}</div><div class="note">satu profil per nomor WhatsApp, lintas outlet</div></div>
  <div class="card kpi"><div class="label">Opt-in WhatsApp</div><div class="value">{{ number_format($stats['opted_in'], 0, ',', '.') }}</div><div class="note">boleh menerima pesan promosi</div></div>
  <div class="card kpi"><div class="label">Belanja 30 hari terakhir</div><div class="value">{{ number_format($stats['active'], 0, ',', '.') }}</div><div class="note">dari POS Rasentra dan Accurate</div></div>
  <div class="card kpi"><div class="label">Baru 30 hari terakhir</div><div class="value">{{ number_format($stats['new'], 0, ',', '.') }}</div><div class="note">dari impor, POS dan Accurate</div></div>
</div>

<form class="card bar" method="get" style="margin-top:14px">
  <label class="f"><span>Cari</span><input name="q" value="{{ request('q') }}" placeholder="Nama, HP atau email"></label>
  <label class="f"><span>Outlet</span><select name="outlet"><option value="">Semua</option>@foreach ($outlets as $o)<option value="{{ $o->id }}" @selected(request('outlet') == $o->id)>{{ $o->code }} {{ $o->name }}</option>@endforeach</select></label>
  <label class="f"><span>Tahap</span><select name="stage"><option value="">Semua</option>@foreach (SegmentQuery::LIFECYCLES as $k => $v)<option value="{{ $k }}" @selected(request('stage') === $k)>{{ $v }}</option>@endforeach</select></label>
  <label class="f"><span>Tag</span><select name="tag"><option value="">Semua</option>@foreach ($tags as $t)<option value="{{ $t->id }}" @selected(request('tag') == $t->id)>{{ $t->name }}</option>@endforeach</select></label>
  <label class="f"><span>Segmen</span><select name="segment"><option value="">—</option>@foreach ($segments as $s)<option value="{{ $s->id }}" @selected(request('segment') == $s->id)>{{ $s->name }}</option>@endforeach</select></label>
  <label class="f"><span>WhatsApp</span><select name="wa"><option value="">Semua</option><option value="opted_in" @selected(request('wa') === 'opted_in')>Opt-in</option><option value="reachable" @selected(request('wa') === 'reachable')>Belum opt-out</option><option value="opted_out" @selected(request('wa') === 'opted_out')>Opt-out</option></select></label>
  <button class="btn">Tampilkan</button>
  <a class="btn alt" href="{{ route('core.crm.customers.export', request()->query()) }}">Unduh CSV</a>
  <a class="btn alt" href="{{ route('core.crm.segments.create', array_filter(['outlet_ids' => request('outlet') ? [request('outlet')] : null, 'tag_ids' => request('tag') ? [request('tag')] : null, 'lifecycle' => request('stage'), 'wa' => request('wa')])) }}">Simpan sebagai segmen</a>
</form>

<div class="tablewrap" style="margin-top:14px">
<table class="t">
  <thead><tr><th>Nama</th><th>No HP</th><th>Outlet</th><th>Tag</th><th>WhatsApp</th><th class="n">Transaksi</th><th class="n">Total belanja</th><th>Terakhir belanja</th></tr></thead>
  <tbody>
  @forelse ($customers as $c)
    <tr>
      <td><a href="{{ route('core.crm.customers.show', $c) }}">{{ $c->name }}</a>@if($c->city)<div class="muted small">{{ $c->city }}</div>@endif</td>
      <td class="mono">{{ Phone::display($c->phone) }}</td>
      <td>{{ $c->outlets->pluck('code')->implode(', ') ?: '—' }}</td>
      <td>@foreach ($c->tags as $t)<span class="b info">{{ $t->name }}</span> @endforeach</td>
      <td>@include('core.crm.customers._wa', ['status' => $c->wa_status])</td>
      <td class="n">{{ $c->purchase_count }}</td>
      <td class="n">{{ Fmt::money($c->total_spent) }}</td>
      <td>{{ $c->last_purchase_on?->translatedFormat('j M Y') ?? '—' }}</td>
    </tr>
  @empty
    <tr><td colspan="8" class="muted">Belum ada pelanggan yang cocok. Mulai dengan <a href="{{ route('core.crm.imports.index') }}">impor member outlet</a>.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $customers->links('core.pager') }}

<div class="grid g2" style="margin-top:14px">
  <form class="card bar" method="post" action="{{ route('core.crm.customers.store') }}">
    @csrf
    <label class="f"><span>Nama</span><input name="name" required maxlength="120"></label>
    <label class="f"><span>No HP</span><input name="phone" required placeholder="0812…"></label>
    <label class="f"><span>Outlet</span><select name="outlet_id"><option value="">—</option>@foreach ($outlets as $o)<option value="{{ $o->id }}">{{ $o->code }}</option>@endforeach</select></label>
    <button class="btn alt">Tambah pelanggan</button>
  </form>
  <form class="card bar" method="post" action="{{ route('core.crm.tags.store') }}">
    @csrf
    <label class="f"><span>Tag baru</span><input name="name" required maxlength="60" placeholder="mis. Reseller, Katering, VIP"></label>
    <button class="btn alt">Buat tag</button>
  </form>
</div>
@endsection

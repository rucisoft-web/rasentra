@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<details class="card" @if($errors->any()) open @endif>
  <summary><b>Buat draft versi harga</b> <span class="muted small">· versi baru menggantikan harga lama mulai tanggal efektifnya</span></summary>
  <form method="post" action="{{ route('core.prices.store') }}" style="margin-top:12px">
    @csrf
    <div class="fields">
      <label class="f"><span>Kode versi</span><input name="code" value="{{ old('code') }}" placeholder="LBK-2026-11" required></label>
      <label class="f"><span>Outlet</span><select name="outlet_id"><option value="">Semua outlet</option>@foreach ($outlets as $o)<option value="{{ $o->id }}" @selected(old('outlet_id') == $o->id)>{{ $o->code }} {{ $o->name }}</option>@endforeach</select></label>
      <label class="f"><span>Kanal</span><select name="channel"><option value="">Semua kanal</option>@foreach ($channels as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
      <label class="f"><span>Berlaku mulai</span><input type="datetime-local" name="effective_from" value="{{ old('effective_from', now(config('rasentra.display_timezone'))->addDay()->startOfDay()->format('Y-m-d\TH:i')) }}" required></label>
      <label class="f"><span>Salin dari versi</span><select name="copy_from"><option value="">Kosong</option>@foreach ($sources as $s)<option value="{{ $s->id }}">{{ $s->code }}</option>@endforeach</select></label>
      <label class="f"><span>Catatan</span><input name="note" value="{{ old('note') }}" placeholder="Kenaikan harga daging November"></label>
    </div>
    <p><button class="btn">Buat draft</button></p>
  </form>
</details>

<form class="bar" method="get" style="margin-top:14px">
  <label class="f"><span>Status</span><select name="status"><option value="">Semua</option><option value="draft" @selected(request('status') === 'draft')>Draft</option><option value="published" @selected(request('status') === 'published')>Terbit</option></select></label>
  <label class="f"><span>Outlet</span><select name="outlet"><option value="">Semua</option><option value="all" @selected(request('outlet') === 'all')>Versi umum</option>@foreach ($outlets as $o)<option value="{{ $o->id }}" @selected(request('outlet') == $o->id)>{{ $o->code }}</option>@endforeach</select></label>
  <button class="btn alt">Saring</button>
</form>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Kode</th><th>Cakupan</th><th>Berlaku</th><th class="n">Produk</th><th>Dibuat</th><th>Diterbitkan</th><th>Status</th><th></th></tr></thead>
  <tbody>
  @forelse ($versions as $v)
    <tr>
      <td class="mono"><b>{{ $v->code }}</b>@if($v->note)<div class="muted small">{{ $v->note }}</div>@endif</td>
      <td>{{ $v->outlet ? $v->outlet->code.' '.$v->outlet->name : 'Semua outlet' }} · {{ $v->channel ? $channels[$v->channel] : 'semua kanal' }}</td>
      <td>{{ Fmt::dt($v->effective_from) }}@if($v->effective_to)<div class="muted small">s/d {{ Fmt::dt($v->effective_to) }}</div>@endif</td>
      <td class="n">{{ $v->items_count }}</td>
      <td>{{ $names[$v->created_by] ?? '-' }}</td>
      <td>{{ $names[$v->published_by] ?? '-' }}@if($v->published_at)<div class="muted small">{{ Fmt::dt($v->published_at) }}</div>@endif</td>
      <td>@if($v->status === 'draft')<span class="b warn">Draft</span>@else<span class="b ok">Terbit</span>@endif</td>
      <td><a class="btn alt sm" href="{{ route('core.prices.show', $v) }}">{{ $v->status === 'draft' ? 'Isi harga' : 'Lihat' }}</a></td>
    </tr>
  @empty
    <tr><td colspan="8" class="muted">Belum ada versi harga.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $versions->links('core.pager') }}
@endsection

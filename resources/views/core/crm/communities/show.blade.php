@extends('core.layout')
@use('App\Support\Fmt')
@use('App\Domain\Crm\Phone')
@section('content')
<p><a href="{{ route('core.crm.communities.index') }}">&larr; Semua komunitas</a></p>
<div class="grid g4">
  <div class="card kpi"><div class="label">Anggota</div><div class="value">{{ number_format($members->total(), 0, ',', '.') }}</div><div class="note">{{ $community->branch?->name ?? 'Semua cabang' }}</div></div>
  <div class="card kpi"><div class="label">Bisa dikirimi iklan</div><div class="value">{{ number_format($optedIn, 0, ',', '.') }}</div><div class="note">anggota yang opt-in WhatsApp</div></div>
  <div class="card kpi"><div class="label">Kata kunci gabung</div><div class="value mono" style="font-size:1.2rem">{{ $community->join_keyword ?? '—' }}</div></div>
  <div class="card kpi"><div class="label">Iklan</div><div style="margin-top:10px"><a class="btn" href="{{ route('core.crm.campaigns.create', ['community' => $community->id]) }}">Kirim iklan</a></div><div class="note">ke anggota opt-in</div></div>
</div>

<div class="grid g2" style="margin-top:14px">
  <form class="card" method="post" action="{{ route('core.crm.communities.members.store', $community) }}">
    @csrf
    <h3>Tambah anggota</h3>
    <div class="fields">
      <label class="f"><span>Dari segmen</span><select name="segment_id"><option value="">—</option>@foreach ($segments as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
      <label class="f"><span>atau semua member cabang</span><select name="crm_branch_id"><option value="">—</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} {{ $b->name }}</option>@endforeach</select></label>
      <label class="f"><span>atau satu nomor HP</span><input name="phone" placeholder="0812…"></label>
    </div>
    <p><button class="btn alt">Tambahkan</button></p>
  </form>
  <form class="card" method="post" action="{{ route('core.crm.communities.update', $community) }}">
    @csrf @method('put')
    <h3>Pengaturan</h3>
    @include('core.crm.communities._fields', ['community' => $community])
    <p><button class="btn alt">Simpan</button></p>
  </form>
</div>

@if ($campaigns->isNotEmpty())
<div class="card" style="margin-top:14px">
  <h3>Iklan ke komunitas ini</h3>
  <table class="t"><tbody>@foreach ($campaigns as $k)<tr><td><a href="{{ route('core.crm.campaigns.show', $k) }}">{{ $k->name }}</a></td><td>{{ $k->status }}</td><td class="n">{{ $k->recipient_count }} penerima</td><td>{{ Fmt::dt($k->created_at) }}</td></tr>@endforeach</tbody></table>
</div>
@endif

<form class="bar" method="get" style="margin-top:14px"><label class="f"><span>Cari anggota</span><input name="q" value="{{ request('q') }}"></label><button class="btn alt">Cari</button></form>
<div class="tablewrap" style="margin-top:10px">
<table class="t">
  <thead><tr><th>Nama</th><th>No HP</th><th>Cabang</th><th>WhatsApp</th><th>Masuk lewat</th><th></th></tr></thead>
  <tbody>
  @forelse ($members as $m)
    <tr>
      <td><a href="{{ route('core.crm.customers.show', $m) }}">{{ $m->name }}</a></td><td class="mono">{{ Phone::display($m->phone) }}</td>
      <td>{{ $m->branches->pluck('code')->implode(', ') }}</td><td>@include('core.crm.customers._wa', ['status' => $m->wa_status])</td>
      <td>{{ ['keyword' => 'Kata kunci WA', 'segment' => 'Segmen', 'manual' => 'Manual'][$m->pivot->source] ?? $m->pivot->source }}</td>
      <td><form method="post" action="{{ route('core.crm.communities.members.destroy', [$community, $m]) }}">@csrf @method('delete')<button class="btn alt sm">Keluarkan</button></form></td>
    </tr>
  @empty
    <tr><td colspan="6" class="muted">Belum ada anggota.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $members->links('core.pager') }}
@endsection

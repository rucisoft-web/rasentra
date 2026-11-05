@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<div class="grid g3" style="margin-bottom:14px">
  <div class="card kpi"><div class="label">Member aktif</div><div class="value">{{ number_format($stats['members'], 0, ',', '.') }}</div><div class="note">{{ $stats['new30'] }} baru dalam 30 hari</div></div>
  <div class="card kpi"><div class="label">Poin beredar</div><div class="value">{{ number_format((float) $stats['points'], 0, ',', '.') }}</div><div class="note">kewajiban poin seluruh grup</div></div>
  <div class="card kpi"><div class="label">Tier</div><div class="value">{{ $tiers->count() }}</div><div class="note"><a href="{{ route('core.member-tiers.index') }}">atur tier & pengali poin</a></div></div>
</div>
<form method="get" style="display:flex;gap:8px;margin-bottom:12px">
  <input name="q" value="{{ request('q') }}" placeholder="Nama, nomor HP atau kode member">
  <select name="tier"><option value="">Semua tier</option>@foreach ($tiers as $t)<option value="{{ $t->id }}" @selected(request('tier') == $t->id)>{{ $t->name }}</option>@endforeach</select>
  <button class="btn alt">Cari</button>
</form>
<div class="tablewrap"><table class="t">
  <thead><tr><th>Kode</th><th>Nama</th><th>HP</th><th>Tier</th><th>Outlet asal</th><th class="n">Belanja member</th><th class="n">Poin</th><th>Sejak</th></tr></thead>
  <tbody>@forelse ($members as $m)
    <tr><td class="mono"><a href="{{ route('core.members.show', $m) }}">{{ $m->code }}</a></td><td>{{ $m->name }}</td><td class="mono">{{ $m->phone }}</td>
      <td>{{ $tiers[$m->member_tier_id]->name ?? '-' }}</td><td>{{ $outlets[$m->home_outlet_id] ?? '-' }}</td>
      <td class="n">{{ Fmt::money($m->member_spend) }}</td><td class="n">{{ number_format((float) $m->points_balance, 0, ',', '.') }}</td><td>{{ $m->member_since?->format('d/m/Y') }}</td></tr>
  @empty <tr><td colspan="8" class="muted">Belum ada member.</td></tr>
  @endforelse</tbody>
</table></div>
{{ $members->links('core.pager') }}
<details class="card" style="margin-top:14px"><summary><b>Daftarkan member</b></summary>
  <form method="post" action="{{ route('core.members.store') }}" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;align-items:end">@csrf
    <label class="f"><span>Nama</span><input name="name" required></label>
    <label class="f"><span>Nomor HP</span><input name="phone" required inputmode="tel"></label>
    <label class="f"><span>Email</span><input name="email" type="email"></label>
    <label class="f"><span>Outlet asal</span><select name="home_outlet_id">@foreach ($outlets as $id => $code)<option value="{{ $id }}">{{ $code }}</option>@endforeach</select></label>
    <button class="btn">Daftarkan</button>
  </form>
</details>
@endsection

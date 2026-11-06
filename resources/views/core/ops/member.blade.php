@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<div class="grid g2">
  <div class="card"><h2>{{ $member->name }}</h2>
    <dl class="dl">
      <dt>Kode member</dt><dd class="mono">{{ $member->code }}</dd>
      <dt>Nomor HP</dt><dd class="mono">{{ $member->phone }}</dd>
      <dt>Tier</dt><dd>{{ $tier?->name ?? '-' }}@if ($tier) <span class="small muted">· pengali poin {{ $tier->point_multiplier }}×</span>@endif</dd>
      <dt>Outlet asal</dt><dd>{{ $outlets[$member->home_outlet_id] ?? '-' }}</dd>
      <dt>Belanja member</dt><dd>{{ Fmt::money($member->member_spend) }}</dd>
      <dt>Saldo poin</dt><dd><b>{{ number_format((float) $member->points_balance, 0, ',', '.') }}</b></dd>
      <dt>Member sejak</dt><dd>{{ $member->member_since?->format('d/m/Y') }}</dd>
    </dl>
  </div>
  <div class="card"><h2>Penyesuaian poin</h2>
    <form method="post" action="{{ route('core.members.adjust', $member) }}">@csrf
      <label class="f"><span>Poin (+ tambah, − kurangi)</span><input name="points" type="number" step="1" required></label>
      <label class="f" style="margin-top:8px"><span>Alasan</span><input name="note" required placeholder="Kompensasi keluhan, koreksi, dll."></label>
      <p><button class="btn">Simpan</button></p>
    </form>
  </div>
</div>
<h3 style="margin-top:18px">Mutasi poin</h3>
<div class="tablewrap"><table class="t">
  <thead><tr><th>Tanggal</th><th>Outlet</th><th>Jenis</th><th>Keterangan</th><th class="n">Belanja</th><th class="n">Poin</th></tr></thead>
  <tbody>@forelse ($ledger as $e)
    <tr><td>{{ $e->business_date->format('d/m/Y') }}</td><td>{{ $outlets[$e->outlet_id] ?? 'Pusat' }}</td><td>{{ ['earn' => 'Dapat', 'redeem' => 'Tukar', 'adjust' => 'Penyesuaian'][$e->type] ?? $e->type }}</td>
      <td class="small">{{ $e->note }}</td><td class="n">{{ (float) $e->order_amount ? Fmt::money($e->order_amount) : '-' }}</td>
      <td class="n"><b>{{ (float) $e->points > 0 ? '+' : '' }}{{ number_format((float) $e->points, 0, ',', '.') }}</b></td></tr>
  @empty <tr><td colspan="6" class="muted">Belum ada mutasi.</td></tr>
  @endforelse</tbody>
</table></div>
@endsection

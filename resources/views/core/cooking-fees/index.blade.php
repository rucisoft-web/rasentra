@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<p class="muted">Tarif jasa masak untuk daging atau ikan yang dipilih pelanggan di etalase lalu dimasak di resto. Dasar perhitungan adalah berat mentah Pack ID.</p>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Kode</th><th>Outlet</th><th>Metode</th><th>Dasar</th><th class="n">Tarif</th><th class="n">Minimum</th><th>Tingkat</th><th>Berlaku</th><th>Status</th><th></th></tr></thead>
  <tbody>
  @foreach ($rules as $r)
    @php $state = $r->effective_from->isFuture() ? ['Terjadwal', 'info'] : ($r->effective_to && $r->effective_to->isPast() ? ['Berakhir', 'mute'] : ['Berlaku', 'ok']); @endphp
    <tr>
      <td class="mono"><b>{{ $r->code }}</b> <span class="muted">v{{ $r->version }}</span></td>
      <td>{{ $r->outlet_id ? $outlets[$r->outlet_id]->code : 'Semua' }}</td>
      <td>{{ $r->method ?? 'standar' }}</td>
      <td>{{ $bases[$r->basis] ?? $r->basis }}</td>
      <td class="n">{{ Fmt::money($r->rate) }}</td>
      <td class="n">{{ Fmt::money($r->minimum_fee) }}</td>
      <td class="small">{{ collect($r->tiers ?? [])->map(fn ($t) => '≤'.$t['up_to'].' kg '.Fmt::money($t['rate']))->implode(', ') ?: '-' }}</td>
      <td class="small">{{ Fmt::dt($r->effective_from) }}@if($r->effective_to)<br>s/d {{ Fmt::dt($r->effective_to) }}@endif</td>
      <td><span class="b {{ $state[1] }}">{{ $state[0] }}</span></td>
      <td><a class="btn alt sm" href="{{ route('core.cooking-fees.edit', $r) }}">Buka</a></td>
    </tr>
  @endforeach
  </tbody>
</table>
</div>

<details class="card" style="margin-top:14px" @if($errors->any()) open @endif>
  <summary><b>Tarif baru</b></summary>
  <form method="post" action="{{ route('core.cooking-fees.store') }}" style="margin-top:12px">
    @csrf
    <label class="f" style="max-width:260px;margin-bottom:12px"><span>Kode</span><input name="code" class="mono" required placeholder="PJT-CF-BAKAR"></label>
    @include('core.cooking-fees._fields', ['rule' => null])
    <p><button class="btn">Simpan tarif</button></p>
  </form>
</details>
@endsection

@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
@php $latest = $history->first()->id === $rule->id; @endphp
<p><a href="{{ route('core.cooking-fees.index') }}">&larr; Semua tarif</a></p>
<div class="grid g2">
  <div class="card">
    <h2>{{ $rule->code }} versi {{ $rule->version }}</h2>
    <dl class="dl">
      <dt>Outlet</dt><dd>{{ $rule->outlet_id ? $outlets[$rule->outlet_id]->code.' '.$outlets[$rule->outlet_id]->name : 'Semua outlet' }}</dd>
      <dt>Metode</dt><dd>{{ $rule->method ?? 'standar' }}</dd>
      <dt>Dasar</dt><dd>{{ $bases[$rule->basis] ?? $rule->basis }}</dd>
      <dt>Tarif / minimum</dt><dd>{{ Fmt::money($rule->rate) }} / {{ Fmt::money($rule->minimum_fee) }}</dd>
      <dt>Tingkat</dt><dd>{{ collect($rule->tiers ?? [])->map(fn ($t) => '≤'.$t['up_to'].' kg '.Fmt::money($t['rate']))->implode(', ') ?: '-' }}</dd>
      <dt>Produk jasa</dt><dd>{{ $services[$rule->service_product_id]->name ?? '-' }}</dd>
      <dt>Berlaku</dt><dd>{{ Fmt::dt($rule->effective_from) }} &ndash; {{ $rule->effective_to ? Fmt::dt($rule->effective_to) : 'seterusnya' }}</dd>
    </dl>
    <h3>Riwayat versi</h3>
    <div class="actions">@foreach ($history as $h)<a href="{{ route('core.cooking-fees.edit', $h) }}" @class(['b', 'ok' => $h->id === $rule->id])>v{{ $h->version }} · {{ Fmt::money($h->rate) }}</a>@endforeach</div>
  </div>
  @if ($latest)
  <form class="card" method="post" action="{{ route('core.cooking-fees.supersede', $rule) }}">
    @csrf
    <h2>Ubah tarif (versi {{ $rule->version + 1 }})</h2>
    <p class="muted small">Versi {{ $rule->version }} otomatis berakhir saat versi baru mulai berlaku.</p>
    @include('core.cooking-fees._fields', ['rule' => $rule])
    <p><button class="btn">Simpan versi baru</button></p>
  </form>
  @endif
</div>
@endsection

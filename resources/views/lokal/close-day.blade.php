@extends('lokal.layout')
@use('App\Support\Fmt')
@section('content')
@php $s = $summary; @endphp
<form class="bar" method="get">
  <label class="f"><span>Tanggal bisnis</span><input type="date" name="date" value="{{ $date }}" max="{{ $today }}"></label>
  <button class="btn alt">Tampilkan</button>
</form>
<p class="muted small">Tutup hari mengirim ringkasan hari bisnis ke Rasentra Central: jumlah struk, nilai bersih, nomor struk dan selisih kas setiap shift. Central mencocokkannya dengan transaksi yang sudah diterima. Server menutup hari kemarin otomatis pukul 04.00; tutup ulang boleh dilakukan, versi terbaru yang dipakai.</p>

<div class="grid g4">
  <div class="card kpi"><div class="label">Struk</div><div class="value">{{ $s['orders'] }}</div><div class="note">{{ collect($s['by_channel'])->map(fn ($c, $k) => "$k {$c['orders']}")->implode(' · ') ?: '-' }}</div></div>
  <div class="card kpi"><div class="label">Penjualan bersih</div><div class="value">{{ Fmt::money($s['net_amount']) }}</div><div class="note">kotor {{ Fmt::money($s['gross_amount']) }} · potongan {{ Fmt::money($s['discount_amount']) }}@if($s['returns'] ?? 0) · {{ $s['returns'] }} retur {{ Fmt::money($s['returns_net_amount']) }}@endif</div></div>
  <div class="card kpi"><div class="label">Selisih kas shift</div><div class="value">{{ Fmt::money($s['cash_difference']) }}</div><div class="note">{{ count($s['shifts']) }} shift</div></div>
  <div class="card kpi"><div class="label">Status</div><div class="value">@if($closed)<span class="b {{ $statuses[$closed->status][1] ?? 'mute' }}">Ditutup v{{ $closed->entity_version }}</span>@else<span class="b mute">Belum ditutup</span>@endif</div>
    <div class="note">@if($closed){{ $statuses[$closed->status][0] ?? $closed->status }} · {{ Fmt::dt($closed->occurred_at) }}@else - @endif</div></div>
</div>

<div class="grid g2" style="margin-top:14px">
  <div class="card">
    <h2>Pembayaran</h2>
    <table class="t"><tbody>
      @forelse ($s['payments'] as $method => $amount)<tr><td>{{ $method }}</td><td class="n">{{ Fmt::money($amount) }}</td></tr>@empty<tr><td class="muted">Tidak ada pembayaran.</td></tr>@endforelse
    </tbody></table>
  </div>
  <div class="card">
    <h2>Shift</h2>
    @php $names = \App\Models\User::whereIn('id', collect($s['shifts'])->pluck('user_id'))->pluck('name', 'id'); @endphp
    <table class="t"><tbody>
      @forelse ($s['shifts'] as $sh)
        <tr><td>{{ $names[$sh['user_id']] ?? $sh['user_id'] }}</td><td>@if($sh['status'] === 'open')<span class="b warn">masih buka</span>@else ditutup @endif</td><td class="n">{{ $sh['cash_difference'] !== null ? Fmt::money($sh['cash_difference']) : '-' }}</td></tr>
      @empty
        <tr><td class="muted">Tidak ada shift pada tanggal ini.</td></tr>
      @endforelse
    </tbody></table>
  </div>
</div>

<form method="post" action="{{ route('lokal.close-day.store') }}" style="margin:14px 0">
  @csrf
  <input type="hidden" name="date" value="{{ $date }}">
  <button class="btn" @if($s['open_shifts'] > 0) data-confirm="Masih ada {{ $s['open_shifts'] }} shift yang buka pada {{ $date }}. Selisih kasnya belum ikut terhitung. Tetap tutup hari?" @endif>{{ $closed ? 'Tutup ulang' : 'Tutup hari' }} {{ $date }}</button>
  @if($date === $today)<span class="muted small">Hari ini belum selesai; tutup hari sekarang hanya menghitung transaksi sampai saat ini.</span>@endif
</form>

<h2>Riwayat tutup hari</h2>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Tanggal bisnis</th><th>Versi</th><th>Ditutup</th><th class="n">Struk</th><th class="n">Bersih</th><th class="n">Selisih kas</th><th>Pengiriman</th></tr></thead>
  <tbody>
  @forelse ($history as $h)
    @php [$label, $tone] = $statuses[$h->status] ?? [$h->status, 'mute']; @endphp
    <tr>
      <td><a href="{{ route('lokal.close-day', ['date' => $h->business_date->toDateString()]) }}">{{ $h->business_date->translatedFormat('D, d M Y') }}</a></td>
      <td>v{{ $h->entity_version }}</td>
      <td>{{ Fmt::dt($h->occurred_at) }}</td>
      <td class="n">{{ $h->payload['orders'] }}</td>
      <td class="n">{{ Fmt::money($h->payload['net_amount']) }}</td>
      <td class="n">{{ Fmt::money($h->payload['cash_difference']) }}</td>
      <td><span class="b {{ $tone }}">{{ $label }}</span></td>
    </tr>
  @empty
    <tr><td colspan="7" class="muted">Belum pernah tutup hari di server ini.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
@endsection

@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<form class="bar" method="get">
  <label class="f"><span>Dari</span><input type="date" name="from" value="{{ $from }}"></label>
  <label class="f"><span>Sampai</span><input type="date" name="to" value="{{ $to }}"></label>
  <button class="btn alt">Tampilkan</button>
</form>
<p class="muted small">Setiap Rasentra Lokal menutup hari bisnis (otomatis pukul 04.00 atau <span class="mono">php artisan rasentra:close-day</span>) dan mengirim jumlah struk, nilai bersih, daftar nomor struk dan selisih kas shift. Central mencocokkannya dengan penjualan yang sudah diterima. "Menunggu struk" berarti ada struk yang belum sampai ke Central; status diperbarui otomatis saat struk itu masuk.</p>

@if ($detail)
  @php [$label, $tone] = $statuses[$detail->status]; $sum = $detail->local_summary ?? []; @endphp
  <div class="card" style="margin-bottom:14px">
    <div class="bar"><h2 style="margin:0">{{ $detail->outlet->code }} · {{ $detail->business_date->translatedFormat('D, d M Y') }}</h2> <span class="b {{ $tone }}">{{ $label }}</span>
      <form class="grow" method="post" action="{{ route('core.reconciliation.recheck', $detail) }}">@csrf<button class="btn alt sm">Periksa ulang</button></form></div>
    <div class="grid g2">
      <dl class="dl">
        <dt>Ditutup</dt><dd>{{ Fmt::dt($detail->closed_at) }} · versi {{ $detail->closing_version }}</dd>
        <dt>Struk Lokal / Central</dt><dd>{{ $detail->local_orders }} / {{ $detail->central_orders }}</dd>
        <dt>Bersih Lokal / Central</dt><dd>{{ Fmt::money($detail->local_net) }} / {{ Fmt::money($detail->central_net) }}</dd>
        <dt>Retur Lokal</dt><dd>{{ $sum['returns'] ?? 0 }} dokumen · {{ Fmt::money($sum['returns_net_amount'] ?? 0) }}</dd>
        <dt>Selisih kas shift</dt><dd>{{ Fmt::money($detail->cash_difference) }} dari {{ $detail->shifts }} shift @if($detail->open_shifts)<span class="b warn">{{ $detail->open_shifts }} masih buka</span>@endif</dd>
      </dl>
      <dl class="dl">
        <dt>Per kanal</dt><dd>{{ collect($sum['by_channel'] ?? [])->map(fn ($c, $k) => "$k {$c['orders']} struk ".Fmt::money($c['net_amount']))->implode(' · ') ?: '-' }}</dd>
        <dt>Per metode bayar</dt><dd>{{ collect($sum['payments'] ?? [])->map(fn ($v, $k) => "$k ".Fmt::money($v))->implode(' · ') ?: '-' }}</dd>
        <dt>Belum sampai di Central</dt><dd class="mono small">{{ implode(', ', $detail->missing_doc_nos ?? []) ?: '-' }}</dd>
        <dt>Tidak dilaporkan Lokal</dt><dd class="mono small">{{ implode(', ', $detail->extra_doc_nos ?? []) ?: '-' }}</dd>
      </dl>
    </div>
  </div>
@endif

<div class="tablewrap">
<table class="t">
  <thead><tr><th>Tanggal</th><th>Outlet</th><th class="n">Struk Lokal</th><th class="n">Struk Central</th><th class="n">Bersih Lokal</th><th class="n">Bersih Central</th><th class="n">Selisih kas</th><th>Status</th><th></th></tr></thead>
  <tbody>
  @foreach ($days as $day)
    @foreach ($outlets as $o)
      @php $r = $rows[$o->id.'|'.$day] ?? null; @endphp
      <tr>
        <td>{{ \Illuminate\Support\Carbon::parse($day)->translatedFormat('D, d M Y') }}</td>
        <td><b>{{ $o->code }}</b> <span class="muted small">{{ $o->businessUnit?->code }}</span></td>
        @if ($r)
          @php [$label, $tone] = $statuses[$r->status]; @endphp
          <td class="n">{{ $r->local_orders }}</td>
          <td class="n">{{ $r->central_orders }}</td>
          <td class="n">{{ Fmt::money($r->local_net) }}</td>
          <td class="n">{{ Fmt::money($r->central_net) }}</td>
          <td class="n">@if((float) $r->cash_difference != 0)<span class="b warn">{{ Fmt::money($r->cash_difference) }}</span>@else 0 @endif</td>
          <td><span class="b {{ $tone }}">{{ $label }}</span>@if($r->missing_doc_nos)<div class="muted small">{{ count($r->missing_doc_nos) }} struk belum sampai</div>@endif</td>
          <td><a href="{{ route('core.reconciliation', ['from' => $from, 'to' => $to, 'show' => $r->id]) }}">detail</a></td>
        @else
          <td colspan="6" class="muted">Belum tutup hari</td><td><span class="b mute">-</span></td><td></td>
        @endif
      </tr>
    @endforeach
  @endforeach
  </tbody>
</table>
</div>
@endsection

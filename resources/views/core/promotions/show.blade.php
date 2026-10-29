@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
@php
    [$label, $tone] = $statuses[$version->status] ?? [$version->status, 'mute'];
    $editable = in_array($version->status, ['draft', 'simulated'], true);
    $mine = $version->created_by == auth()->id();
    $in = $sim['input'] ?? null;
@endphp
<p><a href="{{ route('core.promotions.index') }}">&larr; Semua promo</a></p>
<div class="grid g2">
  <div class="card">
    <div class="actions" style="justify-content:space-between">
      <h2 style="margin:0">{{ $version->promotion->name }}</h2>
      <span class="b {{ $tone }}">{{ $label }}</span>
    </div>
    <ul>@foreach ($ruleText as $line)<li>{{ $line }}</li>@endforeach</ul>
    <dl class="dl">
      <dt>Tipe</dt><dd>{{ $types[$version->type] ?? $version->type }} · {{ $version->scope === 'basket' ? 'keranjang' : 'per barang' }}</dd>
      <dt>Tumpuk / prioritas</dt><dd>{{ str_replace('_', ' ', $version->stacking) }} · {{ $version->priority }}</dd>
      <dt>Periode</dt><dd>{{ Fmt::dt($version->starts_at) }} &ndash; {{ $version->ends_at ? Fmt::dt($version->ends_at) : 'tanpa batas' }}</dd>
      <dt>Didanai</dt><dd>{{ $version->funded_by }}</dd>
      <dt>Dibuat / disunting</dt><dd>{{ $names[$version->created_by] ?? '-' }}</dd>
      @if($version->approved_by)<dt>Diterbitkan</dt><dd>{{ $names[$version->approved_by] ?? '-' }} · {{ Fmt::dt($version->published_at) }}</dd>@endif
    </dl>
    <p class="actions">
      @if($editable)<a class="btn alt" href="{{ route('core.promotions.edit', $version) }}">Ubah aturan</a>@endif
      @if(in_array($version->status, ['published', 'expired', 'superseded'], true))
        <form class="inline" method="post" action="{{ route('core.promotions.new-version', $version) }}">@csrf<button class="btn alt">Buat versi baru</button></form>
      @endif
    </p>
    @if($version->status === 'published')
      <form method="post" action="{{ route('core.promotions.stop', $version) }}" class="bar">
        @csrf
        <label class="f"><span>Hentikan sekarang, alasan</span><input name="reason" required placeholder="Stok habis / salah harga"></label>
        <button class="btn danger" data-confirm="Hentikan promo ini di semua outlet target?">Hentikan promo</button>
      </form>
    @endif
  </div>

  <div class="card">
    <h2>Simulasi keranjang</h2>
    <form method="post" action="{{ route('core.promotions.simulate', $version) }}">
      @csrf
      <div class="fields">
        <label class="f"><span>Outlet</span><select name="outlet_id">@foreach ($outlets as $o)<option value="{{ $o->id }}" @selected(($in['outlet_id'] ?? null) == $o->id || (! $in && $o->code === 'JMS'))>{{ $o->code }} {{ $o->name }}</option>@endforeach</select></label>
        <label class="f"><span>Kanal</span><select name="channel">@foreach (\App\Http\Controllers\Core\PriceController::CHANNELS as $k => $v)<option value="{{ $k }}" @selected(($in['channel'] ?? 'retail') === $k)>{{ $v }}</option>@endforeach</select></label>
      </div>
      <table class="t" style="margin-top:10px">
        <thead><tr><th>Produk</th><th style="width:120px">Jumlah / kg</th></tr></thead>
        <tbody>
        @for ($i = 0; $i < 5; $i++)
          <tr>
            <td><select name="product[{{ $i }}]" style="width:100%"><option value="">-</option>@foreach ($products as $p)<option value="{{ $p->id }}" @selected(($in['product'][$i] ?? null) == $p->id)>{{ $p->name }} ({{ $p->code }})</option>@endforeach</select></td>
            <td><input name="qty[{{ $i }}]" inputmode="decimal" value="{{ $in['qty'][$i] ?? '' }}" style="width:100%"></td>
          </tr>
        @endfor
        </tbody>
      </table>
      <p><button class="btn">Jalankan simulasi</button> <span class="muted small">Memakai harga terbit outlet dan promo lain yang sedang aktif di sana.</span></p>
    </form>
  </div>
</div>

@if ($sim)
  <h2>Hasil simulasi</h2>
  @if(! $sim['channel_ok'])<div class="alert info">Promo ini tidak berlaku di kanal {{ $sim['channel'] }}, jadi hasilnya sama dengan tanpa promo.</div>@endif
  <div class="tablewrap">
  <table class="t">
    <thead><tr><th>Produk</th><th class="n">Jumlah</th><th class="n">Harga</th><th class="n">Bruto</th><th class="n">Potongan tanpa promo ini</th><th class="n">Potongan dengan promo ini</th><th class="n">Bersih</th><th>Promo yang kena</th></tr></thead>
    <tbody>
    @foreach ($sim['lines'] as $l)
      @php $b = $sim['before']['lines'][$l['key']]; $a = $sim['after']['lines'][$l['key']]; @endphp
      <tr>
        <td>{{ $l['name'] }}</td>
        <td class="n">{{ Fmt::qty($l['quantity'], $l['uom'] === 'KG' ? 3 : 0) }} {{ $l['uom'] }}</td>
        <td class="n">{{ Fmt::money($l['unit_price']) }}</td>
        <td class="n">{{ Fmt::money($l['gross']) }}</td>
        <td class="n">{{ Fmt::money($b['discount']) }}</td>
        <td class="n"><b>{{ Fmt::money($a['discount']) }}</b></td>
        <td class="n">{{ Fmt::money($a['net']) }}</td>
        <td class="small">@foreach ($a['allocations'] as $al)<span @class(['b', 'ok' => (int) $al['promotion_version_id'] === $version->id])>{{ $promoNames[$al['promotion_version_id']] ?? '#'.$al['promotion_version_id'] }} {{ Fmt::money($al['amount']) }}</span> @endforeach</td>
      </tr>
    @endforeach
    </tbody>
    <tfoot>
      <tr><th colspan="3">Total</th><th class="n">{{ Fmt::money($sim['gross']) }}</th><th class="n">{{ Fmt::money($sim['before']['total_discount']) }}</th><th class="n">{{ Fmt::money($sim['after']['total_discount']) }}</th><th class="n">{{ Fmt::money(\App\Support\Decimal::of($sim['gross'])->minus(\App\Support\Decimal::of($sim['after']['total_discount']))) }}</th><th>Promo ini: <b>{{ Fmt::money($sim['this_discount']) }}</b></th></tr>
    </tfoot>
  </table>
  </div>
@endif

@if ($version->status === 'simulated')
  <form class="card" method="post" action="{{ route('core.promotions.publish', $version) }}" style="margin-top:14px">
    @csrf
    <h2>Terbitkan ke outlet</h2>
    <div class="actions">
      <label class="check"><input type="radio" name="target" value="all" data-target-mode checked> Semua outlet aktif</label>
      <label class="check"><input type="radio" name="target" value="regions" data-target-mode> Wilayah</label>
      <label class="check"><input type="radio" name="target" value="outlets" data-target-mode> Outlet tertentu</label>
    </div>
    <div class="actions hide" data-target-panel="regions" style="margin-top:8px">@foreach ($regions as $r)<label class="check"><input type="checkbox" name="regions[]" value="{{ $r->code }}"> {{ $r->name }}</label>@endforeach</div>
    <div class="actions hide" data-target-panel="outlets" style="margin-top:8px">@foreach ($outlets as $o)<label class="check"><input type="checkbox" name="outlets[]" value="{{ $o->code }}"> {{ $o->code }} {{ $o->name }}</label>@endforeach</div>
    <p class="actions" style="margin-top:12px">
      <button class="btn" @disabled($mine) data-confirm="Terbitkan promo ini? Versi terbit tidak dapat diubah.">Setujui & terbitkan</button>
      @if($mine)<span class="muted small">Anda yang terakhir menyunting promo ini. Rekan pusat lain yang harus menyetujui.</span>@endif
    </p>
  </form>
@endif

@if ($receipts)
  <h2>Penerimaan di outlet</h2>
  <div class="tablewrap"><table class="t">
    <thead><tr><th>Outlet</th><th>Status</th><th>Waktu konfirmasi</th></tr></thead>
    <tbody>@foreach ($receipts as $r)<tr><td>{{ $r['code'] }} {{ $r['name'] }}</td><td><span @class(['b', 'ok' => in_array($r['ack_status'], ['received', 'applied'], true), 'warn' => $r['ack_status'] === 'pending', 'err' => $r['ack_status'] === 'failed'])>{{ $r['ack_status'] }}</span></td><td>{{ Fmt::dt($r['acked_at']) }}</td></tr>@endforeach</tbody>
  </table></div>
@endif

<h2>Riwayat versi</h2>
<div class="tablewrap"><table class="t">
  <thead><tr><th>Versi</th><th>Status</th><th>Periode</th><th>Terbit</th><th></th></tr></thead>
  <tbody>@foreach ($history as $h)<tr><td>v{{ $h->version }}</td><td>{{ $statuses[$h->status][0] ?? $h->status }}</td><td class="small">{{ Fmt::dt($h->starts_at) }} &ndash; {{ $h->ends_at ? Fmt::dt($h->ends_at) : 'tanpa batas' }}</td><td>{{ Fmt::dt($h->published_at) }}</td><td>@if($h->id !== $version->id)<a href="{{ route('core.promotions.show', $h) }}">buka</a>@else<span class="muted">sedang dibuka</span>@endif</td></tr>@endforeach</tbody>
</table></div>
@endsection

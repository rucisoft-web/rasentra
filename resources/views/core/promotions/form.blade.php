@extends('core.layout')
@section('content')
@php
    $tz = config('rasentra.display_timezone');
    $tiers = $rules['tiers'] ?? [];
    $val = fn ($k, $d = '') => old($k, $rules[$k] ?? $d);
@endphp
<p><a href="{{ $promotion ? route('core.promotions.show', $version) : route('core.promotions.index') }}">&larr; Kembali</a></p>
<form method="post" action="{{ $promotion ? route('core.promotions.update', $version) : route('core.promotions.store') }}" class="grid">
  @csrf @if($promotion) @method('put') @endif
  <div class="card">
    <h2>Identitas dan aturan main</h2>
    <div class="fields">
      <label class="f"><span>Kode promo</span><input name="code" value="{{ old('code', $promotion?->code) }}" @disabled($promotion) required placeholder="SLO-WAGYU-NOV" class="mono"></label>
      <label class="f wide"><span>Nama di struk dan laporan</span><input name="name" value="{{ old('name', $promotion?->name) }}" required></label>
      <label class="f"><span>Tipe</span><select name="type" data-promo-type>@foreach ($types as $k => $v)<option value="{{ $k }}" @selected(old('type', $version->type) === $k)>{{ $v }}</option>@endforeach</select></label>
      <label class="f" data-for-types="percent"><span>Berlaku pada</span><select name="scope"><option value="item" @selected(old('scope', $version->scope) === 'item')>Per barang</option><option value="basket" @selected(old('scope', $version->scope) === 'basket')>Total keranjang</option></select></label>
      <label class="f"><span>Aturan tumpuk</span><select name="stacking">
        <option value="exclusive" @selected(old('stacking', $version->stacking) === 'exclusive')>Eksklusif (baris tidak menerima promo lain)</option>
        <option value="stackable" @selected(old('stacking', $version->stacking) === 'stackable')>Bisa ditumpuk</option>
        <option value="best_benefit" @selected(old('stacking', $version->stacking) === 'best_benefit')>Pilih manfaat terbesar</option></select></label>
      <label class="f"><span>Prioritas</span><input type="number" name="priority" min="1" max="999" value="{{ old('priority', $version->priority) }}"><small>Angka kecil dihitung lebih dulu.</small></label>
      <label class="f"><span>Didanai oleh</span><select name="funded_by">@foreach (['internal' => 'Internal', 'supplier' => 'Pemasok', 'bank' => 'Bank', 'platform' => 'Platform'] as $k => $v)<option value="{{ $k }}" @selected(old('funded_by', $version->funded_by) === $k)>{{ $v }}</option>@endforeach</select></label>
      <label class="f"><span>Mulai</span><input type="datetime-local" name="starts_at" value="{{ old('starts_at', $version->starts_at?->timezone($tz)->format('Y-m-d\TH:i')) }}" required></label>
      <label class="f"><span>Berakhir</span><input type="datetime-local" name="ends_at" value="{{ old('ends_at', $version->ends_at?->timezone($tz)->format('Y-m-d\TH:i')) }}"></label>
      <div class="f wide"><span>Kanal (kosong = semua)</span><div class="actions">@foreach ($channels as $k => $v)<label class="check"><input type="checkbox" name="channels[]" value="{{ $k }}" @checked(in_array($k, old('channels', $version->channels ?? []), true))> {{ $v }}</label>@endforeach</div></div>
    </div>
  </div>

  <div class="card">
    <h2>Manfaat</h2>
    <div class="fields">
      <label class="f" data-for-types="percent"><span>Diskon (%)</span><input name="percent" inputmode="decimal" value="{{ $val('percent') }}"></label>
      <label class="f" data-for-types="amount"><span>Potongan (Rp)</span><input name="amount" inputmode="decimal" value="{{ $val('amount') }}"></label>
      <label class="f" data-for-types="special_price"><span>Harga spesial per satuan (Rp)</span><input name="price" inputmode="decimal" value="{{ $val('price') }}"></label>
      <label class="f" data-for-types="percent amount weight_tier"><span>Potongan maksimal (Rp)</span><input name="max_discount" inputmode="decimal" value="{{ $val('max_discount') }}"></label>
      <label class="f" data-for-types="percent amount"><span>Minimal belanja keranjang (Rp)</span><input name="min_spend" inputmode="decimal" value="{{ $val('min_spend') }}"><small>Hanya untuk promo keranjang.</small></label>
      <div class="wide" data-for-types="weight_tier">
        <table class="t"><thead><tr><th>Total berat minimal (kg)</th><th>Diskon (%)</th></tr></thead><tbody>
        @for ($i = 0; $i < 3; $i++)
          <tr><td><input name="tier_weight[{{ $i }}]" inputmode="decimal" value="{{ old("tier_weight.$i", $tiers[$i]['min_weight'] ?? '') }}"></td><td><input name="tier_percent[{{ $i }}]" inputmode="decimal" value="{{ old("tier_percent.$i", $tiers[$i]['percent'] ?? '') }}"></td></tr>
        @endfor
        </tbody></table>
        <p class="muted small">Berat dihitung gabungan semua baris yang memenuhi syarat, dengan berat aktual Pack ID.</p>
      </div>
      <label class="f" data-for-types="buy_x_get_y"><span>Kode produk dibeli</span><input name="buy_code" value="{{ old('buy_code', $buyCode) }}" class="mono"></label>
      <label class="f" data-for-types="buy_x_get_y"><span>Jumlah beli</span><input type="number" name="buy_qty" min="1" value="{{ $val('buy_qty', 2) }}"></label>
      <label class="f" data-for-types="buy_x_get_y"><span>Kode produk gratis</span><input name="get_code" value="{{ old('get_code', $getCode) }}" class="mono"></label>
      <label class="f" data-for-types="buy_x_get_y"><span>Jumlah gratis</span><input type="number" name="get_qty" min="1" value="{{ $val('get_qty', 1) }}"></label>
      <label class="f" data-for-types="buy_x_get_y"><span>Maksimal kelipatan per struk</span><input type="number" name="max_multiple" min="1" value="{{ $val('max_multiple') }}"></label>
    </div>
  </div>

  <div class="card">
    <h2>Syarat barang</h2>
    <div class="fields">
      <div class="f wide"><span>Kategori</span><div class="actions">@foreach ($categories as $c)<label class="check"><input type="checkbox" name="categories[]" value="{{ $c }}" @checked(in_array($c, old('categories', $rules['categories'] ?? []), true))> {{ $c }}</label>@endforeach</div></div>
      <label class="f wide"><span>Kode produk (pisahkan dengan koma)</span><input name="product_codes" value="{{ old('product_codes', $productCodes) }}" class="mono" placeholder="2101001, 2101002"></label>
      <div class="f wide"><span>Jenis baris</span><div class="actions">@foreach ($lineTypes as $k => $v)<label class="check"><input type="checkbox" name="line_types[]" value="{{ $k }}" @checked(in_array($k, old('line_types', $rules['line_types'] ?? []), true))> {{ $v }}</label>@endforeach</div></div>
    </div>
  </div>
  <p class="actions"><button class="btn">{{ $promotion ? 'Simpan perubahan' : 'Simpan draft' }}</button><span class="muted small">Setelah disimpan, jalankan simulasi lalu minta rekan pusat lain menerbitkan.</span></p>
</form>
@endsection

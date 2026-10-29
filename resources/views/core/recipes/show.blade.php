@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
@php $draft = $version?->status === 'draft'; @endphp
<p><a href="{{ route('core.recipes.index') }}">&larr; Semua resep</a></p>
<div class="grid g2">
  <div class="card">
    <h2>{{ $recipe->name }}</h2>
    <dl class="dl">
      <dt>Produk hasil</dt><dd><span class="mono">{{ $recipe->product->code }}</span> {{ $recipe->product->name }}</dd>
      <dt>Dipakai di resep lain</dt><dd>{{ $usedBy->isEmpty() ? '-' : $usedBy->implode(', ') }}</dd>
    </dl>
    <h3>Versi</h3>
    <div class="actions">
      @foreach ($versions as $v)
        <a href="{{ route('core.recipes.show', [$recipe, 'v' => $v->id]) }}" @class(['b', 'ok' => $v->status === 'approved', 'warn' => $v->status === 'draft', 'mute' => $v->status === 'retired'])>{{ $version?->id === $v->id ? '▸ ' : '' }}v{{ $v->version }} {{ $v->status }}</a>
      @endforeach
    </div>
    @unless ($versions->contains('status', 'draft'))
      <form method="post" action="{{ route('core.recipes.draft', $recipe) }}" style="margin-top:12px">@csrf<button class="btn alt">Buat draft versi baru</button></form>
    @endunless
  </div>
  @if ($version)
  <div class="card">
    <h2>Versi {{ $version->version }}</h2>
    <dl class="dl">
      <dt>Status</dt><dd>{{ $version->status }}@if($approver) · disetujui {{ $approver }} {{ Fmt::dt($version->effective_from) }}@endif</dd>
      <dt>Jenis</dt><dd>{{ $kinds[$version->kind] ?? $version->kind }}</dd>
      <dt>Hasil per resep</dt><dd>{{ Fmt::qty($version->yield_quantity, 3) }} {{ $version->yield_uom }}</dd>
      <dt>Cakupan outlet</dt><dd>{{ empty($version->outlet_ids) ? 'Semua outlet' : count($version->outlet_ids).' outlet' }}</dd>
    </dl>
    @if ($draft)
      <form method="post" action="{{ route('core.recipes.approve', $version) }}" style="margin-top:12px">@csrf
        <button class="btn" @disabled($mine) data-confirm="Setujui versi {{ $version->version }}? Versi aktif sebelumnya dipensiunkan dan resep baru dipakai dapur outlet.">Setujui & kirim ke outlet</button>
        @if($editor)<span class="muted small">Terakhir disunting {{ $editor }}.@if($mine) Rekan lain yang harus menyetujui.@endif</span>@endif
      </form>
    @endif
  </div>
  @endif
</div>

@if ($version)
<form method="post" action="{{ route('core.recipes.update', $version) }}">
  @csrf @method('put')
  <h2>Bahan per {{ Fmt::qty($version->yield_quantity, 3) }} {{ $version->yield_uom }}</h2>
  @if ($draft)
    <div class="fields" style="margin-bottom:10px">
      <label class="f"><span>Hasil</span><input name="yield_quantity" value="{{ (float) $version->yield_quantity }}" inputmode="decimal"></label>
      <label class="f"><span>Satuan hasil</span><select name="yield_uom">@foreach ($uoms as $u)<option @selected($u === $version->yield_uom)>{{ $u }}</option>@endforeach</select></label>
      <label class="f"><span>Jenis</span><select name="kind">@foreach ($kinds as $k => $v)<option value="{{ $k }}" @selected($k === $version->kind)>{{ $v }}</option>@endforeach</select></label>
    </div>
  @endif
  <div class="tablewrap">
  <table class="t">
    <thead><tr><th>Kode bahan</th><th>Nama</th><th class="n">Jumlah</th><th>Satuan</th><th>Hanya bila modifier</th>@if($draft)<th></th>@endif</tr></thead>
    <tbody data-rows="lines">
    @foreach ($version->lines as $i => $l)
      <tr>
        @if ($draft)
          <td><input name="code[{{ $i }}]" value="{{ $l->component->code }}" class="mono" style="width:120px"></td>
          <td>{{ $l->component->name }}</td>
          <td class="n"><input name="qty[{{ $i }}]" value="{{ rtrim(rtrim((string) $l->quantity, '0'), '.') }}" inputmode="decimal" style="width:110px;text-align:right"></td>
          <td><select name="uom[{{ $i }}]">@foreach ($uoms as $u)<option @selected($u === $l->uom_code)>{{ $u }}</option>@endforeach</select></td>
          <td><input name="modifier[{{ $i }}]" value="{{ $l->modifier_key }}" style="width:140px"></td>
          <td><a href="#" data-remove-row>hapus</a></td>
        @else
          <td class="mono">{{ $l->component->code }}</td><td>{{ $l->component->name }}</td><td class="n">{{ Fmt::qty($l->quantity, 3) }}</td><td>{{ $l->uom_code }}</td><td>{{ $l->modifier_key ?? '-' }}</td>
        @endif
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
  @if ($draft)
    <template data-row-template="lines"><tr><td><input name="code[__i__]" class="mono" style="width:120px" placeholder="Kode"></td><td class="muted small">bahan baru</td><td class="n"><input name="qty[__i__]" inputmode="decimal" style="width:110px;text-align:right"></td><td><select name="uom[__i__]">@foreach ($uoms as $u)<option>{{ $u }}</option>@endforeach</select></td><td><input name="modifier[__i__]" style="width:140px"></td><td><a href="#" data-remove-row>hapus</a></td></tr></template>
    <p class="actions"><a href="#" class="btn alt" data-add-row="lines">Tambah bahan</a><button class="btn">Simpan draft</button></p>
  @endif
</form>
@endif
@endsection

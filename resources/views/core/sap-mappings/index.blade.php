@extends('core.layout')
@section('content')
<div class="tabs">
  @foreach ($companies as $c)<a href="{{ route('core.sap-mappings.index', ['company' => $c->sap_company_db, 'type' => $type]) }}" @class(['on' => $c->id === $company->id])>{{ $c->name }} · <span class="mono">{{ $c->sap_company_db }}</span></a>@endforeach
</div>
<div class="tabs">
  @foreach ($types as $k => $v)<a href="{{ route('core.sap-mappings.index', ['company' => $companyDb, 'type' => $k]) }}" @class(['on' => $type === $k])>{{ $v }}</a>@endforeach
</div>
<div class="grid g2">
  <div>
    <div class="tablewrap">
    <table class="t">
      <thead><tr><th>Rasentra</th><th>Kode SAP</th><th>Atribut</th><th></th></tr></thead>
      <tbody>
      @forelse ($mappings as $m)
        <tr>
          <td>@switch($type)
              @case('product')<span class="mono">{{ $products[$m->entity_key]->code ?? $m->entity_key }}</span> {{ $products[$m->entity_key]->name ?? '' }}@break
              @case('outlet'){{ $outlets[$m->entity_key]->code ?? $m->entity_key }} {{ $outlets[$m->entity_key]->name ?? '' }}@break
              @default<span class="mono">{{ $m->entity_key }}</span>
            @endswitch</td>
          <td class="mono"><b>{{ $m->sap_code }}</b></td>
          <td class="small">{{ $m->attributes ? collect($m->attributes)->map(fn ($v, $k) => "$k: $v")->implode(', ') : '-' }}</td>
          <td><form class="inline" method="post" action="{{ route('core.sap-mappings.destroy', $m) }}">@csrf @method('delete')<button class="btn alt sm" data-confirm="Hapus mapping ini? Posting yang membutuhkannya akan tertahan.">Hapus</button></form></td>
        </tr>
      @empty
        <tr><td colspan="4" class="muted">Belum ada mapping.</td></tr>
      @endforelse
      </tbody>
    </table>
    </div>
    {{ $mappings->links('core.pager') }}
  </div>
  <div class="grid">
    <form class="card" method="post" action="{{ route('core.sap-mappings.store') }}">
      @csrf
      <h2>Tambah atau ubah mapping</h2>
      <input type="hidden" name="company" value="{{ $companyDb }}">
      <p class="muted small">{{ $company->name }} · instance SAP <span class="mono">{{ $companyDb }}</span></p>
      <div class="fields">
        <label class="f"><span>Jenis</span><select name="entity_type">@foreach ($types as $k => $v)<option value="{{ $k }}" @selected($type === $k)>{{ $v }}</option>@endforeach</select></label>
        <label class="f"><span>Kode Rasentra</span><input name="entity_key" class="mono" required placeholder="kode produk / kode outlet / cash / walk_in"></label>
        <label class="f"><span>Kode SAP</span><input name="sap_code" class="mono" required placeholder="ItemCode / WhsCode / akun"></label>
        <label class="f"><span>Kode pajak (produk)</span><input name="tax_code" class="mono" placeholder="PPN11 / NONPPN"></label>
      </div>
      <p><button class="btn">Simpan mapping</button></p>
    </form>
    <div class="card">
      <h2>Belum dimapping</h2>
      @if ($unmappedMethods)<p>Metode bayar: @foreach ($unmappedMethods as $m)<span class="b warn mono">{{ $m }}</span> @endforeach</p>@endif
      @if ($unmappedSold->isEmpty())
        <p class="muted">Semua produk yang sudah terjual punya mapping item SAP.</p>
      @else
        <p class="muted small">Produk yang sudah terjual tetapi belum punya ItemCode. Posting penjualannya tertahan.</p>
        <table class="t"><thead><tr><th>Kode</th><th>Nama</th><th class="n">Baris</th></tr></thead>
          <tbody>@foreach ($unmappedSold as $p)<tr><td class="mono">{{ $p->code }}</td><td>{{ $p->name }}</td><td class="n">{{ $p->lines }}</td></tr>@endforeach</tbody></table>
      @endif
    </div>
  </div>
</div>
@endsection

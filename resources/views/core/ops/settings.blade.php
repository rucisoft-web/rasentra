@extends('core.layout')
@section('content')
@php $scopeLabel = $scope === 'global' ? 'Seluruh grup' : ($scope === 'company' ? 'Perusahaan: '.($companies->firstWhere('id', $scopeId)?->name) : 'Outlet: '.($outlets->firstWhere('id', $scopeId)?->name)); @endphp
<p class="muted">Isi di level grup agar semua outlet bekerja dengan aturan yang sama. Level CV atau outlet hanya untuk pengecualian; kolom kosong mengikuti level di atasnya.</p>
<form method="get" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
  <select name="pick" onchange="const [s,i]=this.value.split(':');this.form.scope.value=s;this.form.scope_id.value=i||'';this.form.submit()">
    <option value="global" @selected($scope === 'global')>Seluruh grup</option>
    @foreach ($companies as $c)<option value="company:{{ $c->id }}" @selected($scope === 'company' && $scopeId === $c->id)>CV · {{ $c->name }}</option>@endforeach
    @foreach ($outlets as $o)<option value="outlet:{{ $o->id }}" @selected($scope === 'outlet' && $scopeId === $o->id)>Outlet · {{ $o->code }} {{ $o->name }}</option>@endforeach
  </select>
  <input type="hidden" name="scope" value="{{ $scope }}"><input type="hidden" name="scope_id" value="{{ $scopeId }}">
</form>
<form method="post" action="{{ route('core.settings.update') }}">@csrf @method('put')
<input type="hidden" name="scope" value="{{ $scope }}"><input type="hidden" name="scope_id" value="{{ $scopeId }}">
<h3>{{ $scopeLabel }}</h3>
@foreach (collect($keys)->groupBy(fn ($k) => $k[0], true) as $group => $items)
  <div class="card" style="margin-bottom:12px"><h2>{{ $group }}</h2>
  @foreach ($items as $key => [, $label, $type, $default, $hint])
    @php $v = $values[$key] ?? null; $inherit = $global[$key] ?? $default; @endphp
    <label class="f" style="margin-bottom:10px"><span>{{ $label }}</span>
      @if ($type === 'bool' && $scope === 'global')
        <input type="checkbox" name="values[{{ $key }}]" value="1" @checked($v ?? $default) style="width:auto">
      @elseif ($type === 'bool')
        <select name="values[{{ $key }}]"><option value="">Ikut grup ({{ $inherit ? 'ya' : 'tidak' }})</option><option value="1" @selected($v === true)>Ya</option><option value="0" @selected($v === false)>Tidak</option></select>
      @else
        <input name="values[{{ $key }}]" value="{{ is_scalar($v) ? $v : '' }}" @if (in_array($type, ['int', 'money', 'percent'], true)) type="number" step="any" @endif placeholder="{{ $scope === 'global' ? $default : 'Ikut grup: '.$inherit }}">
      @endif
      @if ($hint)<small class="muted">{{ $hint }}</small>@endif
    </label>
  @endforeach
  </div>
@endforeach
<p><button class="btn">Simpan pengaturan</button></p>
</form>
@endsection

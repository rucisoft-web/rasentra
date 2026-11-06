@extends('core.layout')
@use('App\Domain\Crm\SegmentQuery')
@section('content')
<p><a href="{{ route('core.crm.segments.index') }}">&larr; Semua segmen</a></p>
@php $r = fn ($k, $d = null) => old($k, $rules[$k] ?? $d); @endphp
<form class="card" method="post" action="{{ $segment->exists ? route('core.crm.segments.update', $segment) : route('core.crm.segments.store') }}" style="max-width:960px">
  @csrf @if($segment->exists) @method('put') @endif
  <div class="fields">
    <label class="f"><span>Nama segmen</span><input name="name" value="{{ old('name', $segment->name) }}" required maxlength="80"></label>
    <label class="f"><span>Keterangan</span><input name="description" value="{{ old('description', $segment->description) }}" maxlength="255"></label>
    <div class="f wide"><span>Member outlet (salah satu)</span>
      <div>@foreach ($outlets as $o)<label class="check" style="display:inline-flex;margin-right:12px"><input type="checkbox" name="branch_ids[]" value="{{ $o->id }}" @checked(in_array($o->id, array_map('intval', (array) $r('branch_ids', []))))> {{ $o->code }} {{ $o->name }}</label>@endforeach</div>
    </div>
    <div class="f wide"><span>Punya tag (salah satu)</span>
      <div>@forelse ($tags as $t)<label class="check" style="display:inline-flex;margin-right:12px"><input type="checkbox" name="tag_ids[]" value="{{ $t->id }}" @checked(in_array($t->id, array_map('intval', (array) $r('tag_ids', []))))> {{ $t->name }}</label>@empty<span class="muted small">Belum ada tag.</span>@endforelse</div>
    </div>
    <label class="f"><span>Tahap pelanggan</span><select name="lifecycle"><option value="">Semua</option>@foreach (SegmentQuery::LIFECYCLES as $k => $v)<option value="{{ $k }}" @selected($r('lifecycle') === $k)>{{ $v }}</option>@endforeach</select></label>
    <label class="f"><span>Status WhatsApp</span><select name="wa"><option value="">Semua</option><option value="opted_in" @selected($r('wa') === 'opted_in')>Sudah opt-in</option><option value="reachable" @selected($r('wa') === 'reachable')>Belum opt-out</option><option value="opted_out" @selected($r('wa') === 'opted_out')>Opt-out</option></select><small>Untuk broadcast promosi gunakan "Sudah opt-in".</small></label>
    <label class="f"><span>Belanja dalam N hari terakhir</span><input type="number" min="1" name="active_within_days" value="{{ $r('active_within_days') }}"></label>
    <label class="f"><span>Tidak belanja lebih dari N hari</span><input type="number" min="1" name="inactive_days" value="{{ $r('inactive_days') }}"><small>Hanya pelanggan yang pernah belanja.</small></label>
    <label class="f"><span>Minimal jumlah transaksi</span><input type="number" min="1" name="min_purchase_count" value="{{ $r('min_purchase_count') }}"></label>
    <label class="f"><span>Minimal total belanja (Rp)</span><input type="number" min="0" step="1000" name="min_total_spent" value="{{ $r('min_total_spent') }}"></label>
    <label class="f"><span>Ulang tahun</span><select name="birthday_month"><option value="">—</option><option value="current" @selected($r('birthday_month') === 'current')>Bulan berjalan</option>@foreach (range(1, 12) as $m)<option value="{{ $m }}" @selected((string) $r('birthday_month') === (string) $m)>{{ \Illuminate\Support\Carbon::create(2000, $m)->translatedFormat('F') }}</option>@endforeach</select></label>
    <label class="f"><span>Kota</span><input name="city" value="{{ $r('city') }}" maxlength="80"></label>
  </div>
  @if ($preview !== null)<p class="muted">Dengan aturan tersimpan saat ini: <b>{{ number_format($preview, 0, ',', '.') }}</b> pelanggan.</p>@endif
  <p class="actions"><button class="btn">{{ $segment->exists ? 'Simpan perubahan' : 'Simpan segmen' }}</button></p>
</form>
@if ($segment->exists)
<form method="post" action="{{ route('core.crm.segments.destroy', $segment) }}" onsubmit="return confirm('Hapus segmen ini? Data pelanggan tidak ikut terhapus.')" style="margin-top:10px">
  @csrf @method('delete')<button class="btn alt sm">Hapus segmen</button>
</form>
@endif
@endsection

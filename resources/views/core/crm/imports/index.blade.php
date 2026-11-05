@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<form class="card bar" method="post" action="{{ route('core.crm.imports.store') }}" enctype="multipart/form-data">
  @csrf
  <label class="f"><span>Outlet</span><select name="outlet_id" required><option value="">Pilih outlet</option>@foreach ($outlets as $o)<option value="{{ $o->id }}" @selected(old('outlet_id') == $o->id)>{{ $o->code }} {{ $o->name }}</option>@endforeach</select></label>
  <label class="f"><span>Beri tag (opsional)</span><select name="tag_id"><option value="">—</option>@foreach ($tags as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></label>
  <label class="f"><span>File Excel/CSV (maks. 20 MB)</span><input type="file" name="file" accept=".xlsx,.csv" required></label>
  <button class="btn">Impor</button>
  <a class="btn alt" href="{{ route('core.crm.imports.template') }}">Unduh templat</a>
</form>
<div class="card" style="margin-top:14px">
  <p class="small" style="margin:0">Baris judul cukup berisi <b>Nama</b> dan <b>No HP</b> (juga dikenali: WhatsApp, Telepon, HP). Kolom opsional: Kode Member, Email, Tanggal Lahir, Kota, Tanggal Daftar. Nomor 0812…, +62 812… dan 812… dianggap sama. Bila nomor sudah ada (dari outlet lain, POS atau Accurate), pelanggan tidak dibuat ganda: outlet ini ditambahkan ke keanggotaannya dan data yang masih kosong dilengkapi.</p>
</div>
<div class="tablewrap" style="margin-top:14px">
<table class="t">
  <thead><tr><th>Waktu</th><th>Outlet</th><th>File</th><th>Oleh</th><th class="n">Baru</th><th class="n">Digabung</th><th class="n">Dilewati</th><th></th></tr></thead>
  <tbody>
  @forelse ($imports as $i)
    <tr>
      <td>{{ Fmt::dt($i->created_at) }}</td><td>{{ $i->outlet->code }}</td><td>{{ $i->file_name }}</td><td>{{ $i->user?->name ?? '—' }}</td>
      <td class="n">{{ $i->created_count }}</td><td class="n">{{ $i->merged_count }}</td><td class="n">@if($i->error_count)<span class="b warn">{{ $i->error_count }}</span>@else 0 @endif</td>
      <td><a class="btn alt sm" href="{{ route('core.crm.imports.show', $i) }}">Ringkasan</a></td>
    </tr>
  @empty
    <tr><td colspan="8" class="muted">Belum ada impor.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $imports->links('core.pager') }}
@endsection

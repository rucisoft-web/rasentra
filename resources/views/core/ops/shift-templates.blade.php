@extends('core.layout')
@section('content')
<p class="muted">Jam kerja standar grup. Kepala outlet menyusun roster dari template ini di Rasentra Lokal; absensi dibandingkan dengan jam mulai untuk menghitung keterlambatan.</p>

<div class="tablewrap" style="margin-bottom:14px"><table class="t">
  <thead><tr><th>Kode</th><th>Pengaturan</th></tr></thead>
  <tbody>@foreach ($templates as $t)
    <tr><td class="mono"><b>{{ $t->code }}</b></td><td><form method="post" action="{{ route('core.shift-templates.update', $t) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">@csrf @method('put')@include('core.ops._shift-fields', ['t' => $t])<button class="btn sm">Simpan</button></form></td></tr>
  @endforeach</tbody>
</table></div>
<details class="card"><summary><b>Template baru</b></summary>
  <form method="post" action="{{ route('core.shift-templates.store') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:12px">@csrf
    <label class="f"><span>Kode</span><input name="code" required class="mono" style="width:90px" placeholder="PAGI"></label>@include('core.ops._shift-fields', ['t' => null])<button class="btn">Simpan</button></form>
</details>
@endsection

@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<p class="muted">Tier berlaku di semua outlet. Member naik tier otomatis saat belanja kumulatifnya mencapai batas. Pengali menggandakan poin dasar (aturan poin dasar ada di <a href="{{ route('core.settings.index') }}">Pengaturan umum</a>).</p>
<div class="tablewrap" style="margin-bottom:14px"><table class="t">
  <thead><tr><th>Kode</th><th>Pengaturan</th><th class="n">Member</th></tr></thead>
  <tbody>@foreach ($tiers as $t)
    <tr><td class="mono"><b>{{ $t->code }}</b></td>
      <td><form method="post" action="{{ route('core.member-tiers.update', $t) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">@csrf @method('put')
        @include('core.ops._tier-fields', ['t' => $t])<button class="btn sm">Simpan</button></form></td>
      <td class="n">{{ $counts[$t->id] ?? 0 }}</td></tr>
  @endforeach</tbody>
</table></div>
<details class="card"><summary><b>Tier baru</b></summary>
  <form method="post" action="{{ route('core.member-tiers.store') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:12px">@csrf
    <label class="f"><span>Kode</span><input name="code" required class="mono" style="width:90px"></label>@include('core.ops._tier-fields', ['t' => null])<button class="btn">Simpan</button></form>
</details>
@endsection

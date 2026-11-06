@extends('core.layout')
@use('App\Models\Crm\Branch')
@section('content')
<div class="card">
  <p class="small" style="margin:0">Outlet Rasentra muncul di sini otomatis. CV yang masih memakai <b>Accurate Online + Rene POS/Raptor</b> ditambahkan di bawah: membernya bisa diimpor per cabang, pelanggan dan fakturnya ditarik dari Accurate, dan bisa dikirimi WhatsApp. Cabang ini tidak ikut ke POS, sinkronisasi, harga, promo atau posting SAP Rasentra.</p>
</div>

<div class="tablewrap" style="margin-top:14px">
<table class="t">
  <thead><tr><th>Kode</th><th>Cabang</th><th>Perusahaan</th><th>Sistem POS</th><th>Kota</th><th class="n">Member</th><th>Status</th><th></th></tr></thead>
  <tbody>
  @foreach ($branches as $b)
    <tr>
      <td class="mono">{{ $b->code }}</td><td>{{ $b->name }}</td><td>{{ $b->businessUnit?->name }}</td>
      <td><span class="b {{ $b->pos_system === 'rasentra' ? 'ok' : 'info' }}">{{ Branch::POS_SYSTEMS[$b->pos_system] ?? $b->pos_system }}</span></td>
      <td>{{ $b->city ?? '—' }}</td><td class="n">{{ number_format($b->memberships_count, 0, ',', '.') }}</td>
      <td>@if($b->is_active)<span class="b ok">Aktif</span>@else<span class="b mute">Nonaktif</span>@endif</td>
      <td>@unless($b->outlet_id)<form method="post" action="{{ route('core.crm.branches.toggle', $b) }}">@csrf<button class="btn alt sm">{{ $b->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></form>@endunless</td>
    </tr>
  @endforeach
  </tbody>
</table>
</div>

<div class="grid g2" style="margin-top:14px">
  <form class="card" method="post" action="{{ route('core.crm.companies.store') }}">
    @csrf
    <h3>Tambah perusahaan (CV)</h3>
    <div class="fields">
      <label class="f"><span>Kode</span><input name="code" required maxlength="20" placeholder="mis. MKS"></label>
      <label class="f"><span>Nama</span><input name="name" required maxlength="120" placeholder="CV Makasar Kulina"></label>
    </div>
    <p><button class="btn alt">Tambah perusahaan</button></p>
  </form>
  <form class="card" method="post" action="{{ route('core.crm.branches.store') }}">
    @csrf
    <h3>Tambah cabang non-Rasentra</h3>
    <div class="fields">
      <label class="f"><span>Perusahaan</span><select name="business_unit_id" required>@foreach ($companies as $c)<option value="{{ $c->id }}">{{ $c->code }} {{ $c->name }}</option>@endforeach</select></label>
      <label class="f"><span>Sistem POS</span><select name="pos_system">@foreach (Branch::POS_SYSTEMS as $k => $v)@continue($k === 'rasentra')<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
      <label class="f"><span>Kode cabang</span><input name="code" required maxlength="20" placeholder="MKS-01"></label>
      <label class="f"><span>Nama cabang</span><input name="name" required maxlength="120" placeholder="Outlet Makassar Panakkukang"></label>
      <label class="f"><span>Kota</span><input name="city" maxlength="80"></label>
    </div>
    <p><button class="btn alt">Tambah cabang</button></p>
  </form>
</div>
@endsection

@extends('core.layout')
@section('content')
<p class="muted">Daftar metode bayar yang tampil di Rasentra POS. Kosongkan outlet untuk berlaku di semua outlet. Akun SAP dipakai saat posting penerimaan pembayaran.</p>
<div class="tablewrap" style="margin-bottom:14px"><table class="t">
  <thead><tr><th>Kode</th><th>Nama & pengaturan</th></tr></thead>
  <tbody>@forelse ($methods as $m)
    <tr><td class="mono"><b>{{ $m->code }}</b><br>@if ($m->is_active)<span class="b ok">Aktif</span>@else<span class="b mute">Nonaktif</span>@endif</td>
      <td><form method="post" action="{{ route('core.payment-methods.update', $m) }}">@csrf @method('put')@include('core.ops._payment-fields', ['m' => $m])<button class="btn sm">Simpan</button></form></td></tr>
  @empty <tr><td colspan="2" class="muted">Belum ada metode. POS memakai daftar bawaan (tunai, QRIS, EDC, EDC manual, voucher).</td></tr>
  @endforelse</tbody>
</table></div>
<details class="card" @if($errors->any()) open @endif><summary><b>Metode baru</b></summary>
  <form method="post" action="{{ route('core.payment-methods.store') }}" style="margin-top:12px">@csrf
    <label class="f" style="max-width:240px;margin-bottom:10px"><span>Kode</span><input name="code" class="mono" required placeholder="transfer_bca"></label>
    @include('core.ops._payment-fields', ['m' => null])<button class="btn">Simpan</button>
  </form>
</details>
@endsection

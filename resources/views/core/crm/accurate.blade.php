@extends('core.layout')
@use('App\Support\Fmt')
@use('App\Domain\Integration\Accurate\AccurateSync')
@section('content')
<div class="card">
  <p class="small" style="margin:0">Rasentra CRM menarik <b>pelanggan</b> (nama, HP, email, kota) dan <b>faktur penjualan</b> dari Accurate Online setiap jam, satu arah (Accurate → CRM). Buat API Token di Accurate Online (menu Pengaturan → API Token, cakupan <i>customer view</i> dan <i>sales invoice view</i>) lalu tempel token dan signature secret-nya di bawah. Pelanggan Accurate yang nomornya sama dengan member outlet otomatis digabung.</p>
</div>
@foreach ($companies as $company)
  @php $conn = $connections[$company->id] ?? null; $ok = $conn && AccurateSync::configured($conn); @endphp
  <div class="card" style="margin-top:14px">
    <h3>{{ $company->code }} · {{ $company->name }}
      @if ($ok)<span class="b ok">Terkonfigurasi</span>@else<span class="b mute">Belum terhubung</span>@endif
      @if ($conn?->last_status === 'failed')<span class="b err">Sinkron terakhir gagal</span>@endif
    </h3>
    @if ($conn?->last_synced_at)<p class="muted small">Sinkron terakhir {{ Fmt::dt($conn->last_synced_at) }}: {{ $conn->last_message }}@if($conn->invoices_synced_until) · faktur sampai {{ $conn->invoices_synced_until->translatedFormat('j M Y') }}@endif</p>@endif
    <form method="post" action="{{ route('core.crm.accurate.update', $company) }}">
      @csrf @method('put')
      <div class="fields">
        <label class="f"><span>API Token</span><input name="api_token" autocomplete="off" placeholder="{{ $ok ? 'Tersimpan (kosongkan bila tidak diganti)' : 'aat.…' }}"></label>
        <label class="f"><span>Signature Secret</span><input type="password" name="signature_secret" autocomplete="new-password" placeholder="{{ $ok ? 'Tersimpan' : '' }}"></label>
        <label class="f"><span>Catat pelanggan Accurate sebagai member outlet</span>
          <select name="default_outlet_id"><option value="">Tidak (tanpa outlet)</option>@foreach ($outlets->where('business_unit_id', $company->id) as $o)<option value="{{ $o->id }}" @selected($conn?->default_outlet_id == $o->id)>{{ $o->code }} {{ $o->name }}</option>@endforeach</select></label>
        <div class="f"><span>Data yang ditarik</span>
          <label class="check"><input type="hidden" name="sync_customers" value="0"><input type="checkbox" name="sync_customers" value="1" @checked($conn?->sync_customers ?? true)> Pelanggan</label>
          <label class="check"><input type="hidden" name="sync_invoices" value="0"><input type="checkbox" name="sync_invoices" value="1" @checked($conn?->sync_invoices ?? true)> Faktur penjualan ({{ AccurateSync::INITIAL_DAYS }} hari ke belakang pada sinkron pertama)</label>
        </div>
      </div>
      <p class="actions">
        <button class="btn">Simpan</button>
        @if ($ok)<label class="check" style="display:inline-flex"><input type="checkbox" name="clear" value="1"> Hapus token tersimpan</label>@endif
      </p>
    </form>
    @if ($ok)
      <p class="actions">
        <form method="post" action="{{ route('core.crm.accurate.test', $company) }}" style="display:inline">@csrf<button class="btn alt sm">Uji koneksi</button></form>
        <form method="post" action="{{ route('core.crm.accurate.sync', $company) }}" style="display:inline">@csrf<button class="btn alt sm">Sinkron sekarang</button></form>
      </p>
    @endif
  </div>
@endforeach
@endsection

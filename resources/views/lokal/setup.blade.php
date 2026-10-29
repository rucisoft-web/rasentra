@extends('lokal.layout')
@use('App\Support\Fmt')
@section('content')
<div class="grid g2">
  <form class="card" method="post" action="{{ route('lokal.setup.store') }}">
    @csrf
    <h2>1. Hubungkan ke Rasentra Central</h2>
    <p class="muted small">Admin pusat membuat token di Rasentra Core → Perangkat & token, dengan jenis <span class="mono">outlet_server</span> untuk outlet ini. Kode outlet dan identitas server diambil dari token tersebut.</p>
    <div class="fields">
      <label class="f wide"><span>Alamat Rasentra Central</span><input name="central_url" type="url" required value="{{ old('central_url', $url) }}" placeholder="https://central.indoguna.local"></label>
      <label class="f wide"><span>Token server outlet</span><input name="token" type="password" autocomplete="off" {{ $hasToken ? '' : 'required' }} placeholder="{{ $hasToken ? 'tersimpan; isi hanya untuk mengganti' : 'tempel token dari Rasentra Core' }}"></label>
    </div>
    <p><button class="btn">Uji koneksi & simpan</button></p>
    <dl class="dl">
      <dt>Status</dt><dd>@if($pairedAt)<span class="b ok">Terhubung</span> sejak {{ Fmt::dt($pairedAt) }}@elseif($url && $hasToken)<span class="b info">Diatur lewat .env</span>@else<span class="b mute">Belum terhubung</span>@endif</dd>
      <dt>Outlet</dt><dd>{{ $outletCode ?: '-' }} {{ $outlet?->name }}</dd>
      <dt>Identitas server</dt><dd class="mono">{{ $sourceId }}</dd>
    </dl>
  </form>

  <div class="card">
    <h2>2. Ambil data dari Central</h2>
    <p class="muted small">Snapshot mengisi server ini dengan data outlet, produk, lokasi, meja, petugas beserta PIN, harga, promo, resep dan cooking fee yang berlaku. Aman diulang: data yang sudah ada diperbarui, tidak digandakan. Transaksi outlet tidak disentuh.</p>
    <dl class="dl">
      @foreach ($counts as $label => $n)<dt>{{ ucfirst($label) }}</dt><dd>{{ $n }}</dd>@endforeach
      <dt>Cursor publikasi</dt><dd>{{ $cursor }}</dd>
    </dl>
    <form method="post" action="{{ route('lokal.setup.bootstrap') }}" style="margin-top:12px">@csrf
      <button class="btn" @disabled(! ($outletCode && $hasToken && $url))>Ambil snapshot</button>
    </form>
    @if ($fresh)
      <p class="muted small">Setelah snapshot, kepala outlet masuk dengan PIN di <a href="{{ route('pos.login') }}">layar login</a>. Halaman ini lalu hanya bisa dibuka kepala outlet.</p>
    @endif
  </div>
</div>

<div class="card" style="margin-top:14px">
  <h2>3. Jalankan layanan latar</h2>
  <p class="muted small">Di server outlet, jalankan <span class="mono">php artisan schedule:work</span> sebagai layanan (systemd atau Task Scheduler) agar antrean terkirim tiap 5 detik, publikasi ditarik tiap 30 detik, heartbeat tiap menit, backup pukul 02.00 dan tutup hari pukul 04.00. Perangkat kasir, tablet, handheld dan layar dapur cukup membuka alamat server ini di browser.</p>
</div>
@endsection

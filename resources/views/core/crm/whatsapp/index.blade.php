@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<div class="card">
  <h3>Webhook Meta</h3>
  <p class="small">Di Meta for Developers → aplikasi WhatsApp → Configuration, isi <b>Callback URL</b> dengan alamat di bawah dan <b>Verify token</b> sama dengan <code>WHATSAPP_VERIFY_TOKEN</code> di <code>.env</code> server Central, lalu langganan field <b>messages</b>. Isi juga <code>WHATSAPP_APP_SECRET</code> (App Secret aplikasi Meta) agar tanda tangan webhook bisa diverifikasi.</p>
  <dl class="dl">
    <dt>Callback URL</dt><dd class="mono">{{ $webhookUrl }}</dd>
    <dt>Status .env</dt><dd>@if($webhookReady)<span class="b ok">Verify token & App Secret terisi</span>@else<span class="b warn">WHATSAPP_VERIFY_TOKEN / WHATSAPP_APP_SECRET belum diisi</span>@endif</dd>
  </dl>
</div>

@foreach ($channels as $ch)
<form class="card" method="post" action="{{ route('core.crm.whatsapp.update', $ch) }}" style="margin-top:14px">
  @csrf @method('put')
  <h3>{{ $ch->name }} @if($ch->is_default)<span class="b ok">Bawaan</span>@endif <span class="muted small">{{ $ch->display_phone }}</span></h3>
  @include('core.crm.whatsapp._fields', ['ch' => $ch])
  <p class="actions"><button class="btn">Simpan</button>
    <span class="muted small">Template terakhir disinkron: {{ $ch->templates_synced_at ? Fmt::dt($ch->templates_synced_at) : 'belum' }}</span></p>
</form>
<form method="post" action="{{ route('core.crm.whatsapp.templates', $ch) }}" style="margin-top:6px">@csrf<button class="btn alt sm">Sinkron template {{ $ch->name }} dari Meta</button></form>
@endforeach

<form class="card" method="post" action="{{ route('core.crm.whatsapp.store') }}" style="margin-top:14px">
  @csrf
  <h3>Tambah nomor WhatsApp Business</h3>
  <p class="small muted">Ambil Phone number ID dan WhatsApp Business Account ID dari Meta for Developers → WhatsApp → API Setup. Gunakan token System User permanen (izin whatsapp_business_messaging dan whatsapp_business_management).</p>
  @include('core.crm.whatsapp._fields', ['ch' => null])
  <p><button class="btn">Tambah nomor</button></p>
</form>

<div class="tablewrap" style="margin-top:14px">
<table class="t">
  <thead><tr><th>Template</th><th>Nomor</th><th>Bahasa</th><th>Kategori</th><th>Header</th><th>Isi</th><th>Status</th></tr></thead>
  <tbody>
  @forelse ($templates as $t)
    <tr><td class="mono">{{ $t->name }}</td><td>{{ $t->channel->name }}</td><td>{{ $t->language }}</td><td>{{ $t->category }}</td><td>{{ $t->header_type ?? '—' }}</td>
      <td class="small" style="max-width:420px">{{ \Illuminate\Support\Str::limit($t->body, 160) }}</td>
      <td><span class="b {{ $t->status === 'APPROVED' ? 'ok' : ($t->status === 'REJECTED' ? 'err' : 'warn') }}">{{ $t->status }}</span></td></tr>
  @empty
    <tr><td colspan="7" class="muted">Belum ada template. Buat template (mis. kategori MARKETING dengan header gambar untuk iklan) di WhatsApp Manager, tunggu disetujui Meta, lalu klik Sinkron template.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
@endsection

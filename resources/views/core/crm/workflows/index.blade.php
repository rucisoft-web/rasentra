@extends('core.layout')
@use('App\Models\Crm\Workflow')
@section('content')
<p class="muted">Workflow menjalankan langkah otomatis saat pelanggan mengirim kata kunci, baru tercatat, bergabung ke komunitas, selesai belanja, berulang tahun, atau lama tidak belanja. Pesan promosi hanya dikirim ke pelanggan yang tidak opt-out; template MARKETING hanya ke pelanggan opt-in.</p>
<p><a class="btn" href="{{ route('core.crm.workflows.create') }}">Workflow baru</a></p>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Workflow</th><th>Pemicu</th><th>Langkah</th><th>Status</th><th class="n">Dijalankan</th><th class="n">Menunggu</th><th class="n">Gagal</th><th></th></tr></thead>
  <tbody>
  @forelse ($workflows as $w)
    <tr>
      <td><a href="{{ route('core.crm.workflows.show', $w) }}"><b>{{ $w->name }}</b></a></td>
      <td class="small">{{ Workflow::TRIGGERS[$w->trigger] ?? $w->trigger }}@if($w->trigger === 'keyword') <span class="mono">({{ $w->trigger_config['keywords'] ?? '' }})</span>@elseif($w->trigger === 'inactive') ({{ $w->trigger_config['days'] ?? '' }} hari)@endif</td>
      <td class="small">{{ count($w->steps ?? []) }} langkah</td>
      <td>@if($w->is_active)<span class="b ok">Aktif</span>@else<span class="b mute">Nonaktif</span>@endif</td>
      <td class="n">{{ $w->runs_count }}</td><td class="n">{{ $w->waiting_count }}</td><td class="n">{{ $w->failed_count ?: '—' }}</td>
      <td><form method="post" action="{{ route('core.crm.workflows.toggle', $w) }}">@csrf<button class="btn alt small">{{ $w->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></form></td>
    </tr>
  @empty
    <tr><td colspan="8" class="muted">Belum ada workflow. Contoh: balas otomatis kata kunci "menu", sapaan member baru, ucapan ulang tahun dengan voucher, atau ajak kembali pelanggan yang 60 hari tidak belanja.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
@endsection

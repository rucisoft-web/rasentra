@extends('core.layout')
@use('App\Models\Crm\Workflow')
@use('App\Support\Fmt')
@use('App\Domain\Crm\Phone')
@section('content')
<p><a href="{{ route('core.crm.workflows.index') }}">&larr; Semua workflow</a></p>
@php $cfg = $workflow->trigger_config ?? []; @endphp
<div class="grid g2">
  <div class="card">
    <h3>Pemicu</h3>
    <dl class="dl">
      <dt>Status</dt><dd>@if($workflow->is_active)<span class="b ok">Aktif</span>@else<span class="b mute">Nonaktif</span>@endif</dd>
      <dt>Pemicu</dt><dd>{{ Workflow::TRIGGERS[$workflow->trigger] ?? $workflow->trigger }}</dd>
      @if(!empty($cfg['keywords']))<dt>Kata kunci</dt><dd class="mono">{{ $cfg['keywords'] }}</dd>@endif
      @if(!empty($cfg['days']))<dt>Tidak belanja</dt><dd>{{ $cfg['days'] }} hari</dd>@endif
      @if(!empty($cfg['min_amount']))<dt>Belanja minimal</dt><dd>{{ Fmt::money($cfg['min_amount']) }}</dd>@endif
      @if(!empty($cfg['community_id']))<dt>Komunitas</dt><dd>{{ $lookups['communities']->firstWhere('id', $cfg['community_id'])?->name }}</dd>@endif
      @if(!empty($cfg['crm_branch_id']))<dt>Cabang</dt><dd>{{ $lookups['branches']->firstWhere('id', $cfg['crm_branch_id'])?->name }}</dd>@endif
      @if(!empty($cfg['segment_id']))<dt>Hanya segmen</dt><dd>{{ $lookups['segments']->firstWhere('id', $cfg['segment_id'])?->name }}</dd>@endif
      <dt>Jeda ulang</dt><dd>{{ $workflow->cooldown_days ? $workflow->cooldown_days.' hari per pelanggan' : 'tanpa jeda' }}</dd>
    </dl>
    <p class="actions">
      <form method="post" action="{{ route('core.crm.workflows.toggle', $workflow) }}" style="display:inline">@csrf<button class="btn">{{ $workflow->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></form>
      <a class="btn alt" href="{{ route('core.crm.workflows.edit', $workflow) }}">Ubah</a>
    </p>
  </div>
  <div class="card">
    <h3>Langkah</h3>
    @include('core.crm.workflows._steps', ['steps' => $workflow->steps ?? []] + $lookups)
    <form method="post" action="{{ route('core.crm.workflows.test', $workflow) }}" class="actions" style="margin-top:12px">
      @csrf
      <input name="phone" placeholder="Nomor WA uji coba, mis. 0812…" value="{{ old('phone') }}" style="max-width:220px">
      <button class="btn alt" onclick="return confirm('Jalankan workflow ini sekarang untuk nomor tersebut? Pesan sungguhan akan dikirim.')">Uji coba</button>
    </form>
  </div>
</div>
<div class="card" style="margin-top:14px">
  <h3>Riwayat dijalankan</h3>
  <div class="tablewrap">
  <table class="t">
    <thead><tr><th>Mulai</th><th>Pelanggan</th><th>Status</th><th>Catatan</th></tr></thead>
    <tbody>
    @forelse ($runs as $r)
      @php $map = ['running' => ['info', 'Berjalan'], 'waiting' => ['warn', 'Menunggu'], 'done' => ['ok', 'Selesai'], 'failed' => ['err', 'Gagal'], 'cancelled' => ['mute', 'Dibatalkan']]; @endphp
      <tr>
        <td class="small">{{ Fmt::dt($r->created_at) }}</td>
        <td>@if($r->customer)<a href="{{ route('core.crm.customers.show', $r->customer) }}">{{ $r->customer->name }}</a> <span class="muted small mono">{{ Phone::masked($r->customer->phone) }}</span>@endif</td>
        <td><span class="b {{ $map[$r->status][0] ?? '' }}">{{ $map[$r->status][1] ?? $r->status }}</span>@if($r->status === 'waiting' && $r->resume_at)<div class="muted small">lanjut {{ Fmt::dt($r->resume_at) }}</div>@endif</td>
        <td class="small">{{ collect($r->log ?? [])->pluck(1)->implode(' · ') }}@if($r->error)<div class="err">{{ $r->error }}</div>@endif</td>
      </tr>
    @empty
      <tr><td colspan="4" class="muted">Belum pernah dijalankan.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $runs->links('core.pager') }}
</div>
@endsection

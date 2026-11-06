@extends('core.layout')
@use('App\Support\Fmt')
@use('App\Domain\Crm\Phone')
@section('content')
<p><a href="{{ route('core.crm.campaigns.index') }}">&larr; Semua kampanye</a></p>
@php $sent = $stats['sent'] + $stats['delivered'] + $stats['read']; @endphp
<div class="grid g4">
  <div class="card kpi"><div class="label">Status</div><div class="value" style="font-size:1.2rem">@include('core.crm.campaigns._status', ['status' => $campaign->status])</div><div class="note">{{ $campaign->scheduled_at ? 'Jadwal '.Fmt::dt($campaign->scheduled_at) : 'Kirim segera' }}</div></div>
  <div class="card kpi"><div class="label">Penerima</div><div class="value">{{ number_format($audienceCount ?? $campaign->recipient_count, 0, ',', '.') }}</div><div class="note">{{ $audienceCount !== null ? 'perkiraan saat ini (opt-in)' : 'dikunci saat mulai kirim' }}</div></div>
  <div class="card kpi"><div class="label">Terkirim / sampai / dibaca</div><div class="value">{{ $sent }} / {{ $stats['delivered'] + $stats['read'] }} / {{ $stats['read'] }}</div><div class="note">{{ $stats['pending'] }} antre</div></div>
  <div class="card kpi"><div class="label">Gagal / dilewati</div><div class="value">{{ $stats['failed'] }} / {{ $stats['skipped'] }}</div><div class="note">dilewati = opt-out atau dibatalkan</div></div>
</div>
<div class="grid g2" style="margin-top:14px">
  <div class="card">
    <h3>Pratinjau</h3>
    @if ($campaign->media_url)<p class="small">Materi: <a href="{{ $campaign->media_url }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($campaign->media_url, 70) }}</a></p>@endif
    <div style="white-space:pre-wrap;background:var(--ok-bg);padding:10px 12px;border-radius:10px">{{ $preview }}</div>
    <p class="muted small">Template {{ $campaign->template->name }} ({{ $campaign->template->language }}) via {{ $campaign->channel->name }}</p>
  </div>
  <div class="card">
    <h3>Penerima</h3>
    <dl class="dl">
      <dt>Komunitas</dt><dd>{{ $communities->implode(', ') ?: '—' }}</dd>
      <dt>Segmen</dt><dd>{{ $segment?->name ?? '—' }}</dd>
      <dt>Dibuat</dt><dd>{{ Fmt::dt($campaign->created_at) }} · {{ $campaign->creator?->name }}</dd>
      @if($campaign->started_at)<dt>Mulai kirim</dt><dd>{{ Fmt::dt($campaign->started_at) }}</dd>@endif
      @if($campaign->finished_at)<dt>Selesai</dt><dd>{{ Fmt::dt($campaign->finished_at) }}</dd>@endif
    </dl>
    <p class="actions">
      @if (in_array($campaign->status, ['draft']))<form method="post" action="{{ route('core.crm.campaigns.launch', $campaign) }}" style="display:inline">@csrf<button class="btn" onclick="return confirm('Kirim iklan ini sekarang?')">Kirim / jadwalkan</button></form>@endif
      @if (in_array($campaign->status, ['draft', 'scheduled', 'sending']))<form method="post" action="{{ route('core.crm.campaigns.cancel', $campaign) }}" style="display:inline">@csrf<button class="btn alt">Batalkan</button></form>@endif
    </p>
  </div>
</div>
@if ($failures->isNotEmpty())
<div class="tablewrap" style="margin-top:14px">
<table class="t"><thead><tr><th>Gagal terkirim</th><th>No HP</th><th>Alasan</th></tr></thead>
<tbody>@foreach ($failures as $f)<tr><td>{{ $f->customer->name }}</td><td class="mono">{{ Phone::display($f->customer->phone) }}</td><td class="small">{{ $f->error }}</td></tr>@endforeach</tbody></table>
</div>
@endif
@endsection

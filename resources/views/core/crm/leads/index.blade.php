@extends('core.layout')
@use('App\Support\Fmt')
@use('App\Domain\Crm\Phone')
@use('App\Domain\Crm\AiAssistant')
@section('content')
<p class="muted">Lead diidentifikasi asisten AI dari percakapan WhatsApp: niat, skor 0-100, minat, perkiraan nilai, dan langkah berikut. Pelanggan dengan lead panas otomatis diberi tag "{{ AiAssistant::HOT_TAG }}".</p>
<div class="grid g4">
  @foreach (AiAssistant::STAGES as $k => $v)
    <a class="card kpi" href="{{ route('core.crm.leads.index', ['stage' => $k]) }}" style="text-decoration:none"><div class="label">{{ $v }}</div><div class="value">{{ $counts[$k]->n ?? 0 }}</div><div class="note">perkiraan {{ Fmt::money($counts[$k]->v ?? 0) }}</div></a>
  @endforeach
</div>
<form class="bar" method="get" style="margin-top:12px">
  <label class="f"><span>Tahap</span><select name="stage"><option value="">Semua</option>@foreach (AiAssistant::STAGES as $k => $v)<option value="{{ $k }}" @selected($stage === $k)>{{ $v }}</option>@endforeach</select></label>
  <label class="f"><span>Niat</span><select name="intent"><option value="">Semua</option>@foreach (AiAssistant::INTENTS as $k => $v)<option value="{{ $k }}" @selected($intent === $k)>{{ $v }}</option>@endforeach</select></label>
  <button class="btn alt">Saring</button>
</form>
<div class="tablewrap" style="margin-top:10px">
<table class="t">
  <thead><tr><th>Pelanggan</th><th class="n">Skor</th><th>Niat / minat</th><th>Ringkasan & langkah berikut</th><th class="n">Perkiraan</th><th>CS</th></tr></thead>
  <tbody>
  @forelse ($leads as $c)
    <tr>
      <td><a href="{{ route('core.crm.inbox.show', $c) }}"><b>{{ $c->customer->name }}</b></a><div class="muted small mono">{{ Phone::display($c->customer->phone) }}</div></td>
      <td class="n"><span @class(['b', 'ok' => $c->lead_stage === 'hot', 'warn' => $c->lead_stage === 'warm', 'mute' => $c->lead_stage === 'cold'])>{{ $c->lead_score }}</span></td>
      <td class="small">{{ AiAssistant::INTENTS[$c->lead_intent] ?? $c->lead_intent }}@if($c->lead_interest)<div class="muted">{{ $c->lead_interest }}</div>@endif</td>
      <td class="small">{{ $c->lead_summary }}@if($c->lead_next_action)<div><b>Berikutnya:</b> {{ $c->lead_next_action }}</div>@endif</td>
      <td class="n small">{{ $c->lead_value ? Fmt::money($c->lead_value) : '—' }}</td>
      <td class="small">{{ $c->assignee?->name ?? '—' }}<div class="muted">{{ Fmt::dt($c->lead_scored_at) }}</div></td>
    </tr>
  @empty
    <tr><td colspan="6" class="muted">Belum ada lead. Aktifkan asisten AI pada nomor WhatsApp, lalu lead dianalisis otomatis dari chat yang masuk.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $leads->links('core.pager') }}
@endsection

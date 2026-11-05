@extends('core.layout')
@use('App\Support\Fmt')
@use('App\Domain\Crm\Phone')
@section('content')
<p><a href="{{ route('core.crm.inbox.index') }}">&larr; Inbox</a> · <a href="{{ route('core.crm.customers.show', $c->customer) }}">Profil pelanggan</a></p>
<div class="grid g2" style="grid-template-columns: minmax(0, 2fr) minmax(0, 1fr)">
  <div class="card">
    <h3>{{ $c->customer->name }} <span class="muted small mono">{{ Phone::display($c->customer->phone) }}</span> @include('core.crm.customers._wa', ['status' => $c->customer->wa_status])</h3>
    <div style="max-height:60vh;overflow:auto;display:flex;flex-direction:column;gap:8px;padding:6px 0">
      @forelse ($messages as $m)
        <div style="align-self:{{ $m->direction === 'in' ? 'flex-start' : 'flex-end' }};max-width:75%;padding:8px 12px;border-radius:10px;background:{{ $m->direction === 'in' ? 'var(--soft)' : 'var(--ok-bg)' }}">
          @if ($m->type === 'template')<div class="muted small">Template</div>@endif
          <div style="white-space:pre-wrap">{{ $m->body }}</div>
          <div class="muted small">{{ Fmt::dt($m->created_at) }}@if($m->direction === 'out') · {{ $m->by_ai ? 'Asisten AI' : ($m->sender?->name ?? 'Sistem') }} · <span @class(['b', 'err' => $m->status === 'failed', 'ok' => $m->status === 'read', 'mute' => ! in_array($m->status, ['failed', 'read'])])>{{ $m->status }}</span>@endif</div>
          @if ($m->error)<div class="small" style="color:var(--red)">{{ $m->error }}</div>@endif
        </div>
      @empty
        <p class="muted">Belum ada pesan. Untuk memulai percakapan, kirim template.</p>
      @endforelse
    </div>
    @if ($windowOpen)
      <form method="post" action="{{ route('core.crm.inbox.reply', $c) }}" class="bar" style="margin-top:10px">
        @csrf
        <label class="f" style="flex:1"><span>Balas (jendela 24 jam terbuka sampai {{ Fmt::dt($c->last_inbound_at->copy()->addDay()) }})</span><textarea name="body" rows="3" required maxlength="4096">{{ old('body', $c->ai_suggestion) }}</textarea>@if($c->ai_suggestion)<small>Terisi saran AI ({{ number_format(($c->ai_confidence ?? 0) * 100) }}% yakin). Periksa dan ubah bila perlu sebelum dikirim.</small>@endif</label>
        <button class="btn">Kirim</button>
      </form>
    @else
      <p class="alert err small" style="margin-top:10px">Lebih dari 24 jam sejak pesan terakhir pelanggan: WhatsApp hanya mengizinkan template yang sudah disetujui.</p>
    @endif
  </div>
  <div>
    @if ($aiEnabled || $c->lead_score !== null)
    <div class="card" style="margin-bottom:14px">
      <h3>Asisten AI</h3>
      @if ($c->ai_handoff && $c->status === 'open')<p class="alert err small">Perlu CS: {{ $c->ai_handoff_reason }}</p>@endif
      @if ($c->lead_score !== null)
        <dl class="dl">
          <dt>Lead</dt><dd><span @class(['b', 'ok' => $c->lead_stage === 'hot', 'warn' => $c->lead_stage === 'warm', 'mute' => $c->lead_stage === 'cold'])>{{ \App\Domain\Crm\AiAssistant::STAGES[$c->lead_stage] ?? $c->lead_stage }} · {{ $c->lead_score }}</span></dd>
          <dt>Niat</dt><dd>{{ \App\Domain\Crm\AiAssistant::INTENTS[$c->lead_intent] ?? $c->lead_intent }}</dd>
          @if($c->lead_interest)<dt>Minat</dt><dd>{{ $c->lead_interest }}</dd>@endif
          @if($c->lead_value)<dt>Perkiraan nilai</dt><dd>{{ Fmt::money($c->lead_value) }}</dd>@endif
          @if($c->lead_summary)<dt>Ringkasan</dt><dd>{{ $c->lead_summary }}</dd>@endif
          @if($c->lead_next_action)<dt>Langkah berikut</dt><dd>{{ $c->lead_next_action }}</dd>@endif
          <dt>Dianalisis</dt><dd class="small">{{ Fmt::dt($c->lead_scored_at) }}</dd>
        </dl>
      @endif
      @if ($aiEnabled)
        <form method="post" action="{{ route('core.crm.inbox.assist', $c) }}">@csrf<button class="btn alt sm">{{ $c->ai_suggested_at ? 'Analisis ulang & saran balasan' : 'Saran balasan AI' }}</button></form>
      @endif
    </div>
    @endif
    <form class="card" method="post" action="{{ route('core.crm.inbox.update', $c) }}">
      @csrf @method('put')
      <h3>Penanganan</h3>
      <div class="fields">
        <label class="f"><span>CS</span><select name="assigned_to"><option value="">—</option>@foreach ($agents as $a)<option value="{{ $a->id }}" @selected($c->assigned_to === $a->id)>{{ $a->name }}</option>@endforeach</select></label>
        <label class="f"><span>Status</span><select name="status"><option value="open" @selected($c->status === 'open')>Terbuka</option><option value="closed" @selected($c->status === 'closed')>Selesai</option></select></label>
      </div>
      <p><button class="btn alt sm">Simpan</button></p>
    </form>
    <form class="card" method="post" action="{{ route('core.crm.inbox.template', $c) }}" style="margin-top:14px">
      @csrf
      <h3>Kirim template</h3>
      @if ($templates->isEmpty())
        <p class="muted small">Belum ada template yang disetujui untuk nomor {{ $c->channel->name }}.</p>
      @else
        <div class="fields">
          <label class="f wide"><span>Template</span><select name="template_id">@foreach ($templates as $t)<option value="{{ $t->id }}">{{ $t->name }} ({{ $t->category }}, {{ $t->body_params }} parameter)</option>@endforeach</select></label>
          @for ($i = 0; $i < max($templates->max('body_params'), 0); $i++)
            <label class="f"><span>Parameter &#123;&#123;{{ $i + 1 }}&#125;&#125;</span><input name="params[]" value="{{ $i === 0 ? $c->customer->name : '' }}"></label>
          @endfor
          <label class="f wide"><span>URL media header (bila template memakai gambar/video)</span><input name="media_url" type="url"></label>
        </div>
        <p><button class="btn alt sm">Kirim template</button></p>
      @endif
    </form>
  </div>
</div>
@endsection

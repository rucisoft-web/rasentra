@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
@php [$label, $tone] = $statuses[$doc->status] ?? [$doc->status, 'mute']; @endphp
<p><a href="{{ route('core.monitor.sap') }}">&larr; Posting SAP</a></p>
<div class="grid g2">
  <div class="card">
    <dl class="dl">
      <dt>Objek</dt><dd>{{ $doc->sap_object }} · {{ $doc->company_db }}</dd>
      <dt>Sumber</dt><dd class="mono">{{ $doc->source_type }}:{{ $doc->source_id }}</dd>
      <dt>Struk</dt><dd>{{ $order->doc_no ?? '-' }} · {{ $order ? Fmt::money($order->net_amount) : '-' }}</dd>
      <dt>Status</dt><dd><span class="b {{ $tone }}">{{ $label }}</span></dd>
      <dt>DocEntry / DocNum</dt><dd class="mono">{{ $doc->doc_entry ?? '-' }} / {{ $doc->doc_num ?? '-' }}</dd>
      <dt>Percobaan</dt><dd>{{ $doc->attempts }}</dd>
      <dt>Tanggal posting</dt><dd>{{ $doc->posting_date?->format('d M Y') ?? '-' }}</dd>
      <dt>Error terakhir</dt><dd>{{ $doc->last_error ?? '-' }}</dd>
    </dl>
    <h3>Dokumen terkait</h3>
    <div class="actions">@foreach ($related as $r)<a class="b {{ $statuses[$r->status][1] ?? 'mute' }}" href="{{ route('core.monitor.sap.show', $r) }}">{{ $r->sap_object }} · {{ $statuses[$r->status][0] ?? $r->status }}</a>@endforeach</div>
    @if (in_array($doc->status, ['needs_review', 'failed', 'partially_posted'], true))
      <form method="post" action="{{ route('core.monitor.sap.retry', $doc) }}" style="margin-top:12px">@csrf<button class="btn">Cek di SAP & posting ulang</button></form>
    @endif
  </div>
  <div class="card">
    <h3>Payload permintaan</h3>
    <pre class="json">{{ json_encode($doc->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    <h3>Respons SAP</h3>
    <pre class="json">{{ json_encode($doc->response_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '-' }}</pre>
  </div>
</div>
@endsection

@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
@php $s = $batch->summary ?? []; @endphp
<p><a href="{{ route('core.xml.index') }}">&larr; Semua impor</a></p>
<div class="grid g4">
  <div class="card kpi"><div class="label">Dokumen baru</div><div class="value">{{ $batch->document_count }}</div></div>
  <div class="card kpi"><div class="label">Duplikat dilewati</div><div class="value">{{ $batch->duplicate_count }}</div></div>
  <div class="card kpi"><div class="label">Total faktur</div><div class="value">{{ Fmt::money($s['invoice_total'] ?? 0) }}</div><div class="note">{{ $s['invoices'] ?? 0 }} faktur · {{ $s['lines'] ?? 0 }} baris</div></div>
  <div class="card kpi"><div class="label">Total pembayaran</div><div class="value">{{ Fmt::money($s['payment_total'] ?? 0) }}</div><div class="note">{{ $s['receipts'] ?? 0 }} penerimaan</div></div>
</div>
@if (! empty($s['rounding_differences']))
  <h2>Selisih pembulatan ({{ count($s['rounding_differences']) }})</h2>
  <div class="tablewrap"><table class="t"><thead><tr><th>Faktur</th><th class="n">Nilai sumber</th><th class="n">Hitung ulang</th><th class="n">Selisih</th></tr></thead>
    <tbody>@foreach (array_slice($s['rounding_differences'], 0, 50) as $d)<tr><td class="mono">{{ $d['invoice_no'] }}</td><td class="n">{{ $d['source'] }}</td><td class="n">{{ $d['calculated'] }}</td><td class="n">{{ $d['difference'] }}</td></tr>@endforeach</tbody></table></div>
@endif
@if (! empty($s['waiting_dependency']))
  <div class="alert info" style="margin-top:14px">{{ count($s['waiting_dependency']) }} penerimaan menunggu faktur MSI yang dirujuk: {{ implode(', ', array_slice($s['waiting_dependency'], 0, 10)) }}</div>
@endif
<h2>Dokumen</h2>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Nomor</th><th>Jenis</th><th>Cabang</th><th>Tanggal</th><th>Customer</th><th class="n">Total sumber</th><th>Status</th></tr></thead>
  <tbody>
  @forelse ($documents as $d)
    <tr><td class="mono">{{ $d->source_no }}</td><td>{{ $d->doc_type }}</td><td>{{ $d->branch_code }}</td><td>{{ $d->business_date?->format('d M Y') }}</td><td>{{ $d->legacy_customer_code ?? '-' }}</td><td class="n">{{ Fmt::money($d->document_total_source) }}</td><td><span class="b">{{ $d->status }}</span></td></tr>
  @empty
    <tr><td colspan="7" class="muted">Tidak ada dokumen baru di file ini.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $documents->links('core.pager') }}
@endsection

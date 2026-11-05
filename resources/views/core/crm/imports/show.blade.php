@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<p><a href="{{ route('core.crm.imports.index') }}">&larr; Semua impor</a></p>
<div class="grid g4">
  <div class="card kpi"><div class="label">Baris dibaca</div><div class="value">{{ $import->row_count }}</div><div class="note">{{ $import->outlet->code }} {{ $import->outlet->name }}</div></div>
  <div class="card kpi"><div class="label">Pelanggan baru</div><div class="value">{{ $import->created_count }}</div></div>
  <div class="card kpi"><div class="label">Digabung</div><div class="value">{{ $import->merged_count }}</div><div class="note">nomor sudah terdaftar sebelumnya</div></div>
  <div class="card kpi"><div class="label">Dilewati</div><div class="value">{{ $import->error_count }}</div><div class="note">perbaiki lalu impor ulang barisnya</div></div>
</div>
<p class="muted small">{{ $import->file_name }} · {{ Fmt::dt($import->created_at) }} · oleh {{ $import->user?->name ?? '—' }} · <a href="{{ route('core.crm.customers.index', ['outlet' => $import->outlet_id]) }}">lihat pelanggan outlet ini</a></p>
@if ($import->errors)
<div class="tablewrap">
<table class="t">
  <thead><tr><th class="n">Baris</th><th>Nilai</th><th>Masalah</th></tr></thead>
  <tbody>@foreach ($import->errors as $e)<tr><td class="n">{{ $e['line'] }}</td><td class="mono">{{ $e['value'] ?: '(kosong)' }}</td><td>{{ $e['message'] }}</td></tr>@endforeach</tbody>
</table>
</div>
@if ($import->error_count > count($import->errors))<p class="muted small">Menampilkan {{ count($import->errors) }} dari {{ $import->error_count }} baris bermasalah.</p>@endif
@endif
@endsection

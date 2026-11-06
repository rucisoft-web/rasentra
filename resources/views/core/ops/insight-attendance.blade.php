@extends('core.layout')
@section('content')
@include('core.ops._range')
<div class="tablewrap"><table class="t">
  <thead><tr><th>Outlet</th><th>Staf</th><th class="n">Hari masuk</th><th class="n">Hari terlambat</th><th class="n">Total terlambat</th><th class="n">Jam kerja</th></tr></thead>
  <tbody>@forelse ($rows as $r)
    <tr><td>{{ $outlets[$r->outlet_id] ?? '-' }}</td><td>{{ $r->user_name }}</td><td class="n">{{ $r->days }}</td>
      <td class="n">@if ($r->late_days)<span class="b warn">{{ $r->late_days }}</span>@else 0 @endif</td><td class="n">{{ $r->late_minutes }} mnt</td>
      <td class="n">{{ intdiv((int) $r->worked_minutes, 60) }}j {{ (int) $r->worked_minutes % 60 }}m</td></tr>
  @empty <tr><td colspan="6" class="muted">Belum ada data absensi.</td></tr>
  @endforelse</tbody>
</table></div>
@endsection

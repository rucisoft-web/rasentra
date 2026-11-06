@extends('lokal.layout')
@use('App\Support\Fmt')
@section('content')
<p class="muted">Template shift diatur kantor pusat sehingga jam kerja sama di semua outlet. Kepala outlet menyusun roster minggu ini; staf absen dengan PIN di <a href="{{ route('attendance') }}">layar absen</a>.</p>
<div style="display:flex;gap:8px;margin-bottom:10px">
  <a class="btn alt sm" href="{{ route('lokal.staff', ['week' => $monday->copy()->subWeek()->toDateString()]) }}">← Minggu lalu</a>
  <b style="align-self:center">{{ $monday->translatedFormat('d M') }} – {{ $days->last()->translatedFormat('d M Y') }}</b>
  <a class="btn alt sm" href="{{ route('lokal.staff', ['week' => $monday->copy()->addWeek()->toDateString()]) }}">Minggu depan →</a>
</div>
<form method="post" action="{{ route('lokal.staff.roster') }}">@csrf
<input type="hidden" name="week" value="{{ $monday->toDateString() }}">
<div class="tablewrap"><table class="t">
  <thead><tr><th>Staf</th>@foreach ($days as $d)<th>{{ $d->translatedFormat('D d/m') }}</th>@endforeach</tr></thead>
  <tbody>@foreach ($staff as $s)
    <tr><td><b>{{ $s->name }}</b><br><span class="small muted">{{ str_replace('_', ' ', $s->role) }}</span></td>
    @foreach ($days as $d)
      @php $cur = $roster[$s->id.'@'.$d->toDateString()] ?? null; @endphp
      <td><select name="roster[{{ $s->id }}][{{ $d->toDateString() }}]"><option value="">Libur</option>
        @foreach ($templates as $t)<option value="{{ $t->id }}" @selected($cur == $t->id)>{{ $t->name }}</option>@endforeach</select></td>
    @endforeach</tr>
  @endforeach</tbody>
</table></div>
<p><button class="btn">Simpan roster</button></p>
</form>

<h3 style="margin-top:22px">Kehadiran hari ini</h3>
<div class="tablewrap"><table class="t">
  <thead><tr><th>Staf</th><th>Shift</th><th>Masuk</th><th>Pulang</th><th class="n">Terlambat</th><th class="n">Durasi</th></tr></thead>
  <tbody>@forelse ($attendance as $a)
    <tr><td>{{ $a->user_name }}</td><td>{{ $a->shift_code ?? 'Di luar roster' }}</td>
      <td>{{ $a->clock_in_at->setTimezone($timezone)->format('H:i') }}</td><td>{{ $a->clock_out_at?->setTimezone($timezone)->format('H:i') ?? '-' }}</td>
      <td class="n">@if ($a->late_minutes)<span class="b warn">{{ $a->late_minutes }} mnt</span>@else - @endif</td>
      <td class="n">{{ $a->clock_out_at ? intdiv($a->worked_minutes, 60).'j '.($a->worked_minutes % 60).'m' : 'masih bekerja' }}</td></tr>
  @empty <tr><td colspan="6" class="muted">Belum ada yang absen hari ini.</td></tr>
  @endforelse</tbody>
</table></div>
@endsection

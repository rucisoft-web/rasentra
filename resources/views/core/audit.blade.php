@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<form class="bar" method="get">
  <label class="f"><span>Aksi</span>
    <select name="action"><option value="">Semua</option>@foreach ($actions as $a)<option value="{{ $a }}" @selected(request('action') === $a)>{{ $a }}</option>@endforeach</select>
  </label>
  <label class="f"><span>Pelaku</span>
    <select name="actor"><option value="">Semua</option>@foreach ($actors as $u)<option value="{{ $u->id }}" @selected((int) request('actor') === $u->id)>{{ $u->name }}</option>@endforeach</select>
  </label>
  <label class="f"><span>Outlet</span>
    <select name="outlet"><option value="">Semua</option><option value="hq" @selected(request('outlet') === 'hq')>Kantor pusat</option>
      @foreach ($outlets as $o)<option value="{{ $o->id }}" @selected(request('outlet') == $o->id)>{{ $o->code }} {{ $o->name }}</option>@endforeach</select>
  </label>
  <label class="f"><span>Dari</span><input type="date" name="from" value="{{ request('from') }}"></label>
  <label class="f"><span>Sampai</span><input type="date" name="to" value="{{ request('to') }}"></label>
  <button class="btn">Saring</button>
</form>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Waktu</th><th>Outlet</th><th>Aksi</th><th>Objek</th><th>Pelaku</th><th>Penyetuju</th><th>Alasan</th><th class="n">Nilai</th><th>Detail</th></tr></thead>
  <tbody>
  @forelse ($events as $e)
    <tr>
      <td class="small" style="white-space:nowrap">{{ Fmt::dt($e->created_at) }}</td>
      <td>{{ $e->outlet_id ? ($outletCodes[$e->outlet_id] ?? '-') : 'Pusat' }}</td>
      <td><span class="b {{ str_contains($e->action, 'override') || str_contains($e->action, 'void') ? 'warn' : 'info' }}">{{ $e->action }}</span></td>
      <td class="mono small">{{ $e->subject_type }}:{{ \Illuminate\Support\Str::limit($e->subject_id, 14) }}</td>
      <td>{{ $users[$e->actor_id] ?? '-' }}</td>
      <td>{{ $users[$e->approver_id] ?? '-' }}</td>
      <td class="small">{{ $e->reason ?? '-' }}</td>
      <td class="n">{{ $e->amount !== null ? Fmt::money($e->amount) : '-' }}</td>
      <td class="small mono">
        @if ($e->before || $e->after)
          <details><summary>lihat</summary>
            @if($e->before)<div><b>Sebelum:</b> {{ \Illuminate\Support\Str::limit(json_encode($e->before, JSON_UNESCAPED_UNICODE), 300) }}</div>@endif
            @if($e->after)<div><b>Sesudah:</b> {{ \Illuminate\Support\Str::limit(json_encode($e->after, JSON_UNESCAPED_UNICODE), 300) }}</div>@endif
          </details>
        @endif
      </td>
    </tr>
  @empty
    <tr><td colspan="9" class="muted">Tidak ada catatan audit untuk saringan ini.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $events->links('core.pager') }}
@endsection

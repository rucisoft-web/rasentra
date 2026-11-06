@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<p><a class="btn" href="{{ route('core.crm.campaigns.create') }}">Kampanye / iklan baru</a></p>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Kampanye</th><th>Template</th><th>Status</th><th>Jadwal</th><th class="n">Penerima</th><th>Dibuat</th></tr></thead>
  <tbody>
  @forelse ($campaigns as $c)
    <tr>
      <td><a href="{{ route('core.crm.campaigns.show', $c) }}"><b>{{ $c->name }}</b></a></td><td class="mono small">{{ $c->template?->name }}</td>
      <td>@include('core.crm.campaigns._status', ['status' => $c->status])</td>
      <td>{{ $c->scheduled_at ? Fmt::dt($c->scheduled_at) : 'Segera' }}</td><td class="n">{{ $c->recipient_count ?: '—' }}</td>
      <td class="small">{{ Fmt::dt($c->created_at) }} · {{ $c->creator?->name }}</td>
    </tr>
  @empty
    <tr><td colspan="6" class="muted">Belum ada kampanye.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $campaigns->links('core.pager') }}
@endsection

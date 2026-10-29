@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<div class="tabs">
  @foreach (['open' => 'Terbuka', 'resolved' => 'Selesai', 'all' => 'Semua'] as $k => $v)
    <a href="{{ route('core.monitor.exceptions', ['status' => $k]) }}" @class(['on' => $status === $k])>{{ $v }}</a>
  @endforeach
</div>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Waktu</th><th>Jenis</th><th>Sumber</th><th>Pesan</th><th>Status</th><th></th></tr></thead>
  <tbody>
  @forelse ($items as $x)
    @php $o = $x->source_type === 'order' ? ($orders[$x->source_id] ?? null) : null; @endphp
    <tr>
      <td>{{ Fmt::dt($x->created_at) }}</td>
      <td><span class="b warn">{{ str_replace('_', ' ', $x->kind) }}</span></td>
      <td class="small">@if($o){{ $o->code }} · <span class="mono">{{ $o->doc_no }}</span>@else<span class="mono">{{ $x->source_type }}:{{ \Illuminate\Support\Str::limit($x->source_id, 18) }}</span>@endif</td>
      <td>{{ $x->message }}@if($x->kind === 'missing_mapping')<div class="small"><a href="{{ route('core.sap-mappings.index') }}">Lengkapi mapping SAP</a>, lalu posting ulang.</div>@endif</td>
      <td>@if($x->status === 'open')<span class="b err">Terbuka</span>@else<span class="b ok">Selesai</span>@endif</td>
      <td>
        @if ($x->status === 'open')
          <form class="actions" method="post" action="{{ route('core.monitor.exceptions.resolve', $x) }}">@csrf<input name="note" placeholder="Catatan penyelesaian" required><button class="btn alt sm">Selesai</button></form>
        @endif
      </td>
    </tr>
  @empty
    <tr><td colspan="6" class="muted">Tidak ada masalah integrasi.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $items->links('core.pager') }}
@endsection

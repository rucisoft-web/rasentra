@extends('core.layout')
@use('App\Support\Fmt')
@use('App\Domain\Crm\Phone')
@section('content')
@if (! $channels)<div class="alert err">Belum ada nomor WhatsApp. Tambahkan di <a href="{{ route('core.crm.whatsapp.index') }}">Nomor & template</a>.</div>@endif
<div class="tabs">
  @foreach (['open' => 'Terbuka', 'mine' => 'Ditugaskan ke saya', 'unread' => 'Belum dibaca', 'closed' => 'Selesai', 'all' => 'Semua'] as $k => $v)
    <a href="{{ route('core.crm.inbox.index', ['filter' => $k]) }}" @class(['on' => $filter === $k])>{{ $v }}</a>
  @endforeach
</div>
<form class="bar" method="get"><input type="hidden" name="filter" value="{{ $filter }}"><label class="f"><span>Cari</span><input name="q" value="{{ request('q') }}" placeholder="Nama atau nomor"></label><button class="btn alt">Cari</button></form>
<div class="tablewrap" style="margin-top:10px">
<table class="t">
  <thead><tr><th>Pelanggan</th><th>Pesan terakhir</th><th>Nomor</th><th>CS</th><th>Waktu</th></tr></thead>
  <tbody>
  @forelse ($conversations as $c)
    <tr>
      <td><a href="{{ route('core.crm.inbox.show', $c) }}"><b>{{ $c->customer->name }}</b></a> @if($c->unread_count)<span class="b err">{{ $c->unread_count }}</span>@endif<div class="muted small mono">{{ Phone::display($c->customer->phone) }}</div></td>
      <td class="small">{{ \Illuminate\Support\Str::limit($c->last_preview, 90) }}</td>
      <td class="small">{{ $c->channel->name }}</td>
      <td class="small">{{ $c->assignee?->name ?? '—' }}</td>
      <td class="small">{{ $c->last_message_at ? Fmt::dt($c->last_message_at) : '—' }}</td>
    </tr>
  @empty
    <tr><td colspan="5" class="muted">Belum ada percakapan.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $conversations->links('core.pager') }}
@endsection

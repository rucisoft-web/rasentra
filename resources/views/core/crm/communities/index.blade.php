@extends('core.layout')
@section('content')
<div class="card">
  <p class="small" style="margin:0">Komunitas mengelompokkan pelanggan per outlet atau minat (mis. "Pecinta Steak Solo", "Reseller Makassar"). Anggota bisa ditambahkan dari segmen atau cabang, atau bergabung sendiri dengan mengirim <b>kata kunci</b> komunitas ke nomor WhatsApp, yang sekaligus dicatat sebagai opt-in. Iklan ke komunitas dikirim dari tombol <b>Kirim iklan</b>.</p>
</div>
<div class="tablewrap" style="margin-top:14px">
<table class="t">
  <thead><tr><th>Komunitas</th><th>Cabang</th><th>Kata kunci gabung</th><th class="n">Anggota</th><th class="n">Opt-in WA</th><th></th></tr></thead>
  <tbody>
  @forelse ($communities as $c)
    <tr>
      <td><a href="{{ route('core.crm.communities.show', $c) }}"><b>{{ $c->name }}</b></a>@if($c->description)<div class="muted small">{{ $c->description }}</div>@endif</td>
      <td>{{ $c->branch?->code ?? 'Semua' }}</td><td class="mono">{{ $c->join_keyword ?? '—' }}</td>
      <td class="n">{{ number_format($c->members_count, 0, ',', '.') }}</td><td class="n">{{ number_format($c->opted_in_count, 0, ',', '.') }}</td>
      <td class="actions"><a class="btn sm" href="{{ route('core.crm.campaigns.create', ['community' => $c->id]) }}">Kirim iklan</a></td>
    </tr>
  @empty
    <tr><td colspan="6" class="muted">Belum ada komunitas.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
<form class="card" method="post" action="{{ route('core.crm.communities.store') }}" style="margin-top:14px">
  @csrf
  <h3>Komunitas baru</h3>
  @include('core.crm.communities._fields', ['community' => null])
  <p><button class="btn">Buat komunitas</button></p>
</form>
@endsection

@extends('core.layout')
@use('App\Domain\Crm\SegmentQuery')
@section('content')
<p><a class="btn" href="{{ route('core.crm.segments.create') }}">Segmen baru</a></p>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Segmen</th><th>Aturan</th><th class="n">Pelanggan</th><th></th></tr></thead>
  <tbody>
  @forelse ($segments as $row)
    <tr>
      <td><b>{{ $row['segment']->name }}</b>@if($row['segment']->description)<div class="muted small">{{ $row['segment']->description }}</div>@endif</td>
      <td class="small">{{ SegmentQuery::describe($row['segment']->rules, $outlets, $tags) }}</td>
      <td class="n">{{ number_format($row['count'], 0, ',', '.') }}</td>
      <td class="actions">
        <a class="btn alt sm" href="{{ route('core.crm.customers.index', ['segment' => $row['segment']->id]) }}">Lihat</a>
        <a class="btn alt sm" href="{{ route('core.crm.segments.edit', $row['segment']) }}">Ubah</a>
      </td>
    </tr>
  @empty
    <tr><td colspan="4" class="muted">Belum ada segmen. Contoh: "Member Solo belum belanja 60 hari", "Ulang tahun bulan ini", "Belanja ≥ Rp5 juta".</td></tr>
  @endforelse
  </tbody>
</table>
</div>
<p class="muted small">Isi segmen dihitung ulang setiap kali dibuka, jadi selalu mengikuti data belanja terbaru.</p>
@endsection

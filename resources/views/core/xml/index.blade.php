@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<form class="card bar" method="post" action="{{ route('core.xml.store') }}" enctype="multipart/form-data">
  @csrf
  <label class="f"><span>Jenis</span><select name="kind"><option value="MSI">MSI (faktur penjualan)</option><option value="MSR">MSR (penerimaan pembayaran)</option></select></label>
  <label class="f"><span>File NMEXML (maks. {{ $maxMb }} MB)</span><input type="file" name="file" accept=".xml,text/xml" required></label>
  <button class="btn">Impor</button>
  <span class="muted small">File asli disimpan utuh beserta SHA-256. Dokumen yang sama tidak dibuat dua kali walau nama filenya berbeda.</span>
</form>
<div class="tablewrap" style="margin-top:14px">
<table class="t">
  <thead><tr><th>Waktu</th><th>Jenis</th><th>File</th><th class="n">Dokumen baru</th><th class="n">Duplikat</th><th class="n">Error</th><th>Status</th><th></th></tr></thead>
  <tbody>
  @forelse ($batches as $b)
    <tr>
      <td>{{ Fmt::dt($b->created_at) }}</td><td>{{ $b->kind }}</td><td>{{ $b->file_name }}<div class="muted small mono">{{ substr($b->sha256, 0, 16) }}…</div></td>
      <td class="n">{{ $b->document_count }}</td><td class="n">{{ $b->duplicate_count }}</td><td class="n">{{ $b->error_count }}</td>
      <td><span class="b">{{ $b->status }}</span></td><td><a class="btn alt sm" href="{{ route('core.xml.show', $b) }}">Ringkasan</a></td>
    </tr>
  @empty
    <tr><td colspan="8" class="muted">Belum ada file yang diimpor.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $batches->links('core.pager') }}
@endsection

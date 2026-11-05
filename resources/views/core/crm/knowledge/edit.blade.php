@extends('core.layout')
@section('content')
<p><a href="{{ route('core.crm.knowledge.index') }}">&larr; Knowledge base</a></p>
<form class="card" method="post" action="{{ route('core.crm.knowledge.update', $article) }}" style="max-width:900px">
  @csrf @method('put')
  @include('core.crm.knowledge._fields', ['a' => $article])
  <p class="actions"><button class="btn">Simpan</button></p>
</form>
<form method="post" action="{{ route('core.crm.knowledge.destroy', $article) }}" onsubmit="return confirm('Hapus artikel ini?')" style="margin-top:10px">@csrf @method('delete')<button class="btn alt sm">Hapus artikel</button></form>
@endsection

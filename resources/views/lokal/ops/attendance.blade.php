@extends('lokal.layout')
@section('content')
@if (! $outlet)
  <div class="card">Server ini belum terpasang ke outlet.</div>
@else
<p class="muted">Sentuh nama Anda, ketik PIN, lalu tekan Absen. Absen pertama hari ini tercatat sebagai masuk, berikutnya sebagai pulang.</p>
<form method="post" action="{{ route('attendance.clock') }}" class="card" style="max-width:520px">@csrf
  <label class="f"><span>Nama</span><select name="user_id" required>@foreach ($staff as $s)<option value="{{ $s->id }}" @selected(old('user_id') == $s->id)>{{ $s->name }} · {{ str_replace('_', ' ', $s->role) }}</option>@endforeach</select></label>
  <label class="f" style="margin-top:10px"><span>PIN</span><input name="pin" type="password" inputmode="numeric" pattern="[0-9]*" required autocomplete="off"></label>
  <p><button class="btn">Absen</button></p>
</form>
@endif
@endsection

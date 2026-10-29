{{-- Filter rentang tanggal bisnis dan outlet, dipakai semua laporan. --}}
<form class="bar" method="get">
  <label class="f"><span>Dari</span><input type="date" name="from" value="{{ $r->from()->toDateString() }}"></label>
  <label class="f"><span>Sampai</span><input type="date" name="to" value="{{ $r->to()->toDateString() }}"></label>
  <label class="f"><span>Outlet</span>
    <select name="outlet">
      @unless ($required ?? false)<option value="">Semua outlet</option>@endunless
      @foreach ($outlets as $o)<option value="{{ $o->id }}" @selected((int) ($selected ?? request('outlet')) === $o->id)>{{ $o->code }} {{ $o->name }}</option>@endforeach
    </select>
  </label>
  <button class="btn">Tampilkan</button>
  @isset($extra){!! $extra !!}@endisset
</form>

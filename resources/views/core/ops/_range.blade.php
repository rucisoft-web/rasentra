<form method="get" style="display:flex;gap:8px;margin-bottom:14px;align-items:end;flex-wrap:wrap">
  <label class="f"><span>Dari</span><input type="date" name="from" value="{{ $from }}"></label>
  <label class="f"><span>Sampai</span><input type="date" name="to" value="{{ $to }}"></label>
  @isset($outlets)<label class="f"><span>Outlet</span><select name="outlet"><option value="">Semua</option>@foreach ($outlets as $id => $code)<option value="{{ $id }}" @selected(request('outlet') == $id)>{{ $code }}</option>@endforeach</select></label>@endisset
  <button class="btn alt">Tampilkan</button>
</form>

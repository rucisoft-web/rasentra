<label class="f"><span>Nama</span><input name="name" value="{{ $t->name ?? '' }}" required style="width:140px"></label>
  <label class="f"><span>Mulai</span><input name="starts_at" type="time" value="{{ isset($t) ? substr($t->starts_at, 0, 5) : '' }}" required></label>
  <label class="f"><span>Selesai</span><input name="ends_at" type="time" value="{{ isset($t) ? substr($t->ends_at, 0, 5) : '' }}" required></label>
  <label class="f"><span>Istirahat (mnt)</span><input name="break_minutes" type="number" value="{{ $t->break_minutes ?? 60 }}" style="width:80px"></label>
  <label class="f"><span>Aktif</span><input type="checkbox" name="is_active" value="1" @checked($t->is_active ?? true)></label>
  <span class="small">Outlet: @foreach ($outlets as $o)<label style="margin-right:6px"><input type="checkbox" name="outlet_ids[]" value="{{ $o->id }}" @checked(in_array($o->id, $t->outlet_ids ?? [], false))>{{ $o->code }}</label>@endforeach</span>

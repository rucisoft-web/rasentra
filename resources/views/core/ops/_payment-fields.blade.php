<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:8px">
  <label class="f"><span>Nama</span><input name="name" value="{{ $m->name ?? '' }}" required></label>
  <label class="f"><span>Jenis</span><select name="type">@foreach ($types as $k => $t)<option value="{{ $k }}" @selected(($m->type ?? '') === $k)>{{ $t }}</option>@endforeach</select></label>
  <label class="f"><span>Akun SAP</span><input name="sap_account" value="{{ $m->sap_account ?? '' }}" class="mono" style="width:120px"></label>
  <label class="f"><span>Urutan</span><input name="sort" type="number" value="{{ $m->sort ?? 0 }}" style="width:70px"></label>
  <label class="f"><span>Wajib no. referensi</span><input type="checkbox" name="requires_reference" value="1" @checked($m->requires_reference ?? false)></label>
  <label class="f"><span>Aktif</span><input type="checkbox" name="is_active" value="1" @checked($m->is_active ?? true)></label>
</div>
<div class="small" style="margin-bottom:8px">Outlet:
  @foreach ($outlets as $o)<label style="margin-right:10px"><input type="checkbox" name="outlet_ids[]" value="{{ $o->id }}" @checked(in_array($o->id, $m->outlet_ids ?? [], false))> {{ $o->code }}</label>@endforeach
  <span class="muted">(kosong = semua)</span></div>

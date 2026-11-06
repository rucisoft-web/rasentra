<label class="f"><span>Nama</span><input name="name" value="{{ $t->name ?? '' }}" required style="width:120px"></label>
<label class="f"><span>Belanja minimal (Rp)</span><input name="min_spend" type="number" min="0" step="1000" value="{{ isset($t) ? (int) $t->min_spend : 0 }}" required></label>
<label class="f"><span>Pengali poin</span><input name="point_multiplier" type="number" min="0" step="0.05" value="{{ $t->point_multiplier ?? 1 }}" required style="width:80px"></label>
<label class="f"><span>Benefit</span><input name="benefits" value="{{ $t->benefits ?? '' }}" style="width:260px"></label>
<label class="f"><span>Aktif</span><input type="checkbox" name="is_active" value="1" @checked($t->is_active ?? true)></label>

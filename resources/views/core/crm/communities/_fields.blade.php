<div class="fields">
  <label class="f"><span>Nama</span><input name="name" value="{{ old('name', $community?->name) }}" required maxlength="80"></label>
  <label class="f"><span>Keterangan</span><input name="description" value="{{ old('description', $community?->description) }}" maxlength="255"></label>
  <label class="f"><span>Cabang</span><select name="crm_branch_id"><option value="">Semua cabang</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(old('crm_branch_id', $community?->crm_branch_id) == $b->id)>{{ $b->code }} {{ $b->name }}</option>@endforeach</select></label>
  <label class="f"><span>Kata kunci gabung</span><input name="join_keyword" value="{{ old('join_keyword', $community?->join_keyword) }}" maxlength="30" placeholder="mis. STEAKSOLO"><small>Pelanggan yang mengirim kata ini ke WhatsApp otomatis menjadi anggota dan opt-in.</small></label>
</div>

<div class="fields">
  <label class="f"><span>Judul</span><input name="title" value="{{ old('title', $a?->title) }}" required maxlength="160" placeholder="Jam buka outlet Solo"></label>
  <label class="f"><span>Kata kunci tambahan</span><input name="keywords" value="{{ old('keywords', $a?->keywords) }}" maxlength="300" placeholder="buka, tutup, jam operasional, libur"></label>
  <label class="f"><span>Berlaku untuk</span><select name="crm_branch_id"><option value="">Semua cabang</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected((int) old('crm_branch_id', $a?->crm_branch_id) === $b->id)>{{ $b->name }}</option>@endforeach</select><small>Artikel cabang dipakai untuk chat ke nomor WhatsApp cabang tersebut.</small></label>
  <label class="f wide"><span>Isi</span><textarea name="body" rows="6" required maxlength="8000" placeholder="Tulis jawaban resmi apa adanya: harga, jam, syarat, cara pesan.">{{ old('body', $a?->body) }}</textarea></label>
  <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $a?->is_active ?? true))> Aktif dipakai AI</label>
</div>

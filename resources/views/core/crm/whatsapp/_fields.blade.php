<div class="fields">
  <label class="f"><span>Nama</span><input name="name" value="{{ $ch?->name }}" required maxlength="80" placeholder="WA Indoguna Pusat"></label>
  <label class="f"><span>Nomor tampil</span><input name="display_phone" value="{{ $ch?->display_phone }}" maxlength="30" placeholder="0811-…"></label>
  <label class="f"><span>Phone number ID</span><input name="phone_number_id" value="{{ $ch?->phone_number_id }}" required inputmode="numeric"></label>
  <label class="f"><span>WhatsApp Business Account ID</span><input name="waba_id" value="{{ $ch?->waba_id }}" required inputmode="numeric"></label>
  <label class="f"><span>Access token</span><input type="password" name="access_token" autocomplete="new-password" @unless($ch) required @endunless placeholder="{{ $ch ? 'Tersimpan (kosongkan bila tidak diganti)' : 'EAAG…' }}"></label>
  <label class="f"><span>Milik cabang</span><select name="crm_branch_id"><option value="">Pusat</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected($ch?->crm_branch_id == $b->id)>{{ $b->code }} {{ $b->name }}</option>@endforeach</select><small>Pelanggan baru yang chat ke nomor ini dicatat sebagai member cabang tersebut.</small></label>
  <label class="f"><span>Batas kirim per menit</span><input type="number" name="send_per_minute" min="1" max="1000" value="{{ $ch?->send_per_minute ?? 60 }}"><small>Untuk kampanye; sesuaikan dengan tier pesan nomor di Meta.</small></label>
  <label class="f"><span>Asisten AI</span><select name="ai_mode">@foreach (\App\Domain\Crm\AiAssistant::MODES as $k => $v)<option value="{{ $k }}" @selected(($ch?->ai_mode ?? 'off') === $k)>{{ $v }}</option>@endforeach</select><small>Jawaban AI hanya dari Knowledge base AI. Mode otomatis membalas bila AI yakin; selain itu diserahkan ke CS.</small></label>
  <label class="check"><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" @checked($ch?->is_default)> Nomor bawaan untuk chat baru</label>
</div>

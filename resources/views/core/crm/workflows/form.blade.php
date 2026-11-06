@extends('core.layout')
@use('App\Models\Crm\Workflow')
@section('content')
<p><a href="{{ $workflow->exists ? route('core.crm.workflows.show', $workflow) : route('core.crm.workflows.index') }}">&larr; Kembali</a></p>
@php
  $cfg = $workflow->trigger_config ?? [];
  $c = fn ($k) => old($k, $cfg[$k] ?? null);
  $steps = old('steps', $workflow->steps ?? []);
@endphp
<style>#wf .f{min-width:0}#wf select,#wf input{width:100%;min-width:0;box-sizing:border-box}</style>
<form class="card" method="post" id="wf" action="{{ $workflow->exists ? route('core.crm.workflows.update', $workflow) : route('core.crm.workflows.store') }}" style="max-width:1040px">
  @csrf @if($workflow->exists) @method('put') @endif
  <h3>Pemicu</h3>
  <div class="fields">
    <label class="f"><span>Nama workflow</span><input name="name" value="{{ old('name', $workflow->name) }}" required maxlength="120" placeholder="Balas otomatis kata kunci MENU"></label>
    <label class="f"><span>Pemicu</span>
      <select name="trigger" id="wf-trigger">@foreach (Workflow::TRIGGERS as $k => $v)<option value="{{ $k }}" @selected(old('trigger', $workflow->trigger) === $k)>{{ $v }}</option>@endforeach</select>
    </label>
    <label class="f" data-for="keyword"><span>Kata kunci (pisahkan dengan koma)</span><input name="keywords" value="{{ $c('keywords') }}" placeholder="menu, harga, reservasi"><small>Cocok bila pesan pelanggan mengandung salah satu kata. STOP/START dan kata kunci komunitas diproses lebih dulu.</small></label>
    <label class="f" data-for="inactive"><span>Tidak belanja selama (hari)</span><input type="number" min="1" max="730" name="days" value="{{ $c('days') ?? 60 }}"><small>Dipicu tepat pada hari ke-N sejak belanja terakhir, sekali sehari mulai pukul 09.00.</small></label>
    <label class="f" data-for="purchase"><span>Belanja minimal (Rp)</span><input type="number" min="0" step="1000" name="min_amount" value="{{ $c('min_amount') }}"><small>Dari POS Rasentra atau faktur Accurate (Rene POS/Raptor).</small></label>
    <label class="f" data-for="joined_community"><span>Komunitas</span><select name="community_id"><option value="">Semua komunitas</option>@foreach ($communities as $o)<option value="{{ $o->id }}" @selected((int) $c('community_id') === $o->id)>{{ $o->name }}</option>@endforeach</select><small>Dipicu saat pelanggan bergabung lewat kata kunci WhatsApp.</small></label>
    <label class="f" data-for="new_customer"><span>Cabang</span><select name="crm_branch_id"><option value="">Semua cabang</option>@foreach ($branches as $o)<option value="{{ $o->id }}" @selected((int) $c('crm_branch_id') === $o->id)>{{ $o->name }}</option>@endforeach</select><small>Pelanggan baru dari chat WhatsApp, transaksi POS, atau input manual. Impor file dan tarikan Accurate tidak memicu.</small></label>
    <label class="f"><span>Hanya untuk segmen</span><select name="segment_id"><option value="">Semua pelanggan</option>@foreach ($segments as $o)<option value="{{ $o->id }}" @selected((int) $c('segment_id') === $o->id)>{{ $o->name }}</option>@endforeach</select></label>
    <label class="f"><span>Jeda ulang per pelanggan (hari)</span><input type="number" min="0" name="cooldown_days" value="{{ old('cooldown_days', $workflow->cooldown_days) }}"><small>0 = boleh dipicu lagi kapan saja setelah run sebelumnya selesai.</small></label>
  </div>

  <h3 style="margin-top:18px">Langkah</h3>
  <p class="muted small">Langkah dijalankan berurutan. "Kirim pesan teks" hanya terkirim bila pelanggan chat dalam 24 jam terakhir (aturan WhatsApp); di luar itu gunakan template. Parameter template dipisah tanda | dan boleh memakai {name} atau {first_name}.</p>
  @for ($i = 0; $i < $maxSteps; $i++)
    @php $s = $steps[$i] ?? []; $t = $s['type'] ?? ''; @endphp
    <div class="fields wf-step" style="border-top:1px solid var(--line);padding-top:8px">
      <label class="f"><span>Langkah {{ $i + 1 }}</span>
        <select name="steps[{{ $i }}][type]" class="wf-type"><option value="">—</option>@foreach (Workflow::STEP_TYPES as $k => $v)<option value="{{ $k }}" @selected($t === $k)>{{ $v }}</option>@endforeach</select>
        @error("steps.$i.type")<small class="err">{{ $message }}</small>@enderror
      </label>
      <label class="f" data-step="send_template"><span>Template</span><select name="steps[{{ $i }}][template_id]"><option value="">—</option>@foreach ($templates as $o)<option value="{{ $o->id }}" @selected((int) ($s['template_id'] ?? 0) === $o->id)>{{ $o->name }} · {{ $o->category }}</option>@endforeach</select>@if($templates->isEmpty())<small>Belum ada template disetujui; sinkron di <a href="{{ route('core.crm.whatsapp.index') }}">Nomor & template</a>.</small>@endif</label>
      <label class="f" data-step="send_template"><span>Parameter</span><input name="steps[{{ $i }}][params]" value="{{ is_array($s['params'] ?? null) ? implode(' | ', $s['params']) : ($s['params'] ?? '') }}" placeholder="{first_name} | ULTAH20"></label>
      <label class="f" data-step="send_template"><span>URL materi (header gambar/video)</span><input type="url" name="steps[{{ $i }}][media_url]" value="{{ $s['media_url'] ?? '' }}">@error("steps.$i.media_url")<small class="err">{{ $message }}</small>@enderror</label>
      <label class="f wide" data-step="send_text"><span>Pesan</span><textarea name="steps[{{ $i }}][body]" rows="2" maxlength="1000">{{ $s['body'] ?? '' }}</textarea></label>
      <label class="f" data-step="wait"><span>Tunggu (jam)</span><input type="number" min="1" max="2160" name="steps[{{ $i }}][hours]" value="{{ $s['hours'] ?? 24 }}"><small>24 = 1 hari, 72 = 3 hari.</small></label>
      <label class="f" data-step="add_tag"><span>Tag</span><select name="steps[{{ $i }}][tag_id]"><option value="">—</option>@foreach ($tags as $o)<option value="{{ $o->id }}" @selected((int) ($s['tag_id'] ?? 0) === $o->id)>{{ $o->name }}</option>@endforeach</select></label>
      <label class="f" data-step="join_community"><span>Komunitas</span><select name="steps[{{ $i }}][community_id]"><option value="">—</option>@foreach ($communities as $o)<option value="{{ $o->id }}" @selected((int) ($s['community_id'] ?? 0) === $o->id)>{{ $o->name }}</option>@endforeach</select></label>
      <label class="f" data-step="assign"><span>CS</span><select name="steps[{{ $i }}][user_id]"><option value="">Siapa saja</option>@foreach ($agents as $o)<option value="{{ $o->id }}" @selected((int) ($s['user_id'] ?? 0) === $o->id)>{{ $o->name }}</option>@endforeach</select></label>
    </div>
  @endfor
  <p class="actions"><button class="btn">{{ $workflow->exists ? 'Simpan perubahan' : 'Simpan workflow' }}</button></p>
</form>
<script>
(function () {
  var form = document.getElementById('wf');
  var trigger = document.getElementById('wf-trigger');
  function sync() {
    form.querySelectorAll('[data-for]').forEach(function (el) { el.style.display = el.dataset.for === trigger.value ? '' : 'none'; });
    var shownEmpty = false;
    form.querySelectorAll('.wf-step').forEach(function (row) {
      var type = row.querySelector('.wf-type').value;
      row.querySelectorAll('[data-step]').forEach(function (el) { el.style.display = el.dataset.step === type ? '' : 'none'; });
      // Tampilkan satu baris kosong berikutnya saja agar formulir tetap ringkas.
      row.style.display = type || !shownEmpty ? '' : 'none';
      if (!type) shownEmpty = true;
    });
  }
  form.addEventListener('change', sync);
  sync();
})();
</script>
@endsection

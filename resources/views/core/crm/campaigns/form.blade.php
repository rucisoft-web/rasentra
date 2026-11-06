@extends('core.layout')
@section('content')
<p><a href="{{ route('core.crm.campaigns.index') }}">&larr; Semua kampanye</a></p>
@if ($templates->isEmpty())
  <div class="alert err">Belum ada template yang disetujui Meta. Buat template kategori MARKETING (boleh dengan header gambar/video) di WhatsApp Manager, lalu sinkron di <a href="{{ route('core.crm.whatsapp.index') }}">Nomor & template</a>.</div>
@endif
<form class="card" method="post" action="{{ route('core.crm.campaigns.store') }}" enctype="multipart/form-data" style="max-width:980px">
  @csrf
  <div class="fields">
    <label class="f wide"><span>Nama kampanye</span><input name="name" value="{{ old('name') }}" required maxlength="120" placeholder="Promo Wagyu Akhir Pekan – Komunitas Steak Solo"></label>
    <label class="f wide"><span>Template iklan</span>
      <select name="crm_wa_template_id" required>
        @foreach ($templates as $t)<option value="{{ $t->id }}" data-params="{{ $t->body_params }}" @selected(old('crm_wa_template_id') == $t->id)>{{ $t->name }} · {{ $t->category }} · header {{ $t->header_type ?? 'tidak ada' }} · {{ \Illuminate\Support\Str::limit($t->body, 80) }}</option>@endforeach
      </select>
    </label>
    <div class="f wide"><span>Komunitas penerima</span>
      <div>@forelse ($communities as $c)<label class="check" style="display:inline-flex;margin-right:14px"><input type="checkbox" name="community_ids[]" value="{{ $c->id }}" @checked(in_array($c->id, old('community_ids', $campaign->audience['community_ids'] ?? [])))> {{ $c->name }} <span class="muted small">({{ $c->opted_in_count }} opt-in)</span></label>@empty<span class="muted small">Belum ada komunitas.</span>@endforelse</div>
    </div>
    <label class="f"><span>dan/atau segmen</span><select name="segment_id"><option value="">—</option>@foreach ($segments as $s)<option value="{{ $s->id }}" @selected(old('segment_id', $campaign->audience['segment_id'] ?? null) == $s->id)>{{ $s->name }}</option>@endforeach</select><small>Hanya pelanggan opt-in yang dikirimi; duplikat otomatis dihapus.</small></label>
    <label class="f"><span>Jadwal kirim</span><input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at') }}"><small>Kosongkan untuk kirim segera.</small></label>
    @for ($i = 0; $i < max(1, $templates->max('body_params') ?? 0); $i++)
      <label class="f"><span>Parameter &#123;&#123;{{ $i + 1 }}&#125;&#125;</span><input name="params[]" value="{{ old('params.'.$i, $i === 0 ? '{first_name}' : '') }}"><small>Teks tetap, atau {name}, {first_name}, {outlet}.</small></label>
    @endfor
    <label class="f"><span>Materi iklan (gambar/video/PDF, maks. 16 MB)</span><input type="file" name="media" accept=".jpg,.jpeg,.png,.mp4,.pdf"></label>
    <label class="f"><span>atau URL materi</span><input type="url" name="media_url" value="{{ old('media_url') }}" placeholder="https://…/promo.jpg"></label>
  </div>
  <p class="actions">
    <button class="btn" name="action" value="launch" onclick="return confirm('Kirim/jadwalkan iklan ini ke semua penerima opt-in?')">Kirim / jadwalkan</button>
    <button class="btn alt" name="action" value="draft">Simpan draf</button>
  </p>
</form>
@endsection

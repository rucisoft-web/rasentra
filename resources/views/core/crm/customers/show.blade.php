@extends('core.layout')
@use('App\Support\Fmt')
@use('App\Domain\Crm\Phone')
@use('App\Domain\Crm\SegmentQuery')
@section('content')
<div class="bar"><a href="{{ route('core.crm.customers.index') }}">&larr; Semua pelanggan</a>
  <form method="post" action="{{ route('core.crm.inbox.start', $customer) }}">@csrf<button class="btn alt sm">Chat WhatsApp</button></form>
  @if ($customer->communities->isNotEmpty())<span class="small">Komunitas: @foreach ($customer->communities as $cm)<a class="b info" href="{{ route('core.crm.communities.show', $cm) }}">{{ $cm->name }}</a> @endforeach</span>@endif
</div>
<div class="grid g4">
  <div class="card kpi"><div class="label">Tahap</div><div class="value" style="font-size:1.2rem">{{ strtok(SegmentQuery::LIFECYCLES[$stage], '(') }}</div><div class="note">{{ SegmentQuery::LIFECYCLES[$stage] }}</div></div>
  <div class="card kpi"><div class="label">Transaksi</div><div class="value">{{ $customer->purchase_count }}</div><div class="note">sejak {{ $customer->first_purchase_on?->translatedFormat('j M Y') ?? '—' }}</div></div>
  <div class="card kpi"><div class="label">Total belanja</div><div class="value">{{ Fmt::money($customer->total_spent) }}</div><div class="note">rata-rata {{ Fmt::money($customer->purchase_count ? (float) $customer->total_spent / $customer->purchase_count : 0) }}</div></div>
  <div class="card kpi"><div class="label">Terakhir belanja</div><div class="value" style="font-size:1.2rem">{{ $customer->last_purchase_on?->translatedFormat('j M Y') ?? '—' }}</div><div class="note">{{ $customer->last_purchase_on ? (int) $customer->last_purchase_on->diffInDays(now()->startOfDay()).' hari lalu' : 'belum ada transaksi' }}</div></div>
</div>

<div class="grid g2" style="margin-top:14px">
  <form class="card" method="post" action="{{ route('core.crm.customers.update', $customer) }}">
    @csrf @method('put')
    <h3>Profil</h3>
    <div class="fields">
      <label class="f"><span>Nama</span><input name="name" value="{{ old('name', $customer->name) }}" required maxlength="120"></label>
      <label class="f"><span>No WhatsApp</span><input value="{{ Phone::display($customer->phone) }}" disabled><small>Kunci pelanggan; tidak bisa diubah.</small></label>
      <label class="f"><span>Email</span><input type="email" name="email" value="{{ old('email', $customer->email) }}"></label>
      <label class="f"><span>Tanggal lahir</span><input type="date" name="birth_date" value="{{ old('birth_date', $customer->birth_date?->toDateString()) }}"></label>
      <label class="f"><span>Kota</span><input name="city" value="{{ old('city', $customer->city) }}" maxlength="80"></label>
      <label class="f"><span>Status WhatsApp</span>
        <select name="wa_status">
          @foreach (['unknown' => 'Belum ditanya', 'opted_in' => 'Opt-in (setuju menerima pesan)', 'opted_out' => 'Opt-out (jangan kirim promosi)'] as $k => $v)
            <option value="{{ $k }}" @selected(old('wa_status', $customer->wa_status) === $k)>{{ $v }}</option>
          @endforeach
        </select>
        @if($customer->wa_status_at)<small>Sejak {{ Fmt::dt($customer->wa_status_at) }}</small>@endif
      </label>
      <div class="f wide"><span>Tag</span>
        <div>@forelse ($tags as $t)<label class="check" style="display:inline-flex;margin-right:12px"><input type="checkbox" name="tag_ids[]" value="{{ $t->id }}" @checked($customer->tags->contains($t))> {{ $t->name }}</label>@empty<span class="muted small">Belum ada tag. Buat dari halaman Pelanggan.</span>@endforelse</div>
      </div>
      <label class="f wide"><span>Catatan</span><textarea name="notes" rows="3" maxlength="2000">{{ old('notes', $customer->notes) }}</textarea></label>
    </div>
    <p><button class="btn">Simpan</button></p>
  </form>

  <div class="card">
    <h3>Keanggotaan outlet</h3>
    <table class="t">
      <thead><tr><th>Outlet</th><th>Kode member</th><th>Sumber</th><th>Bergabung</th></tr></thead>
      <tbody>
      @forelse ($customer->memberships as $m)
        <tr><td>{{ $m->branch->code }} {{ $m->branch->name }}</td><td class="mono">{{ $m->member_code ?? '—' }}</td><td>{{ ['import' => 'Impor', 'pos' => 'POS', 'accurate' => 'Accurate', 'manual' => 'Manual', 'whatsapp' => 'WhatsApp'][$m->source] ?? $m->source }}</td><td>{{ $m->joined_on?->translatedFormat('j M Y') ?? '—' }}</td></tr>
      @empty
        <tr><td colspan="4" class="muted">Belum terdaftar di outlet mana pun.</td></tr>
      @endforelse
      </tbody>
    </table>
    @if ($externalIds->isNotEmpty())
      <h3 style="margin-top:14px">Kode di sistem lain</h3>
      <dl class="dl">@foreach ($externalIds as $x)<dt>{{ ucfirst($x->system) }} {{ $x->company }}</dt><dd class="mono">{{ $x->external_id }}</dd>@endforeach</dl>
    @endif
    @if ($byOutlet->isNotEmpty())
      <h3 style="margin-top:14px">Belanja per outlet</h3>
      <table class="t"><tbody>@foreach ($byOutlet as $r)<tr><td>{{ $r->code }}</td><td>{{ $r->source === 'pos' ? 'POS' : 'Accurate' }}</td><td class="n">{{ $r->n }}×</td><td class="n">{{ Fmt::money($r->total) }}</td></tr>@endforeach</tbody></table>
    @endif
  </div>
</div>

<div class="tablewrap" style="margin-top:14px">
<table class="t">
  <thead><tr><th>Tanggal</th><th>Sumber</th><th>Outlet</th><th>Dokumen</th><th class="n">Nilai</th></tr></thead>
  <tbody>
  @forelse ($purchases as $p)
    <tr><td>{{ $p->purchased_on->translatedFormat('j M Y') }}</td><td><span class="b {{ $p->source === 'pos' ? 'ok' : 'info' }}">{{ $p->source === 'pos' ? 'POS Rasentra' : 'Accurate' }}</span></td><td>{{ $p->branch?->code ?? '—' }}</td><td class="mono">{{ $p->doc_no ?? '—' }}</td><td class="n">{{ Fmt::money($p->amount) }}</td></tr>
  @empty
    <tr><td colspan="5" class="muted">Belum ada riwayat belanja.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
@if ($customer->purchase_count > 50)<p class="muted small">Menampilkan 50 transaksi terakhir dari {{ $customer->purchase_count }}.</p>@endif
@endsection

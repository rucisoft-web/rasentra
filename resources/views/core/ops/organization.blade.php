@extends('core.layout')
@section('content')
<p class="muted">Struktur {{ config('rasentra.group_name') }}: setiap CV punya pembukuan sendiri, setiap outlet dilayani satu Rasentra Lokal yang menjalankan meatshop/grocery dan/atau resto. Semua master diatur dari kantor pusat ini.</p>
<div class="card" style="margin-bottom:14px">
  <h2>Kantor pusat · Rasentra Central</h2>
  <p class="small">{{ $hq->groupBy('role')->map(fn ($u, $r) => (\App\Domain\Core\CoreAccess::ROLES[$r] ?? $r).': '.$u->pluck('name')->implode(', '))->implode(' · ') ?: 'Belum ada akun pusat.' }}</p>
</div>
@foreach ($companies as $c)
  <div class="card" style="margin-bottom:14px">
    <h2>{{ $c->name }} <span class="muted small mono">{{ $c->code }} · SAP {{ $c->sap_company_db ?? '-' }}</span></h2>
    <p class="small muted">Wilayah: {{ ($regions[$c->id] ?? collect())->map(fn ($r) => $r->name.' ('.$r->code.')')->implode(', ') ?: '-' }}</p>
    <div class="tablewrap"><table class="t">
      <thead><tr><th>Outlet</th><th>Wilayah</th><th>Usaha</th><th>Lokasi & station</th><th class="n">Meja</th><th>Staf per peran</th></tr></thead>
      <tbody>@foreach ($c->outlets as $o)
        <tr>
          <td><b>{{ $o->code }}</b> {{ $o->name }}<br><span class="small muted">{{ $o->timezone }}</span></td>
          <td>{{ $o->region?->name }}</td>
          <td class="small">{{ collect([$o->has_retail ? 'Meatshop/grocery: '.($o->retail_name ?? '-') : null, $o->has_restaurant ? 'Resto: '.($o->restaurant_name ?? '-') : null])->filter()->implode(' · ') }}</td>
          <td class="small">{{ $o->locations->where('is_active', true)->pluck('name')->implode(', ') }}</td>
          <td class="n">{{ $tables[$o->id] ?? 0 }}</td>
          <td class="small">{{ collect($staff[$o->id] ?? [])->map(fn ($s) => str_replace('_', ' ', $s->role).' '.$s->n)->implode(', ') ?: '-' }}</td>
        </tr>
      @endforeach</tbody>
    </table></div>
  </div>
@endforeach
<details class="card"><summary><b>Tambah wilayah</b></summary>
  <form method="post" action="{{ route('core.organization.regions.store') }}" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;align-items:end">@csrf
    <label class="f"><span>Perusahaan</span><select name="business_unit_id">@foreach ($companies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
    <label class="f"><span>Kode</span><input name="code" required class="mono" placeholder="JKT"></label>
    <label class="f"><span>Nama</span><input name="name" required placeholder="Jakarta"></label>
    <button class="btn">Simpan</button>
  </form>
  <p class="small muted">Outlet baru ditambahkan di <a href="{{ route('core.outlets.index') }}">Perusahaan & outlet</a>.</p>
</details>
@endsection

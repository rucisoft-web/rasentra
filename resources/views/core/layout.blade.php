@use('App\Domain\Core\CoreAccess')
@php
    $user = auth()->user();
    // Rasentra Central terdiri dari tiga modul: Sinkronisasi, Posting SAP dan Rasentra Core.
    $modules = [
        'Rasentra Central' => [
            '' => [
                ['monitoring', 'core.central', 'Status Central'],
            ],
        ],
        'Sinkronisasi' => [
            '' => [
                ['monitoring', 'core.monitor.sync', 'Sinkronisasi outlet'],
                ['monitoring', 'core.reconciliation', 'Rekonsiliasi harian'],
                ['master', 'core.devices.index', 'Perangkat & token'],
                ['monitoring', 'core.xml.index', 'Impor XML'],
            ],
        ],
        'Posting SAP' => [
            '' => [
                ['monitoring', 'core.monitor.sap', 'Dokumen SAP'],
                ['monitoring', 'core.monitor.exceptions', 'Masalah integrasi'],
                ['monitoring', 'core.sap-mappings.index', 'Mapping SAP'],
            ],
        ],
        'Rasentra Core' => [
            'Ringkasan' => [
                ['dashboard', 'core.dashboard', 'Dashboard'],
            ],
            'Master' => [
                ['master', 'core.outlets.index', 'Perusahaan & outlet'],
                ['master', 'core.users.index', 'Pengguna'],
            ],
            'Katalog' => [
                ['catalog', 'core.products.index', 'Produk'],
                ['catalog', 'core.prices.index', 'Versi harga'],
                ['promotions', 'core.promotions.index', 'Promotion Designer'],
                ['recipes', 'core.recipes.index', 'Resep'],
                ['recipes', 'core.cooking-fees.index', 'Cooking fee'],
            ],
            'Laporan' => [
                ['reports', 'core.reports.sales', 'Penjualan'],
                ['reports', 'core.reports.promotions', 'Promo'],
                ['reports', 'core.reports.kitchen', 'Waktu saji'],
                ['reports', 'core.reports.stock', 'Opname & waste'],
                ['audit', 'core.audit', 'Audit log'],
            ],
        ],
    ];
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? 'Rasentra Central' }} · Rasentra Central</title>
<link rel="stylesheet" href="{{ asset('assets/core/core.css') }}">
<script src="{{ asset('assets/core/core.js') }}" defer></script>
</head>
<body>
<div class="shell">
  <nav class="side">
    <div class="brand"><i></i>Rasentra Central</div>
    <div class="sub">Kantor pusat {{ config('rasentra.group_name') }}</div>
    @foreach ($modules as $module => $groups)
      @php $visible = array_filter(array_map(fn ($items) => array_filter($items, fn ($i) => CoreAccess::allows($user, $i[0])), $groups)); @endphp
      @if ($visible)
        <div class="module">
          @if ($module !== 'Rasentra Central')<h5>{{ $module }}</h5>@endif
          @foreach ($visible as $group => $items)
            @if ($group !== '')<h6>{{ $group }}</h6>@endif
            @foreach ($items as [$area, $route, $label])
              <a href="{{ route($route) }}" @class(['on' => request()->routeIs($route) || (str_ends_with($route, '.index') && request()->routeIs(substr($route, 0, -6).'.*'))])>{{ $label }}</a>
            @endforeach
          @endforeach
        </div>
      @endif
    @endforeach
  </nav>
  <div class="main">
    <header class="top">
      <h1>{{ $title ?? 'Rasentra Central' }}</h1>
      <div class="who">
        <span>{{ $user->name }} · {{ CoreAccess::ROLES[$user->role] ?? $user->role }}</span>
        <form method="post" action="{{ route('core.logout') }}">@csrf<button class="btn alt sm">Keluar</button></form>
      </div>
    </header>
    <div class="content">
      @if (session('ok'))<div class="alert ok">{{ session('ok') }}</div>@endif
      @if (session('error'))<div class="alert err">{{ session('error') }}</div>@endif
      @if ($errors->any())
        <div class="alert err">Periksa isian berikut:<ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
      @endif
      @yield('content')
    </div>
  </div>
</div>
</body>
</html>

@use('App\Domain\Core\CoreAccess')
@php
    $user = auth()->user();
    $nav = [
        'Ringkasan' => [
            ['dashboard', 'core.dashboard', 'Dashboard'],
        ],
        'Master' => [
            ['master', 'core.outlets.index', 'Perusahaan & outlet'],
            ['master', 'core.users.index', 'Pengguna'],
            ['master', 'core.devices.index', 'Perangkat & token'],
        ],
        'Katalog' => [
            ['catalog', 'core.products.index', 'Produk'],
            ['catalog', 'core.prices.index', 'Versi harga'],
            ['promotions', 'core.promotions.index', 'Promotion Designer'],
            ['recipes', 'core.recipes.index', 'Resep'],
            ['recipes', 'core.cooking-fees.index', 'Cooking fee'],
        ],
        'Monitoring' => [
            ['monitoring', 'core.central', 'Rasentra Central'],
            ['monitoring', 'core.monitor.sync', 'Sinkronisasi outlet'],
            ['monitoring', 'core.reconciliation', 'Rekonsiliasi harian'],
            ['monitoring', 'core.monitor.sap', 'Posting SAP'],
            ['monitoring', 'core.monitor.exceptions', 'Masalah integrasi'],
            ['monitoring', 'core.sap-mappings.index', 'Mapping SAP'],
            ['monitoring', 'core.xml.index', 'Impor XML'],
        ],
        'Laporan' => [
            ['reports', 'core.reports.sales', 'Penjualan'],
            ['reports', 'core.reports.promotions', 'Promo'],
            ['reports', 'core.reports.kitchen', 'Waktu saji'],
            ['reports', 'core.reports.stock', 'Opname & waste'],
            ['audit', 'core.audit', 'Audit log'],
        ],
    ];
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? 'Rasentra Core' }} · Rasentra Core</title>
<link rel="stylesheet" href="{{ asset('assets/core/core.css') }}">
<script src="{{ asset('assets/core/core.js') }}" defer></script>
</head>
<body>
<div class="shell">
  <nav class="side">
    <div class="brand"><i></i>Rasentra Core</div>
    <div class="sub">Admin kantor pusat {{ config('rasentra.group_name') }}</div>
    @foreach ($nav as $group => $items)
      @php $visible = array_filter($items, fn ($i) => CoreAccess::allows($user, $i[0])); @endphp
      @if ($visible)
        <h6>{{ $group }}</h6>
        @foreach ($visible as [$area, $route, $label])
          <a href="{{ route($route) }}" @class(['on' => request()->routeIs($route) || (str_ends_with($route, '.index') && request()->routeIs(substr($route, 0, -6).'.*'))])>{{ $label }}</a>
        @endforeach
      @endif
    @endforeach
  </nav>
  <div class="main">
    <header class="top">
      <h1>{{ $title ?? 'Rasentra Core' }}</h1>
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

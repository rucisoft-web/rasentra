<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rasentra Kitchen</title>
<link rel="stylesheet" href="{{ asset('assets/kitchen/kitchen.css') }}">
</head>
<body class="kds home">
<header class="kbar">
  <div class="brand"><i></i>Rasentra Kitchen</div>
  <div class="muted">{{ $outlet?->code }} · {{ $outlet?->name }} · {{ auth()->user()->name }}</div>
  <form method="post" action="{{ route('pos.logout') }}" class="push">@csrf<button class="kbtn ghost">Keluar</button></form>
</header>
<main class="picker">
  <h1>Pilih layar untuk perangkat ini</h1>
  <p class="muted">Pilihan disimpan di perangkat. Setiap station hanya melihat tiket miliknya.</p>
  <div class="picker-grid">
    @foreach ($stations as $code => $s)
      <a class="pick" href="{{ route('kitchen.screen', $code) }}"><b>{{ $s['name'] }}</b><span>Target {{ $s['target_minutes'] }} menit</span></a>
    @endforeach
    <a class="pick expo" href="{{ route('kitchen.screen', 'expo') }}"><b>Expeditor</b><span>Semua station per pesanan</span></a>
    <a class="pick queue" href="{{ route('kitchen.queue') }}"><b>Layar antrean</b><span>Nomor pickup untuk pelanggan</span></a>
    <a class="pick" href="{{ route('kitchen.analytics') }}"><b>Analisis waktu saji</b><span>Per station, per menu dan per jam</span></a>
  </div>
</main>
</body>
</html>

<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Analisis waktu saji · Rasentra Kitchen</title>
<link rel="stylesheet" href="{{ asset('assets/kitchen/kitchen.css') }}">
</head>
<body class="kds report" data-analytics>
<header class="kbar">
  <a class="brand" href="{{ route('kitchen') }}"><i></i>Rasentra Kitchen</a>
  <b class="station-name">Analisis waktu saji</b>
  <span class="muted">{{ $outlet?->code }} · {{ $outlet?->name }}</span>
</header>
<main class="analytics">
  <form class="range" id="range">
    <label>Dari <input type="date" name="from" value="{{ now()->toDateString() }}"></label>
    <label>Sampai <input type="date" name="to" value="{{ now()->toDateString() }}"></label>
    <button class="kbtn">Tampilkan</button>
  </form>
  <p class="muted">Waktu saji dihitung dari tiket masuk dapur sampai item Siap. Antre = masuk sampai mulai dimasak; masak = mulai sampai Siap. Terlambat dihitung terhadap target station.</p>
  <div class="tiles" id="tiles"></div>
  <h2>Per station</h2>
  <div class="tbl"><table id="by-station"></table></div>
  <h2>Per menu</h2>
  <div class="tbl"><table id="by-product"></table></div>
  <h2>Per jam</h2>
  <div class="tbl"><table id="by-hour"></table></div>
</main>
<script>window.KDS_STATIONS = @json(collect($stations)->map(fn ($s) => $s['name']));</script>
<script src="{{ asset('assets/kitchen/kitchen.js') }}" defer></script>
@include('partials.demo-bar')
</body>
</html>

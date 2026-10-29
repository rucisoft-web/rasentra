<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<title>@yield('title')</title>
<style>
  /* Printer thermal 80 mm: lebar cetak efektif ±72 mm. */
  @page { size: 80mm auto; margin: 0; }
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #e9e7e2; font: 12px/1.35 "DejaVu Sans Mono", "Courier New", monospace; color: #000; }
  .paper { width: 80mm; margin: 12px auto; padding: 4mm 4mm 8mm; background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.2); }
  .c { text-align: center; }
  .r { text-align: right; }
  .b { font-weight: 700; }
  .big { font-size: 15px; }
  .muted { color: #333; }
  hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  table { width: 100%; border-collapse: collapse; }
  td { vertical-align: top; padding: 0; }
  td.r { white-space: nowrap; padding-left: 6px; }
  .item td { padding-top: 3px; }
  .sub td { font-size: 11px; color: #222; }
  .stamp { border: 2px solid #000; text-align: center; font-weight: 700; letter-spacing: 2px; padding: 3px; margin: 6px 0; }
  .sign { display: flex; justify-content: space-between; gap: 8mm; margin-top: 14mm; }
  .sign div { flex: 1; border-top: 1px solid #000; text-align: center; padding-top: 2px; font-size: 11px; }
  .tools { width: 80mm; margin: 0 auto 16px; display: flex; gap: 8px; font-family: system-ui, sans-serif; }
  .tools button { flex: 1; padding: 10px; font: inherit; font-weight: 600; border: 1px solid #999; border-radius: 8px; background: #fff; cursor: pointer; }
  @media print {
    body { background: #fff; }
    .paper { margin: 0; box-shadow: none; }
    .tools { display: none; }
  }
</style>
</head>
<body>
<div class="paper">
  <div class="c b big">{{ $outlet->name }}</div>
  <div class="c">{{ $outlet->businessUnit->legal_name ?? $outlet->businessUnit->name }}</div>
  <div class="c muted">Indoguna Group</div>
  <hr>
  @yield('body')
</div>
<div class="tools"><button onclick="window.print()">Cetak</button><button onclick="window.close()">Tutup</button></div>
@if ($auto)
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 150));</script>
@endif
</body>
</html>

<!doctype html>
<html lang="id"><head><meta charset="utf-8"><title>QR meja {{ $outlet->code }}</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
  body { font-family: "Segoe UI", system-ui, sans-serif; margin: 16px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
  .tag { border: 2px solid #1f5a40; border-radius: 12px; padding: 14px; text-align: center; break-inside: avoid; }
  .tag b { font-size: 1.6rem; display: block; color: #1f5a40; }
  .qr { display: inline-block; margin: 8px 0; }
  small { color: #5d6d65; word-break: break-all; }
  @media print { button { display: none; } }
</style></head>
<body>
<button onclick="print()">Cetak</button>
<h2>{{ $outlet->restaurant_name ?? $outlet->name }} · Pindai untuk pesan</h2>
<div class="grid">
@foreach ($tables as $i => $row)
  <div class="tag"><b>Meja {{ $row['table']->code }}</b><span>{{ $row['table']->area }}</span><div class="qr" id="qr{{ $i }}" data-url="{{ $row['url'] }}"></div><small>{{ $row['url'] }}</small></div>
@endforeach
</div>
<script>document.querySelectorAll('.qr').forEach((el) => new QRCode(el, { text: el.dataset.url, width: 160, height: 160 }));</script>
</body></html>

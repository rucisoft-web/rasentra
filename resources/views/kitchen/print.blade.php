<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Tiket dapur {{ $label }}</title>
<style>
  /* Printer thermal 80 mm di station. Satu tiket per station, dipotong per halaman. */
  @page { size: 80mm auto; margin: 0; }
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #e9e7e2; font: 13px/1.35 "DejaVu Sans Mono", "Courier New", monospace; color: #000; }
  .paper { width: 80mm; margin: 12px auto; padding: 4mm 4mm 8mm; background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.2); break-after: page; }
  .station { font-size: 20px; font-weight: 700; text-align: center; border: 2px solid #000; padding: 2px; letter-spacing: 1px; text-transform: uppercase; }
  .label { font-size: 26px; font-weight: 700; text-align: center; margin: 6px 0 2px; }
  .meta { display: flex; justify-content: space-between; font-size: 11px; }
  hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  .item { display: grid; grid-template-columns: 9mm 1fr; font-size: 16px; font-weight: 700; margin-top: 4px; }
  .mods { grid-column: 2; font-size: 12px; font-weight: 400; }
  .allergy { grid-column: 2; font-size: 13px; font-weight: 700; border: 1px solid #000; padding: 0 3px; margin-top: 2px; }
  .void { text-decoration: line-through; }
  .code { text-align: center; margin-top: 8px; }
  .code svg { max-width: 100%; height: 14mm; }
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
@forelse ($tickets as $ticket)
<div class="paper">
  <div class="station">{{ $stations[$ticket->station]['name'] ?? $ticket->station }}</div>
  <div class="label">{{ $label }}</div>
  <div class="meta"><span>{{ $order->doc_no ? $order->doc_no.' · ' : '' }}T{{ $ticket->sequence }}</span><span>{{ $ticket->created_at->timezone(config('rasentra.display_timezone'))->format('H:i') }}</span></div>
  <div class="meta"><span>{{ ucfirst(str_replace('_', '-', $order->channel)) }}</span>@if (in_array($ticket->id, $additional, true))<span class="b">TAMBAHAN</span>@endif</div>
  <hr>
  @foreach ($ticket->items as $item)
    @php($ins = $item->instructions ?? [])
    <div class="item {{ $item->status === 'cancelled' ? 'void' : '' }}">
      <span>{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}×</span>
      <span>{{ $ins['product'] ?? '-' }}</span>
      @php($mods = collect($ins['modifiers'] ?? [])->map(fn ($m) => is_array($m) ? (($m['key'] ?? '') === 'cooking_fee' ? null : ($m['label'] ?? $m['key'] ?? null)) : $m)
          ->merge([isset($ins['raw_weight']) ? 'Pack '.rtrim(rtrim($ins['raw_weight'], '0'), '.').' kg' : null, isset($ins['portions']) ? $ins['portions'].' porsi' : null,
            $ins['method'] ?? null, $ins['doneness'] ?? null, $ins['sauce'] ?? null, $ins['course'] ?? null, $ins['note'] ?? null])->filter()->implode(' · '))
      @if ($mods !== '')<span class="mods">{{ $mods }}</span>@endif
      @if (! empty($ins['allergy']))<span class="allergy">ALERGI: {{ $ins['allergy'] }}</span>@endif
    </div>
  @endforeach
  <hr>
  @if ($ticket->bump_code)<div class="code">{!! \App\Support\Barcode::svg($ticket->bump_code) !!}<div>#{{ $ticket->bump_code }}</div></div>@endif
</div>
@empty
<div class="paper">Belum ada tiket dapur untuk pesanan ini.</div>
@endforelse
<div class="tools"><button onclick="window.print()">Cetak</button><button onclick="window.close()">Tutup</button></div>
@if ($auto)
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 150));</script>
@endif
</body>
</html>

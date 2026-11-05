@php $map = ['draft' => ['mute', 'Draf'], 'scheduled' => ['info', 'Terjadwal'], 'sending' => ['warn', 'Sedang dikirim'], 'done' => ['ok', 'Selesai'], 'cancelled' => ['err', 'Dibatalkan']]; @endphp
<span class="b {{ $map[$status][0] ?? '' }}">{{ $map[$status][1] ?? $status }}</span>

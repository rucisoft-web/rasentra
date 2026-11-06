@switch($status)
  @case('opted_in')<span class="b ok">Opt-in</span>@break
  @case('opted_out')<span class="b err">Opt-out</span>@break
  @default<span class="b mute">Belum ditanya</span>
@endswitch

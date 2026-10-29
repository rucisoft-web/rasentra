@php $tz = config('rasentra.display_timezone'); $tiers = $rule?->tiers ?? []; @endphp
<div class="fields">
  <label class="f"><span>Outlet</span><select name="outlet_id"><option value="">Semua outlet</option>@foreach ($outlets as $o)<option value="{{ $o->id }}" @selected(old('outlet_id', $rule?->outlet_id) == $o->id)>{{ $o->code }} {{ $o->name }}</option>@endforeach</select></label>
  <label class="f"><span>Metode</span><input name="method" value="{{ old('method', $rule?->method) }}" placeholder="kosong = standar/grill"><small>Mis. bakar, goreng, saus_padang. Tampil di Rasentra POS.</small></label>
  <label class="f"><span>Dasar tarif</span><select name="basis">@foreach ($bases as $k => $v)<option value="{{ $k }}" @selected(old('basis', $rule?->basis) === $k)>{{ $v }}</option>@endforeach</select></label>
  <label class="f"><span>Tarif dasar (Rp)</span><input name="rate" inputmode="decimal" value="{{ old('rate', $rule ? (float) $rule->rate : '') }}" required><small>Untuk bertingkat: tarif di atas tingkat terakhir.</small></label>
  <label class="f"><span>Biaya minimum (Rp)</span><input name="minimum_fee" inputmode="decimal" value="{{ old('minimum_fee', $rule ? (float) $rule->minimum_fee : '') }}"></label>
  <label class="f"><span>Produk jasa</span><select name="service_product_id">@foreach ($services as $s)<option value="{{ $s->id }}" @selected(old('service_product_id', $rule?->service_product_id) == $s->id)>{{ $s->name }} ({{ $s->code }})</option>@endforeach</select></label>
  <label class="f"><span>Resep jasa (bumbu, saus)</span><select name="recipe_version_id"><option value="">-</option>@foreach ($recipes as $id => $name)<option value="{{ $id }}" @selected(old('recipe_version_id', $rule?->recipe_version_id) == $id)>{{ $name }}</option>@endforeach</select></label>
  <label class="f"><span>Berlaku mulai</span><input type="datetime-local" name="effective_from" value="{{ old('effective_from', now($tz)->addDay()->startOfDay()->format('Y-m-d\TH:i')) }}" required></label>
  <div class="wide">
    <table class="t"><thead><tr><th>Tingkat: berat sampai (kg)</th><th>Tarif per kg (Rp)</th></tr></thead><tbody>
      @for ($i = 0; $i < 3; $i++)
        <tr><td><input name="tier_up_to[{{ $i }}]" inputmode="decimal" value="{{ old("tier_up_to.$i", $tiers[$i]['up_to'] ?? '') }}"></td><td><input name="tier_rate[{{ $i }}]" inputmode="decimal" value="{{ old("tier_rate.$i", $tiers[$i]['rate'] ?? '') }}"></td></tr>
      @endfor
    </tbody></table>
    <p class="muted small">Tingkat hanya dipakai untuk dasar tarif bertingkat.</p>
  </div>
</div>

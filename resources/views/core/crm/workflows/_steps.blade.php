@use('App\Models\Crm\Workflow')
@use('App\Support\Fmt')
<ol class="small" style="margin:0;padding-left:20px">
@foreach ($steps as $s)
  <li>{{ Workflow::STEP_TYPES[$s['type']] ?? $s['type'] }}:
    @switch($s['type'])
      @case('send_template'){{ $templates->firstWhere('id', $s['template_id'])?->name ?? 'template #'.$s['template_id'] }}@if(!empty($s['params'])) <span class="muted">[{{ implode(' | ', $s['params']) }}]</span>@endif @break
      @case('send_text')“{{ \Illuminate\Support\Str::limit($s['body'], 120) }}” @break
      @case('wait'){{ $s['hours'] % 24 === 0 ? ($s['hours'] / 24).' hari' : $s['hours'].' jam' }} @break
      @case('add_tag'){{ $tags->firstWhere('id', $s['tag_id'])?->name ?? '—' }} @break
      @case('join_community'){{ $communities->firstWhere('id', $s['community_id'])?->name ?? '—' }} @break
      @case('assign'){{ $agents->firstWhere('id', $s['user_id'])?->name ?? 'tanpa CS tertentu (buka percakapan)' }} @break
    @endswitch
  </li>
@endforeach
</ol>

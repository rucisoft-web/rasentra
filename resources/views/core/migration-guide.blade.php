@extends('core.layout')
@section('content')
<div class="grid" style="grid-template-columns:minmax(0,1fr) 260px;align-items:start">
  <article class="card guide-md" style="line-height:1.6">{!! $html !!}</article>
  <nav class="card small" style="position:sticky;top:14px">
    <b>Isi</b>
    <ol style="padding-left:18px;margin:8px 0 0">@foreach ($toc as $t)<li><a href="#{{ $t['id'] }}">{{ $t['text'] }}</a></li>@endforeach</ol>
  </nav>
</div>
<style>
  .guide-md h2 { margin-top: 28px; padding-top: 6px; border-top: 1px solid var(--line); }
  .guide-md h2:first-child { margin-top: 0; border-top: 0; }
  .guide-md blockquote { margin: 12px 0; padding: 10px 14px; background: var(--warn-bg); border-radius: 8px; }
  .guide-md li { margin: 4px 0; }
  .guide-md ul:has(input) { list-style: none; padding-left: 4px; }
  @media (max-width: 900px) { .guide-md + nav { display: none; } .grid[style] { grid-template-columns: 1fr !important; } }
</style>
@endsection

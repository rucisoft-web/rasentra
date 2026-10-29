@if ($paginator->hasPages())
<nav class="pager">
  @if ($paginator->onFirstPage())<span class="muted">&larr; Sebelumnya</span>@else<a href="{{ $paginator->previousPageUrl() }}">&larr; Sebelumnya</a>@endif
  <span class="muted">Halaman {{ $paginator->currentPage() }} dari {{ $paginator->lastPage() }}</span>
  @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}">Berikutnya &rarr;</a>@else<span class="muted">Berikutnya &rarr;</span>@endif
</nav>
@endif

<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Panduan Operasional per Peran · Rasentra</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&family=Source+Sans+3:wght@400;600;700&family=JetBrains+Mono:wght@400;600&display=swap">
<link rel="stylesheet" href="{{ asset('assets/site/doc.css') }}">
</head>
<body>
<header class="top"><div class="wrap">
  <a class="brand" href="{{ route('landing') }}"><i></i>Rasentra</a>
  <nav class="links" aria-label="Navigasi">
    <a href="{{ route('landing') }}">Beranda</a>
    <a href="{{ route('fitur') }}">Fitur Aplikasi</a>
    @if (config('rasentra.demo.enabled'))<a class="btn" href="{{ route('demo') }}">Coba demo</a>@endif
  </nav>
</div></header>

<div class="wrap layout">
  <aside>
    <details open id="toc"><summary>Daftar isi</summary>
      <ol>
        @foreach ($toc as $section)
          <li><a href="#{{ $section['id'] }}">{{ $section['text'] }}</a>
            @if ($section['children'])
              <ol>@foreach ($section['children'] as $child)<li><a href="#{{ $child['id'] }}">{{ $child['text'] }}</a></li>@endforeach</ol>
            @endif
          </li>
        @endforeach
      </ol>
    </details>
  </aside>

  <article>
    <p class="eyebrow">Panduan penggunaan</p>
    <h1>Panduan Operasional Rasentra per Peran</h1>
    @if (config('rasentra.demo.enabled'))
      <p class="intro">Ingin mencoba langsung? Buka <a href="{{ route('demo') }}">mode tamu</a> dan masuk sebagai peran yang sedang Anda baca, tanpa PIN atau kata sandi.</p>
    @endif
    {!! $html !!}
  </article>
</div>

<script>
// Tandai bagian yang sedang dibaca di daftar isi.
(() => {
  const links = new Map([...document.querySelectorAll('aside > details > ol > li > a')].map((a) => [a.getAttribute('href').slice(1), a]));
  const seen = new IntersectionObserver((entries) => {
    entries.forEach((e) => {
      if (!e.isIntersecting) return;
      links.forEach((a) => a.classList.remove('on'));
      links.get(e.target.id)?.classList.add('on');
    });
  }, { rootMargin: '-80px 0px -70% 0px' });
  document.querySelectorAll('article h2[id]').forEach((h) => seen.observe(h));
  if (matchMedia('(max-width: 900px)').matches) document.getElementById('toc').open = false;
})();
</script>
</body>
</html>

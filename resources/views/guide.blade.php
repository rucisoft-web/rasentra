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
<style>
:root{
  --bg:#f2f5f3; --surface:#fff; --ink:#15261e; --muted:#53645b; --line:#d3ddd7;
  --green:#1f5a40; --green-soft:#e3efe8; --amber:#e3a21a; --amber-soft:#fff3d6;
  --display:"Bricolage Grotesque", "Segoe UI", system-ui, sans-serif;
  --body:"Source Sans 3", "Segoe UI", system-ui, sans-serif;
  --mono:"JetBrains Mono", ui-monospace, Menlo, monospace;
}
@media (prefers-color-scheme: dark){:root{
  --bg:#0f1713; --surface:#16211b; --ink:#e6eee9; --muted:#9fb1a7; --line:#2a3a31;
  --green:#6fc49a; --green-soft:#1c3328; --amber:#f0b73c; --amber-soft:#2d2410; color-scheme:dark}}
*{box-sizing:border-box}
html{scroll-behavior:smooth;scroll-padding-top:84px}
body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--body);font-size:17px;line-height:1.6}
.wrap{max-width:1240px;margin:0 auto;padding:0 16px}
header.top{position:sticky;top:0;z-index:5;background:var(--surface);border-bottom:1px solid var(--line)}
header.top .wrap{display:flex;align-items:center;justify-content:space-between;gap:16px;min-height:64px}
.brand{font-family:var(--display);font-weight:800;font-size:1.2rem;color:var(--ink);text-decoration:none;display:flex;align-items:center;gap:10px}
.brand i{display:inline-block;width:32px;height:32px;background:url({{ asset('assets/brand/rasentra-mark.png') }}) center/contain no-repeat}
.links{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
.links a{color:var(--green);font-weight:600;text-decoration:none}
.links a.btn{background:var(--green);color:var(--surface);padding:8px 14px;border-radius:10px}
.layout{display:grid;grid-template-columns:270px minmax(0,1fr);gap:40px;padding:32px 0 64px}
aside{position:sticky;top:88px;align-self:start;max-height:calc(100vh - 104px);overflow:auto;font-size:.93rem;padding-right:6px}
aside summary{font-family:var(--mono);font-size:.75rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);cursor:pointer;list-style:none;margin-bottom:8px}
aside ol{list-style:none;margin:0;padding:0}
aside li{margin:2px 0}
aside a{display:block;color:var(--ink);text-decoration:none;padding:4px 10px;border-radius:8px;border-left:3px solid transparent}
aside a:hover{background:var(--green-soft)}
aside a.on{border-left-color:var(--green);background:var(--green-soft);font-weight:700}
aside ol ol{margin:2px 0 8px 10px}
aside ol ol a{color:var(--muted);font-size:.88rem;padding:2px 10px}
article{max-width:860px}
.eyebrow{font-family:var(--mono);font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin:0}
article h1{font-family:var(--display);font-size:clamp(2rem,4.5vw,2.8rem);line-height:1.1;margin:8px 0 12px}
article h2{font-family:var(--display);font-size:1.6rem;line-height:1.2;margin:56px 0 12px;padding-top:24px;border-top:1px solid var(--line)}
article h3{font-family:var(--display);font-size:1.18rem;margin:32px 0 8px}
article p,article li{margin:8px 0}
article a{color:var(--green)}
article code{font-family:var(--mono);font-size:.85em;background:var(--green-soft);padding:1px 5px;border-radius:5px}
article table{width:100%;border-collapse:collapse;font-size:.92rem;margin:14px 0;display:block;overflow-x:auto}
article th,article td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:left;vertical-align:top}
article th{background:var(--green-soft);font-weight:700}
article blockquote{margin:14px 0;background:var(--amber-soft);border-left:4px solid var(--amber);padding:6px 16px;border-radius:8px}
article ul.contains-task-list{list-style:none;padding-left:4px}
figure{margin:18px 0 26px;background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:12px;text-align:center}
figure img{max-width:100%;max-height:640px;height:auto;border-radius:8px;border:1px solid var(--line);background:#fff}
figcaption{margin-top:8px;color:var(--muted);font-size:.9rem;text-align:center}
.intro{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin:16px 0 8px;font-size:.95rem}
@media (max-width: 900px){
  .layout{grid-template-columns:minmax(0,1fr);gap:16px;padding-top:16px}
  aside{position:static;max-height:none;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:12px}
  aside ol ol{display:none}
  .links a:not(.btn){display:none}
}
@media print{header.top,aside{display:none}.layout{display:block}figure{break-inside:avoid}}
</style>
</head>
<body>
<header class="top"><div class="wrap">
  <a class="brand" href="{{ route('landing') }}"><i></i>Rasentra</a>
  <nav class="links" aria-label="Navigasi">
    <a href="{{ route('landing') }}">Beranda</a>
    <a href="{{ route('landing') }}#arsitektur">Arsitektur</a>
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

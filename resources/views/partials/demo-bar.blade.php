@if (config('rasentra.demo.enabled') && session('demo'))
<a href="{{ route('demo') }}" class="rasentra-demo-bar" title="Ganti aplikasi atau peran" style="position:fixed;left:12px;bottom:12px;z-index:9999;display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;background:#e3a21a;color:#2b1d00;font:700 14px/1.2 system-ui,sans-serif;text-decoration:none;box-shadow:0 4px 14px rgba(0,0,0,.18)">Mode tamu · Ganti aplikasi</a>
@endif

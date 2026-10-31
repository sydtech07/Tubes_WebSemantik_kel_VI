<!doctype html>
<html lang="id" data-bs-theme="dark">
<head prefix="og: https://ogp.me/ns# video: https://ogp.me/ns/video#">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0a0d14">
    <title>@yield('title', 'CINÉLOG')</title>
    <meta name="description" content="@yield('description', 'Katalog dan ulasan film rilis Januari-September 2026, ditenagai data semantik (RDF/SPARQL).')">

    {{-- Ikon emoji bawaan (juga mencegah browser meminta /favicon.ico ke server PHP) --}}
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎬</text></svg>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/cinelog.css') }}?v={{ @filemtime(public_path('css/cinelog.css')) }}" rel="stylesheet">
        <style>
        .cl-suggest { position: absolute; left: 0; right: 0; top: 100%; z-index: 1050; margin-top: .25rem; overflow: hidden;
            background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: .5rem; box-shadow: 0 .75rem 2rem rgba(0,0,0,.45); }
        .cl-suggest a { display: flex; justify-content: space-between; gap: .75rem; padding: .5rem .85rem; color: var(--bs-body-color); text-decoration: none; }
        .cl-suggest a small { color: var(--bs-secondary-color); }
        .cl-suggest a:hover, .cl-suggest a.active { background: var(--bs-tertiary-bg); }
        .cl-suggest .cl-suggest-all { justify-content: center; border-top: 1px solid var(--bs-border-color); font-size: .85rem; color: var(--bs-secondary-color); }
        @media (min-width: 768px) { .cl-search { min-width: 22rem; } }
        .cl-cast-name { display: block; color: inherit; text-decoration: none; }
        a.cl-cast-name:hover, dd a:hover { text-decoration: underline; }
    </style>
    @stack('head')
    @stack('head')
</head>
<body>
<nav class="navbar navbar-expand-md navbar-dark cl-navbar sticky-top">
    <div class="container">
        <a class="navbar-brand cl-brand" href="{{ route('home') }}">CINÉLOG</a>

        {{-- Tombol hamburger: tampil hanya di layar kecil (HP) --}}
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#clNav"
                aria-controls="clNav" aria-expanded="false" aria-label="Buka menu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="clNav">
            <ul class="navbar-nav me-auto mb-2 mb-md-0">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('home', 'film.show', 'search', 'person.show') ? 'active' : '' }}" href="{{ route('home') }}">Katalog</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('status') ? 'active' : '' }}" href="{{ route('status') }}">Status</a>
                </li>
            </ul>
                       <form class="cl-search position-relative" role="search" action="{{ route('search') }}" method="get">
                <div class="input-group">
                    <input class="form-control" type="search" name="q" value="{{ request()->string('q')->toString() }}"
                           placeholder="Cari film, aktor, sutradara, karakter, genre" aria-label="Cari" autocomplete="off"
                           data-suggest-url="{{ route('search.suggest', [], false) }}">
                    <button class="btn" type="submit" aria-label="Cari"><i class="bi bi-search"></i></button>
                </div>
                <div id="clSuggest" class="cl-suggest" role="listbox" hidden></div>
            </form>
        </div>
    </div>
</nav>

{{-- Halaman yang butuh lebar penuh (hero) memakai section 'bleed'; halaman lain memakai 'content' di dalam container --}}
@hasSection('bleed')
    <main>@yield('bleed')</main>
@else
    <main class="container py-4">@yield('content')</main>
@endif

<footer class="cl-footer">
    <div class="container text-center text-md-start">
        <strong>CINÉLOG</strong> · katalog film berbasis data semantik (RDF, SPARQL, Fuseki).<br>
        Produk ini memakai API TMDB tetapi tidak didukung atau disertifikasi oleh TMDB.
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
<script>
(function () {
    var input = document.querySelector('input[data-suggest-url]');
    var box = document.getElementById('clSuggest');
    if (!input || !box) return;
    var timer = null, ctrl = null, active = -1;
    var LABEL = { film: 'Film', orang: 'Orang', genre: 'Genre' };

    function close() { box.hidden = true; box.textContent = ''; active = -1; }
    function render(items) {
        box.textContent = '';
        if (!items.length) { close(); return; }
        items.forEach(function (it) {
            var a = document.createElement('a');
            a.href = it.url; a.setAttribute('role', 'option');
            var s = document.createElement('span'); s.textContent = it.label;
            var k = document.createElement('small'); k.textContent = LABEL[it.kind] || it.kind;
            a.appendChild(s); a.appendChild(k); box.appendChild(a);
        });
        var all = document.createElement('a');
        all.href = input.form.action + '?q=' + encodeURIComponent(input.value.trim());
        all.className = 'cl-suggest-all'; all.textContent = 'Lihat semua hasil untuk "' + input.value.trim() + '"';
        box.appendChild(all);
        box.hidden = false; active = -1;
    }
    function fetchSuggest() {
        var q = input.value.trim();
        if (q.length < 2) { close(); return; }
        if (ctrl) ctrl.abort();
        ctrl = new AbortController();
        fetch(input.dataset.suggestUrl + '?q=' + encodeURIComponent(q), { signal: ctrl.signal, headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(render)
            .catch(function () {});
    }
    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(fetchSuggest, 200); });
    input.addEventListener('keydown', function (e) {
        var links = box.querySelectorAll('a');
        if (e.key === 'Escape') { close(); return; }
        if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && links.length && !box.hidden) {
            e.preventDefault();
            active = (active + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
            links.forEach(function (l, i) { l.classList.toggle('active', i === active); });
        } else if (e.key === 'Enter' && active >= 0 && links[active]) {
            e.preventDefault(); window.location = links[active].href;
        }
    });
    document.addEventListener('click', function (e) { if (!input.form.contains(e.target)) close(); });
})();
</script>
</body>
</html>

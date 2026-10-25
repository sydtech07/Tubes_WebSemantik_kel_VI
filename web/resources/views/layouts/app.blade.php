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
                    <a class="nav-link {{ request()->routeIs('home', 'film.show') ? 'active' : '' }}" href="{{ route('home') }}">Katalog</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('status') ? 'active' : '' }}" href="{{ route('status') }}">Status</a>
                </li>
            </ul>
            <form class="cl-search" role="search" action="{{ route('home') }}" method="get">
                <div class="input-group">
                    <input class="form-control" type="search" name="q" value="{{ request()->string('q')->toString() }}"
                           placeholder="Cari judul film" aria-label="Cari judul film">
                    <button class="btn" type="submit" aria-label="Cari"><i class="bi bi-search"></i></button>
                </div>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

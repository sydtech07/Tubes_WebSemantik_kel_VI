<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'CINÉLOG')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    @stack('head')
</head>
<body class="bg-body-tertiary">
<nav class="navbar navbar-expand navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="{{ route('home') }}">CINÉLOG</a>
        <ul class="navbar-nav me-auto">
            <li class="nav-item"><a class="nav-link" href="{{ route('home') }}">Katalog</a></li>
            <li class="nav-item"><a class="nav-link" href="{{ route('status') }}">Status</a></li>
        </ul>
        <form class="d-flex" action="{{ route('home') }}" method="get">
            <input class="form-control form-control-sm me-2" type="search" name="q" value="{{ request('q') }}" placeholder="Cari judul film">
            <button class="btn btn-sm btn-outline-light" type="submit">Cari</button>
        </form>
    </div>
</nav>

<main class="container">
    @yield('content')
</main>

<footer class="container text-center text-muted small my-5">
    Produk ini memakai API TMDB tetapi tidak didukung atau disertifikasi oleh TMDB.
</footer>
</body>
</html>

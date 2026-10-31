@extends('layouts.app')

@section('title', 'Cari "' . $q . '" - CINÉLOG')

@section('content')
    <h1 class="cl-section-title">Hasil pencarian "{{ $q }}"</h1>
    <div class="text-muted small mt-2 mb-4">
        Dicari di judul film, nama aktor/sutradara/penulis/komposer, nama karakter, dan genre.
        @if (count($result['terms']) > 1)
            Kata kunci yang dipakai: {{ implode(', ', array_slice($result['terms'], 0, 4)) }}.
        @endif
    </div>

    @if (! empty($result['failed']) && ! $error)
        <div class="alert alert-warning small">
            Sebagian hasil belum bisa dimuat ({{ implode(', ', $result['failed']) }}) karena server data sedang lambat.
            Coba muat ulang halaman ini beberapa saat lagi.
        </div>
    @endif

    @if ($error)
        <div class="alert alert-danger">{{ $error }}</div>
    @elseif (mb_strlen($q) < 2)
        <div class="cl-panel text-center py-4">Ketik minimal 2 huruf di kotak pencarian.</div>
    @elseif ($kosong)
        <div class="cl-panel text-center py-5">
            <i class="bi bi-search display-4 text-muted"></i>
            <p class="mt-3 mb-1 fw-semibold">Maaf, kata kunci "{{ $q }}" tidak ditemukan.</p>
            <p class="text-muted small mb-2">Tidak ada film, aktor, sutradara, karakter, atau genre yang cocok.</p>
            @if (! empty($maksud))
                <p class="text-muted small mb-2">Mungkin maksud Anda:</p>
                <div class="d-flex flex-wrap gap-2 justify-content-center">
                    @foreach ($maksud as $s)
                        <a href="{{ route('search', ['q' => $s['label']]) }}" class="cl-chip">{{ $s['label'] }} <small>{{ $s['kind'] }}</small></a>
                    @endforeach
                </div>
            @else
                <p class="text-muted small mb-0">Coba kata kunci lain, misalnya sebagian judul, nama aktor, atau genre.</p>
            @endif
        </div>
    @endif

    @if (! empty($result['genres']))
        <h2 class="h5 mt-4 mb-3">Genre</h2>
        <div class="d-flex flex-wrap gap-2">
            @foreach ($result['genres'] as $g)
                <a href="{{ route('home', ['genre' => $g['id']]) }}" class="cl-chip">{{ $g['label'] }} <small>{{ $g['films'] }}</small></a>
            @endforeach
        </div>
    @endif

    @if (! empty($result['people']))
        <h2 class="h5 mt-4 mb-3">Orang <small class="text-muted">aktor, sutradara, penulis, komposer</small></h2>
        <div class="row row-cols-3 row-cols-sm-4 row-cols-md-6 row-cols-lg-8 g-3">
            @foreach ($result['people'] as $p)
                <div class="col text-center">
                    <a href="{{ route('person.show', ['id' => $p['id']]) }}" class="text-decoration-none">
                        <div class="cl-avatar mx-auto">
                            @if ($p['photo'])
                                <img src="{{ $p['photo'] }}" alt="Foto {{ $p['name'] }}" loading="lazy" decoding="async">
                            @else
                                {{ mb_strtoupper(mb_substr($p['name'], 0, 1)) }}
                            @endif
                        </div>
                        <div class="cl-cast-name">{{ $p['name'] }}</div>
                        <span class="cl-cast-role">{{ $p['films'] }} film</span>
                    </a>
                </div>
            @endforeach
        </div>
    @endif

    @if (! empty($result['characters']))
        <h2 class="h5 mt-4 mb-3">Karakter</h2>
        <div class="cl-panel p-0 overflow-hidden">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Karakter</th><th>Diperankan oleh</th><th>Film</th></tr></thead>
                <tbody>
                @foreach ($result['characters'] as $c)
                    <tr>
                        <td>{{ $c['character'] }}</td>
                        <td><a href="{{ route('person.show', ['id' => $c['actor_id']]) }}">{{ $c['actor'] }}</a></td>
                        <td><a href="{{ route('film.show', $c['slug']) }}">{{ $c['title'] }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (! empty($result['films']))
        <h2 class="h5 mt-4 mb-3">Film</h2>
        <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 row-cols-xl-6 g-3 g-md-4">
            @foreach ($result['films'] as $m)
                @include('partials.film-card', ['m' => $m, 'r' => $ratings->get($m['slug'])])
            @endforeach
        </div>
    @endif

    @if (! empty($result['queries']))
        <details class="mt-5">
            <summary class="text-muted small">Lihat query SPARQL yang dijalankan ke Fuseki</summary>
            @foreach ($result['queries'] as $nama => $query)
                <p class="small mb-1 mt-3"><strong>{{ $nama }}</strong></p>
                <pre class="cl-panel small" style="white-space: pre-wrap">{{ $query }}</pre>
            @endforeach
        </details>
    @endif
@endsection
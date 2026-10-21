@extends('layouts.app')

@section('title', 'Katalog Film 2026 - CINÉLOG')

@section('content')
    <div class="d-flex justify-content-between align-items-end mb-3">
        <h1 class="h4 mb-0">
            @if ($q !== '') Hasil pencarian "{{ $q }}" @else Film Populer 2026 @endif
        </h1>
        <span class="text-muted small">{{ $movies->total() }} film</span>
    </div>

    @if ($movies->isEmpty())
        <div class="alert alert-warning">
            Tidak ada film. Jika katalog kosong, pastikan data sudah diimpor
            (<code>python pipeline\03_import_mysql.py</code>) dan lihat halaman <a href="{{ route('status') }}">Status</a>.
        </div>
    @endif

    <div class="row row-cols-2 row-cols-md-4 row-cols-lg-6 g-3">
        @foreach ($movies as $movie)
            <div class="col">
                <a href="{{ route('film.show', $movie) }}" class="text-decoration-none text-body">
                    <div class="card h-100 shadow-sm">
                        @if ($movie->poster_url)
                            <img src="{{ $movie->poster_url }}" class="card-img-top" alt="Poster {{ $movie->title }}" loading="lazy">
                        @else
                            <div class="card-img-top bg-secondary-subtle d-flex align-items-center justify-content-center" style="aspect-ratio:2/3">tanpa poster</div>
                        @endif
                        <div class="card-body p-2">
                            <div class="fw-semibold small">{{ $movie->title }}</div>
                            <div class="text-muted small">{{ $movie->release_date?->format('d M Y') }}</div>
                            <div class="small">
                                @if ($movie->reviews_count > 0)
                                    ★ {{ number_format($movie->avg_rating, 1) }} <span class="text-muted">({{ $movie->reviews_count }})</span>
                                @else
                                    <span class="text-muted">belum ada ulasan</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="mt-4">{{ $movies->links('pagination::bootstrap-5') }}</div>
@endsection

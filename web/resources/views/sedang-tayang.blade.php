@extends('layouts.app')

@section('title', 'Sedang Tayang - CINÉLOG')
@section('description', 'Daftar film yang sedang ditayangkan di bioskop berdasarkan data RDF CINÉLOG.')

@section('content')

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
        <p class="text-uppercase small fw-semibold mb-2"
           style="color: var(--cl-accent, #ff8a00); letter-spacing: .12em">
            Jadwal Bioskop
        </p>

        <h1 class="cl-section-title mb-2">
            Sedang Tayang
        </h1>

        <p class="cl-sub mb-0">
            Film yang sedang ditayangkan di bioskop.
        </p>
    </div>

    <span class="badge rounded-pill text-bg-dark border px-3 py-2">
        Sumber: RDF · Fuseki · SPARQL
    </span>
</div>

@if ($error)

    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        {{ $error }}
    </div>

@else

    <form method="GET"
          action="{{ route('screenings.now') }}"
          class="cl-genre-filter mb-4">

        <div class="cl-genre-field">
            <label for="kota" class="form-label">
                <i class="bi bi-geo-alt"></i>
                Kota
            </label>

            <select id="kota"
                    name="kota"
                    class="form-select"
                    onchange="this.form.submit()">

                <option value="">
                    Semua kota
                </option>

                @foreach ($cities as $city)
                    <option value="{{ $city }}"
                        @selected($selectedCity === $city)>
                        {{ $city }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="cl-genre-field">
            <label for="genre" class="form-label">
                <i class="bi bi-tags"></i>
                Genre
            </label>
            <select id="genre" name="genre" class="form-select" onchange="this.form.submit()">
                <option value="">Semua genre</option>
                @foreach ($genres as $genre)
                    <option value="{{ $genre['id'] }}" @selected($selectedGenre === $genre['id'])>{{ $genre['label'] }}</option>
                @endforeach
            </select>
        </div>

        <div class="cl-genre-actions">
            <button type="submit" class="btn btn-cl">
                Terapkan
            </button>

            @if ($selectedCity || $selectedGenre)
                <a href="{{ route('screenings.now') }}"
                   class="btn btn-ghost">
                    Reset
                </a>
            @endif
        </div>
    </form>

    @if (empty($movies))

        <div class="cl-panel text-center py-5">
            <i class="bi bi-film display-4 text-muted"></i>

            <p class="mt-3 mb-1 fw-semibold">
                Tidak ada film yang sedang tayang
                @if ($selectedCity)
                    di {{ $selectedCity }}
                @endif
                .
            </p>
        </div>

    @else

        <div class="d-flex justify-content-between align-items-center mb-4">
            <p class="cl-sub mb-0">
                {{ count($movies) }} film sedang tayang
                @if ($selectedCity)
                    di <strong>{{ $selectedCity }}</strong>
                @endif
                @if ($selectedGenre)
                    untuk genre terpilih
                @endif
            </p>
        </div>

        <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 row-cols-xl-6 g-3 g-md-4">

            @foreach ($movies as $movie)

                @php
                    $r = $ratings->get($movie['slug']);
                @endphp

                @include('partials.film-card', [
                    'm' => $movie,
                    'r' => $r,
                    'badge' => 'Sedang Tayang',
                    'i' => $loop->index % 12,
                ])

            @endforeach

        </div>

    @endif

@endif

<p class="cl-sub small mt-4 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Status tayang berasal dari dataset screening CINÉLOG yang dimuat ke Apache Jena Fuseki.
</p>

@endsection

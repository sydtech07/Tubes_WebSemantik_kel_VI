@extends('layouts.app')

@section('title', $movie->title . ' - CINÉLOG')

@section('content')
    <div class="row g-4">
        <div class="col-md-3">
            @if ($movie->poster_url)
                <img src="{{ $movie->poster_url }}" class="img-fluid rounded shadow-sm" alt="Poster {{ $movie->title }}">
            @endif
        </div>
        <div class="col-md-9">
            <h1 class="h3">{{ $movie->title }}</h1>
            @if ($movie->original_title && $movie->original_title !== $movie->title)
                <div class="text-muted">{{ $movie->original_title }}</div>
            @endif
            <p class="text-muted">
                {{ $movie->release_date?->format('d F Y') }}
                @if ($movie->runtime) · {{ $movie->runtime }} menit @endif
            </p>

            <p>
                @foreach ($movie->genres as $genre)
                    <span class="badge text-bg-secondary">{{ $genre->name }}</span>
                @endforeach
            </p>

            <p>
                @if ($movie->reviews_count > 0)
                    <strong>★ {{ number_format($movie->avg_rating, 2) }}</strong> dari {{ $movie->reviews_count }} ulasan
                @else
                    Belum ada ulasan.
                @endif
            </p>

            @if ($movie->tagline)<p class="fst-italic">{{ $movie->tagline }}</p>@endif
            <p>{{ $movie->overview_id ?: $movie->overview_en }}</p>

            @if ($directors->isNotEmpty())
                <p><strong>Sutradara:</strong>
                    {{ $directors->map(fn ($c) => $c->person->name)->implode(', ') }}
                </p>
            @endif

            <h2 class="h5 mt-4">Pemeran</h2>
            <ul class="list-unstyled row row-cols-1 row-cols-md-2">
                @foreach ($movie->cast as $c)
                    <li class="col">{{ $c->person->name }}
                        @if ($c->role) <span class="text-muted">sebagai {{ $c->role }}</span> @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endsection

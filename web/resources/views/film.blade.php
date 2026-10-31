@extends('layouts.app')

@section('title', $film['title'] . ' - CINÉLOG')
@section('description', $ogDescription)

{{-- ===== Open Graph (tipe video.movie) + Twitter Card. Prefix og: dan video: dideklarasikan di <head> layout. ===== --}}
@push('head')
    <meta property="og:type" content="video.movie">
    <meta property="og:site_name" content="CINÉLOG">
    <meta property="og:locale" content="id_ID">
    <meta property="og:title" content="{{ $film['title'] }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:url" content="{{ $film['url'] }}">
    @if ($film['poster'])
        <meta property="og:image" content="{{ $film['poster'] }}">
        <meta property="og:image:alt" content="Poster {{ $film['title'] }}">
    @endif
    <meta property="video:release_date" content="{{ $film['date'] }}">
    @if ($film['runtime'])
        <meta property="video:duration" content="{{ $film['runtime'] * 60 }}">
    @endif
    @foreach ($film['genres'] as $g)
        <meta property="video:tag" content="{{ $g['label'] }}">
    @endforeach
    <meta name="twitter:card" content="summary_large_image">
@endpush

@section('bleed')
@php
    $cinNs   = rtrim(config('cinelog.base_uri'), '/') . '/ontology#';
    $tanggal = \Illuminate\Support\Carbon::parse($film['date'])->locale('id')->translatedFormat('d F Y');
    $vote    = $film['vote'] !== null ? (float) $film['vote'] : null;
    $warna   = $vote === null ? '#98a2b8' : ($vote >= 7 ? '#2ecc71' : ($vote >= 5 ? '#ffc83d' : '#ff4d4d'));
    // inisial untuk avatar pemeran yang tidak punya foto
    $inisial = fn (string $nama) => mb_strtoupper(collect(preg_split('/\s+/', trim($nama)))->take(2)->map(fn ($k) => mb_substr($k, 0, 1))->implode(''));
@endphp

{{-- ===== RDFa 1.1. Subjek = IRI yang sama dengan di Fuseki, jadi triple di halaman ini cocok dengan triple di triple store. ===== --}}
<article vocab="https://schema.org/"
         prefix="cin: {{ $cinNs }} owl: http://www.w3.org/2002/07/owl# xsd: http://www.w3.org/2001/XMLSchema#"
         typeof="Movie cin:Movie"
         resource="{{ $film['iri'] }}"
         lang="">

    <link property="url" href="{{ $film['url'] }}">
    <meta property="cin:tmdbId" content="{{ $movie->tmdb_id }}" datatype="xsd:integer">
    @if ($film['lang'])
        <meta property="inLanguage" content="{{ $film['lang'] }}">
        <meta property="cin:originalLanguage" content="{{ $film['lang'] }}">
    @endif
    @if ($film['runtime'])
        <meta property="duration" content="PT{{ $film['runtime'] }}M" datatype="xsd:duration">
        <meta property="cin:runtimeMinutes" content="{{ $film['runtime'] }}" datatype="xsd:integer">
    @endif
    @if ($film['backdrop'])
        <link property="cin:backdropImage" href="{{ $film['backdrop'] }}">
    @endif
    @if ($film['descId'] && $film['descEn'])
        {{-- sinopsis bahasa lain disertakan untuk mesin, tidak ditampilkan --}}
        <meta property="description" lang="en" content="{{ $film['descEn'] }}">
    @endif

    {{-- ===== Hero penuh lebar: backdrop + poster + judul. Di HP poster di atas, di layar lebar di samping. ===== --}}
    <section class="cl-film-hero">
        @if ($film['backdrop'])
            <div class="cl-film-bg" style="background-image:url('{{ $film['backdrop'] }}')"></div>
        @endif
        <div class="container cl-film-hero-inner">
            <a href="{{ route('home') }}" class="cl-back d-inline-block mb-4"><i class="bi bi-arrow-left"></i> Kembali ke katalog</a>

            <div class="row g-4 align-items-end">
                <div class="col-6 col-sm-4 col-md-3 mx-auto mx-md-0">
                    @if ($film['poster'])
                        <div class="cl-hero-poster mx-auto">
                            <img property="image" src="{{ $film['poster'] }}" alt="Poster {{ $film['title'] }}">
                        </div>
                    @endif
                </div>

                <div class="col-12 col-md-9">
                    <h1 class="cl-film-title mb-1"><span property="name">{{ $film['title'] }}</span></h1>
                    @if ($film['altTitle'])
                        <div class="cl-sub mb-1"><span property="alternateName">{{ $film['altTitle'] }}</span></div>
                    @endif

                    <p class="cl-sub mb-3">
                        <i class="bi bi-calendar3"></i>
                        <span property="datePublished" content="{{ $film['date'] }}" datatype="xsd:date">{{ $tanggal }}</span>
                        @if ($film['runtime']) · <i class="bi bi-clock"></i> {{ $film['runtime'] }} menit @endif
                    </p>

                    <p class="d-flex flex-wrap gap-2 mb-3">
                        @foreach ($film['genres'] as $g)
                            <a property="genre" resource="{{ $g['iri'] }}" href="{{ route('home', ['genre' => $g['id']]) }}"
                               class="cl-genre">{{ $g['label'] }}</a>
                        @endforeach
                    </p>

                    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                        @if ($vote !== null)
                            <span class="cl-score-box">
                                <span class="cl-ring cl-ring-lg" style="--p: {{ round($vote * 10) }}; --c: {{ $warna }}"><span>{{ number_format($vote, 1) }}</span></span>
                                <span>Skor<br>TMDB</span>
                            </span>
                        @endif

                        {{-- Rating pengguna CINÉLOG: dari MariaDB (trigger). Hanya ditulis sebagai RDFa bila sudah ada ulasan. --}}
                        @if ($movie->reviews_count > 0)
                            <p class="cl-rating" property="aggregateRating" typeof="AggregateRating">
                                <i class="bi bi-people-fill"></i>
                                <span property="ratingValue">{{ number_format($movie->avg_rating, 2) }}</span>
                                <small>/ <span property="bestRating">10</span> · <span property="reviewCount">{{ $movie->reviews_count }}</span> ulasan</small>
                            </p>
                        @else
                            <span class="cl-sub small"><i class="bi bi-chat-square-text"></i> Belum ada ulasan pengguna.</span>
                        @endif
                    </div>

                    @if ($film['tagline'])
                        <p class="fst-italic cl-sub mb-0" property="slogan">{{ $film['tagline'] }}</p>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <div class="container py-4">
        <div class="row g-4">
            {{-- ===== Kolom utama: sinopsis dan pemeran ===== --}}
            <div class="col-lg-8">
                @if ($film['description'])
                    <div class="cl-panel mb-4">
                        <h2><i class="bi bi-card-text"></i> Sinopsis</h2>
                        <p class="mb-0" property="description" lang="{{ $film['descId'] ? 'id' : 'en' }}">{{ $film['description'] }}</p>
                    </div>
                @endif

                @if ($film['cast'])
                    <div class="cl-panel">
                        <h2><i class="bi bi-people"></i> Pemeran</h2>
                        <ul class="cl-cast-row">
                            @foreach ($film['cast'] as $c)
                                <li class="cl-cast-item" property="actor" typeof="Person cin:Person" resource="{{ $c['iri'] }}">
                                    <div class="cl-avatar">
                                        @if ($c['photo'])
                                            <img property="image" src="{{ $c['photo'] }}" alt="Foto {{ $c['name'] }}" loading="lazy">
                                        @else
                                            {{ $inisial($c['name']) }}
                                        @endif
                                    </div>
                                    <a class="cl-cast-name" resource="{{ $c['iri'] }}" href="{{ route('person.show', ['id' => \Illuminate\Support\Str::afterLast($c['iri'], '/')]) }}"><span property="name">{{ $c['name'] }}</span></a>
                                    @if ($c['character']) <span class="cl-cast-role">{{ $c['character'] }}</span> @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- 2B-3: form rating + ulasan dan daftar ulasan ditempatkan di sini --}}
            </div>

            {{-- ===== Kolom samping: informasi dan tautan ===== --}}
            <aside class="col-lg-4">
                <div class="cl-panel mb-4">
                    <h2><i class="bi bi-info-circle"></i> Informasi</h2>
                    <dl class="cl-dl mb-0">
                @if ($film['directors'])
                            <dt>Sutradara</dt>
                            <dd>
                                @foreach ($film['directors'] as $p)
                                    <span property="director" typeof="Person cin:Person" resource="{{ $p['iri'] }}"><a resource="{{ $p['iri'] }}" href="{{ route('person.show', ['id' => \Illuminate\Support\Str::afterLast($p['iri'], '/')]) }}"><span property="name">{{ $p['name'] }}</span></a></span>@if (! $loop->last), @endif
                                @endforeach
                            </dd>
                @endif
                 @if ($film['writers'])
                    <dt>Penulis</dt>
                    <dd>
                         @foreach ($film['writers'] as $p)
                              <span property="author" typeof="Person cin:Person" resource="{{ $p['iri'] }}"><a resource="{{ $p['iri'] }}" href="{{ route('person.show', ['id' => \Illuminate\Support\Str::afterLast($p['iri'], '/')]) }}"><span property="name">{{ $p['name'] }}</span></a></span>@if (! $loop->last), @endif
                         @endforeach
                    </dd>
                 @endif
                 @if ($film['composers'])
                    <dt>Musik</dt>
                      <dd>
                          @foreach ($film['composers'] as $p)
                               <span property="musicBy" typeof="Person cin:Person" resource="{{ $p['iri'] }}"><a resource="{{ $p['iri'] }}" href="{{ route('person.show', ['id' => \Illuminate\Support\Str::afterLast($p['iri'], '/')]) }}"><span property="name">{{ $p['name'] }}</span></a></span>@if (! $loop->last), @endif
                          @endforeach
                      </dd>
                @endif
                 @if ($film['lang'])
                     <dt>Bahasa asli</dt>
                     <dd>{{ strtoupper($film['lang']) }}</dd>
                @endif
                 @if ($film['vote'])
                      <dt>Skor TMDB</dt>
                      <dd>
                          <i class="bi bi-star-fill" style="color:var(--cl-gold)"></i>
                          <span property="cin:tmdbVoteAverage" content="{{ $film['vote'] }}" datatype="xsd:decimal">{{ number_format((float) $film['vote'], 1) }}</span>
                         @if ($film['votes'])
                             <span class="text-muted small">(<span property="cin:tmdbVoteCount" content="{{ $film['votes'] }}" datatype="xsd:integer">{{ number_format($film['votes'], 0, ',', '.') }}</span> suara)</span>
                         @endif
                            </dd>
                        @endif
                        @if ($film['budget'] >= 1000)
                            {{-- nilai di bawah 1000 USD di TMDB adalah placeholder, jadi tidak ditampilkan --}}
                            <dt>Anggaran</dt>
                            <dd>${{ number_format($film['budget'], 0, ',', '.') }}</dd>
                        @endif
                        @if ($film['revenue'] >= 1000)
                            <dt>Pendapatan</dt>
                            <dd>${{ number_format($film['revenue'], 0, ',', '.') }}</dd>
                        @endif
                    </dl>
                </div>

                <div class="cl-panel">
                    <h2><i class="bi bi-link-45deg"></i> Tautan eksternal</h2>
                    <div class="d-flex flex-wrap gap-2">
                        <a property="sameAs" href="https://www.themoviedb.org/movie/{{ $movie->tmdb_id }}" rel="noopener"
                           class="btn btn-ghost btn-sm">TMDB</a>
                        @if ($film['imdb'])
                            <a property="sameAs" href="https://www.imdb.com/title/{{ $film['imdb'] }}/" rel="noopener"
                               class="btn btn-ghost btn-sm">IMDb</a>
                            <meta property="cin:imdbId" content="{{ $film['imdb'] }}">
                        @endif
                        @if ($film['wikidata'])
                            <a property="owl:sameAs" href="{{ $film['wikidata'] }}" rel="noopener"
                               class="btn btn-ghost btn-sm">Wikidata</a>
                        @endif
                    </div>
                    {{-- 2B-4: kotak data Wikidata (negara asal, box office) ditempatkan di sini --}}
                </div>
            </aside>
        </div>
    </div>
</article>
@endsection

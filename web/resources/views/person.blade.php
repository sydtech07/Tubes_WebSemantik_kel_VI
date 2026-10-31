@extends('layouts.app')

@section('title', $person['name'] . ' - CINÉLOG')
@section('description', $person['name'] . ' di CINÉLOG: ' . count($person['films']) . ' film.')

@push('head')
    <meta property="og:type" content="profile">
    <meta property="og:site_name" content="CINÉLOG">
    <meta property="og:locale" content="id_ID">
    <meta property="og:title" content="{{ $person['name'] }}">
    <meta property="og:description" content="{{ $person['name'] }}: {{ count($person['films']) }} film di CINÉLOG.">
    <meta property="og:url" content="{{ $pageUrl }}">
    @if ($person['photo'])
        <meta property="og:image" content="{{ $person['photo'] }}">
    @endif
@endpush

@section('content')
    {{-- lang="" mereset bahasa halaman (id) agar literal RDFa tanpa tag bahasa, sama seperti di dataset RDF. --}}
    <article vocab="https://schema.org/" prefix="cin: {{ $cinNs }}" typeof="Person cin:Person" resource="{{ $person['iri'] }}" lang="">
        <div class="row g-4 align-items-center mb-4">
            <div class="col-auto">
                <div class="cl-avatar">
                    @if ($person['photo'])
                        <img property="image" src="{{ $person['photo'] }}" alt="Foto {{ $person['name'] }}">
                    @else
                        {{ mb_strtoupper(mb_substr($person['name'], 0, 1)) }}
                    @endif
                </div>
            </div>
            <div class="col">
                <h1 class="cl-section-title" property="name">{{ $person['name'] }}</h1>
                <div class="text-muted small mt-2">
                    @if ($person['department'])
                        Dikenal di bidang <span property="cin:knownForDepartment">{{ $person['department'] }}</span> ·
                    @endif
                    {{ count($person['films']) }} film
                </div>
                @if ($person['gender'])
                    <link property="gender" href="{{ $person['gender'] }}">
                @endif
                <meta property="cin:tmdbId" content="{{ $person['id'] }}" datatype="xsd:integer">
                <a property="sameAs" href="https://www.themoviedb.org/person/{{ $person['id'] }}" rel="noopener" class="btn btn-ghost btn-sm mt-2">TMDB</a>
            </div>
        </div>
    </article>

    <h2 class="h5 mb-3">Karya</h2>
    @if (empty($person['films']))
        <div class="cl-panel text-center py-4">Belum ada film untuk orang ini di dataset.</div>
    @endif
    <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 row-cols-xl-6 g-3 g-md-4">
        @foreach ($person['films'] as $m)
            @include('partials.film-card', ['m' => $m, 'r' => $ratings->get($m['slug']), 'note' => implode(' · ', $m['roles'])])
        @endforeach
    </div>
@endsection